<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Media\StoreUploadService;
use App\Services\Stores\SellerStoreContext;
use Throwable;

final class SettingsController extends Controller
{
    public function storeEdit(): string
    {
        $context = new SellerStoreContext();
        $store = $context->current();
        $pdo = Database::connection();
        $stats = ['products' => 0, 'active_products' => 0, 'coupons' => 0];
        $shippingOrigin = null;
        if ($store) {
            (new StoreUploadService())->ensureDirectory($store);
            $statement = $pdo->prepare("SELECT COUNT(*) products, SUM(status='active') active_products FROM products WHERE store_id=?");
            $statement->execute([$store['id']]);
            $stats = array_merge($stats, $statement->fetch() ?: []);
            $statement = $pdo->prepare("SELECT COUNT(*) FROM coupons WHERE store_id=? AND status='active'");
            $statement->execute([$store['id']]);
            $stats['coupons'] = (int) $statement->fetchColumn();

            $statement = $pdo->prepare("SELECT id,postal_code,city,state FROM warehouses WHERE seller_id=? AND status='active' ORDER BY id LIMIT 1");
            $statement->execute([$store['seller_id']]);
            $shippingOrigin = $statement->fetch() ?: null;
            $statement = $pdo->prepare('SELECT * FROM store_addresses WHERE store_id=? AND is_shipping_origin=1 ORDER BY id LIMIT 1');
            $statement->execute([$store['id']]);
            $storeAddress = $statement->fetch();
            if (is_array($storeAddress)) {
                $shippingOrigin = array_merge($shippingOrigin ?: [], $storeAddress);
            }
        }
        return $this->page('seller/settings/store', 'layouts/seller', ['pageTitle' => 'Configuração da loja', 'currentStore' => $store, 'stores' => $context->stores(), 'stats' => $stats, 'shippingOrigin' => $shippingOrigin]);
    }

