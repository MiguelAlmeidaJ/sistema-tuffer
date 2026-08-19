<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Payments\Pagarme\PagarmePlatformAccountService;
use App\Services\Payments\Pagarme\PagarmeRecipientService;
use App\Services\Payments\PagarmeClient;
use Throwable;

final class PaymentSettingsController extends Controller
{
    public function index(): string
    {
        $seller = $this->seller();
        if (!$seller) {
            http_response_code(403);
            return $this->page('errors/403', 'layouts/seller', ['pageTitle' => 'Acesso negado']);
        }
        $officialStore = (int) ($seller['is_official_store'] ?? 0) === 1;
        $service = new PagarmeRecipientService();
        $platformService = new PagarmePlatformAccountService();
        $account = $officialStore
            ? $this->platformAccountForView($platformService->account())
            : $service->accountForSeller((int) $seller['id']);
        $syncWarning = null;
        if ($account && (new PagarmeClient())->configured()) {
            try {
                $account = $officialStore
                    ? $this->platformAccountForView($platformService->synchronize())
                    : $service->synchronizeStatus((int) $seller['id']);
                $seller = $this->seller() ?: $seller;
            } catch (Throwable $exception) {
                Logger::warning('Não foi possível sincronizar o recebedor ao abrir a configuração.', [
                    'seller_id' => (int) $seller['id'],
                    'exception' => $exception::class,
                ], 'pagarme_recipient');
                $syncWarning = 'Não foi possível atualizar o status agora. A última informação confirmada continua exibida.';
            }
        }
        return $this->page('seller/settings/payments', 'layouts/seller', [
            'pageTitle' => 'Configuração para recebimento',
            'seller' => $seller,
            'account' => $account,
            'environment' => $service->environment(),
            'paymentConfigured' => (new PagarmeClient())->configured(),
            'syncWarning' => $syncWarning,
            'officialStore' => $officialStore,
            'currentStore' => (new \App\Services\Stores\SellerStoreContext())->current(),
        ]);
    }

    public function create(): string
    {
        $seller = $this->seller();
        if (!$seller) {
            http_response_code(403);
            return Response::redirect('/vendedor');
        }
        if ((int) ($seller['is_official_store'] ?? 0) === 1) {
            Session::flash('error', 'A loja oficial usa o recebedor global definido pelo administrador.');
            return Response::redirect('/vendedor/configuracoes/recebimentos');
        }
        try {
            (new PagarmeRecipientService())->createForSeller((int) $seller['id'], $_POST);
            Session::flash('success', 'Recebedor criado. Agora acompanhe a liberação da validação de identidade.');
        } catch (Throwable $exception) {
            Logger::warning('Falha ao criar recebedor Pagar.me.', [
                'seller_id' => (int) $seller['id'],
                'exception' => $exception::class,
            ], 'pagarme_recipient');
            Session::flash('error', $exception->getMessage());
            Session::flash('old', $this->safeOldInput());
        }
        return Response::redirect('/vendedor/configuracoes/recebimentos');
    }

    public function refresh(): string
    {
        $seller = $this->seller();
        if (!$seller) {
            http_response_code(403);
            return Response::redirect('/vendedor');
        }
        try {
            $officialStore = (int) ($seller['is_official_store'] ?? 0) === 1;
            $account = $officialStore
                ? $this->platformAccountForView((new PagarmePlatformAccountService())->synchronize())
                : (new PagarmeRecipientService())->synchronizeStatus((int) $seller['id']);
            Session::flash('success', ($account['onboarding_status'] ?? null) === 'active'
                ? 'Configuração aprovada. O vendedor está habilitado para vender.'
                : 'Status atualizado com a Pagar.me.');
        } catch (Throwable $exception) {
            Logger::warning('Falha ao sincronizar recebedor Pagar.me.', [
                'seller_id' => (int) $seller['id'],
                'exception' => $exception::class,
            ], 'pagarme_recipient');
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect('/vendedor/configuracoes/recebimentos');
    }

    public function kyc(): string
    {
        $seller = $this->seller();
        if (!$seller) {
            http_response_code(403);
            return Response::redirect('/vendedor');
        }
        if ((int) ($seller['is_official_store'] ?? 0) === 1) {
            Session::flash('error', 'O KYC da loja oficial pertence à conta global e deve ser administrado fora do painel do vendedor.');
            return Response::redirect('/vendedor/configuracoes/recebimentos');
        }
        try {
            $url = (new PagarmeRecipientService())->generateKycLink((int) $seller['id']);
            return Response::redirectAway($url, 303);
        } catch (Throwable $exception) {
            Logger::warning('Falha ao gerar link KYC Pagar.me.', [
                'seller_id' => (int) $seller['id'],
                'exception' => $exception::class,
            ], 'pagarme_recipient');
            Session::flash('error', $exception->getMessage());
            return Response::redirect('/vendedor/configuracoes/recebimentos');
        }
    }

    /** @return array<string,mixed>|null */
    private function seller(): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.*,u.name user_name,u.email user_email,u.phone user_phone FROM sellers s JOIN users u ON u.id=s.user_id WHERE s.user_id=? LIMIT 1'
        );
        $statement->execute([Auth::id()]);
        $seller = $statement->fetch();
        return is_array($seller) ? $seller : null;
    }

    /** @return array<string,mixed> */
    private function safeOldInput(): array
    {
        $blocked = [
            '_token', 'partner_document', 'bank_holder_document', 'branch_number',
            'branch_check_digit', 'account_number', 'account_check_digit',
        ];
        return array_filter(
            $_POST,
            static fn(string $key): bool => !in_array($key, $blocked, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** @param array<string,mixed>|null $account @return array<string,mixed>|null */
    private function platformAccountForView(?array $account): ?array
    {
        if ($account === null) {
            return null;
        }
        $active = (int) ($account['payment_enabled'] ?? 0) === 1;
        return $account + [
            'onboarding_status' => $active ? 'active' : 'platform_pending',
            'bank_code' => $account['bank_name_masked'] ?? null,
            'bank_branch_masked' => null,
            'bank_account_masked' => $account['bank_account_masked'] ?? null,
            'kyc_status_reason' => null,
        ];
    }
}
