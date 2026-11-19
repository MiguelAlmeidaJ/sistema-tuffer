<?php
declare(strict_types=1);

/**
 * Diagnóstico somente leitura: não cria clientes, pedidos ou cobranças.
 * Uso: php scripts/check-asaas-sandbox.php
 */
use App\Services\Payments\Asaas\AsaasClient;
use App\Core\Database;
use App\Services\Payments\Asaas\AsaasCheckoutAccess;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

if (!AsaasCheckoutAccess::sandbox()) {
    fwrite(STDERR, "ASAAS_SANDBOX=FAILED environment_not_sandbox\n");
    exit(1);
}

$client = new AsaasClient();
$testEmail = trim((string) ($_ENV['ASAAS_SANDBOX_TEST_EMAIL'] ?? ''));
if (!$client->configured()) {
    fwrite(STDERR, "ASAAS_SANDBOX=NOT_READY missing_api_key_or_webhook_token\n");
    exit(2);
}
if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ASAAS_SANDBOX=NOT_READY missing_test_email_allowlist\n");
    exit(2);
}
try {
    $response = $client->get('/payments?limit=1');
    if (!isset($response['data']) || !is_array($response['data'])) {
        throw new RuntimeException('Resposta de listagem sem formato esperado');
    }
    echo "ASAAS_SANDBOX=CONNECTED read_only=true checkout_enabled="
        . ($client->checkoutEnabled() ? 'yes' : 'no')
        . " payment_records=" . count($response['data']) . "\n";
    $stmt = Database::connection()->prepare(
        "SELECT COUNT(*) FROM users WHERE LOWER(TRIM(email))=? AND type='customer' AND status='active'"
    );
    $stmt->execute([strtolower($testEmail)]);
    echo "authorized_customer_account=" . ((int)$stmt->fetchColumn() > 0 ? 'found' : 'not_found') . "\n";
    echo "NEXT=Log in as this exact customer. If checkout remains blocked, read its new explicit access message.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ASAAS_SANDBOX=FAILED api_connection_or_authorization\n");
    exit(1);
}
