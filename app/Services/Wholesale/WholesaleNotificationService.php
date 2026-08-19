<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Core\Database;
use App\Services\Mail\QueuedMailService;
use Throwable;

final class WholesaleNotificationService
{
    public function customer(int $userId, string $event, string $title, string $message): void
    {
        $this->notify($userId, $event, $title, $message, '/minha-conta/atacado/status');
    }

    public function admins(string $event, string $title, string $message): void
    {
        $admins = Database::connection()->query("SELECT id FROM users WHERE type='admin' AND status='active'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) $this->notify((int) $adminId, $event, $title, $message, '/admin/atacadistas');
    }

    private function notify(int $userId, string $event, string $title, string $message, string $url): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO user_notifications(user_id,type,title,message,action_url) VALUES(?,?,?,?,?)')->execute([$userId, 'wholesale.' . $event, $title, $message, $url]);
        $statement = $pdo->prepare('SELECT name,email FROM users WHERE id=?');
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if ($user) {
            try {
                (new QueuedMailService())->enqueue((string) $user['name'], (string) $user['email'], $title, $message, 'wholesale_' . $event, 'user', $userId, 'wholesale-mail:' . $userId . ':' . hash('sha256', $event . '|' . $title . '|' . $message));
            } catch (Throwable) {
                // A notificação interna permanece disponível quando o e-mail está desativado.
            }
        }
    }
}
