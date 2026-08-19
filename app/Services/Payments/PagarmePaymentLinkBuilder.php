<?php

declare(strict_types=1);

namespace App\Services\Payments;

use InvalidArgumentException;

final class PagarmePaymentLinkBuilder
{
    /**
     * @param array<int,array<string,mixed>> $groups
     * @param array<int,array<string,mixed>> $shippingSelections
     * @param array<string,mixed> $customer
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    public function build(
        string $orderCode,
        int $grandTotalCents,
        array $groups,
        array $shippingSelections,
        array $customer,
        array $address,
        string $method,
        string $successUrl
    ): array {
        $gatewayMethod = match ($method) {
            'pix' => 'pix',
            'card' => 'credit_card',
            'boleto' => 'boleto',
            default => throw new InvalidArgumentException('Forma de pagamento inválida.'),
        };

        $items = [];
        $itemsTotal = 0;
        foreach ($groups as $group) {
            $storeId = (int) $group['store_id'];
            $amount = $this->cents($group['subtotal'] ?? 0)
                - $this->cents($group['discount'] ?? 0)
                + $this->cents($shippingSelections[$storeId]['price'] ?? 0);
            if ($amount < 1) {
                continue;
            }
            $items[] = [
                'name' => mb_substr('Pedido ' . $orderCode . ' - ' . (string) $group['store_name'], 0, 128),
                'amount' => $amount,
                'default_quantity' => 1,
            ];
            $itemsTotal += $amount;
        }

        if ($items === [] || $itemsTotal !== $grandTotalCents) {
            throw new InvalidArgumentException('Os valores do pedido não fecham com o total do pagamento.');
        }

        $paymentSettings = [
            'accepted_payment_methods' => [$gatewayMethod],
            'statement_descriptor' => $this->statementDescriptor(),
        ];
        if ($gatewayMethod === 'credit_card') {
            $paymentSettings['credit_card_settings'] = [
                'operation_type' => 'auth_and_capture',
                'installments' => array_map(
                    static fn(int $number): array => ['number' => $number, 'total' => $grandTotalCents],
                    range(1, 6)
                ),
            ];
        } elseif ($gatewayMethod === 'pix') {
            $paymentSettings['pix_settings'] = ['expires_in' => 3600];
        } else {
            $paymentSettings['boleto_settings'] = [
                'due_in' => 2,
                'instructions' => 'Não receber após o vencimento.',
            ];
        }

        $payload = [
            'name' => mb_substr('Pedido ' . $orderCode, 0, 64),
            'order_code' => mb_substr($orderCode, 0, 52),
            'type' => 'order',
            'is_building' => false,
            'expires_in' => 1440,
            'max_sessions' => 1,
            'max_paid_sessions' => 1,
            'payment_settings' => $paymentSettings,
            'cart_settings' => ['items' => $items],
            'flow_settings' => ['success_url' => $successUrl],
            'customer_settings' => [
                'customer' => $this->customer($customer, $address, $orderCode),
            ],
        ];

        return $payload;
    }

    /** @param array<string,mixed> $customer @param array<string,mixed> $address @return array<string,mixed> */
    private function customer(array $customer, array $address, string $orderCode): array
    {
        $result = [
            'name' => mb_substr(trim((string) ($customer['name'] ?? 'Cliente')), 0, 64),
            'email' => mb_substr(trim((string) ($customer['email'] ?? '')), 0, 64),
            'code' => mb_substr('customer-' . (string) ($customer['id'] ?? $orderCode), 0, 52),
            'address' => [
                'country' => 'BR',
                'state' => mb_strtoupper(mb_substr((string) ($address['state'] ?? ''), 0, 2)),
                'city' => mb_substr((string) ($address['city'] ?? ''), 0, 64),
                'zip_code' => preg_replace('/\D+/', '', (string) ($address['postal_code'] ?? '')),
                'line_1' => mb_substr(implode(', ', array_filter([
                    trim((string) ($address['number'] ?? '')),
                    trim((string) ($address['street'] ?? '')),
                    trim((string) ($address['neighborhood'] ?? '')),
                ])), 0, 256),
                'line_2' => mb_substr(trim((string) ($address['complement'] ?? '')), 0, 128),
            ],
        ];

        $document = preg_replace('/\D+/', '', (string) ($customer['document'] ?? '')) ?? '';
        if (in_array(strlen($document), [11, 14], true)) {
            $result['document'] = $document;
            $result['document_type'] = strlen($document) === 11 ? 'CPF' : 'CNPJ';
        }

        return $result;
    }

    private function statementDescriptor(): string
    {
        $name = (string) ($_ENV['PAGARME_STATEMENT_DESCRIPTOR'] ?? 'TUFFER');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $normalized = preg_replace('/[^A-Za-z0-9 ]+/', '', is_string($ascii) ? $ascii : $name) ?? 'TUFFER';
        return mb_substr(mb_strtoupper(trim($normalized) ?: 'TUFFER'), 0, 13);
    }

    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
