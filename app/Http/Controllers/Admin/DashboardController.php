<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Http\Controllers\Controller;

final class DashboardController extends Controller
{
    public function index(): string
    {
        $pdo = Database::connection();
        $stats = $pdo->query("SELECT (SELECT COUNT(*) FROM sellers) sellers, (SELECT COUNT(*) FROM sellers WHERE status IN ('pending','under_review')) pending_sellers, (SELECT COUNT(*) FROM wholesale_accounts WHERE status IN ('pending','under_review')) pending_wholesale, (SELECT COUNT(*) FROM products) products, (SELECT COUNT(*) FROM orders) orders, (SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE status IN ('paid','processing','completed')) revenue, (SELECT COUNT(*) FROM system_alerts WHERE status='open') open_alerts")->fetch();
        $daily = $pdo->query("SELECT DATE(created_at) day,COUNT(*) orders,COALESCE(SUM(CASE WHEN status IN ('paid','processing','completed') THEN grand_total ELSE 0 END),0) revenue FROM orders WHERE created_at>=CURDATE()-INTERVAL 13 DAY GROUP BY DATE(created_at)")->fetchAll();
        return $this->page('admin/dashboard', 'layouts/admin', ['pageTitle' => 'Dashboard', 'stats' => $stats, 'chart' => $this->chart($daily)]);
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function chart(array $rows): array
    {
        $indexed=[];foreach($rows as $row)$indexed[(string)$row['day']]=$row;$chart=[];
        for($offset=13;$offset>=0;$offset--){$day=date('Y-m-d',strtotime("-{$offset} days"));$row=$indexed[$day]??[];$chart[]=['day'=>$day,'label'=>date('d/m',strtotime($day)),'orders'=>(int)($row['orders']??0),'value'=>(float)($row['revenue']??0)];}
        return $chart;
    }
}
