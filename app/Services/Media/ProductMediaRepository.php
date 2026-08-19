<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Database;
use InvalidArgumentException;
use App\Services\Settings\PlatformSettings;

final class ProductMediaRepository
{
    /**
     * Registra somente respostas de upload do Cloudinary. Nenhum caminho de
     * /uploads pode ser associado a produto por este fluxo.
     *
     * @param array<string, mixed> $media
     */
    public function create(int $productId, ?int $variantId, array $media): int
    {
        $resourceType = (string) ($media['resource_type'] ?? '');
        $secureUrl = (string) ($media['secure_url'] ?? '');
        $publicId = trim((string) ($media['public_id'] ?? ''));
        $host = mb_strtolower((string) parse_url($secureUrl, PHP_URL_HOST));

        if (!MediaStoragePolicy::productMediaUsesCloudinary() || !PlatformSettings::enabled('cloudinary_enabled')) {
            throw new InvalidArgumentException('O disco de produtos deve ser o Cloudinary.');
        }
        if (!in_array($resourceType, ['image', 'video'], true)) {
            throw new InvalidArgumentException('Tipo de mídia de produto inválido.');
        }
        $cloudName = trim((string) ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? ''));
        $urlPath = (string) parse_url($secureUrl, PHP_URL_PATH);
        $expectedFolder = trim((string) (MediaStoragePolicy::config()['products']['folder'] ?? ''), '/');
        if ($publicId === '' || $host !== 'res.cloudinary.com' || $cloudName === ''
            || !preg_match('#^/' . preg_quote($cloudName, '#') . '/(?:image|video)/upload/#', $urlPath)
            || ($expectedFolder !== '' && !str_starts_with($publicId, $expectedFolder . '/'))) {
            throw new InvalidArgumentException('A mídia de produto precisa ser uma resposta válida do Cloudinary.');
        }

        $statement = Database::connection()->prepare('INSERT INTO product_media (product_id, variant_id, public_id, resource_type, url, secure_url, thumbnail_url, format, width, height, bytes, duration, sort_order, is_cover) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            $productId,
            $variantId,
            $publicId,
            $resourceType,
            $media['url'] ?? $secureUrl,
            $secureUrl,
            $media['thumbnail_url'] ?? null,
            $media['format'] ?? null,
            $media['width'] ?? null,
            $media['height'] ?? null,
            $media['bytes'] ?? null,
            $media['duration'] ?? null,
            $media['sort_order'] ?? 0,
            !empty($media['is_cover']) ? 1 : 0,
        ]);

        return (int) Database::connection()->lastInsertId();
    }
}
