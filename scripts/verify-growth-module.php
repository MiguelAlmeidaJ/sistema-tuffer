<?php

declare(strict_types=1);

use App\Core\Database;use App\Http\Controllers\Public\SeoController;use Dotenv\Dotenv;

$root=dirname(__DIR__);require $root.'/vendor/autoload.php';Dotenv::createImmutable($root)->safeLoad();$pdo=Database::connection();
$required=['newsletter_subscriptions','application_logs','service_health_checks','system_alerts','backup_runs'];$tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);foreach($required as $table)if(!in_array($table,$tables,true)){fwrite(STDERR,'GROWTH_MODULE=FAILED missing='.$table.PHP_EOL);exit(1);}
$sitemap=(new SeoController())->sitemap();$robots=(new SeoController())->robots();if(!str_contains($sitemap,'<urlset')||!str_contains($sitemap,'<loc>')||!str_contains($robots,'Sitemap: ')){fwrite(STDERR,"GROWTH_MODULE=FAILED seo\n");exit(1);}
$counts=['newsletter'=>(int)$pdo->query('SELECT COUNT(*) FROM newsletter_subscriptions')->fetchColumn(),'logs'=>(int)$pdo->query('SELECT COUNT(*) FROM application_logs')->fetchColumn(),'health_checks'=>(int)$pdo->query('SELECT COUNT(*) FROM service_health_checks')->fetchColumn(),'alerts_open'=>(int)$pdo->query("SELECT COUNT(*) FROM system_alerts WHERE status='open'")->fetchColumn(),'backups_ok'=>(int)$pdo->query("SELECT COUNT(*) FROM backup_runs WHERE status='completed'")->fetchColumn()];echo 'GROWTH_MODULE=READY '.json_encode($counts,JSON_UNESCAPED_SLASHES).PHP_EOL;
