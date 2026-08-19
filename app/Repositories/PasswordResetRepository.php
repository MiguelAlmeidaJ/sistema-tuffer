<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PasswordResetRepository
{
    public function invalidateOpenCodes(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        )->execute([$userId]);
    }

    /** @param array{user_id:int,email:string,code_hash:string,request_ip:string,resend_count:int} $data */
    public function create(array $data): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO password_reset_codes (user_id, email, code_hash, request_ip, resend_count, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))'
        );
        $statement->execute([
            $data['user_id'],
            $data['email'],
            $data['code_hash'],
            $data['request_ip'],
            $data['resend_count'],
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findLatestOpenByEmail(string $email): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM password_reset_codes WHERE email = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$email]);

        return $statement->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findVerifiedById(int $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM password_reset_codes WHERE id = ? AND verified_at IS NOT NULL AND used_at IS NULL AND expires_at >= NOW() LIMIT 1'
        );
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    public function incrementAttempts(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE password_reset_codes SET attempts = LEAST(attempts + 1, 255) WHERE id = ?'
        )->execute([$id]);
    }

    public function markAsVerified(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE password_reset_codes SET verified_at = NOW() WHERE id = ? AND verified_at IS NULL AND used_at IS NULL'
        )->execute([$id]);
    }

    public function markAsUsed(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE password_reset_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL'
        )->execute([$id]);
    }

    public function secondsSinceLatestRequest(string $email): ?int
    {
        $statement = Database::connection()->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM password_reset_codes WHERE email = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$email]);
        $value = $statement->fetchColumn();

        return $value === false ? null : max(0, (int) $value);
    }

    public function countRequestsToday(string $email, string $ip): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM password_reset_codes WHERE created_at >= CURDATE() AND (email = ? OR request_ip = ?)'
        );
        $statement->execute([$email, $ip]);

        return (int) $statement->fetchColumn();
    }
}
