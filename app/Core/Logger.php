<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Logger
{
    private static bool $writing = false;
    private static ?string $requestId = null;

    public static function register(): void
    {
        self::$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(12));
        header('X-Request-ID: ' . self::$requestId);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) return false;
            self::warning($message, ['file' => $file, 'line' => $line, 'severity' => $severity], 'php');
            return false;
        });
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = [], string $channel = 'application'): void { self::log('info', $message, $context, $channel); }
    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = [], string $channel = 'application'): void { self::log('warning', $message, $context, $channel); }
    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = [], string $channel = 'application'): void { self::log('error', $message, $context, $channel); }
    /** @param array<string,mixed> $context */
    public static function critical(string $message, array $context = [], string $channel = 'application'): void { self::log('critical', $message, $context, $channel); }

    /** @param array<string,mixed> $context */
    public static function exception(Throwable $exception, array $context = [], string $channel = 'application'): void
    {
        self::error($exception->getMessage(), $context + ['exception' => $exception::class, 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'trace' => $exception->getTraceAsString()], $channel);
    }

    /** @param array<string,mixed> $context */
    private static function log(string $level, string $message, array $context, string $channel): void
    {
        if (self::$writing) return;
        self::$writing = true;
        try {
            $context = self::sanitize($context);
            $record = ['timestamp' => date(DATE_ATOM), 'request_id' => self::$requestId, 'level' => $level, 'channel' => mb_substr($channel, 0, 80), 'message' => mb_substr($message, 0, 1000), 'context' => $context, 'method' => $_SERVER['REQUEST_METHOD'] ?? null, 'path' => isset($_SERVER['REQUEST_URI']) ? mb_substr((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 0, 500) : null];
            $directory = dirname(__DIR__, 2) . '/storage/logs';
            if (is_dir($directory) || @mkdir($directory, 0770, true)) @file_put_contents($directory . '/application-' . date('Y-m-d') . '.jsonl', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
            try {
                $statement = Database::connection()->prepare('INSERT INTO application_logs(request_id,level,channel,message,context,request_method,request_path) VALUES(?,?,?,?,?,?,?)');
                $statement->execute([$record['request_id'], $level, $record['channel'], $record['message'], json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $record['method'], $record['path']]);
            } catch (Throwable) {}
        } finally { self::$writing = false; }
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private static function sanitize(array $context): array
    {
        $sensitive = [
            'password','secret','token','authorization','cookie','api_key','card','cvv',
            'bank','branch','account_number','holder_document','document','qr_code','pix_code',
        ];
        $walk = function (mixed $value, string $key = '') use (&$walk, $sensitive): mixed {
            foreach($sensitive as $needle)if(str_contains(strtolower($key),$needle))return '[REDACTED]';
            if(is_array($value)){foreach($value as $childKey=>$childValue)$value[$childKey]=$walk($childValue,(string)$childKey);return $value;}
            if(is_string($value)&&strlen($value)>4000)return mb_substr($value,0,4000).'…';
            return $value;
        };
        return $walk($context);
    }
}
