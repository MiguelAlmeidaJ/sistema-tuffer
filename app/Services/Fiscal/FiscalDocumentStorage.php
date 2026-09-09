<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use DOMDocument;
use RuntimeException;

final class FiscalDocumentStorage
{
    private const MAX_XML_BYTES = 5 * 1024 * 1024;
    private const MAX_DANFE_BYTES = 15 * 1024 * 1024;

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
        if ($xml !== null && $xml !== '') $this->validateXml($xml, $accessKey);
        if ($danfe !== null && $danfe !== '') $this->validatePdf($danfe);

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

    /** @param array<string,mixed>|null $file */
    public function readUploaded(?array $file, string $kind): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('O arquivo fiscal não foi recebido corretamente.');
        }
        if (!in_array($kind, ['xml', 'danfe'], true)) throw new RuntimeException('Tipo de arquivo fiscal inválido.');
        $size = (int) ($file['size'] ?? 0);
        $limit = $kind === 'xml' ? self::MAX_XML_BYTES : self::MAX_DANFE_BYTES;
        if ($size < 1 || $size > $limit) throw new RuntimeException($kind === 'xml' ? 'O XML deve ter no máximo 5 MB.' : 'O DANFE deve ter no máximo 15 MB.');
        $contents = file_get_contents((string) $file['tmp_name']);
        if (!is_string($contents) || $contents === '') throw new RuntimeException('Não foi possível ler o arquivo fiscal enviado.');
        if ($kind === 'xml') $this->validateXml($contents, null); else $this->validatePdf($contents);
        return $contents;
    }

    public function path(string $relativePath): string
    {
        $relative = str_replace('\\', '/', ltrim($relativePath, '/'));
        if ($relative === '' || str_contains($relative, '..') || !str_starts_with($relative, $this->relativeBase . '/')) {
            throw new RuntimeException('Caminho fiscal privado inválido.');
        }
        $candidate = $this->root . '/' . $relative;
        $real = realpath($candidate);
        $base = realpath($this->root . '/' . $this->relativeBase);
        if ($real === false || $base === false || !is_file($real) || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Documento fiscal não encontrado.');
        }
        return $real;
    }

    private function validateXml(string $xml, ?string $accessKey): void
    {
        if (strlen($xml) < 20 || strlen($xml) > self::MAX_XML_BYTES) throw new RuntimeException('XML fiscal vazio ou acima do limite de 5 MB.');
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
            if (!$loaded || $document->doctype !== null) throw new RuntimeException('O XML fiscal enviado é inválido.');
            $infNFe = $document->getElementsByTagName('infNFe')->item(0);
            if ($infNFe === null) throw new RuntimeException('O XML enviado não contém uma NF-e reconhecível.');
            $id = (string) $infNFe->attributes?->getNamedItem('Id')?->nodeValue;
            $xmlKey = str_starts_with($id, 'NFe') ? substr($id, 3) : '';
            if ($xmlKey !== '' && !preg_match('/^\d{44}$/', $xmlKey)) throw new RuntimeException('A chave encontrada no XML da NF-e é inválida.');
            $normalizedKey = preg_replace('/\D+/', '', (string) $accessKey) ?? '';
            if ($normalizedKey !== '' && $xmlKey !== '' && !hash_equals($normalizedKey, $xmlKey)) {
                throw new RuntimeException('A chave da NF-e informada não corresponde ao XML enviado.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function validatePdf(string $pdf): void
    {
        if (strlen($pdf) < 8 || strlen($pdf) > self::MAX_DANFE_BYTES || !str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('O DANFE deve ser um PDF válido de até 15 MB.');
        }
    }

    private function write(string $folder, string $folderRelative, string $fileName, string $contents): string
    {
        $path = $folder . '/' . $fileName;
        if (file_put_contents($path, $contents, LOCK_EX) === false) throw new RuntimeException('Não foi possível armazenar o documento fiscal privado.');
        @chmod($path, 0600);
        return $folderRelative . '/' . $fileName;
    }
}
