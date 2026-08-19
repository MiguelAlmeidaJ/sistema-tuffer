<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Core\Database;
use Throwable;

final class PlatformSettings
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $rows = Database::connection()->query("SELECT setting_key,setting_value FROM settings WHERE scope_type='platform' AND scope_id=0")->fetchAll();
            self::$cache = [];
            foreach ($rows as $row) {
                self::$cache[$row['setting_key']] = json_decode($row['setting_value'], true);
            }
        } catch (Throwable) {
            self::$cache = [];
        }
        return self::$cache;
    }

    public static function enabled(string $key, bool $default = true): bool
    {
        $settings = self::all();
        if (!array_key_exists($key, $settings)) return $default;
        return filter_var($settings[$key], FILTER_VALIDATE_BOOL);
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
