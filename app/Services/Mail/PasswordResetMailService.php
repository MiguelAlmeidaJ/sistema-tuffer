<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

final class PasswordResetMailService
{
    private ?string $lastError = null;

    public function sendCode(string $recipientName, string $recipientEmail, string $code): bool
    {
        return $this->sendMessage(
            $recipientName,
            $recipientEmail,
            'Seu código de recuperação Tuffer',
            "Olá, {$recipientName}.\n\nSeu código para redefinir a senha é: {$code}\n\nEle expira em 15 minutos. Se você não fez esta solicitação, ignore este e-mail."
        );
    }

    public function sendPasswordChanged(string $recipientName, string $recipientEmail): bool
    {
        return $this->sendMessage(
            $recipientName,
            $recipientEmail,
            'Sua senha Tuffer foi alterada',
            "Olá, {$recipientName}.\n\nSua senha foi alterada com sucesso. Se não foi você, entre em contato com o suporte imediatamente."
        );
    }

    public function sendMessage(string $recipientName, string $recipientEmail, string $subject, string $message): bool
    {
        $this->lastError = null;
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipientName . $recipientEmail . $subject)) {
            $this->lastError = 'Destinatário ou assunto inválido.';
            return false;
        }
        $host = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
        if ($host !== '') {
            try {
                return $this->sendWithSmtp($recipientName, $recipientEmail, $subject, $message);
            } catch (RuntimeException $exception) {
                $this->lastError = $exception->getMessage();
                return false;
            }
        }

        $fromAddress = (string) ($_ENV['MAIL_FROM_ADDRESS'] ?? 'nao-responda@tuffer.com.br');
        $fromName = (string) ($_ENV['MAIL_FROM_NAME'] ?? 'Tuffer');
        $sent = @mail($recipientEmail, $subject, $message, implode("\r\n", [
            'From: ' . $this->mailbox($fromName, $fromAddress),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ]));
        if (!$sent) $this->lastError = 'A função mail() não confirmou o envio.';
        return $sent;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function sendWithSmtp(string $recipientName, string $recipientEmail, string $subject, string $message): bool
    {
        $host = (string) $_ENV['MAIL_HOST'];
        $port = (int) ($_ENV['MAIL_PORT'] ?? 587);
        $encryption = strtolower((string) ($_ENV['MAIL_ENCRYPTION'] ?? 'tls'));
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl' => [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]);
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errorNumber, $errorMessage, 15, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new RuntimeException("SMTP indisponível: {$errorNumber} {$errorMessage}");
        }
        stream_set_timeout($socket, 30);

        try {
            $this->expect($socket, [220]);
            $serverName = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            $this->command($socket, "EHLO {$serverName}", [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Não foi possível ativar TLS no SMTP.');
                }
                $this->command($socket, "EHLO {$serverName}", [250]);
            }

            $username = (string) ($_ENV['MAIL_USERNAME'] ?? '');
            $password = (string) ($_ENV['MAIL_PASSWORD'] ?? '');
            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }

            $fromAddress = (string) ($_ENV['MAIL_FROM_ADDRESS'] ?? $username);
            $fromName = (string) ($_ENV['MAIL_FROM_NAME'] ?? 'Tuffer');
            if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $fromName)) {
                throw new RuntimeException('Remetente SMTP inválido.');
            }
            $this->command($socket, "MAIL FROM:<{$fromAddress}>", [250]);
            $this->command($socket, "RCPT TO:<{$recipientEmail}>", [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $body = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r"], "\n", $message)) ?? $message;
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $this->mailbox($fromName, $fromAddress),
                'To: ' . $this->mailbox($recipientName, $recipientEmail),
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }

        return true;
    }

    /** @param resource $socket @param array<int, int> $expected */
    private function command($socket, string $command, array $expected): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $expected);
    }

    /** @param resource $socket @param array<int, int> $expected */
    private function expect($socket, array $expected): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('Resposta SMTP inesperada: ' . trim($response));
        }
    }

    private function mailbox(string $name, string $email): string
    {
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }
}
