<?php

declare(strict_types=1);

use App\Core\Database;use App\Services\Newsletter\NewsletterService;use Dotenv\Dotenv;

$root=dirname(__DIR__);require $root.'/vendor/autoload.php';Dotenv::createImmutable($root)->safeLoad();$pdo=Database::connection();$pdo->beginTransaction();
try{$email='newsletter-integration-test@example.invalid';(new NewsletterService())->subscribe($email,'integration_test','127.0.0.1','Tuffer test runner',false);$statement=$pdo->prepare('SELECT status,consent_version,consent_proof_hash,ip_hash,user_agent_hash FROM newsletter_subscriptions WHERE email=?');$statement->execute([$email]);$row=$statement->fetch();if(!$row||$row['status']!=='pending'||$row['consent_version']!==NewsletterService::CONSENT_VERSION||strlen((string)$row['consent_proof_hash'])!==64||strlen((string)$row['ip_hash'])!==64||strlen((string)$row['user_agent_hash'])!==64)throw new RuntimeException('Registro de consentimento incompleto.');$pdo->rollBack();echo "NEWSLETTER_STORAGE=READY\n";}catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,'NEWSLETTER_STORAGE=FAILED ERROR='.$exception->getMessage().PHP_EOL);exit(1);}
