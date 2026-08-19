<?php

declare(strict_types=1);

return [
    'products' => [
        'disk' => 'cloudinary',
        'folder' => 'tuffer/products',
        'resource_types' => ['image', 'video'],
        'database_table' => 'product_media',
    ],
    'site' => [
        'disk' => 'public_uploads',
        'root' => dirname(__DIR__) . '/public/uploads',
        'url' => '/uploads',
        'directories' => ['platform/banners', 'platform/category', 'platform/favicon', 'platform/logos', 'platform/site'],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'ico'],
        'max_bytes' => 10 * 1024 * 1024,
    ],
    'stores' => [
        'root' => dirname(__DIR__) . '/public/uploads/stores',
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'logo_max_bytes' => 5 * 1024 * 1024,
        'banner_max_bytes' => 10 * 1024 * 1024,
    ],
];
