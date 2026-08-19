<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;
use App\Core\Database;
use App\Http\Controllers\Controller;

final class NotificationController extends Controller
{
    public function index(): string
    {
        $statement = Database::connection()->prepare('SELECT * FROM user_notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
        $statement->execute([Auth::id()]);
        $notifications = $statement->fetchAll();
        Database::connection()->prepare('UPDATE user_notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=?')->execute([Auth::id()]);
        return $this->page('customer/notifications/index', 'layouts/customer', ['pageTitle' => 'Notificações', 'notifications' => $notifications]);
    }
}
