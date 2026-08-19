<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Database;
use App\Services\Queue\JobQueue;
use RuntimeException;
use App\Services\Settings\PlatformSettings;

final class QueuedMailService
{
    public function enqueue(string $recipientName, string $recipientEmail, string $subject, string $message, string $template = 'generic', ?string $relatedType = null, ?int $relatedId = null, ?string $uniqueKey = null): int
    {
        if (!PlatformSettings::enabled('mail_enabled')) throw new RuntimeException('O envio de e-mail está desativado nas configurações da plataforma.');
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipientName . $recipientEmail . $subject)) {
            throw new RuntimeException('Destinatário ou assunto inválido.');
        }
        $pdo = Database::connection();
        $statement = $pdo->prepare("INSERT INTO mail_deliveries(unique_key,recipient_email,recipient_name,template,subject,message_body,related_type,related_id,status) VALUES(?,?,?,?,?,?,?,?,'pending') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $statement->execute([$uniqueKey, mb_substr($recipientEmail, 0, 190), mb_substr($recipientName, 0, 150), mb_substr($template, 0, 100), mb_substr($subject, 0, 190), $message, $relatedType, $relatedId]);
        $deliveryId = (int) $pdo->lastInsertId();
        (new JobQueue($pdo))->dispatch('mail.send', ['delivery_id' => $deliveryId], 'mail-delivery:' . $deliveryId, 'mail', 5, 50);
        return $deliveryId;
    }
}
