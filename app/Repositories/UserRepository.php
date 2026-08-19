<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, name, email, password_hash, auth_version FROM users WHERE email = ? LIMIT 1'
        );
        $statement->execute([$email]);

        return $statement->fetch() ?: null;
    }

    /** @return array<int, string> */
    public function recentPasswordHashes(int $userId, int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));
        $statement = Database::connection()->prepare(
            "SELECT password_hash FROM user_password_history WHERE user_id = ? ORDER BY id DESC LIMIT {$limit}"
        );
        $statement->execute([$userId]);

        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }
}
