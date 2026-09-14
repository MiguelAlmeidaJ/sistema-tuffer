<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Payments\CardInstallmentPricingService;

final class CardInstallmentPricingController extends Controller
{
    public function show(): string
    {
        header('Content-Type: application/json; charset=UTF-8');
        return json_encode(
            (new CardInstallmentPricingService())->configuration(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }
}
