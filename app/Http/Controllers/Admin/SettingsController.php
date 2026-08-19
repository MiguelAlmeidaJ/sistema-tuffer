<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Media\SiteUploadService;
use App\Services\Payments\Pagarme\PagarmeRecipientId;
use RuntimeException;
use Throwable;
use App\Services\Settings\PlatformSettings;

final class SettingsController extends Controller
{
    private const FIELDS = [
        'identidade' => ['platform_name', 'tagline', 'slogan', 'logo_path', 'logo_dark_path', 'favicon_path', 'share_image_path'],
        'aparencia' => ['primary_color', 'secondary_color', 'accent_color', 'color_mode', 'typography', 'button_style'],
        'banners' => [
            'home_main_banner', 'home_main_banner_link',
            'home_discount_banner', 'home_discount_banner_link',
            'home_official_banner', 'home_official_banner_link',
            'official_wide_banner', 'official_wide_banner_link',
        ],
        'seo' => ['seo_title', 'seo_description', 'seo_keywords', 'seo_robots'],
        'comunicacao' => ['legal_name', 'tax_id', 'support_email', 'privacy_email', 'new_sale_alert_email', 'support_phone', 'whatsapp', 'instagram', 'facebook', 'linkedin', 'business_address', 'support_hours'],
        'integracoes' => ['cloudinary_enabled', 'pagarme_enabled', 'melhor_envio_enabled', 'mail_enabled'],
        'regras' => ['default_commission', 'maintenance_mode', 'orders_prefix'],
        'financeiro' => [
            'official_store_seller_id','official_store_reserve_percentage',
            'official_store_reserve_min_amount','official_store_transfer_enabled',
            'official_store_settlement_frequency','marketplace_financial_policy_version',
        ],
        'seguranca' => ['admin_session_timeout', 'admin_login_alerts'],
    ];

    private const UPLOADS = [
        'logo_path' => ['directory' => 'platform/logos', 'max' => 2097152],
        'logo_dark_path' => ['directory' => 'platform/logos', 'max' => 2097152],
        'favicon_path' => ['directory' => 'platform/favicon', 'max' => 1048576],
        'share_image_path' => ['directory' => 'platform/site', 'max' => 4194304],
        'home_main_banner' => ['directory' => 'platform/banners', 'max' => 10485760],
        'home_discount_banner' => ['directory' => 'platform/banners', 'max' => 10485760],
        'home_official_banner' => ['directory' => 'platform/banners', 'max' => 10485760],
        'official_wide_banner' => ['directory' => 'platform/banners', 'max' => 10485760],
    ];

    public function index(): string
    {
        $section = array_key_exists((string) ($_GET['secao'] ?? ''), self::FIELDS) ? (string) $_GET['secao'] : 'identidade';
        return $this->page('admin/settings/index', 'layouts/admin', [
            'pageTitle' => 'Configurações da plataforma',
            'section' => $section,
            'sections' => array_keys(self::FIELDS),
            'fields' => self::FIELDS[$section],
            'settings' => $this->settings(),
            'securityStatus' => $this->securityStatus(),
            'sellerOptions' => Database::connection()->query(
                "SELECT id,trade_name,status,is_official_store FROM sellers
                 WHERE is_official_store=1 OR NOT EXISTS(
                     SELECT 1 FROM seller_payment_accounts spa
                     WHERE spa.seller_id=sellers.id AND spa.provider='pagarme'
                 ) ORDER BY trade_name,id"
            )->fetchAll(),
        ]);
    }

    public function update(): string
    {
        $section = array_key_exists((string) ($_POST['section'] ?? ''), self::FIELDS) ? (string) $_POST['section'] : 'identidade';
        $pdo = Database::connection();
        $existing = $this->settings();
        $uploads = new SiteUploadService();
        $newPaths = [];
        $obsoletePaths = [];
        $values = [];
        try {
            foreach (self::FIELDS[$section] as $field) {
                if (isset(self::UPLOADS[$field])) {
                    $current = trim((string) ($existing[$field] ?? ''));
                    $value = !empty($_POST['remove_' . $field]) ? '' : $current;
                    $file = $_FILES['upload_' . $field] ?? null;
                    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber a imagem. Verifique o tamanho e tente novamente.');
                        $config = self::UPLOADS[$field];
                        $value = $uploads->store($file, $config['directory'], $config['max']);
                        $newPaths[] = $value;
                    }
                    if ($current !== '' && $current !== $value) $obsoletePaths[] = $current;
                    $values[$field] = $value;
                    continue;
                }
                $values[$field] = $this->sanitize($field, $_POST[$field] ?? '');
            }

            $pdo->beginTransaction();
            $statement = $pdo->prepare("INSERT INTO settings(scope_type,scope_id,setting_key,setting_value) VALUES('platform',0,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()");
            foreach ($values as $field => $value) $statement->execute([$field, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            if ($section === 'financeiro') {
                $sellerId = (int) ($values['official_store_seller_id'] ?? 0);
                $eligible = $pdo->prepare(
                    "SELECT id FROM sellers WHERE id=? AND status='active'
                     AND NOT EXISTS(
                         SELECT 1 FROM seller_payment_accounts spa
                         WHERE spa.seller_id=sellers.id AND spa.provider='pagarme'
                     ) FOR UPDATE"
                );
                $eligible->execute([$sellerId]);
                if ((int) $eligible->fetchColumn() !== $sellerId) {
                    throw new RuntimeException('Selecione um seller ativo que não possua recebedor externo próprio.');
                }
                $pdo->prepare(
                    "UPDATE sellers SET pagarme_recipient_id=NULL,payment_enabled=0,
                        payment_onboarding_status='not_started',
                        payment_block_reason='Configure o recebedor externo'
                     WHERE is_official_store=1 AND id<>?"
                )->execute([$sellerId]);
                $pdo->exec('UPDATE sellers SET is_official_store=0 WHERE is_official_store=1');
                $recipientId = trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''));
                $pdo->prepare(
                    "UPDATE sellers SET is_official_store=1,pagarme_recipient_id=?,
                        payment_enabled=0,payment_onboarding_status='platform_pending',
                        payment_block_reason='Aguardando validação do recebedor da plataforma'
                     WHERE id=?"
                )->execute([
                    PagarmeRecipientId::isValid($recipientId) ? $recipientId : null,
                    $sellerId,
                ]);
            }
            $pdo->commit();
            PlatformSettings::reset();
            foreach ($obsoletePaths as $path) $uploads->deleteManaged($path);
            Session::flash('success', 'Configurações atualizadas com sucesso.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($newPaths as $path) $uploads->deleteManaged($path);
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível salvar as configurações. Tente novamente.');
        }
        return Response::redirect('/admin/configuracoes?secao=' . rawurlencode($section));
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $rows = Database::connection()->query("SELECT setting_key,setting_value FROM settings WHERE scope_type='platform' AND scope_id=0")->fetchAll();
        $settings = [];
        foreach ($rows as $row) $settings[$row['setting_key']] = json_decode((string) $row['setting_value'], true);
        return $settings;
    }

