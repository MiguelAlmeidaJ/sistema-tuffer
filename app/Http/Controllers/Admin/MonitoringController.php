<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Queue\JobQueue;

final class MonitoringController extends Controller
{
    public function index(): string
    {
        $pdo=Database::connection();
        $health=$pdo->query('SELECT h.* FROM service_health_checks h JOIN (SELECT service,MAX(id) id FROM service_health_checks GROUP BY service) latest ON latest.id=h.id ORDER BY h.service')->fetchAll();
        $alerts=$pdo->query("SELECT * FROM system_alerts ORDER BY status='open' DESC,severity='critical' DESC,last_occurred_at DESC LIMIT 50")->fetchAll();
        $backups=$pdo->query('SELECT * FROM backup_runs ORDER BY started_at DESC LIMIT 20')->fetchAll();
        $queueSummary=$pdo->query('SELECT status,COUNT(*) total FROM async_jobs GROUP BY status')->fetchAll();
        $jobs=$pdo->query('SELECT id,queue,job_type,status,attempts,max_attempts,available_at,last_error,created_at,completed_at FROM async_jobs ORDER BY id DESC LIMIT 50')->fetchAll();
        $logs=$pdo->query("SELECT id,request_id,level,channel,message,request_method,request_path,created_at FROM application_logs ORDER BY id DESC LIMIT 100")->fetchAll();
        return $this->page('admin/monitoring/index','layouts/admin',['pageTitle'=>'Monitoramento','health'=>$health,'alerts'=>$alerts,'backups'=>$backups,'queueSummary'=>$queueSummary,'jobs'=>$jobs,'logs'=>$logs]);
    }

    public function resolve(string $id): string
    {
        $statement=Database::connection()->prepare("UPDATE system_alerts SET status='resolved',resolved_at=NOW() WHERE id=?");$statement->execute([(int)$id]);
        Session::flash('success',$statement->rowCount()?'Alerta resolvido.':'Alerta não encontrado.');
        return Response::redirect('/admin/monitoramento');
    }

    public function retryJob(string $id): string
    {
        $retried = (new JobQueue())->retry((int) $id);
        Session::flash($retried ? 'success' : 'error', $retried ? 'Job reenfileirado.' : 'Somente jobs falhos podem ser reenfileirados.');
        return Response::redirect('/admin/monitoramento');
    }
}
