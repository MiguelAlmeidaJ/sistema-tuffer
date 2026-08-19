<?php

declare(strict_types=1);

namespace App\Services\Media;

final class MediaStoragePolicy
{
    public const PRODUCT_DISK = 'cloudinary';
    public const SITE_DISK = 'public_uploads';

    public static function productMediaUsesCloudinary(): bool
    {
        return self::config()['products']['disk'] === self::PRODUCT_DISK;
    }

    public static function siteMediaRoot(): string
    {
        return self::config()['site']['root'];
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/config/media.php';
        return $config;
    }
}
