<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use Closure;
use JsonException;
use RuntimeException;

final class TinyApiClient
{
    private const BASE_URL = 'https://api.tiny.com.br/api2/';
    private const NO_RECORDS_ERROR = 20;

    /** @param Closure(string,array<string,string>):array<string,mixed>|null $transport */
    public function __construct(private readonly string $token, private readonly ?Closure $transport = null)
    {
        if (trim($this->token) === '') throw new RuntimeException('Token da API Tiny não informado.');
    }

    /** @return array<string,mixed> */
    public function account(): array
    {
        $ret = $this->post('info.php');
        return is_array($ret['conta'] ?? null) ? $ret['conta'] : [];
    }

    /** @return array<string,mixed>|null */
    public function findOrderByEcommerce(string $code): ?array
    {
        $ret = $this->post('pedidos.pesquisa.php', ['numeroEcommerce'=>$code], true);
        if ($ret === null) return null;
        $rows = $ret['pedidos'] ?? [];
        if (!is_array($rows)) return null;
        foreach ($rows as $row) {
            $order = is_array($row) && is_array($row['pedido'] ?? null) ? $row['pedido'] : $row;
            if (!is_array($order)) continue;
            $external = (string)($order['numero_ecommerce'] ?? $order['numero_pedido_ecommerce'] ?? '');
            if ($external === $code) return $order;
        }
        return null;
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    public function createOrder(array $order): array
    {
        $payload = json_encode(['pedido'=>$order], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ret = $this->post('pedido.incluir.php', ['pedido'=>$payload]);
        if ($ret === null) throw new RuntimeException('Tiny não retornou o pedido criado.');
        $rows = $ret['registros'] ?? [];
        if (!is_array($rows)) throw new RuntimeException('Tiny não retornou o pedido criado.');
        foreach ($rows as $row) {
            $record = is_array($row) && is_array($row['registro'] ?? null) ? $row['registro'] : $row;
            if (!is_array($record)) continue;
            if (mb_strtolower((string)($record['status'] ?? 'ok')) === 'erro') throw new RuntimeException($this->message($record));
            if ((int)($record['id'] ?? 0) > 0) return $record;
        }
        throw new RuntimeException('Tiny não retornou o identificador do pedido criado.');
    }

    public function approveOrder(int $orderId): void
    {
        $this->post('pedido.alterar.situacao', ['id'=>(string)$orderId,'situacao'=>'aprovado']);
    }

    /** @return array<string,mixed> */
    public function generateInvoice(int $orderId): array
    {
        $ret = $this->post('gerar.nota.fiscal.pedido.php', ['id'=>(string)$orderId,'modelo'=>'NFe']);
        if ($ret === null) throw new RuntimeException('Tiny não retornou a NF-e gerada para o pedido.');
        $rows = $ret['registros'] ?? [];
        if (!is_array($rows)) throw new RuntimeException('Tiny não retornou a NF-e gerada para o pedido.');
        foreach ($rows as $row) {
            $record = is_array($row) && is_array($row['registro'] ?? null) ? $row['registro'] : $row;
            if (is_array($record) && (int)($record['idNotaFiscal'] ?? 0) > 0) return $record;
        }
        throw new RuntimeException('Tiny não retornou o identificador da NF-e gerada.');
    }

    /** @return array<string,mixed> */
    public function emitInvoice(int $invoiceId, bool $sendEmail = false): array
    {
        $ret = $this->post('nota.fiscal.emitir.php', ['id'=>(string)$invoiceId,'enviarEmail'=>$sendEmail ? 'S' : 'N']);
        if ($ret === null || !is_array($ret['nota_fiscal'] ?? null)) throw new RuntimeException('Tiny não retornou os dados da emissão da NF-e.');
        return $ret['nota_fiscal'];
    }

    /** @return array<string,mixed> */
    public function getInvoice(int $invoiceId): array
    {
        $ret = $this->post('nota.fiscal.obter.php', ['id'=>(string)$invoiceId]);
        if ($ret === null || !is_array($ret['nota_fiscal'] ?? null)) throw new RuntimeException('Tiny não retornou os dados da NF-e.');
        return $ret['nota_fiscal'];
    }

    /** @return array<string,mixed>|null */
    public function findInvoiceByEcommerce(string $code): ?array
    {
        $ret = $this->post('notas.fiscais.pesquisa.php', ['numeroEcommerce'=>$code], true);
        if ($ret === null) return null;
        $rows = $ret['notas_fiscais'] ?? [];
        if (!is_array($rows)) return null;
        foreach ($rows as $row) {
            $invoice = is_array($row) && is_array($row['nota_fiscal'] ?? null) ? $row['nota_fiscal'] : $row;
            if (!is_array($invoice)) continue;
            $external = (string)($invoice['numero_ecommerce'] ?? $invoice['numero_pedido_ecommerce'] ?? '');
            if ($external === '' || $external === $code) return $invoice;
        }
        return null;
    }

    public function getInvoiceLink(int $invoiceId): ?string
    {
        $ret = $this->post('nota.fiscal.obter.link.php', ['id'=>(string)$invoiceId]);
        if ($ret === null) return null;
        $url = trim((string)($ret['link_nfe'] ?? ''));
        return $url !== '' ? $url : null;
    }

    /** @param array<string,string> $params @return array<string,mixed>|null */
    private function post(string $path, array $params = [], bool $allowNoRecords = false): ?array
    {
        $params = ['token'=>$this->token,'formato'=>'JSON'] + $params;
        $decoded = $this->transport !== null
            ? ($this->transport)(self::BASE_URL . $path, $params)
            : $this->curl(self::BASE_URL . $path, $params);
        $ret = is_array($decoded['retorno'] ?? null) ? $decoded['retorno'] : $decoded;
        if (!is_array($ret)) throw new RuntimeException('Resposta inválida da API Tiny.');
        if (mb_strtolower((string)($ret['status'] ?? 'erro')) !== 'ok') {
            if ($allowNoRecords && (int)($ret['codigo_erro'] ?? 0) === self::NO_RECORDS_ERROR) return null;
            throw new RuntimeException($this->message($ret));
        }
        return $ret;
    }

    /** @param array<string,string> $params @return array<string,mixed> */
    private function curl(string $url, array $params): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Extensão cURL indisponível para integração com Tiny.');
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Não foi possível iniciar a integração com Tiny.');
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>7,
            CURLOPT_TIMEOUT=>30,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json','User-Agent: Tuffer-Tiny-Connector/1.0'],
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $error !== '') throw new RuntimeException('Falha de rede ao acessar Tiny: ' . mb_substr($error ?: 'resposta vazia', 0, 500));
        if ($status < 200 || $status >= 300) throw new RuntimeException('Tiny respondeu HTTP ' . $status . '.');
        try {
            $decoded = json_decode((string)$body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Tiny retornou JSON inválido.', 0, $e);
        }
        if (!is_array($decoded)) throw new RuntimeException('Resposta inválida da API Tiny.');
        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function message(array $payload): string
    {
        $messages = [];
        foreach (($payload['erros'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['erro'])) $messages[] = trim((string)$entry['erro']);
            elseif (is_string($entry)) $messages[] = trim($entry);
        }
        if ($messages === [] && isset($payload['erro'])) $messages[] = trim((string)$payload['erro']);
        $code = trim((string)($payload['codigo_erro'] ?? ''));
        $message = $messages !== [] ? implode(' | ', array_filter($messages)) : 'Tiny recusou a operação.';
        return $code !== '' ? 'Tiny [' . $code . ']: ' . $message : $message;
    }
}
