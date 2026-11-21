<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Response;

/** Compatibilidade para links antigos de lojas, agora substituídas pelo catálogo único. */
final class StoreController
{
    public function index(): string
    {
        return Response::redirect('/produtos', 301);
    }

    public function show(string $slug): string
    {
        return Response::redirect('/produtos', 301);
    }
}
