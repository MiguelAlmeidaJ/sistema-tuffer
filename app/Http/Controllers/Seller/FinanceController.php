<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Finance\SellerWalletService;
use App\Services\Stores\SellerStoreContext;
use Throwable;

final class FinanceController extends Controller
{
    private const SALE_STATUSES = ['paid', 'processing', 'shipped', 'delivered'];

    public function index(): string
    {
        $store = (new SellerStoreContext())->current();
        $pdo = Database::connection();
        $placeholders = implode(',', array_fill(0, count(self::SALE_STATUSES), '?'));

        $summary = $pdo->prepare(
            "SELECT
                COALESCE(SUM(products_total+shipping_total-discount_total),0) gross,
                COALESCE(SUM(commission_total),0) commissions,
                COALESCE(SUM(seller_net_total),0) net,
                COUNT(*) orders
             FROM seller_orders
             WHERE store_id=?
               AND status IN ({$placeholders})"
        );
        $summary->execute(array_merge([(int) $store['id']], self::SALE_STATUSES));

        $orders = $pdo->prepare(
            'SELECT code,products_total,shipping_total,discount_total,commission_total,seller_net_total,status,created_at
             FROM seller_orders
             WHERE store_id=?
             ORDER BY created_at DESC
             LIMIT 50'
        );
        $orders->execute([$store['id']]);

        $seller = $this->sellerForStore((int) $store['seller_id']);
        $wallet = null;
        $walletWarning = null;
        if ($seller && (int) ($seller['is_official_store'] ?? 0) !== 1) {
            try {
                $wallet = (new SellerWalletService())->snapshot((int) $seller['id']);
            } catch (Throwable $exception) {
                $walletWarning = 'Não foi possível atualizar o saldo da carteira agora. Tente novamente em instantes.';
            }
        }

        return $this->page('seller/finance/index', 'layouts/seller', [
            'pageTitle' => 'Financeiro da loja',
            'summary' => $summary->fetch(),
            'orders' => $orders->fetchAll(),
            'currentStore' => $store,
            'seller' => $seller,
            'wallet' => $wallet,
            'walletWarning' => $walletWarning,
            'canWithdraw' => (Auth::user()['type'] ?? null) === 'seller',
        ]);
    }

    public function withdraw(): string
    {
        $store = (new SellerStoreContext())->current();
        $seller = $this->sellerForStore((int) $store['seller_id']);
        if (!$seller || (int) ($seller['is_official_store'] ?? 0) === 1) {
            Session::flash('error', 'A carteira da loja oficial é administrada pela conta global da Tuffer.');
            return Response::redirect('/vendedor/financeiro?aba=carteira');
        }

        $raw = trim((string) ($_POST['amount'] ?? '0'));
        $normalized = str_contains($raw, ',')
            ? str_replace(['.', ','], ['', '.'], $raw)
            : $raw;
        $amountCents = (int) round(((float) $normalized) * 100);

        try {
            $transfer = (new SellerWalletService())->withdraw((int) $seller['id'], $amountCents);
            $status = (string) ($transfer['status'] ?? 'processing');
            Session::flash('success', 'Transferência solicitada à Pagar.me. Status: ' . $status . '.');
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return Response::redirect('/vendedor/financeiro?aba=carteira');
    }

    /** @return array<string,mixed>|null */
    private function sellerForStore(int $sellerId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id,trade_name,is_official_store,payment_enabled,pagarme_recipient_id
             FROM sellers WHERE id=? LIMIT 1'
        );
        $statement->execute([$sellerId]);
        $seller = $statement->fetch();
        return is_array($seller) ? $seller : null;
    }
}
