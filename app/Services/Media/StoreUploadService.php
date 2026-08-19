<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Support\Str;
use finfo;
use RuntimeException;

final class StoreUploadService
{
    /**
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
     * @param array{id:mixed,name:mixed} $store
     */
    public function store(array $file, array $store, string $type): string
    {
        if (!in_array($type, ['logo', 'banner'], true)) {
            throw new RuntimeException('Tipo de imagem da loja inválido.');
        }

        $config = MediaStoragePolicy::config()['stores'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('O arquivo enviado é inválido.');
        }

        $limit = $type === 'logo' ? (int) $config['logo_max_bytes'] : (int) $config['banner_max_bytes'];
        if ((int) ($file['size'] ?? 0) > $limit) {
            throw new RuntimeException($type === 'logo' ? 'A logo deve ter no máximo 5 MB.' : 'O banner deve ter no máximo 10 MB.');
        }

        $extension = mb_strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, $config['allowed_extensions'], true)) {
            throw new RuntimeException('Use uma imagem JPG, PNG ou WebP.');
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if (!in_array($mimeType, $config['allowed_mime_types'], true) || @getimagesize($temporaryPath) === false) {
            throw new RuntimeException('O conteúdo enviado não é uma imagem válida.');
        }

        $folder = $this->folder($store);
        $targetDirectory = $this->ensureDirectory($store);

        $filename = $type . '-' . bin2hex(random_bytes(10)) . '.' . $extension;
        if (!move_uploaded_file($temporaryPath, $targetDirectory . DIRECTORY_SEPARATOR . $filename)) {
            throw new RuntimeException('Não foi possível salvar a imagem da loja.');
        }

        return 'stores/' . $folder . '/' . $filename;
    }

    public function deleteOwned(?string $path, int $storeId): void
    {
        if (!is_string($path) || !preg_match('#^stores/[^/]+-' . preg_quote((string) $storeId, '#') . '/[^/]+$#', $path)) {
            return;
        }

        $root = realpath(dirname(__DIR__, 3) . '/public/uploads/stores');
        $target = realpath(dirname(__DIR__, 3) . '/public/uploads/' . $path);
        if ($root !== false && $target !== false && str_starts_with($target, $root . DIRECTORY_SEPARATOR) && is_file($target)) {
            unlink($target);
        }
    }

    /** @param array{id:mixed,name:mixed,slug?:mixed} $store */
    public function ensureDirectory(array $store): string
    {
        $root = rtrim((string) MediaStoragePolicy::config()['stores']['root'], '/\\');
        $targetDirectory = $root . DIRECTORY_SEPARATOR . $this->folder($store);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Não foi possível preparar a pasta da loja.');
        }
        return $targetDirectory;
    }

    /** @param array{id:mixed,name:mixed} $store */
    public function folder(array $store): string
    {
        $name = Str::slug(trim((string) ($store['slug'] ?? $store['name'] ?? 'loja'))) ?: 'loja';
        return $name . '-' . max(1, (int) ($store['id'] ?? 0));
    }
}
