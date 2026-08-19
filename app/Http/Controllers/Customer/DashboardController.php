<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;
use App\Core\Database;
use App\Http\Controllers\Controller;

final class DashboardController extends Controller
{
    public function index(): string
    {
        $statement = Database::connection()->prepare("SELECT COUNT(*) total, SUM(status IN ('paid','processing')) ongoing, SUM(status='completed') delivered FROM orders WHERE user_id=?");
        $statement->execute([Auth::id()]);
        return $this->page('customer/dashboard', 'layouts/customer', ['pageTitle' => 'Visão geral', 'stats' => $statement->fetch()]);
    }
}
