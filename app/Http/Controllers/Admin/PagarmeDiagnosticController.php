<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Http\Controllers\Controller;
use App\Services\Payments\Pagarme\PagarmeCheckoutConfiguration;
use App\Services\Payments\Pagarme\PagarmePlatformDiagnosticService;

final class PagarmeDiagnosticController extends Controller
{
    public function index(): string
    {
        $pdo = Database::connection();
        $configuration = new PagarmeCheckoutConfiguration();
        $platform = (new PagarmePlatformDiagnosticService())->inspect();
        $environment = (string) ($platform['key_environment'] ?? 'unknown');

        $enabled = $pdo->prepare(
            "SELECT COUNT(*) FROM seller_payment_accounts
             WHERE provider='pagarme' AND environment=?
               AND enabled_for_sales=1 AND recipient_status='active'
               AND kyc_status IN ('approved','legacy_not_required')"
        );
        $enabled->execute([$environment]);
        $summary = [
            'enabled_sellers' => (int) $enabled->fetchColumn(),
            'allowed_sellers' => count($configuration->allowedSellerIds()),
            'pending_pix' => (int) $pdo->query(
                "SELECT COUNT(*) FROM payments
                 WHERE provider='pagarme' AND integration_type='orders' AND method='pix'
                   AND status IN ('pending','waiting_payment','processing')"
            )->fetchColumn(),
            'failed_webhooks' => (int) $pdo->query(
                "SELECT COUNT(*) FROM payment_webhooks
                 WHERE status='failed' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)"
            )->fetchColumn(),
            'open_divergences' => (int) $pdo->query(
                "SELECT COUNT(*) FROM pagarme_reconciliation_divergences WHERE review_status='open'"
            )->fetchColumn(),
        ];
        $failedWebhooks = $pdo->query(
            "SELECT provider_event_id,event_type,error_message,created_at
             FROM payment_webhooks WHERE status='failed'
             ORDER BY created_at DESC LIMIT 10"
        )->fetchAll();
        $divergences = $pdo->query(
            "SELECT d.id,d.divergence_type,d.local_status,d.remote_status,d.detected_at,
                    o.code order_code
             FROM pagarme_reconciliation_divergences d
             LEFT JOIN payments p ON p.id=d.payment_id
             LEFT JOIN orders o ON o.id=p.order_id
             WHERE d.review_status='open'
             ORDER BY d.detected_at DESC LIMIT 20"
        )->fetchAll();
        $lastRun = $pdo->query(
            'SELECT * FROM pagarme_reconciliation_runs ORDER BY started_at DESC,id DESC LIMIT 1'
        )->fetch() ?: null;

        return $this->page('admin/pagarme-diagnostic/index', 'layouts/admin', [
            'pageTitle' => 'Diagnóstico Pagar.me',
            'configuration' => $configuration,
            'platform' => $platform,
            'summary' => $summary,
            'failedWebhooks' => $failedWebhooks,
            'divergences' => $divergences,
            'lastRun' => $lastRun,
        ]);
    }
}
