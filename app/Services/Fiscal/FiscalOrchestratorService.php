<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class FiscalOrchestratorService
{
    private readonly PDO $pdo;
    private readonly StoreFiscalProfileRepository $profiles;

    public function __construct(?PDO $pdo = null, ?StoreFiscalProfileRepository $profiles = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->profiles = $profiles ?? new StoreFiscalProfileRepository($this->pdo);
    }

    public function syncPaidOrder(int $orderId): void
    {
        if ($orderId < 1) return;

        $stmt = $this->pdo->prepare("SELECT id FROM seller_orders WHERE order_id=? AND status IN ('paid','processing','shipped','delivered') ORDER BY id");
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sellerOrderId) {
            $this->syncSellerOrder((int) $sellerOrderId);
        }
    }

    /** @return array<string,mixed> */
    public function syncSellerOrder(int $sellerOrderId): array
    {
        $context = $this->sellerOrderContext($sellerOrderId);
        if (!in_array((string) $context['seller_order_status'], ['paid','processing','shipped','delivered'], true)) {
            throw new RuntimeException('O pedido da loja ainda não está apto para acompanhamento fiscal.');
        }

        $profile = $this->profiles->find((int) $context['store_id'], (int) $context['seller_id']);
        $document = $this->findOrCreateDocument($context, $profile);

        if (in_array((string) $document['status'], ['authorized','cancelled','voided'], true)) {
            return $document;
        }

        if ($profile === null) {
            $this->markConfigurationRequired((int) $document['id'], 'A loja ainda não definiu como informará a NF-e emitida no próprio sistema.');
            return $this->document((int) $document['id']);
        }

        if (!(bool) ($profile['enabled'] ?? false)) {
            $this->markConfigurationRequired((int) $document['id'], 'O acompanhamento fiscal está desabilitado para esta loja.');
            return $this->document((int) $document['id']);
        }

        $mode = FiscalIssuanceMode::normalize((string) ($profile['issuance_mode'] ?? FiscalIssuanceMode::MANUAL));
        return $this->prepareStoreManagedDocument($document, $context, $profile, $mode);
    }

    public function reviewRefund(int $orderId, bool $fullRefund): void
    {
        $stmt = $this->pdo->prepare('SELECT fd.* FROM fiscal_documents fd JOIN seller_orders so ON so.id=fd.seller_order_id WHERE so.order_id=?');
        $stmt->execute([$orderId]);

        foreach ($stmt->fetchAll() as $document) {
            $id = (int) $document['id'];
            $status = (string) $document['status'];

            if ($status === 'authorized') {
                $reason = $fullRefund
                    ? 'Pedido reembolsado: a loja deve avaliar no próprio emissor o cancelamento da NF-e ou documento fiscal de devolução.'
                    : 'Pedido parcialmente reembolsado: a loja deve avaliar no próprio emissor o ajuste ou devolução fiscal.';
                $this->pdo->prepare('UPDATE fiscal_documents SET requires_action=1,action_reason=? WHERE id=?')->execute([$reason, $id]);
                $this->event($id, 'refund_review_required', $status, 'Reembolso exige revisão fiscal pela loja; a Tuffer não executou cancelamento.', ['full_refund' => $fullRefund]);
                continue;
            }

            if ($fullRefund && in_array($status, ['pending','configuration_required','validation_failed','ready','error','awaiting_manual','awaiting_external','submitting','processing','rejected'], true)) {
                $this->pdo->prepare("UPDATE fiscal_documents SET status='voided',requires_action=0,action_reason=NULL WHERE id=?")->execute([$id]);
                $this->event($id, 'voided_after_refund', 'voided', 'Acompanhamento fiscal não autorizado encerrado após reembolso integral.', ['full_refund' => true]);
            } elseif (!$fullRefund && !in_array($status, ['cancelled','voided'], true)) {
                $this->pdo->prepare("UPDATE fiscal_documents SET requires_action=1,action_reason='Reembolso parcial exige revisão fiscal pela loja no próprio emissor.' WHERE id=?")->execute([$id]);
                $this->event($id, 'partial_refund_review_required', $status, 'Reembolso parcial exige revisão fiscal pela loja.', ['full_refund' => false]);
            }
        }
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $context
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function prepareStoreManagedDocument(array $document, array $context, array $profile, string $mode): array
    {
        $status = $mode === FiscalIssuanceMode::MANUAL ? 'awaiting_manual' : 'awaiting_external';
        $provider = $mode === FiscalIssuanceMode::MANUAL ? 'manual' : trim((string) ($profile['provider'] ?? 'external'));
        if ($provider === '' || $provider === 'disabled' || $provider === 'manual') {
            $provider = $mode === FiscalIssuanceMode::MANUAL ? 'manual' : 'external';
        }

        $reason = $mode === FiscalIssuanceMode::MANUAL
            ? 'A loja deve emitir a NF-e no próprio sistema e registrar número, série, chave e arquivos na Tuffer.'
            : 'Aguardando o ERP ou emissor da loja devolver os dados da NF-e para a Tuffer.';

        $items = $this->items((int) $context['seller_order_id']);
        $issuerState = mb_strtoupper(trim((string) ($profile['state'] ?? '')));
        $destinationState = mb_strtoupper(trim((string) ($context['destination_state'] ?? '')));

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE fiscal_documents SET issuance_mode=?,provider=?,environment=?,series=?,products_total=?,shipping_total=?,discount_total=?,grand_total=?,issuer_snapshot=?,recipient_snapshot=?,tax_context=?,validation_errors=NULL,status=?,requires_action=1,action_reason=?,error_code=NULL,error_message=NULL WHERE id=?'
            )->execute([
                $mode,
                $provider,
                $profile['environment'] ?? 'production',
                (int) ($profile['nfe_series'] ?? 1),
                $context['products_total'],
                $context['shipping_total'],
                $context['discount_total'],
                $this->grand($context),
                $this->json($this->issuerSnapshot($context, $profile)),
                $this->json($this->recipientSnapshot($context)),
                $this->json([
                    'issuer_state' => $issuerState,
                    'destination_state' => $destinationState,
                    'managed_by_store' => true,
                    'tuffer_emits_document' => false,
                ]),
                $status,
                $reason,
                $document['id'],
            ]);

            $this->replaceItems((int) $document['id'], $items, $issuerState, $destinationState);
            $this->event((int) $document['id'], 'store_fiscal_pending', $status, $reason, ['mode' => $mode, 'provider' => $provider]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return $this->document((int) $document['id']);
    }

    private function markConfigurationRequired(int $documentId, string $reason): void
    {
        $this->pdo->prepare("UPDATE fiscal_documents SET status='configuration_required',requires_action=1,action_reason=?,error_code=NULL,error_message=NULL WHERE id=?")
            ->execute([$reason, $documentId]);
        $this->event($documentId, 'configuration_required', 'configuration_required', $reason);
    }

    /** @return array<string,mixed> */
    private function sellerOrderContext(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT so.id seller_order_id,so.order_id,so.seller_id,so.store_id,so.code seller_order_code,so.status seller_order_status,so.products_total,so.shipping_total,so.discount_total,so.seller_net_total,o.code order_code,o.status order_status,o.user_id,s.legal_name,s.trade_name,s.document,s.state_registration,st.name store_name,u.name customer_name,u.email customer_email,u.phone customer_phone,u.document customer_document,oa.recipient_name,oa.postal_code destination_postal_code,oa.street destination_street,oa.number destination_number,oa.complement destination_complement,oa.neighborhood destination_neighborhood,oa.city destination_city,COALESCE(oa.city_ibge_code,ua.city_ibge_code) destination_city_ibge_code,oa.state destination_state FROM seller_orders so JOIN orders o ON o.id=so.order_id JOIN sellers s ON s.id=so.seller_id JOIN stores st ON st.id=so.store_id JOIN users u ON u.id=o.user_id LEFT JOIN order_addresses oa ON oa.order_id=o.id LEFT JOIN user_addresses ua ON ua.user_id=o.user_id AND ua.postal_code=oa.postal_code AND ua.street=oa.street AND ua.number=oa.number AND ua.city=oa.city AND ua.state=oa.state WHERE so.id=? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Pedido da loja não encontrado para acompanhamento fiscal.');
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    private function items(int $sellerOrderId): array
    {
        $stmt = $this->pdo->prepare('SELECT oi.id order_item_id,oi.product_id,oi.product_variant_id,oi.product_name,oi.sku,oi.quantity,oi.unit_price,oi.total,COALESCE(pfp.gtin,pv.barcode) gtin,pfp.ncm,pfp.cest,pfp.origin,pfp.commercial_unit,pfp.tributary_unit,pfp.cfop_in_state,pfp.cfop_out_state,pfp.icms_code,pfp.pis_code,pfp.cofins_code,pfp.ipi_code,pfp.ibs_cbs_cst,pfp.ibs_cbs_classification,pfp.tax_metadata FROM order_items oi LEFT JOIN product_variants pv ON pv.id=oi.product_variant_id LEFT JOIN product_fiscal_profiles pfp ON pfp.product_id=oi.product_id WHERE oi.seller_order_id=? ORDER BY oi.id');
        $stmt->execute([$sellerOrderId]);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $profile
     * @return array<string,mixed>
     */
    private function findOrCreateDocument(array $context, ?array $profile): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM fiscal_documents WHERE seller_order_id=? AND document_type='nfe' AND revision=1 LIMIT 1");
        $stmt->execute([$context['seller_order_id']]);
        $row = $stmt->fetch();
        if (is_array($row)) return $row;

        $mode = FiscalIssuanceMode::normalize((string) ($profile['issuance_mode'] ?? FiscalIssuanceMode::MANUAL));
        $provider = $mode === FiscalIssuanceMode::MANUAL ? 'manual' : trim((string) ($profile['provider'] ?? 'external'));
        if ($provider === '' || $provider === 'disabled' || ($mode === FiscalIssuanceMode::EXTERNAL && $provider === 'manual')) {
            $provider = $mode === FiscalIssuanceMode::MANUAL ? 'manual' : 'external';
        }
        $environment = (string) ($profile['environment'] ?? 'production');
        $key = 'nfe-sale:' . (int) $context['seller_order_id'] . ':1';

        $this->pdo->prepare("INSERT INTO fiscal_documents(seller_order_id,seller_id,store_id,document_type,operation_type,issuance_mode,revision,provider,environment,idempotency_key,status,series,products_total,shipping_total,discount_total,grand_total) VALUES(?,?,?,'nfe','sale',?,1,?,?,?,'pending',?,?,?,?,?)")
            ->execute([
                $context['seller_order_id'],
                $context['seller_id'],
                $context['store_id'],
                $mode,
                $provider,
                $environment,
                $key,
                (int) ($profile['nfe_series'] ?? 1),
                $context['products_total'],
                $context['shipping_total'],
                $context['discount_total'],
                $this->grand($context),
            ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->event($id, 'created', 'pending', 'Acompanhamento fiscal criado a partir do pedido pago.', [
            'seller_order_id' => (int) $context['seller_order_id'],
            'mode' => $mode,
        ]);
        return $this->document($id);
    }

    /** @param array<int,array<string,mixed>> $items */
    private function replaceItems(int $documentId, array $items, string $issuerState, string $destinationState): void
    {
        $this->pdo->prepare('DELETE FROM fiscal_document_items WHERE fiscal_document_id=?')->execute([$documentId]);
        $sameState = $issuerState !== '' && $issuerState === $destinationState;
        $insert = $this->pdo->prepare('INSERT INTO fiscal_document_items(fiscal_document_id,order_item_id,product_id,product_variant_id,product_name,sku,quantity,unit_price,total,ncm,cest,origin,commercial_unit,tributary_unit,gtin,cfop_in_state,cfop_out_state,applied_cfop,icms_code,pis_code,cofins_code,ipi_code,ibs_cbs_cst,ibs_cbs_classification,tax_metadata) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

        foreach ($items as $item) {
            $meta = $item['tax_metadata'] ?? null;
            if (is_array($meta)) $meta = $this->json($meta);
            $insert->execute([
                $documentId, $item['order_item_id'], $item['product_id'], $item['product_variant_id'],
                $item['product_name'], $item['sku'], $item['quantity'], $item['unit_price'], $item['total'],
                $item['ncm'] ?: null, $item['cest'] ?: null, $item['origin'],
                $item['commercial_unit'] ?: null, $item['tributary_unit'] ?: null, $item['gtin'] ?: null,
                $item['cfop_in_state'] ?: null, $item['cfop_out_state'] ?: null,
                $item[$sameState ? 'cfop_in_state' : 'cfop_out_state'] ?: null,
                $item['icms_code'] ?: null, $item['pis_code'] ?: null, $item['cofins_code'] ?: null,
                $item['ipi_code'] ?: null, $item['ibs_cbs_cst'] ?: null, $item['ibs_cbs_classification'] ?: null,
                $meta,
            ]);
        }
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $profile @return array<string,mixed> */
    private function issuerSnapshot(array $context, array $profile): array
    {
        return [
            'seller_id' => (int) $context['seller_id'],
            'store_id' => (int) $context['store_id'],
            'legal_name' => $profile['legal_name'] ?? $context['legal_name'],
            'trade_name' => $profile['trade_name'] ?? $context['trade_name'],
            'document' => $profile['document'] ?? $context['document'],
            'state_registration' => $profile['state_registration'] ?? $context['state_registration'],
            'state_registration_indicator' => $profile['state_registration_indicator'] ?? 'contributor',
            'municipal_registration' => $profile['municipal_registration'] ?? null,
            'tax_regime' => $profile['tax_regime'] ?? null,
            'crt' => $profile['crt'] ?? null,
            'fiscal_email' => $profile['fiscal_email'] ?? null,
            'address' => [
                'postal_code' => $profile['postal_code'] ?? null,
                'street' => $profile['street'] ?? null,
                'number' => $profile['number'] ?? null,
                'complement' => $profile['complement'] ?? null,
                'neighborhood' => $profile['neighborhood'] ?? null,
                'city' => $profile['city'] ?? null,
                'city_ibge_code' => $profile['city_ibge_code'] ?? null,
                'state' => $profile['state'] ?? null,
            ],
        ];
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function recipientSnapshot(array $context): array
    {
        return [
            'user_id' => (int) $context['user_id'],
            'name' => $context['recipient_name'] ?: $context['customer_name'],
            'document' => $context['customer_document'],
            'email' => $context['customer_email'],
            'phone' => $context['customer_phone'],
            'address' => [
                'postal_code' => $context['destination_postal_code'],
                'street' => $context['destination_street'],
                'number' => $context['destination_number'],
                'complement' => $context['destination_complement'],
                'neighborhood' => $context['destination_neighborhood'],
                'city' => $context['destination_city'],
                'city_ibge_code' => $context['destination_city_ibge_code'],
                'state' => $context['destination_state'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function document(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fiscal_documents WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Documento fiscal não encontrado.');
        return $row;
    }

    /** @param array<string,mixed> $context */
    private function grand(array $context): string
    {
        return number_format(max(0, (float) $context['products_total'] + (float) $context['shipping_total'] - (float) $context['discount_total']), 2, '.', '');
    }

    /** @param array<string,mixed> $payload */
    private function event(int $documentId, string $type, string $status, ?string $message, array $payload = []): void
    {
        $encoded = $payload === [] ? null : $this->json($this->sanitized($payload));
        $hash = $encoded === null ? null : hash('sha256', $encoded);
        $this->pdo->prepare('INSERT INTO fiscal_events(fiscal_document_id,event_type,status,message,payload_sha256) VALUES(?,?,?,?,?)')
            ->execute([
                $documentId,
                mb_substr($type, 0, 60),
                mb_substr($status, 0, 30),
                $message === null ? null : mb_substr($message, 0, 1000),
                $hash,
            ]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function sanitized(array $payload): array
    {
        foreach (['xml','danfe','certificate','password','token','secret','api_key'] as $key) unset($payload[$key]);
        return $payload;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
