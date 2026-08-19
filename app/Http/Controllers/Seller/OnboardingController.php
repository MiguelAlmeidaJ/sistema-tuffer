<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Http\Controllers\Controller;

final class OnboardingController extends Controller
{
    public function index(): string
    {
        $statement = Database::connection()->prepare('SELECT trade_name,status,created_at FROM sellers WHERE user_id=?');
        $statement->execute([Auth::id()]);
        return $this->page('seller/onboarding/review', 'layouts/seller', ['pageTitle' => 'Análise cadastral', 'seller' => $statement->fetch()]);
    }
}
