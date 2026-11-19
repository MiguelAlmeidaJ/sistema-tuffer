<?php
declare(strict_types=1);

namespace App\Services\Payments\Asaas;

use App\Core\Database;
use PDO;
use RuntimeException;

/** Cria uma única cobrança Asaas por pedido, sem split nem reenvio incerto. */
final class AsaasChargeService
{
    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?AsaasClient $api = null
    ) {}

    private function pdo(): PDO { return $this->database ?? Database::connection(); }
    private function client(): AsaasClient { return $this->api ?? new AsaasClient(); }

    public function create(int $paymentId): void
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            "SELECT p.*,o.code,o.user_id,o.grand_total,u.name,u.email,u.document,u.phone
               FROM payments p JOIN orders o ON o.id=p.order_id
               JOIN users u ON u.id=o.user_id WHERE p.id=? LIMIT 1"
        );
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['provider'] !== 'asaas') {
            throw new RuntimeException('Cobrança Asaas não localizada.');
        }
        if ($row['status'] !== 'pending' || !in_array($row['method'], ['pix','boleto','card'], true)) {
            return;
        }
        if ((int) $row['amount_cents'] < 1 ||
            (int) $row['amount_cents'] !== (int) round((float) $row['grand_total'] * 100)) {
            throw new RuntimeException('Valor do pagamento diverge do pedido.');
        }
        $remote = null;
        $existingId = trim((string) ($row['external_order_id'] ?? ''));
        if ($existingId !== '') {
            $remote = $this->client()->get('/payments/' . rawurlencode($existingId));
        } else {
            $remote = $this->findRemote((string) $row['code']);
        }
        if ($remote === null) {
            $marker = json_decode((string) ($row['provider_payload'] ?? ''), true);
            if (is_array($marker) && !empty($marker['asaas_create_attempted'])) {
                throw new RuntimeException('Criação Asaas anterior incerta: reconciliação manual necessária, sem reenviar.');
            }
            $document = preg_replace('/\D+/', '', (string) $row['document']) ?? '';
            if (!in_array(strlen($document), [11,14], true)) {
                throw new RuntimeException('CPF/CNPJ inválido no cadastro.');
            }
            $customer = $this->findCustomer((int) $row['user_id']);
            if ($customer === null) {
                $created = $this->client()->post('/customers', [
                    'name' => mb_substr((string) $row['name'], 0, 120),
                    'cpfCnpj' => $document,
                    'email' => (string) $row['email'],
                    'externalReference' => 'tuffer-user-' . (int) $row['user_id'],
                    'notificationDisabled' => true,
                ]);
                $customer = trim((string) ($created['id'] ?? ''));
            }
            if ($customer === '') {
                throw new RuntimeException('O Asaas não retornou um cliente válido.');
            }
            $method = ['pix'=>'PIX','boleto'=>'BOLETO','card'=>'CREDIT_CARD'][(string) $row['method']];
            // Marcador persistente antes do POST: evita duplicar uma cobrança se
            // a resposta de rede for perdida. Em caso incerto, intervenção humana.
            $mark = $pdo->prepare(
                "UPDATE payments SET provider_payload=JSON_OBJECT('asaas_create_attempted',true)
                 WHERE id=? AND provider='asaas' AND status='pending'
                   AND external_order_id IS NULL"
            );
            $mark->execute([$paymentId]);
            if ($mark->rowCount() !== 1) {
                throw new RuntimeException('Estado de cobrança alterado. Não reenviar.');
            }
            $remote = $this->client()->post('/payments', [
                'customer' => $customer,
                'billingType' => $method,
                'value' => (float) number_format((int) $row['amount_cents'] / 100, 2, '.', ''),
                'dueDate' => date('Y-m-d', strtotime('+2 days')),
                'description' => 'Pedido Tuffer ' . (string) $row['code'],
                'externalReference' => (string) $row['code'],
            ]);
        }
        $this->persist($row, $remote);
    }

    /** @return array<string,mixed>|null */
    private function findRemote(string $code): ?array
    {
        $result = $this->client()->get('/payments?externalReference=' . rawurlencode($code) . '&limit=10');
        $data = $result['data'] ?? [];
        if (!is_array($data)) return null;
        $matches = array_values(array_filter($data, static fn($p): bool =>
            is_array($p) && ($p['externalReference'] ?? null) === $code));
        if (count($matches) > 1) {
            throw new RuntimeException('Mais de uma cobrança Asaas encontrada: revisão manual necessária.');
        }
        return $matches[0] ?? null;
    }

    private function findCustomer(int $userId): ?string
    {
        $ref = 'tuffer-user-' . $userId;
        $data = $this->client()->get('/customers?externalReference=' . rawurlencode($ref) . '&limit=10');
        $rows = is_array($data['data'] ?? null) ? $data['data'] : [];
        $matches = array_values(array_filter($rows, static fn($c): bool =>
            is_array($c) && ($c['externalReference'] ?? null) === $ref));
        if (count($matches) > 1) {
            throw new RuntimeException('Cliente duplicado no Asaas: revisão manual necessária.');
        }
        return isset($matches[0]['id']) ? (string) $matches[0]['id'] : null;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $remote */
    private function persist(array $row, array $remote): void
    {
        $id = trim((string) ($remote['id'] ?? ''));
        $method = ['pix'=>'PIX','boleto'=>'BOLETO','card'=>'CREDIT_CARD'][(string) $row['method']];
        if (!preg_match('/^pay_[A-Za-z0-9_-]+$/', $id)
            || ($remote['externalReference'] ?? null) !== $row['code']
            || ($remote['billingType'] ?? null) !== $method
            || (int) round(((float) ($remote['value'] ?? -1)) * 100) !== (int) $row['amount_cents']) {
            throw new RuntimeException('Resposta de cobrança Asaas inconsistente com o pedido.');
        }
        $url = trim((string) ($remote['invoiceUrl'] ?? ''));
        if (!$this->trustedInvoiceUrl($url)) {
            throw new RuntimeException('O Asaas não retornou uma fatura HTTPS confiável.');
        }

        $pixPayload = null;
        $qrImage = null;
        $expires = null;
        if ($row['method'] === 'pix') {
            $qr = $this->client()->get('/payments/' . rawurlencode($id) . '/pixQrCode');
            $pixPayload = trim((string) ($qr['payload'] ?? ''));
            $qrImage = trim((string) ($qr['encodedImage'] ?? ''));
            $expires = trim((string) ($qr['expirationDate'] ?? ''));
            if ($pixPayload === '' || $qrImage === '' || base64_decode($qrImage, true) === false) {
                throw new RuntimeException('Asaas não retornou Pix copia e cola e QR Code completos.');
            }
            if (strlen($qrImage) > 250000) throw new RuntimeException('QR Code Asaas excede tamanho permitido.');
        }

        $pdo = $this->pdo();
        $pdo->prepare(
            "UPDATE payments SET external_order_id=?,checkout_url=?,pix_qr_code=?,
                asaas_pix_image=?,pix_expires_at=?,
                provider_payload=JSON_OBJECT('asaas_payment_id',?),
                status=IF(status='pending','waiting_payment',status)
             WHERE id=? AND provider='asaas' AND status IN ('pending','waiting_payment')
               AND (external_order_id IS NULL OR external_order_id=?)"
        )->execute([
            $id,$url,$pixPayload,$qrImage,
            $expires !== '' ? date('Y-m-d H:i:s', strtotime($expires) ?: time()) : null,
            $id,(int) $row['id'],$id
        ]);
    }

    public static function trustedInvoiceUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['pass'])
            && ($host === 'asaas.com' || str_ends_with($host, '.asaas.com'));
    }
}
