<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use PDO;
use RuntimeException;

final class FiscalSellerOrderPayloadService
{
    public function __construct(private readonly ?PDO $database = null) {}

    /** @return array<string,mixed> */
    public function payload(int $sellerOrderId): array
    {
        $pdo = $this->database ?? Database::connection();
        $stmt = $pdo->prepare("SELECT so.id seller_order_id,so.code seller_order_code,so.status seller_order_status,so.products_total,so.shipping_total,so.discount_total,so.seller_net_total,so.created_at seller_order_created_at,
            o.code order_code,o.status order_status,o.user_id,o.created_at order_created_at,
            st.name store_name,s.legal_name,s.trade_name,s.document seller_document,s.state_registration seller_state_registration,
            sfp.tax_regime,sfp.crt,sfp.state_registration_indicator,sfp.municipal_registration,sfp.fiscal_email,sfp.postal_code issuer_postal_code,sfp.street issuer_street,sfp.number issuer_number,sfp.complement issuer_complement,sfp.neighborhood issuer_neighborhood,sfp.city issuer_city,sfp.city_ibge_code issuer_city_ibge_code,sfp.state issuer_state,sfp.nfe_series,sfp.environment,
            u.name customer_name,u.email customer_email,u.phone customer_phone,u.document customer_document,
            oa.recipient_name,oa.postal_code destination_postal_code,oa.street destination_street,oa.number destination_number,oa.complement destination_complement,oa.neighborhood destination_neighborhood,oa.city destination_city,oa.city_ibge_code destination_city_ibge_code,oa.state destination_state
            FROM seller_orders so
            JOIN orders o ON o.id=so.order_id
            JOIN stores st ON st.id=so.store_id
            JOIN sellers s ON s.id=so.seller_id
            LEFT JOIN store_fiscal_profiles sfp ON sfp.store_id=so.store_id AND sfp.seller_id=so.seller_id
            JOIN users u ON u.id=o.user_id
            LEFT JOIN order_addresses oa ON oa.order_id=o.id
            WHERE so.id=? LIMIT 1");
        $stmt->execute([$sellerOrderId]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('Pedido da loja não encontrado para integração fiscal.');

        $stmt = $pdo->prepare("SELECT oi.product_id,oi.product_variant_id,oi.product_name,oi.sku,oi.quantity,oi.unit_price,oi.total,
            COALESCE(pfp.gtin,pv.barcode) gtin,pfp.ncm,pfp.cest,pfp.origin,pfp.commercial_unit,pfp.tributary_unit,pfp.cfop_in_state,pfp.cfop_out_state,pfp.icms_code,pfp.pis_code,pfp.cofins_code,pfp.ipi_code,pfp.ibs_cbs_cst,pfp.ibs_cbs_classification,pfp.tax_metadata
            FROM order_items oi
            LEFT JOIN product_variants pv ON pv.id=oi.product_variant_id
            LEFT JOIN product_fiscal_profiles pfp ON pfp.product_id=oi.product_id
            WHERE oi.seller_order_id=? ORDER BY oi.id");
        $stmt->execute([$sellerOrderId]);
        $items = [];
        foreach ($stmt->fetchAll() as $item) {
            $metadata = null;
            if (is_string($item['tax_metadata'] ?? null) && trim((string)$item['tax_metadata']) !== '') {
                $decoded = json_decode((string)$item['tax_metadata'], true);
                $metadata = is_array($decoded) ? $decoded : null;
            }
            $items[] = [
                'product_id'=>(int)$item['product_id'],
                'variant_id'=>$item['product_variant_id'] !== null ? (int)$item['product_variant_id'] : null,
                'name'=>(string)$item['product_name'],
                'sku'=>$item['sku'] ?? null,
                'quantity'=>(int)$item['quantity'],
                'unit_price'=>(string)$item['unit_price'],
                'total'=>(string)$item['total'],
                'fiscal'=>[
                    'gtin'=>$item['gtin'] ?? null,
                    'ncm'=>$item['ncm'] ?? null,
                    'cest'=>$item['cest'] ?? null,
                    'origin'=>$item['origin'] !== null ? (int)$item['origin'] : null,
                    'commercial_unit'=>$item['commercial_unit'] ?? null,
                    'tributary_unit'=>$item['tributary_unit'] ?? null,
                    'cfop_in_state'=>$item['cfop_in_state'] ?? null,
                    'cfop_out_state'=>$item['cfop_out_state'] ?? null,
                    'icms_code'=>$item['icms_code'] ?? null,
                    'pis_code'=>$item['pis_code'] ?? null,
                    'cofins_code'=>$item['cofins_code'] ?? null,
                    'ipi_code'=>$item['ipi_code'] ?? null,
                    'ibs_cbs_cst'=>$item['ibs_cbs_cst'] ?? null,
                    'ibs_cbs_classification'=>$item['ibs_cbs_classification'] ?? null,
                    'metadata'=>$metadata,
                ],
            ];
        }

        $products = (float)$row['products_total'];
        $shipping = (float)$row['shipping_total'];
        $discount = (float)$row['discount_total'];

        return [
            'seller_order'=>[
                'code'=>(string)$row['seller_order_code'],
                'status'=>(string)$row['seller_order_status'],
                'created_at'=>$row['seller_order_created_at'] ?? null,
                'order_code'=>(string)$row['order_code'],
                'order_status'=>(string)$row['order_status'],
                'order_created_at'=>$row['order_created_at'] ?? null,
            ],
            'amounts'=>[
                'products_total'=>number_format($products,2,'.',''),
                'shipping_total'=>number_format($shipping,2,'.',''),
                'discount_total'=>number_format($discount,2,'.',''),
                'grand_total'=>number_format(max(0,$products+$shipping-$discount),2,'.',''),
                'seller_net_total'=>(string)$row['seller_net_total'],
                'currency'=>'BRL',
            ],
            'issuer'=>[
                'store_name'=>(string)$row['store_name'],
                'legal_name'=>(string)$row['legal_name'],
                'trade_name'=>$row['trade_name'] ?? null,
                'document'=>(string)$row['seller_document'],
                'state_registration'=>$row['seller_state_registration'] ?? null,
                'state_registration_indicator'=>$row['state_registration_indicator'] ?? null,
                'municipal_registration'=>$row['municipal_registration'] ?? null,
                'tax_regime'=>$row['tax_regime'] ?? null,
                'crt'=>$row['crt'] ?? null,
                'fiscal_email'=>$row['fiscal_email'] ?? null,
                'nfe_series'=>$row['nfe_series'] !== null ? (int)$row['nfe_series'] : null,
                'environment'=>$row['environment'] ?? null,
                'address'=>[
                    'postal_code'=>$row['issuer_postal_code'] ?? null,
                    'street'=>$row['issuer_street'] ?? null,
                    'number'=>$row['issuer_number'] ?? null,
                    'complement'=>$row['issuer_complement'] ?? null,
                    'neighborhood'=>$row['issuer_neighborhood'] ?? null,
                    'city'=>$row['issuer_city'] ?? null,
                    'city_ibge_code'=>$row['issuer_city_ibge_code'] ?? null,
                    'state'=>$row['issuer_state'] ?? null,
                    'country'=>'BR',
                ],
            ],
            'recipient'=>[
                'name'=>(string)($row['recipient_name'] ?: $row['customer_name']),
                'document'=>$row['customer_document'] ?? null,
                'email'=>$row['customer_email'] ?? null,
                'phone'=>$row['customer_phone'] ?? null,
                'address'=>[
                    'postal_code'=>$row['destination_postal_code'] ?? null,
                    'street'=>$row['destination_street'] ?? null,
                    'number'=>$row['destination_number'] ?? null,
                    'complement'=>$row['destination_complement'] ?? null,
                    'neighborhood'=>$row['destination_neighborhood'] ?? null,
                    'city'=>$row['destination_city'] ?? null,
                    'city_ibge_code'=>$row['destination_city_ibge_code'] ?? null,
                    'state'=>$row['destination_state'] ?? null,
                    'country'=>'BR',
                ],
            ],
            'items'=>$items,
        ];
    }
}
