<?php

declare(strict_types=1);

use App\Core\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$pdo = Database::connection();
$requiredColumns = [
    'shipments' => ['tracking_url', 'raw_status', 'last_synced_at'],
    'shipment_tracking_events' => ['provider_event_key', 'raw_payload'],
];

foreach ($requiredColumns as $table => $columns) {
    $statement = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');
    $statement->execute([$table]);
    $found = $statement->fetchAll(PDO::FETCH_COLUMN);
    foreach ($columns as $column) {
        if (!in_array($column, $found, true)) {
            fwrite(STDERR, "ORDER_MODULE=FAILED missing={$table}.{$column}\n");
            exit(1);
        }
    }
}

$pdo->query('SELECT o.id,u.name,COUNT(DISTINCT so.id) store_count,COUNT(DISTINCT sh.id) shipment_count FROM orders o JOIN users u ON u.id=o.user_id LEFT JOIN seller_orders so ON so.order_id=o.id LEFT JOIN shipments sh ON sh.seller_order_id=so.id GROUP BY o.id,u.id ORDER BY o.created_at DESC LIMIT 1')->fetch();
$sellerList = $pdo->prepare('SELECT so.*,o.code order_code,o.order_type,o.created_at order_created_at,u.name customer_name,u.email customer_email,sh.id shipment_id,sh.service_name,sh.carrier_name,sh.tracking_code,sh.status shipment_status FROM seller_orders so JOIN orders o ON o.id=so.order_id JOIN users u ON u.id=o.user_id LEFT JOIN shipments sh ON sh.seller_order_id=so.id WHERE so.store_id=? ORDER BY so.created_at DESC LIMIT 1');
$sellerList->execute([0]);
$sellerList->fetch();

$counts = [
    'orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'shipments' => (int) $pdo->query('SELECT COUNT(*) FROM shipments')->fetchColumn(),
    'mail_deliveries' => (int) $pdo->query('SELECT COUNT(*) FROM mail_deliveries')->fetchColumn(),
];

fwrite(STDOUT, 'ORDER_MODULE=READY ' . json_encode($counts, JSON_UNESCAPED_SLASHES) . PHP_EOL);
