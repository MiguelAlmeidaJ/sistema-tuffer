<?php

declare(strict_types=1);

use App\Services\Mail\PasswordResetMailService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$recipient = $argv[1] ?? (string) ($_ENV['MAIL_FROM_ADDRESS'] ?? '');
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Informe um destinatário válido.\n");
    exit(2);
}

$mailer = new PasswordResetMailService();
$sent = $mailer->sendMessage(
    'Equipe Tuffer',
    $recipient,
    'Teste SMTP Tuffer - ' . date('d/m/Y H:i'),
    "Este e-mail confirma o teste de envio SMTP autenticado da plataforma Tuffer.\n\n" .
    'Se você recebeu esta mensagem, o servidor aceitou e entregou o teste.'
);

if (!$sent) {
    fwrite(STDERR, 'SMTP_TEST=FAILED' . PHP_EOL . 'ERROR=' . $mailer->lastError() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'SMTP_TEST=ACCEPTED' . PHP_EOL);
