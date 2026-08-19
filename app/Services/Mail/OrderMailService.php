<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Database;
use Throwable;

final class OrderMailService
{
    public function send(int $orderId, string $template, string $subject, string $message): bool
    {
        $statement = Database::connection()->prepare('SELECT o.code,u.name,u.email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');
        $statement->execute([$orderId]);
        $recipient = $statement->fetch();
        if (!is_array($recipient)) return false;
        try {
            (new QueuedMailService())->enqueue(
                (string) $recipient['name'],
                (string) $recipient['email'],
                $subject,
                $message,
                $template,
                'order',
                $orderId,
                'order-mail:' . $orderId . ':' . hash('sha256', $template),
            );
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