    private function sanitize(string $field, mixed $value): string
    {
        $value = trim((string) $value);
        if (in_array($field, ['cloudinary_enabled', 'pagarme_enabled', 'melhor_envio_enabled', 'mail_enabled', 'maintenance_mode', 'admin_login_alerts', 'official_store_transfer_enabled'], true)) return $value === '1' ? '1' : '0';
        if (in_array($field, ['primary_color', 'secondary_color', 'accent_color'], true)) {
            if (!preg_match('/^#[0-9a-f]{6}$/i', $value)) throw new RuntimeException('Informe as cores no formato hexadecimal, como #D20A16.');
            return strtoupper($value);
        }
        if ($field === 'default_commission') {
            $number = (float) str_replace(',', '.', $value);
            if ($number < 0 || $number > 100) throw new RuntimeException('A comissão padrão deve estar entre 0% e 100%.');
            return number_format($number, 2, '.', '');
        }
        if ($field === 'admin_session_timeout') return (string) max(5, min(1440, (int) $value));
        if ($field === 'official_store_seller_id') return (string) max(0, (int) $value);
        if ($field === 'official_store_reserve_percentage') {
            $number = (float) str_replace(',', '.', $value);
            if ($number < 0 || $number > 100) throw new RuntimeException('A reserva deve estar entre 0% e 100%.');
            return number_format($number, 2, '.', '');
        }
        if ($field === 'official_store_reserve_min_amount') {
            $number = (float) str_replace(',', '.', $value);
            if ($number < 0) throw new RuntimeException('A reserva mínima não pode ser negativa.');
            return (string) (int) round($number * 100);
        }
        if ($field === 'official_store_settlement_frequency') {
            if (!in_array($value, ['monthly'], true)) throw new RuntimeException('Frequência de fechamento inválida.');
            return $value;
        }
        if ($field === 'marketplace_financial_policy_version') {
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $value)) throw new RuntimeException('Versão da política inválida.');
            return $value;
        }
        if (in_array($field, ['support_email', 'privacy_email', 'new_sale_alert_email'], true) && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
        if (str_ends_with($field, '_banner_link')) return $this->sanitizeBannerLink($value);
        if ($field === 'orders_prefix') {
            $value = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9-]+/', '', $value));
            if ($value === '' || strlen($value) > 12) throw new RuntimeException('O prefixo dos pedidos deve ter entre 1 e 12 letras ou números.');
        }
        $allowed = [
            'color_mode' => ['light', 'dark', 'automatic'],
            'typography' => ['editorial', 'modern', 'system'],
            'button_style' => ['rounded', 'soft', 'square'],
            'seo_robots' => ['index,follow', 'noindex,follow', 'noindex,nofollow'],
        ];
        if (isset($allowed[$field]) && !in_array($value, $allowed[$field], true)) throw new RuntimeException('Uma das opções selecionadas é inválida.');
        return mb_substr($value, 0, in_array($field, ['tagline', 'seo_description', 'business_address', 'support_hours'], true) ? 1000 : 320);
    }

    private function sanitizeBannerLink(string $value): string
    {
        if ($value === '') return '';
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $value)) throw new RuntimeException('O link do banner contém caracteres inválidos.');
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return mb_substr($value, 0, 1000);
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) return mb_substr($value, 0, 1000);
        }
        throw new RuntimeException('Informe um link interno iniciado por / ou uma URL externa com http:// ou https://.');
    }

    /** @return array<string,mixed> */
    private function securityStatus(): array
    {
        return [
            'environment' => (string) ($_ENV['APP_ENV'] ?? 'production'),
            'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
            'app_key' => strlen(trim((string) ($_ENV['APP_KEY'] ?? ''))) >= 32,
            'webhook_secret' => strlen(trim((string) ($_ENV['PAGARME_WEBHOOK_SECRET'] ?? ''))) >= 16,
            'smtp' => trim((string) ($_ENV['MAIL_HOST'] ?? '')) !== '',
            'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (filter_var($_ENV['TRUST_PROXY_HEADERS'] ?? false, FILTER_VALIDATE_BOOL)
                    && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https'),
        ];
    }
}
