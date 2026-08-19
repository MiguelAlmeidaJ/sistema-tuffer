<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\View;

abstract class Controller
{
    /** @param array<string, mixed> $data */
    protected function page(string $view, string $layout, array $data = []): string
    {
        return View::page($view, $layout, $data);
    }
}
