<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Core\Database;
use App\Services\Payments\MarketplaceFinancialPolicy;
use App\Services\Finance\MarketplaceFinancialLedgerService;
use App\Services\Finance\FinancialSplitConsolidator;
use App\Services\Settings\PlatformSettings;
use App\Services\Payments\Pagarme\DTO\SplitRuleData;
use PDO;
use RuntimeException;

final class PagarmeSplitService
{
    private readonly PDO $pdo;
    private readonly MarketplaceFinancialPolicy $policy;
    private readonly PagarmeRecipientService $recipientService;

    public function __construct(
        ?PDO $database = null,
        ?MarketplaceFinancialPolicy $policy = null,
        ?PagarmeRecipientService $recipientService = null
    ) {
        $this->pdo = $database ?? Database::connection();
        $this->policy = $policy ?? new MarketplaceFinancialPolicy();
        $this->recipientService = $recipientService ?? new PagarmeRecipientService(null, $this->pdo);
    }

    /** @return array<int,array<string,mixed>> */
    public function createSnapshot(int $paymentId): array
    {
        $existing = $this->snapshotRows($paymentId);
        if ($existing !== []) {
            $this->rulesFromRows($existing, $this->paymentAmount($paymentId));
            return $existing;
        }

        $payment = $this->payment($paymentId);
        $statement = $this->pdo->prepare(
            "SELECT so.id seller_order_id,so.seller_id,so.products_total,so.shipping_total,
                    so.discount_total,so.commission_rate,so.commission_total,so.seller_net_total,
                    s.pagarme_recipient_id,s.is_official_store,
                    COALESCE(SUM(CASE WHEN oc.funding_source='seller' THEN oc.discount_amount_cents ELSE 0 END),0) seller_discount_cents,
                    COALESCE(SUM(CASE WHEN oc.funding_source='platform' THEN oc.discount_amount_cents ELSE 0 END),0) platform_discount_cents
             FROM seller_orders so
             JOIN sellers s ON s.id=so.seller_id
             LEFT JOIN order_coupons oc ON oc.seller_order_id=so.id
             WHERE so.order_id=?
             GROUP BY so.id,so.seller_id,so.products_total,so.shipping_total,so.discount_total,
                      so.commission_rate,so.commission_total,so.seller_net_total,s.pagarme_recipient_id,s.is_official_store
             ORDER BY so.seller_id,so.id"
        );
        $statement->execute([$payment['order_id']]);
        $sellerOrders = $statement->fetchAll();
        if ($sellerOrders === []) {
            throw new RuntimeException('O pedido não possui vendedores para criar o split.');
        }

        $this->createDetailedSnapshot($paymentId, $sellerOrders);

        $participants = [];
        foreach ($sellerOrders as $sellerOrder) {
            $sellerId = (int) $sellerOrder['seller_id'];
            $recipientId = (int) ($sellerOrder['is_official_store'] ?? 0) === 1
                ? trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''))
                : trim((string) $sellerOrder['pagarme_recipient_id']);
            if (!PagarmeRecipientId::isValid($recipientId)) {
                throw new RuntimeException('Um vendedor não possui recebedor Pagar.me válido.');
            }
            $key = 'seller:' . $sellerId;
            $participants[$key] ??= [
                'participant_key' => $key,
                'participant_type' => 'seller',
                'seller_id' => $sellerId,
                'recipient_id' => $recipientId,
                'products_amount_cents' => 0,
                'shipping_amount_cents' => 0,
                'seller_discount_cents' => 0,
                'platform_discount_cents' => 0,
                'commission_amount_cents' => 0,
                'split_amount_cents' => 0,
            ];
            if ($participants[$key]['recipient_id'] !== $recipientId) {
                throw new RuntimeException('O recebedor do vendedor diverge entre as lojas do pedido.');
            }
            $participants[$key]['products_amount_cents'] += $this->decimalCents($sellerOrder['products_total']);
            $participants[$key]['shipping_amount_cents'] += $this->decimalCents($sellerOrder['shipping_total']);
            $participants[$key]['seller_discount_cents'] += (int) $sellerOrder['seller_discount_cents'];
            $participants[$key]['platform_discount_cents'] += (int) $sellerOrder['platform_discount_cents'];
            $participants[$key]['commission_amount_cents'] += $this->decimalCents($sellerOrder['commission_total']);
            $participants[$key]['split_amount_cents'] += $this->decimalCents($sellerOrder['seller_net_total']);
        }

        $sellerTotal = array_sum(array_column($participants, 'split_amount_cents'));
        $platformAmount = (int) $payment['amount_cents'] - $sellerTotal;
        $this->policy->assertPlatformAmount($platformAmount);
        $platformRecipient = trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''));
        if (!PagarmeRecipientId::isValid($platformRecipient)) {
            throw new RuntimeException('Configure PAGARME_PLATFORM_RECIPIENT_ID com o recebedor da Tuffer.');
        }

        $participants['platform'] = [
            'participant_key' => 'platform',
            'participant_type' => 'platform',
            'seller_id' => null,
            'recipient_id' => $platformRecipient,
            'products_amount_cents' => 0,
            'shipping_amount_cents' => 0,
            'seller_discount_cents' => 0,
            'platform_discount_cents' => array_sum(array_column($participants, 'platform_discount_cents')),
            'commission_amount_cents' => array_sum(array_column($participants, 'commission_amount_cents')),
            'split_amount_cents' => $platformAmount,
        ];
        $participants = $this->consolidateParticipantsByRecipient($participants, $platformRecipient);

        $insert = $this->pdo->prepare(
            'INSERT INTO payment_split_snapshots(
                payment_id,participant_key,participant_type,seller_id,recipient_id,
                products_amount_cents,shipping_amount_cents,seller_discount_cents,
                platform_discount_cents,commission_amount_cents,split_amount_cents,
                liable,charge_processing_fee,charge_remainder_fee,policy_version
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($participants as $participant) {
            $options = $participant['participant_type'] === 'platform'
                ? $this->policy->platformOptions()
                : $this->policy->sellerOptions();
            $insert->execute([
                $paymentId,
                $participant['participant_key'],
                $participant['participant_type'],
                $participant['seller_id'],
                $participant['recipient_id'],
                $participant['products_amount_cents'],
                $participant['shipping_amount_cents'],
                $participant['seller_discount_cents'],
                $participant['platform_discount_cents'],
                $participant['commission_amount_cents'],
                $participant['split_amount_cents'],
                $options['liable'] ? 1 : 0,
                $options['charge_processing_fee'] ? 1 : 0,
                $options['charge_remainder_fee'] ? 1 : 0,
                MarketplaceFinancialPolicy::VERSION,
            ]);
        }

        $rows = $this->snapshotRows($paymentId);
        $this->rulesFromRows($rows, (int) $payment['amount_cents']);
        return $rows;
    }

    /** @return array<int,SplitRuleData> */
    public function rulesForPayment(int $paymentId): array
    {
        return (new FinancialSplitConsolidator($this->pdo, $this->policy))->forPayment($paymentId);
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,SplitRuleData> */
    public function rulesFromRows(array $rows, int $paymentAmountCents): array
    {
        if ($rows === []) {
            throw new RuntimeException('O snapshot financeiro do pagamento não foi criado.');
        }
        $rules = [];
        $seen = [];
        $platforms = 0;
        foreach ($rows as $row) {
            $key = (string) ($row['participant_key'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                throw new RuntimeException('O snapshot possui participantes duplicados.');
            }
            $seen[$key] = true;
            $platforms += ($row['participant_type'] ?? null) === 'platform' ? 1 : 0;
            $rules[] = new SplitRuleData(
                (int) ($row['split_amount_cents'] ?? -1),
                (string) ($row['recipient_id'] ?? ''),
                [
                    'liable' => (bool) ($row['liable'] ?? false),
                    'charge_processing_fee' => (bool) ($row['charge_processing_fee'] ?? false),
                    'charge_remainder_fee' => (bool) ($row['charge_remainder_fee'] ?? false),
                ]
            );
        }
        if ($platforms !== 1 || array_sum(array_map(static fn(SplitRuleData $rule): int => $rule->amount, $rules)) !== $paymentAmountCents) {
            throw new RuntimeException('A soma do split não corresponde ao valor da cobrança.');
        }
        return $rules;
    }

    public function revalidateRecipients(int $paymentId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT seller_id,is_official_store,recipient_id
             FROM payment_financial_snapshot_lines WHERE payment_id=? ORDER BY id'
        );
        $statement->execute([$paymentId]);
        $rows = $statement->fetchAll();
        foreach ($rows as $row) {
            if ((int) ($row['is_official_store'] ?? 0) === 1) {
                $account = (new PagarmePlatformAccountService(null, $this->pdo))->synchronize();
                if (!PagarmeRecipientEligibility::isEligible(
                        (string) ($account['recipient_status'] ?? ''),
                        isset($account['kyc_status']) ? (string) $account['kyc_status'] : null
                    )
                    || !hash_equals((string) $row['recipient_id'], (string) ($account['recipient_id'] ?? ''))) {
                    throw new RuntimeException('O recebedor da plataforma deixou de estar elegível.');
                }
                continue;
            }
            $sellerId = (int) ($row['seller_id'] ?? 0);
            $account = $this->recipientService->synchronizeStatus($sellerId);
            $this->assertRecipientEligible($row, $account);
        }
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $account */
    public function assertRecipientEligible(array $snapshot, array $account): void
    {
        if (!PagarmeRecipientEligibility::isEligible(
                (string) ($account['recipient_status'] ?? ''),
                isset($account['kyc_status']) ? (string) $account['kyc_status'] : null
            )
            || !hash_equals((string) ($snapshot['recipient_id'] ?? ''), (string) ($account['recipient_id'] ?? ''))) {
            throw new RuntimeException('Um vendedor deixou de estar habilitado para receber pagamentos.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function snapshotRows(int $paymentId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM payment_split_snapshots WHERE payment_id=?
             ORDER BY participant_type='platform',seller_id,id"
        );
        $statement->execute([$paymentId]);
        return $statement->fetchAll();
    }

    /** @return array<string,mixed> */
    private function payment(int $paymentId): array
    {
        $statement = $this->pdo->prepare('SELECT id,order_id,amount_cents FROM payments WHERE id=?');
        $statement->execute([$paymentId]);
        $payment = $statement->fetch();
        if (!is_array($payment)) {
            throw new RuntimeException('Pagamento não encontrado para criar o split.');
        }
        return $payment;
    }

    private function paymentAmount(int $paymentId): int
    {
        return (int) $this->payment($paymentId)['amount_cents'];
    }

    private function decimalCents(mixed $value): int
    {
        $normalized = number_format((float) $value, 2, '.', '');
        [$whole, $fraction] = explode('.', $normalized);
        return ((int) $whole * 100) + (int) $fraction;
    }

    /** @param array<string,array<string,mixed>> $participants @return array<string,array<string,mixed>> */
    private function consolidateParticipantsByRecipient(array $participants, string $platformRecipient): array
    {
        $consolidated = [];
        foreach ($participants as $participant) {
            $recipientId = (string) $participant['recipient_id'];
            $key = 'recipient:' . $recipientId;
            if (!isset($consolidated[$key])) {
                $participant['participant_key'] = $key;
                $participant['participant_type'] = hash_equals($platformRecipient, $recipientId) ? 'platform' : 'seller';
                $participant['seller_id'] = $participant['participant_type'] === 'platform' ? null : $participant['seller_id'];
                $consolidated[$key] = $participant;
                continue;
            }
            foreach ([
                'products_amount_cents','shipping_amount_cents','seller_discount_cents',
                'platform_discount_cents','commission_amount_cents','split_amount_cents',
            ] as $column) {
                $consolidated[$key][$column] += (int) $participant[$column];
            }
            if (hash_equals($platformRecipient, $recipientId)) {
                $consolidated[$key]['participant_type'] = 'platform';
                $consolidated[$key]['seller_id'] = null;
            }
        }
        return $consolidated;
    }

    /** @param array<int,array<string,mixed>> $sellerOrders */
    private function createDetailedSnapshot(int $paymentId, array $sellerOrders): void
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM payment_financial_snapshot_lines WHERE payment_id=? LIMIT 1');
        $exists->execute([$paymentId]);
        if ($exists->fetchColumn()) {
            return;
        }

        $liabilityRules = json_encode([
            'seller' => $this->policy->sellerOptions(),
            'platform' => $this->policy->platformOptions(),
            'processing_fee_recipient' => 'platform',
            'remainder_fee_recipient' => 'platform',
            'shipping_recipient' => $this->policy->shippingRecipient(),
            'partial_refund_enabled' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $insertLine = $this->pdo->prepare(
            'INSERT INTO payment_financial_snapshot_lines(
                payment_id,seller_order_id,seller_id,seller_type,is_official_store,recipient_id,products_amount_cents,
                seller_discount_cents,platform_discount_cents,shipping_amount_cents,
                shipping_recipient,gross_revenue_cents,commission_rate_basis_points,commission_amount_cents,
                fixed_fee_cents,expected_provider_fee_cents,tax_provision_cents,product_cost_cents,
                product_cost_known,reserve_amount_cents,seller_net_amount_cents,platform_contribution_cents,
                transferable_amount_cents,policy_version,policy_applied_at,liability_rules
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $itemQuery = $this->pdo->prepare(
            'SELECT oi.id order_item_id,oi.product_id,oi.product_variant_id,oi.quantity,
                    oi.unit_price,oi.total,pv.cost_price
             FROM order_items oi
             JOIN product_variants pv ON pv.id=oi.product_variant_id
             WHERE oi.seller_order_id=? ORDER BY oi.id'
        );
        $insertItem = $this->pdo->prepare(
            'INSERT INTO payment_financial_snapshot_items(
                payment_id,financial_snapshot_line_id,order_item_id,product_id,product_variant_id,
                quantity,unit_revenue_cents,total_revenue_cents,unit_cost_cents,total_cost_cents,
                cost_known,cost_effective_at
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $couponQuery = $this->pdo->prepare(
            'SELECT oc.id order_coupon_id,oc.coupon_id,c.code,oc.funding_source,oc.discount_amount_cents
             FROM order_coupons oc
             JOIN coupons c ON c.id=oc.coupon_id
             WHERE oc.seller_order_id=? ORDER BY oc.id'
        );
        $insertCoupon = $this->pdo->prepare(
            'INSERT INTO payment_financial_snapshot_coupons(
                payment_id,financial_snapshot_line_id,order_coupon_id,coupon_id,coupon_code,
                funding_source,discount_amount_cents
             ) VALUES(?,?,?,?,?,?,?)'
        );

        foreach ($sellerOrders as $sellerOrder) {
            $products = $this->decimalCents($sellerOrder['products_total']);
            $shipping = $this->decimalCents($sellerOrder['shipping_total']);
            $sellerDiscount = (int) $sellerOrder['seller_discount_cents'];
            $platformDiscount = (int) $sellerOrder['platform_discount_cents'];
            $commission = $this->decimalCents($sellerOrder['commission_total']);
            $sellerNet = $this->decimalCents($sellerOrder['seller_net_total']);
            $shippingRecipient = $this->policy->shippingRecipient();
            $expectedSellerNet = $products
                + ($shippingRecipient === 'seller' ? $shipping : 0)
                - $sellerDiscount
                - $commission;
            if ($sellerNet !== $expectedSellerNet) {
                throw new RuntimeException('O snapshot financeiro diverge dos valores persistidos no pedido.');
            }
            $platformContribution = $commission
                - $platformDiscount
                + ($shippingRecipient === 'platform' ? $shipping : 0);
            $this->policy->assertPlatformAmount($commission - $platformDiscount);
            $official = (int) ($sellerOrder['is_official_store'] ?? 0) === 1;
            $recipientId = $official
                ? trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''))
                : (string) $sellerOrder['pagarme_recipient_id'];
            $itemQuery->execute([(int) $sellerOrder['seller_order_id']]);
            $items = $itemQuery->fetchAll();
            $costKnown = $items !== [] && array_reduce(
                $items,
                static fn(bool $known, array $item): bool => $known && $item['cost_price'] !== null,
                true
            );
            $productCost = $costKnown ? array_sum(array_map(
                fn(array $item): int => $this->decimalCents($item['cost_price']) * (int) $item['quantity'],
                $items
            )) : null;
            $financialSettings = PlatformSettings::all();
            $taxRate = max(0.0, min(100.0, (float) (
                $financialSettings['official_store_tax_provision_percentage']
                ?? $_ENV['OFFICIAL_STORE_TAX_PROVISION_PERCENTAGE']
                ?? 0
            )));
            $taxProvision = $official ? (int) round($sellerNet * $taxRate / 100) : 0;
            $reserveRate = max(0.0, min(100.0, (float) (
                $financialSettings['official_store_reserve_percentage']
                ?? $_ENV['OFFICIAL_STORE_RESERVE_PERCENTAGE']
                ?? 5
            )));
            $reserve = $official ? (int) round($sellerNet * $reserveRate / 100) : 0;
            $expectedProviderFee = $official
                ? max(0, (int) ($_ENV['PAGARME_EXPECTED_FEE_CENTS'] ?? 0))
                : 0;
            $transferable = $official && $costKnown
                ? max(0, $sellerNet - (int) $productCost - $taxProvision - $reserve - $expectedProviderFee)
                : null;

            $insertLine->execute([
                $paymentId,
                (int) $sellerOrder['seller_order_id'],
                (int) $sellerOrder['seller_id'],
                $official ? 'official_store' : 'external',
                $official ? 1 : 0,
                $recipientId,
                $products,
                $sellerDiscount,
                $platformDiscount,
                $shipping,
                $shippingRecipient,
                $products + $shipping,
                $this->policy->commissionBasisPoints((string) $sellerOrder['commission_rate']),
                $commission,
                0,
                $expectedProviderFee,
                $taxProvision,
                $productCost,
                $costKnown ? 1 : 0,
                $reserve,
                $sellerNet,
                $platformContribution,
                $transferable,
                trim((string) (
                    $financialSettings['marketplace_financial_policy_version']
                    ?? $_ENV['MARKETPLACE_FINANCIAL_POLICY_VERSION']
                    ?? MarketplaceFinancialLedgerService::POLICY_VERSION
                )),
                date('Y-m-d H:i:s'),
                $liabilityRules,
            ]);
            $lineId = (int) $this->pdo->lastInsertId();

            foreach ($items as $item) {
                $unitCost = $item['cost_price'] === null ? null : $this->decimalCents($item['cost_price']);
                $insertItem->execute([
                    $paymentId,
                    $lineId,
                    (int) $item['order_item_id'],
                    (int) $item['product_id'],
                    (int) $item['product_variant_id'],
                    (int) $item['quantity'],
                    $this->decimalCents($item['unit_price']),
                    $this->decimalCents($item['total']),
                    $unitCost,
                    $unitCost === null ? null : $unitCost * (int) $item['quantity'],
                    $unitCost === null ? 0 : 1,
                    $unitCost === null ? null : date('Y-m-d H:i:s'),
                ]);
            }

            $couponQuery->execute([(int) $sellerOrder['seller_order_id']]);
            foreach ($couponQuery->fetchAll() as $coupon) {
                $insertCoupon->execute([
                    $paymentId,
                    $lineId,
                    (int) $coupon['order_coupon_id'],
                    (int) $coupon['coupon_id'],
                    mb_substr((string) $coupon['code'], 0, 100),
                    ($coupon['funding_source'] ?? null) === 'platform' ? 'platform' : 'seller',
                    (int) $coupon['discount_amount_cents'],
                ]);
            }
        }
    }
}
