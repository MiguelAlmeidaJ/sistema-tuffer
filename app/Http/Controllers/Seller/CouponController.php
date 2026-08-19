<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Stores\SellerStoreContext;
use RuntimeException;
use Throwable;

final class CouponController extends Controller
{
    public function index(): string
    {
        $store = (new SellerStoreContext())->current();
        $statement = Database::connection()->prepare('SELECT * FROM coupons WHERE store_id=? ORDER BY created_at DESC');
        $statement->execute([$store['id']]);
        $coupons = $statement->fetchAll();
        $stats = ['total' => count($coupons), 'active' => 0, 'uses' => 0, 'expiring' => 0];
        foreach ($coupons as $coupon) {
            $stats['active'] += $coupon['status'] === 'active' ? 1 : 0;
            $stats['uses'] += (int) $coupon['usage_count'];
            $expires = $coupon['expires_at'] ? strtotime((string) $coupon['expires_at']) : false;
            $stats['expiring'] += $expires && $expires >= time() && $expires <= strtotime('+7 days') ? 1 : 0;
        }
        return $this->page('seller/coupons/index', 'layouts/seller', ['pageTitle' => 'Cupons', 'coupons' => $coupons, 'stats' => $stats, 'currentStore' => $store]);
    }

    public function create(): string { return $this->form(); }

    public function store(): string
    {
        $store = (new SellerStoreContext())->current();
        try {
            $data = $this->validatedData();
            Database::connection()->prepare("INSERT INTO coupons(store_id,code,name,description,discount_type,discount_value,funding_source,minimum_total,usage_limit,starts_at,expires_at,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$store['id'], ...$data]);
            Session::flash('success', 'Cupom criado e pronto para uso.');
        } catch (Throwable) {
            Session::flash('error', 'Não foi possível criar. Revise datas, valor e código do cupom.');
            return Response::redirect('/vendedor/cupons/novo');
        }
        return Response::redirect('/vendedor/cupons');
    }

    public function edit(string $id): string
    {
        $store = (new SellerStoreContext())->current();
        $statement = Database::connection()->prepare('SELECT * FROM coupons WHERE id=? AND store_id=?');
        $statement->execute([(int) $id, $store['id']]);
        return $this->form($statement->fetch() ?: null);
    }

    public function update(string $id): string
    {
        $store = (new SellerStoreContext())->current();
        try {
            $data = $this->validatedData(false);
            Database::connection()->prepare('UPDATE coupons SET code=?,name=?,description=?,discount_type=?,discount_value=?,minimum_total=?,usage_limit=?,starts_at=?,expires_at=?,status=? WHERE id=? AND store_id=?')->execute([...$data, (int) $id, $store['id']]);
            Session::flash('success', 'Cupom atualizado.');
        } catch (Throwable) {
            Session::flash('error', 'Não foi possível atualizar o cupom.');
        }
        return Response::redirect('/vendedor/cupons/' . (int) $id . '/editar');
    }

    public function destroy(string $id): string
    {
        $store = (new SellerStoreContext())->current();
        Database::connection()->prepare('DELETE FROM coupons WHERE id=? AND store_id=?')->execute([(int) $id, $store['id']]);
        Session::flash('success', 'Cupom excluído.');
        return Response::redirect('/vendedor/cupons');
    }

    private function form(?array $coupon = null): string
    {
        if ($coupon === null && func_num_args() > 0) {
            http_response_code(404);
            return $this->page('errors/404', 'layouts/seller', ['pageTitle' => 'Cupom não encontrado', 'path' => 'cupom']);
        }
        return $this->page('seller/coupons/form', 'layouts/seller', ['pageTitle' => $coupon ? 'Editar cupom' : 'Criar cupom', 'coupon' => $coupon, 'currentStore' => (new SellerStoreContext())->current()]);
    }

    /** @return array<int,mixed> */
    private function validatedData(bool $includeFundingSource = true): array
    {
        $code = mb_strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = ($_POST['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percentage';
        $value = (float) ($_POST['discount_value'] ?? 0);
        $startsAt = trim((string) ($_POST['starts_at'] ?? '')) ?: null;
        $expiresAt = trim((string) ($_POST['expires_at'] ?? '')) ?: null;
        if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code) || $name === '' || $value <= 0 || ($type === 'percentage' && $value > 100)) throw new RuntimeException('Cupom inválido.');
        if ($startsAt && $expiresAt && strtotime($expiresAt) <= strtotime($startsAt)) throw new RuntimeException('Datas inválidas.');
        $data = [$code, $name, trim((string) ($_POST['description'] ?? '')), $type, $value];
        if ($includeFundingSource) {
            $data[] = 'seller';
        }
        return [...$data, max(0, (float) ($_POST['minimum_total'] ?? 0)), (int) ($_POST['usage_limit'] ?? 0) ?: null, $startsAt, $expiresAt, ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active'];
    }
}
