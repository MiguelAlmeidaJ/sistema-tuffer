<?php

declare(strict_types=1);

namespace App\Services\Finance;

use RuntimeException;

final class FinancialProofStorage
{
    /** @param array<string,mixed> $file */
    public function store(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 5_242_880) {
            throw new RuntimeException('Comprovante inválido ou maior que 5 MB.');
        }
        $temporary = (string) ($file['tmp_name'] ?? '');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($extensions[$mime])) throw new RuntimeException('Envie comprovante PDF, JPG ou PNG.');
        $directory = dirname(__DIR__, 3) . '/storage/app/financial-proofs';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento privado.');
        }
        $name = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($temporary, $directory . '/' . $name)) {
            throw new RuntimeException('Não foi possível armazenar o comprovante.');
        }
        return 'financial-proofs/' . $name;
    }

    public function resolve(string $relativePath): string
    {
        if (preg_match('#^financial-proofs/[a-f0-9]{48}\.(pdf|jpg|png)$#', $relativePath) !== 1) {
            throw new RuntimeException('Comprovante inválido.');
        }
        $root = realpath(dirname(__DIR__, 3) . '/storage/app/financial-proofs');
        $path = realpath(dirname(__DIR__, 3) . '/storage/app/' . $relativePath);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Comprovante não encontrado.');
        }
        return $path;
    }

    public function delete(?string $relativePath): void
    {
        if (!$relativePath) return;
        try {
            $path = $this->resolve($relativePath);
            if (is_file($path)) unlink($path);
        } catch (RuntimeException) {
            // Caminhos inválidos nunca são seguidos ou removidos.
        }
    }
}
