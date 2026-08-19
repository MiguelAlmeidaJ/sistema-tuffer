<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$host = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
$username = (string) ($_ENV['MAIL_USERNAME'] ?? '');
$password = (string) ($_ENV['MAIL_PASSWORD'] ?? '');
$subject = trim((string) ($argv[1] ?? 'Teste SMTP Tuffer'));
if ($host === '' || $username === '' || $password === '') {
    fwrite(STDERR, "IMAP_CHECK=NOT_CONFIGURED\n");
    exit(2);
}

$context = stream_context_create(['ssl' => [
    'peer_name' => $host,
    'SNI_enabled' => true,
    'verify_peer' => true,
    'verify_peer_name' => true,
]]);
$socket = @stream_socket_client('ssl://' . $host . ':993', $errorNumber, $errorMessage, 15, STREAM_CLIENT_CONNECT, $context);
if (!is_resource($socket)) {
    fwrite(STDERR, "IMAP_CHECK=FAILED CONNECTION={$errorNumber} {$errorMessage}\n");
    exit(1);
}
stream_set_timeout($socket, 20);

/** @param resource $stream */
$readUntilTag = static function ($stream, string $tag): string {
    $response = '';
    while (($line = fgets($stream, 8192)) !== false) {
        $response .= $line;
        if (str_starts_with($line, $tag . ' ')) return $response;
    }
    return $response;
};
$quote = static fn(string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';

try {
    $greeting = fgets($socket, 8192);
    if (!is_string($greeting) || !str_starts_with($greeting, '* OK')) throw new RuntimeException('Saudação IMAP inválida.');

    fwrite($socket, 'A1 LOGIN ' . $quote($username) . ' ' . $quote($password) . "\r\n");
    $login = $readUntilTag($socket, 'A1');
    if (!preg_match('/^A1 OK/im', $login)) throw new RuntimeException('Autenticação IMAP recusada.');

    fwrite($socket, "A2 SELECT INBOX\r\n");
    $select = $readUntilTag($socket, 'A2');
    if (!preg_match('/^A2 OK/im', $select)) throw new RuntimeException('Não foi possível abrir a caixa de entrada.');

    fwrite($socket, 'A3 SEARCH HEADER SUBJECT ' . $quote($subject) . "\r\n");
    $search = $readUntilTag($socket, 'A3');
    if (!preg_match('/^A3 OK/im', $search)) throw new RuntimeException('A busca IMAP foi recusada.');
    preg_match('/^\* SEARCH(?: ([0-9 ]+))?/im', $search, $matches);
    $ids = trim((string) ($matches[1] ?? ''));

    fwrite($socket, "A4 LOGOUT\r\n");
    $readUntilTag($socket, 'A4');
    if ($ids === '') {
        fwrite(STDERR, "IMAP_CHECK=NOT_FOUND\n");
        exit(1);
    }
    fwrite(STDOUT, 'IMAP_CHECK=DELIVERED messages=' . count(preg_split('/\s+/', $ids)) . PHP_EOL);
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'IMAP_CHECK=FAILED ERROR=' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    fclose($socket);
}
