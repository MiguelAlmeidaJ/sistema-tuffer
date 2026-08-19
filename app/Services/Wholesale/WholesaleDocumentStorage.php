<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use RuntimeException;

final class WholesaleDocumentStorage
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /** @param array<string,mixed> $file @return array{storage_key:string,original_name:string,mime_type:string,file_size:int} */
    public function store(array $file, int $accountId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('O documento não foi recebido corretamente.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) throw new RuntimeException('Cada documento deve ter no máximo 10 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!is_string($mime) || !isset(self::ALLOWED[$mime])) throw new RuntimeException('Envie documentos em PDF, JPG ou PNG.');

        $directory = $this->root() . DIRECTORY_SEPARATOR . $accountId;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Não foi possível preparar o armazenamento privado.');
        $name = bin2hex(random_bytes(20)) . '.' . self::ALLOWED[$mime];
        $target = $directory . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) throw new RuntimeException('Não foi possível guardar o documento.');

        return [
            'storage_key' => $accountId . '/' . $name,
            'original_name' => mb_substr(basename((string) ($file['name'] ?? 'documento')), 0, 255),
            'mime_type' => $mime,
            'file_size' => $size,
        ];
    }

    public function path(string $storageKey): string
    {
        $key = str_replace('\\', '/', ltrim($storageKey, '/'));
        if (str_contains($key, '..') || !preg_match('#^\d+/[a-f0-9]{40}\.(pdf|jpg|png)$#', $key)) throw new RuntimeException('Documento inválido.');
        $path = $this->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
        if (!is_file($path)) throw new RuntimeException('Documento não encontrado.');
        return $path;
    }

    public function delete(string $storageKey): void
    {
        try { $path = $this->path($storageKey); } catch (RuntimeException) { return; }
        @unlink($path);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'wholesale';
    }
}