    public function storeUpdate(): string
    {
        $context = new SellerStoreContext();
        $store = $context->current();
        if (!$store) return Response::redirect('/vendedor');
        $name = trim((string) ($_POST['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            Session::flash('error', 'Informe um nome de loja com pelo menos 3 caracteres.');
            return Response::redirect('/vendedor/configuracoes/loja');
        }
        $sources = [(int) ($_POST['shipping_source_store_id'] ?? 0)];
        foreach ($sources as $source) {
            if ($source && ($source === (int) $store['id'] || !array_filter($context->stores(), fn(array $candidate): bool => (int) $candidate['id'] === $source))) {
                Session::flash('error', 'Loja de origem inválida.');
                return Response::redirect('/vendedor/configuracoes/loja');
            }
        }
        $originPostalCode = preg_replace('/\D+/', '', (string) ($_POST['origin_postal_code'] ?? '')) ?? '';
        $originStreet = trim((string) ($_POST['origin_street'] ?? ''));
        $originNumber = trim((string) ($_POST['origin_number'] ?? ''));
        $originComplement = trim((string) ($_POST['origin_complement'] ?? ''));
        $originNeighborhood = trim((string) ($_POST['origin_neighborhood'] ?? ''));
        $originCity = trim((string) ($_POST['origin_city'] ?? ''));
        $originState = mb_strtoupper(trim((string) ($_POST['origin_state'] ?? '')));
        if (!preg_match('/^\d{8}$/', $originPostalCode) || $originStreet === '' || $originNumber === '' || $originNeighborhood === '' || $originCity === '' || !preg_match('/^[A-Z]{2}$/', $originState)) {
            Session::flash('error', 'Informe o endereço completo e válido para a origem das entregas.');
            return Response::redirect('/vendedor/configuracoes/loja');
        }
        $uploads = new StoreUploadService();
        $logoPath = !empty($_POST['remove_logo']) ? null : ($store['logo_url'] ?? null);
        $bannerPath = !empty($_POST['remove_banner']) ? null : ($store['banner_url'] ?? null);
        $newPaths = [];
        $pdo = Database::connection();
        try {
            $uploadStore = array_merge($store, ['name' => $name]);
            if (isset($_FILES['logo']) && is_array($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $logoPath = $uploads->store($_FILES['logo'], $uploadStore, 'logo');
                $newPaths[] = $logoPath;
            }
            if (isset($_FILES['banner']) && is_array($_FILES['banner']) && ($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $bannerPath = $uploads->store($_FILES['banner'], $uploadStore, 'banner');
                $newPaths[] = $bannerPath;
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE stores SET name=?,description=?,logo_url=?,banner_url=?,shipping_source_store_id=?,wholesale_min_quantity=?,wholesale_min_total=? WHERE id=?')->execute([
                $name, trim((string) ($_POST['description'] ?? '')), $logoPath, $bannerPath, $sources[0] ?: null,
                max(0, (int) ($_POST['wholesale_min_quantity'] ?? 0)) ?: null,
                max(0, (float) ($_POST['wholesale_min_total'] ?? 0)) ?: null,
                $store['id'],
            ]);
            $statement = $pdo->prepare("SELECT id FROM warehouses WHERE seller_id=? AND status='active' ORDER BY id LIMIT 1");
            $statement->execute([$store['seller_id']]);
            $warehouseId = (int) $statement->fetchColumn();
            if ($warehouseId) {
                $pdo->prepare('UPDATE warehouses SET postal_code=?,city=?,state=? WHERE id=?')->execute([$originPostalCode, $originCity, $originState, $warehouseId]);
            } else {
                $pdo->prepare("INSERT INTO warehouses (seller_id,name,postal_code,city,state,status) VALUES (?,'Origem principal',?,?,?,'active')")->execute([$store['seller_id'], $originPostalCode, $originCity, $originState]);
            }
            $statement = $pdo->prepare('SELECT id FROM store_addresses WHERE store_id=? AND is_shipping_origin=1 ORDER BY id LIMIT 1');
            $statement->execute([$store['id']]);
            $addressId = (int) $statement->fetchColumn();
            $addressValues = [$originPostalCode, $originStreet, $originNumber, $originComplement ?: null, $originNeighborhood, $originCity, $originState];
            if ($addressId) {
                $addressValues[] = $addressId;
                $pdo->prepare('UPDATE store_addresses SET postal_code=?,street=?,number=?,complement=?,neighborhood=?,city=?,state=? WHERE id=?')->execute($addressValues);
            } else {
                array_unshift($addressValues, $store['id']);
                $pdo->prepare('INSERT INTO store_addresses (store_id,postal_code,street,number,complement,neighborhood,city,state,is_shipping_origin) VALUES (?,?,?,?,?,?,?,?,1)')->execute($addressValues);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($newPaths as $newPath) $uploads->deleteOwned($newPath, (int) $store['id']);
            Session::flash('error', $exception->getMessage());
            return Response::redirect('/vendedor/configuracoes/loja');
        }
        if ($logoPath !== ($store['logo_url'] ?? null)) $uploads->deleteOwned($store['logo_url'] ?? null, (int) $store['id']);
        if ($bannerPath !== ($store['banner_url'] ?? null)) $uploads->deleteOwned($store['banner_url'] ?? null, (int) $store['id']);
        Session::flash('success', 'Configurações da loja atualizadas.');
        return Response::redirect('/vendedor/configuracoes/loja');
    }

    public function sellerEdit(): string
    {
        $statement = Database::connection()->prepare('SELECT s.*,u.name user_name,u.email,u.phone FROM sellers s JOIN users u ON u.id=s.user_id WHERE s.user_id=? LIMIT 1');
        $statement->execute([Auth::id()]);
        $seller = $statement->fetch();
        if (!$seller) {
            http_response_code(403);
            Session::flash('error', 'O cadastro empresarial não está vinculado a esta conta.');
            return Response::redirect('/vendedor');
        }
        $stores = (new SellerStoreContext())->stores();
        return $this->page('seller/settings/seller', 'layouts/seller', ['pageTitle' => 'Configuração do vendedor', 'seller' => $seller, 'storeCount' => count($stores), 'currentStore' => (new SellerStoreContext())->current()]);
    }

    public function sellerUpdate(): string
    {
        $name = trim((string) ($_POST['user_name'] ?? ''));
        $legalName = trim((string) ($_POST['legal_name'] ?? ''));
        $tradeName = trim((string) ($_POST['trade_name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
        $stateRegistration = trim((string) ($_POST['state_registration'] ?? ''));
        if (mb_strlen($name) < 3 || $legalName === '' || $tradeName === '') {
            Session::flash('error', 'Preencha responsável, razão social e nome comercial.');
            return Response::redirect('/vendedor/configuracoes/vendedor');
        }
        $pdo = Database::connection();
        $document = $pdo->prepare('SELECT document FROM sellers WHERE user_id=? LIMIT 1');
        $document->execute([Auth::id()]);
        $sellerDocument = preg_replace('/\D+/', '', (string) $document->fetchColumn()) ?? '';
        if (!in_array(strlen($phone), [10, 11], true) || (strlen($sellerDocument) === 14 && $stateRegistration === '')) {
            Session::flash('error', 'Informe telefone com DDD e inscrição estadual (ou ISENTO) para emitir etiquetas.');
            return Response::redirect('/vendedor/configuracoes/vendedor');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET name=?,phone=? WHERE id=?')->execute([$name, $phone, Auth::id()]);
            $pdo->prepare('UPDATE sellers SET legal_name=?,trade_name=?,state_registration=? WHERE user_id=?')->execute([$legalName, $tradeName, $stateRegistration, Auth::id()]);
            $pdo->commit();
            Session::flash('success', 'Dados pessoais e empresariais atualizados.');
        } catch (Throwable) {
            $pdo->rollBack();
            Session::flash('error', 'Não foi possível atualizar o cadastro do vendedor.');
        }
        return Response::redirect('/vendedor/configuracoes/vendedor');
    }
}
