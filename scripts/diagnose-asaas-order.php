<?php
declare(strict_types=1);

// Read-only diagnostic. Prints no CPF, email, API key, Pix code or QR image.
use App\Core\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$code = trim((string) ($argv[1] ?? ''));
if (!preg_match('/^TF-[A-Z0-9-]{8,60}$/i', $code)) {
    fwrite(STDERR, "Usage: php scripts/diagnose-asaas-order.php TF-XX-...\n");
    exit(2);
}
$pdo = Database::connection();
$stmt = $pdo->prepare(
    "SELECT o.status order_status,p.provider,p.method,p.status payment_status,
            p.external_order_id,p.checkout_url,p.pix_qr_code,p.asaas_pix_image,
            aj.status job_status,aj.attempts,aj.last_error
     FROM orders o LEFT JOIN payments p ON p.order_id=o.id
     LEFT JOIN async_jobs aj ON aj.unique_key=CONCAT('asaas-payment:',p.id)
     WHERE o.code=? ORDER BY p.id DESC LIMIT 1"
);
$stmt->execute([$code]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($payment)) {
    echo "ORDER=NOT_FOUND\n";
    exit(1);
}
foreach (['order_status','provider','method','payment_status','job_status','attempts'] as $key) {
    echo strtoupper($key).'='.((string) ($payment[$key] ?? 'none'))."\n";
}
foreach (['external_order_id','checkout_url','pix_qr_code','asaas_pix_image'] as $key) {
    echo strtoupper($key).'='.(trim((string) ($payment[$key] ?? '')) !== '' ? 'present' : 'missing')."\n";
}
$error = (string) ($payment['last_error'] ?? '');
if ($error !== '') {
    $category = match (true) {
        str_contains($error, 'HTTP 401') => 'API_AUTHENTICATION',
        str_contains($error, 'HTTP 403') => 'API_FORBIDDEN',
        str_contains($error, 'HTTP 400') => 'API_REQUEST_REJECTED',
        str_contains($error, 'HTTP 422') => 'API_VALIDATION',
        str_contains($error, 'QR Code') || str_contains($error, 'Pix') => 'PIX_FETCH_FAILED',
        str_contains($error, 'incerta') => 'RECONCILIATION_REQUIRED',
        str_contains($error, 'SQLSTATE') => 'DATABASE_ERROR',
        default => 'CHECK_SERVER_PAYMENT_LOGS',
    };
    echo 'LAST_ERROR_CATEGORY='.$category."\n";
}
