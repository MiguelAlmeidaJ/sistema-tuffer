<?php

declare(strict_types=1);

namespace App\Services\Media;

use finfo;
use RuntimeException;

final class SiteUploadService
{
    /**
     * Armazena somente mídias institucionais. Imagens e vídeos de produtos
     * devem passar pela integração Cloudinary e pela tabela product_media.
     *
     * @param array{name?: string, tmp_name?: string, error?: int, size?: int} $file
     */
    public function store(array $file, string $directory, ?int $maximumBytes = null): string
    {
        $config = MediaStoragePolicy::config()['site'];
        if (!in_array($directory, $config['directories'], true)) {
            throw new RuntimeException('Diretório de mídia institucional inválido.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('Upload inválido.');
        }
        $maximumBytes = min((int) $config['max_bytes'], $maximumBytes ?? (int) $config['max_bytes']);
        if (($file['size'] ?? 0) > $maximumBytes) {
            throw new RuntimeException('O arquivo excede o limite de ' . max(1, (int) ceil($maximumBytes / 1048576)) . ' MB.');
        }

        $extension = mb_strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, $config['allowed_extensions'], true)) {
            throw new RuntimeException('Formato de imagem não permitido.');
        }

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon'];
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new RuntimeException('O conteúdo do arquivo não é uma imagem válida.');
        }

        $targetDirectory = $config['root'] . '/' . $directory;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Não foi possível preparar o diretório de uploads.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        if (!move_uploaded_file((string) $file['tmp_name'], $targetDirectory . '/' . $filename)) {
            throw new RuntimeException('Não foi possível salvar a imagem.');
        }

        return $directory . '/' . $filename;
    }

    /**
     * Valida, recorta pelo centro e converte uma imagem de categoria para WebP.
     *
     * @param array{name?: string, tmp_name?: string, error?: int, size?: int} $file
     */
    public function storeCategorySquare(array $file, int $size = 300, int $maximumBytes = 2097152): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('Não foi possível receber a imagem da categoria.');
        }
        if (($file['size'] ?? 0) > $maximumBytes) {
            throw new RuntimeException('A imagem da categoria deve ter no máximo 2 MB.');
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Envie a imagem da categoria em JPG, PNG ou WebP.');
        }
        $dimensions = @getimagesize($temporaryPath);
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || ($width * $height) > 40000000) {
            throw new RuntimeException('A imagem possui dimensões inválidas ou excessivas.');
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            throw new RuntimeException('O servidor não possui suporte para processar imagens WebP.');
        }

        $contents = file_get_contents($temporaryPath);
        $source = $contents === false ? false : @imagecreatefromstring($contents);
        if ($source === false) throw new RuntimeException('Não foi possível processar a imagem enviada.');

        $destination = imagecreatetruecolor($size, $size);
        if ($destination === false) {
            imagedestroy($source);
            throw new RuntimeException('Não foi possível preparar a imagem da categoria.');
        }
        imagealphablending($destination, false);
        imagesavealpha($destination, true);
        $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
        imagefill($destination, 0, 0, $transparent);
        $crop = min($width, $height);
        $sourceX = (int) floor(($width - $crop) / 2);
        $sourceY = (int) floor(($height - $crop) / 2);
        imagecopyresampled($destination, $source, 0, 0, $sourceX, $sourceY, $size, $size, $crop, $crop);

        $directory = 'platform/category';
        $config = MediaStoragePolicy::config()['site'];
        $targetDirectory = $config['root'] . '/' . $directory;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
            imagedestroy($source);
            imagedestroy($destination);
            throw new RuntimeException('Não foi possível preparar a pasta das categorias.');
        }
        $filename = bin2hex(random_bytes(16)) . '.webp';
        $stored = imagewebp($destination, $targetDirectory . '/' . $filename, 84);
        imagedestroy($source);
        imagedestroy($destination);
        if (!$stored) throw new RuntimeException('Não foi possível salvar a imagem da categoria.');

        return $directory . '/' . $filename;
    }

    public function deleteManaged(string $storagePath): bool
    {
        $config = MediaStoragePolicy::config()['site'];
        $storagePath = str_replace('\\', '/', ltrim($storagePath, '/'));
        if (!preg_match('#^(platform/(?:banners|category|favicon|logos|site))/[a-f0-9]{32}\.(?:jpe?g|png|webp|gif|ico)$#i', $storagePath, $matches)) return false;
        if (!in_array($matches[1], $config['directories'], true)) return false;
        $root = realpath((string) $config['root']);
        $target = realpath((string) $config['root'] . '/' . $storagePath);
        if ($root === false || $target === false || !str_starts_with(str_replace('\\', '/', $target), rtrim(str_replace('\\', '/', $root), '/') . '/')) return false;
        return is_file($target) && unlink($target);
    }
}
