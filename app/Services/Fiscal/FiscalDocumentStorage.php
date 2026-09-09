<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class FiscalDocumentStorage
{
    private readonly string $root;
    private readonly string $relativeBase;

    public function __construct(?FiscalConfiguration $configuration = null)
    {
        $configuration ??= new FiscalConfiguration();
        $relative = trim(str_replace('\\', '/', $configuration->privateStorage()), '/');
        if ($relative === '' || str_contains($relative, '..')) throw new RuntimeException('Caminho de armazenamento fiscal privado inválido.');
        $this->relativeBase = $relative;
        $this->root = dirname(__DIR__, 3);
    }

    /** @return array{xml_storage_path:?string,danfe_storage_path:?string} */
    public function store(int $sellerId, int $documentId, ?string $accessKey, ?string $xml, ?string $danfe): array
    {
        if ($sellerId < 1 || $documentId < 1) throw new RuntimeException('Documento fiscal inválido para armazenamento.');
        $suffix = $accessKey !== null && trim($accessKey) !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $accessKey) : 'document-' . $documentId;
        if (!is_string($suffix) || $suffix === '') $suffix = 'document-' . $documentId;
        $folderRelative = $this->relativeBase . '/seller-' . $sellerId . '/' . date('Y/m');
        $folder = $this->root . '/' . $folderRelative;
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) throw new RuntimeException('Não foi possível criar o diretório privado dos documentos fiscais.');
        return [
            'xml_storage_path' => $xml !== null && $xml !== '' ? $this->write($folder, $folderRelative, $suffix . '.xml', $xml) : null,
            'danfe_storage_path' => $danfe !== null && $danfe !== '' ? $this->write($folder, $folderRelative, $suffix . '.pdf', $danfe) : null,
        ];
    }

    private function write(string $folder, string $folderRelative, string $fileName, string $contents): string
    {
        $path = $folder . '/' . $fileName;
        if (file_put_contents($path, $contents, LOCK_EX) === false) throw new RuntimeException('Não foi possível armazenar o documento fiscal privado.');
        @chmod($path, 0600);
        return $folderRelative . '/' . $fileName;
    }
}
