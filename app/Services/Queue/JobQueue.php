<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Core\Database;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class JobQueue
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    /** @param array<string,mixed> $payload */
    public function dispatch(string $type, array $payload, ?string $uniqueKey = null, string $queue = 'default', int $maxAttempts = 5, int $priority = 100, ?DateTimeImmutable $availableAt = null): int
    {
        $type = trim($type);
        $queue = trim($queue);
        if ($type === '' || strlen($type) > 100 || $queue === '' || strlen($queue) > 60) {
            throw new RuntimeException('Tipo ou fila do job inválido.');
        }
        if ($uniqueKey !== null && (trim($uniqueKey) === '' || strlen($uniqueKey) > 191)) {
            throw new RuntimeException('Chave única do job inválida.');
        }
        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Payload do job inválido.', 0, $exception);
        }
        $pdo = $this->database ?? Database::connection();
        $statement = $pdo->prepare("INSERT INTO async_jobs(queue,job_type,unique_key,payload,status,priority,max_attempts,available_at) VALUES(?,?,?,?,'pending',?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),status=IF(status='failed','pending',status),attempts=IF(status='failed',0,attempts),available_at=IF(status='failed',VALUES(available_at),available_at),failed_at=IF(status='failed',NULL,failed_at),last_error=IF(status='failed',NULL,last_error)");
        $statement->execute([$queue, $type, $uniqueKey, $encoded, max(0, min(65535, $priority)), max(1, min(100, $maxAttempts)), ($availableAt ?? new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<int,string> $queues @return array<string,mixed>|null */
    public function reserve(array $queues, string $workerId): ?array
    {
        $queues = array_values(array_unique(array_filter(array_map('trim', $queues))));
        if ($queues === []) $queues = ['default'];
        $pdo = $this->database ?? Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE async_jobs SET status='pending',reserved_at=NULL,reserved_by=NULL,available_at=NOW(),last_error='Job recuperado após expiração da reserva.' WHERE status='processing' AND reserved_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
            $placeholders = implode(',', array_fill(0, count($queues), '?'));
            $statement = $pdo->prepare("SELECT * FROM async_jobs WHERE status='pending' AND available_at<=NOW() AND queue IN ({$placeholders}) ORDER BY priority ASC,id ASC LIMIT 1 FOR UPDATE");
            $statement->execute($queues);
            $job = $statement->fetch();
            if (!is_array($job)) {
                $pdo->commit();
                return null;
            }
            $pdo->prepare("UPDATE async_jobs SET status='processing',attempts=attempts+1,reserved_at=NOW(),reserved_by=? WHERE id=? AND status='pending'")->execute([mb_substr($workerId, 0, 100), $job['id']]);
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['status'] = 'processing';
            $pdo->commit();
            try {
                $payload = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Payload persistido do job é inválido.', 0, $exception);
            }
            if (!is_array($payload)) throw new RuntimeException('Payload persistido do job é inválido.');
            $job['payload'] = $payload;
            return $job;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function reserveByUniqueKey(string $uniqueKey, string $workerId): ?array
    {
        $uniqueKey = trim($uniqueKey);
        if ($uniqueKey === '' || strlen($uniqueKey) > 191) {
            throw new RuntimeException('Chave única do job inválida.');
        }

        $pdo = $this->database ?? Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                "SELECT * FROM async_jobs
                 WHERE unique_key=? AND status='pending' AND available_at<=NOW()
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute([$uniqueKey]);
            $job = $statement->fetch();
            if (!is_array($job)) {
                $pdo->commit();
                return null;
            }

            $pdo->prepare(
                "UPDATE async_jobs
                 SET status='processing',attempts=attempts+1,reserved_at=NOW(),reserved_by=?
                 WHERE id=? AND status='pending'"
            )->execute([mb_substr($workerId, 0, 100), $job['id']]);
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['status'] = 'processing';
            $pdo->commit();

            try {
                $payload = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Payload persistido do job é inválido.', 0, $exception);
            }
            if (!is_array($payload)) {
                throw new RuntimeException('Payload persistido do job é inválido.');
            }
            $job['payload'] = $payload;
            return $job;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function complete(int $jobId): void
    {
        ($this->database ?? Database::connection())->prepare("UPDATE async_jobs SET status='completed',payload=JSON_OBJECT(),completed_at=NOW(),reserved_at=NULL,reserved_by=NULL,last_error=NULL WHERE id=? AND status='processing'")->execute([$jobId]);
    }

    public function retry(int $jobId): bool
    {
        $statement = ($this->database ?? Database::connection())->prepare("UPDATE async_jobs SET status='pending',attempts=0,available_at=NOW(),reserved_at=NULL,reserved_by=NULL,failed_at=NULL,last_error=NULL WHERE id=? AND status='failed'");
        $statement->execute([$jobId]);
        return $statement->rowCount() === 1;
    }

    public function fail(array $job, Throwable $exception): void
    {
        $attempts = (int) ($job['attempts'] ?? 1);
        $maximum = (int) ($job['max_attempts'] ?? 5);
        $terminal = $attempts >= $maximum;
        $delay = min(3600, 15 * (2 ** max(0, $attempts - 1)));
        $availableAt = (new DateTimeImmutable())->modify("+{$delay} seconds")->format('Y-m-d H:i:s');
        $statement = ($this->database ?? Database::connection())->prepare("UPDATE async_jobs SET status=?,available_at=?,reserved_at=NULL,reserved_by=NULL,failed_at=?,last_error=? WHERE id=? AND status='processing'");
        $statement->execute([$terminal ? 'failed' : 'pending', $availableAt, $terminal ? date('Y-m-d H:i:s') : null, mb_substr($exception->getMessage(), 0, 1000), $job['id']]);
    }
}
