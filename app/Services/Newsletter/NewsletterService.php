<?php

declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Core\Database;
use App\Services\Mail\QueuedMailService;
use PDO;
use RuntimeException;
use Throwable;

final class NewsletterService
{
    public const CONSENT_VERSION = 'newsletter-2026-07-v1';
    public const CONSENT_STATEMENT = 'Autorizo a Tuffer a usar meu e-mail para enviar novidades, ofertas e conteúdos do marketplace. Posso cancelar gratuitamente a qualquer momento.';

    public function subscribe(string $email, string $source, ?string $ip, ?string $userAgent, bool $sendConfirmation = true): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');

        $confirmationToken = bin2hex(random_bytes(32));
        $unsubscribeToken = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $key = $this->proofKey($confirmationToken);
        $proof = hash_hmac('sha256', implode('|', [$email, self::CONSENT_VERSION, self::CONSENT_STATEMENT, $now]), $key);
        $hashPersonal = static fn(?string $value): ?string => $value ? hash_hmac('sha256', $value, $key) : null;
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare("INSERT INTO newsletter_subscriptions(email,status,consent_version,consent_statement,consent_proof_hash,source,ip_hash,user_agent_hash,confirmation_token_hash,unsubscribe_token_hash,consented_at,confirmed_at,unsubscribed_at) VALUES(?,'pending',?,?,?,?,?,?,?,?,?,NULL,NULL) ON DUPLICATE KEY UPDATE status='pending',consent_version=VALUES(consent_version),consent_statement=VALUES(consent_statement),consent_proof_hash=VALUES(consent_proof_hash),source=VALUES(source),ip_hash=VALUES(ip_hash),user_agent_hash=VALUES(user_agent_hash),confirmation_token_hash=VALUES(confirmation_token_hash),unsubscribe_token_hash=VALUES(unsubscribe_token_hash),consented_at=VALUES(consented_at),confirmed_at=NULL,unsubscribed_at=NULL");
            $confirmationHash = hash('sha256', $confirmationToken);
            $statement->execute([$email, self::CONSENT_VERSION, self::CONSENT_STATEMENT, $proof, mb_substr($source, 0, 80), $hashPersonal($ip), $hashPersonal($userAgent), $confirmationHash, $unsubscribeToken, $now]);
            $subscription = $this->subscriptionByEmail($pdo, $email, true);
            $eventKey = hash('sha256', 'newsletter|requested|' . $subscription['id'] . '|' . $confirmationHash);
            $this->recordEvent($pdo, $subscription, 'consent_requested', $eventKey, $now, ['double_opt_in' => true]);

            if ($sendConfirmation) {
                $confirmUrl = absolute_url('/newsletter/confirmar?token=' . rawurlencode($confirmationToken));
                $privacyUrl = absolute_url('/politica-de-privacidade');
                (new QueuedMailService())->enqueue(
                    'Assinante Tuffer',
                    $email,
                    'Confirme sua inscrição na newsletter Tuffer',
                    "Confirme sua inscrição acessando:\n{$confirmUrl}\n\nFinalidade: novidades, ofertas e conteúdos da Tuffer.\nPolítica de Privacidade: {$privacyUrl}\n\nSe você não solicitou esta inscrição, ignore esta mensagem.",
                    'newsletter_confirmation',
                    'newsletter',
                    (int) $subscription['id'],
                    'newsletter-confirmation:' . $eventKey,
                );
            }
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function confirm(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $tokenHash = hash('sha256', $token);
            $statement = $pdo->prepare('SELECT * FROM newsletter_subscriptions WHERE confirmation_token_hash=? LIMIT 1 FOR UPDATE');
            $statement->execute([$tokenHash]);
            $subscription = $statement->fetch();
            if (!is_array($subscription)) {
                if ($ownsTransaction) $pdo->commit();
                return null;
            }
            $pdo->prepare("UPDATE newsletter_subscriptions SET status='active',confirmed_at=COALESCE(confirmed_at,NOW()),confirmation_token_hash=NULL,unsubscribed_at=NULL WHERE id=?")->execute([$subscription['id']]);
            $eventKey = hash('sha256', 'newsletter|confirmed|' . $subscription['id'] . '|' . $tokenHash);
            $this->recordEvent($pdo, $subscription, 'consent_confirmed', $eventKey, date('Y-m-d H:i:s'), ['double_opt_in' => true]);
            $unsubscribeUrl = absolute_url('/newsletter/cancelar?token=' . rawurlencode((string) $subscription['unsubscribe_token_hash']));
            try {
                (new QueuedMailService())->enqueue(
                    'Assinante Tuffer',
                    (string) $subscription['email'],
                    'Inscrição confirmada na newsletter Tuffer',
                    "Sua inscrição foi confirmada.\n\nVocê receberá novidades, ofertas e conteúdos da Tuffer.\nPara cancelar gratuitamente a qualquer momento, acesse:\n{$unsubscribeUrl}",
                    'newsletter_welcome',
                    'newsletter',
                    (int) $subscription['id'],
                    'newsletter-welcome:' . $eventKey,
                );
            } catch (Throwable) {
                // A confirmação do consentimento não depende do e-mail de boas-vindas.
            }
            if ($ownsTransaction) $pdo->commit();
            return $subscription;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function unsubscribe(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT * FROM newsletter_subscriptions WHERE unsubscribe_token_hash=? LIMIT 1 FOR UPDATE');
            $statement->execute([$token]);
            $subscription = $statement->fetch();
            if (!is_array($subscription)) {
                if ($ownsTransaction) $pdo->commit();
                return false;
            }
            if ($subscription['status'] !== 'unsubscribed') {
                $occurredAt = date('Y-m-d H:i:s');
                $pdo->prepare("UPDATE newsletter_subscriptions SET status='unsubscribed',unsubscribed_at=?,confirmation_token_hash=NULL WHERE id=?")->execute([$occurredAt, $subscription['id']]);
                $eventKey = hash('sha256', 'newsletter|withdrawn|' . $subscription['id'] . '|' . hash('sha256', $token));
                $this->recordEvent($pdo, $subscription, 'consent_withdrawn', $eventKey, $occurredAt, ['method' => 'self_service_link']);
            }
            if ($ownsTransaction) $pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function subscriptionByEmail(PDO $pdo, string $email, bool $lock = false): array
    {
        $statement = $pdo->prepare('SELECT * FROM newsletter_subscriptions WHERE email=?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$email]);
        $subscription = $statement->fetch();
        if (!is_array($subscription)) throw new RuntimeException('Não foi possível registrar a preferência da newsletter.');
        return $subscription;
    }

    /** @param array<string,mixed> $subscription @param array<string,mixed> $metadata */
    private function recordEvent(PDO $pdo, array $subscription, string $eventType, string $eventKey, string $occurredAt, array $metadata): void
    {
        $emailHash = hash_hmac('sha256', mb_strtolower((string) $subscription['email']), $this->proofKey($eventKey));
        $statement = $pdo->prepare('INSERT INTO newsletter_consent_events(subscription_id,event_key,event_type,email_hash,consent_version,consent_statement,consent_proof_hash,source,ip_hash,user_agent_hash,metadata,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([(int) $subscription['id'], $eventKey, $eventType, $emailHash, $subscription['consent_version'], $subscription['consent_statement'], $subscription['consent_proof_hash'], $subscription['source'], $subscription['ip_hash'], $subscription['user_agent_hash'], json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $occurredAt]);
    }

    private function proofKey(string $fallback): string
    {
        $configured = trim((string) ($_ENV['APP_KEY'] ?? ''));
        return $configured !== '' ? $configured : hash('sha256', $fallback);
    }
}
