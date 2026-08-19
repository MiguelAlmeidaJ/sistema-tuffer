<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Database;
use PDO;

final class LoginThrottle
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;
    private const BLOCK_MINUTES = 15;

    public function __construct(private readonly ?PDO $database = null)
    {
    }

    public function blocked(string $email, string $ip): bool
    {
        [$emailHash, $ipHash] = $this->identity($email, $ip);
        $statement = $this->pdo()->prepare('SELECT blocked_until IS NOT NULL AND blocked_until>NOW() FROM login_attempts WHERE email_hash=? AND ip_hash=? LIMIT 1');
        $statement->execute([$emailHash, $ipHash]);
        return (bool) $statement->fetchColumn();
    }

    public function recordFailure(string $email, string $ip): void
    {
        [$emailHash, $ipHash] = $this->identity($email, $ip);
        $statement = $this->pdo()->prepare(
            "INSERT INTO login_attempts(email_hash,ip_hash,attempts,first_attempt_at,last_attempt_at,blocked_until)
             VALUES(?,?,1,NOW(),NOW(),NULL)
             ON DUPLICATE KEY UPDATE
                blocked_until=IF(
                    last_attempt_at<DATE_SUB(NOW(),INTERVAL " . self::WINDOW_MINUTES . " MINUTE),
                    NULL,
                    IF(attempts+1>=" . self::MAX_ATTEMPTS . ",DATE_ADD(NOW(),INTERVAL " . self::BLOCK_MINUTES . " MINUTE),blocked_until)
                ),
                first_attempt_at=IF(last_attempt_at<DATE_SUB(NOW(),INTERVAL " . self::WINDOW_MINUTES . " MINUTE),NOW(),first_attempt_at),
                attempts=IF(last_attempt_at<DATE_SUB(NOW(),INTERVAL " . self::WINDOW_MINUTES . " MINUTE),1,attempts+1),
                last_attempt_at=NOW()"
        );
        $statement->execute([$emailHash, $ipHash]);
    }

    public function clear(string $email, string $ip): void
    {
        [$emailHash, $ipHash] = $this->identity($email, $ip);
        $this->pdo()->prepare('DELETE FROM login_attempts WHERE email_hash=? AND ip_hash=?')->execute([$emailHash, $ipHash]);
    }

    public function prune(): int
    {
        return $this->pdo()->exec('DELETE FROM login_attempts WHERE last_attempt_at<DATE_SUB(NOW(),INTERVAL 7 DAY)');
    }

    /** @return array{string,string} */
    private function identity(string $email, string $ip): array
    {
        $key = trim((string) ($_ENV['APP_KEY'] ?? ''));
        if ($key === '') $key = 'tuffer-login-throttle';
        return [
            hash_hmac('sha256', mb_strtolower(trim($email)), $key),
            hash_hmac('sha256', trim($ip) ?: 'unknown', $key),
        ];
    }

    private function pdo(): PDO
    {
        return $this->database ?? Database::connection();
    }
}
