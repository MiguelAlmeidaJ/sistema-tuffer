<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Settings\PlatformSettings;
use App\Services\Shipping\MelhorEnvioWalletService;
use JsonException;
use Throwable;

final class ShippingWalletController extends Controller
{
    public function index(): string
    {
        $service = new MelhorEnvioWalletService();
        $balance = null;
        $balanceError = null;
        try {
            $balance = $service->balance();
        } catch (Throwable $exception) {
            $balanceError = $exception->getMessage();
        }
        $topups = Database::connection()->query(
            'SELECT t.*,u.name admin_name FROM melhor_envio_wallet_topups t
             JOIN users u ON u.id=t.admin_user_id ORDER BY t.created_at DESC,t.id DESC LIMIT 30'
        )->fetchAll();
        return $this->page('admin/finance/shipping-wallet', 'layouts/admin', [
            'pageTitle' => 'Carteira de fretes',
            'balance' => $balance,
            'balanceError' => $balanceError,
            'topups' => $topups,
            'walletConfigured' => $service->configured(),
        ]);
    }

    public function create(): string
    {
        if (empty($_POST['confirm_topup'])) {
            Session::flash('error', 'Confirme a geração da recarga antes de continuar.');
            return Response::redirect('/admin/financeiro/carteira-fretes');
        }
        $method = strtolower(trim((string) ($_POST['method'] ?? '')));
        $amount = $this->amount((string) ($_POST['amount'] ?? ''));
        try {
            $settings = PlatformSettings::all();
            $result = (new MelhorEnvioWalletService())->createTopUp(
                $method,
                $amount,
                trim((string) ($settings['legal_name'] ?? '')),
                (string) ($settings['tax_id'] ?? '')
            );
            try {
                $responsePayload = json_encode($result['response'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException) {
                $responsePayload = null;
            }
            Database::connection()->prepare(
                'INSERT INTO melhor_envio_wallet_topups
                 (admin_user_id,method,amount,provider_reference,payment_url,provider_status,response_payload)
                 VALUES(?,?,?,?,?,?,?)'
            )->execute([
                Auth::id(),
                $method,
                number_format($amount, 2, '.', ''),
                $result['reference'],
                $result['payment_url'],
                $result['status'],
                $responsePayload,
            ]);
            Session::flash('success', $result['payment_url']
                ? 'Recarga gerada. Abra o link abaixo para efetuar o pagamento.'
                : 'A recarga foi solicitada, mas o provedor não retornou um link. Consulte o Melhor Envio antes de tentar novamente.');
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect('/admin/financeiro/carteira-fretes');
    }

    private function amount(string $value): float
    {
        $value = trim($value);
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }
        if (!is_numeric($value)) return 0.0;
        return round((float) $value, 2);
    }
}
