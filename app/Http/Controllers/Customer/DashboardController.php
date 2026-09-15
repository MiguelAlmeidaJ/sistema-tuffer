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
        $pdo = Database::connection();
        $userId = Auth::id();

        $statement = $pdo->prepare(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(status IN ('pending','pending_payment','paid','processing')),0) ongoing,
                    COALESCE(SUM(status='completed'),0) delivered,
                    COALESCE(SUM(status='cancelled'),0) cancelled
             FROM orders WHERE user_id=?"
        );
        $statement->execute([$userId]);
        $stats = $statement->fetch() ?: [];

        $favoriteStatement = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id=?');
        $favoriteStatement->execute([$userId]);
        $stats['favorites'] = (int) ($favoriteStatement->fetchColumn() ?: 0);

        $latestStatement = $pdo->prepare(
            'SELECT code,grand_total,status,created_at,order_type
             FROM orders WHERE user_id=?
             ORDER BY created_at DESC,id DESC LIMIT 1'
        );
        $latestStatement->execute([$userId]);
        $latestOrder = $latestStatement->fetch() ?: null;

        return $this->page('customer/dashboard', 'layouts/customer', [
            'pageTitle' => 'Visão geral',
            'stats' => $stats,
            'latestOrder' => $latestOrder,
        ]);
    }
}
