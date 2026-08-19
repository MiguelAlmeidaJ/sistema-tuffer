<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Mail\QueuedMailService;
use Throwable;

final class AlertService
{
    public function raise(string $source, string $message, string $severity = 'warning'): void
    {
        $severity = $severity === 'critical' ? 'critical' : 'warning';
        $fingerprint = hash('sha256', $source . '|' . $message);
        Logger::warning($message, ['source' => $source, 'severity' => $severity], 'monitoring');
        try {
            $pdo=Database::connection();
            $pdo->prepare("INSERT INTO system_alerts(fingerprint,severity,source,message,status) VALUES(?,?,?,?,'open') ON DUPLICATE KEY UPDATE severity=VALUES(severity),status='open',occurrence_count=occurrence_count+1,last_occurred_at=NOW(),resolved_at=NULL")->execute([$fingerprint,$severity,mb_substr($source,0,80),mb_substr($message,0,1000)]);
            $statement=$pdo->prepare('SELECT id,notified_at FROM system_alerts WHERE fingerprint=?');$statement->execute([$fingerprint]);$alert=$statement->fetch();
            if(!$alert||($alert['notified_at']&&strtotime((string)$alert['notified_at'])>time()-3600))return;
            $recipient=(string)($_ENV['ALERT_EMAIL']??$_ENV['ADMIN_EMAIL']??'');
            if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))return;
            (new QueuedMailService())->enqueue('Operação Tuffer',$recipient,'['.strtoupper($severity).'] Alerta Tuffer: '.$source,$message."\n\nData: ".date('d/m/Y H:i:s'),'system_alert','system_alert',(int)$alert['id'],'system-alert:'.$alert['id'].':'.date('YmdH'));
            $pdo->prepare('UPDATE system_alerts SET notified_at=NOW() WHERE id=?')->execute([$alert['id']]);
        } catch(Throwable $exception) { Logger::exception($exception, ['alert_source'=>$source], 'monitoring'); }
    }

    public function resolve(string $source, string $message): void
    {
        try { Database::connection()->prepare("UPDATE system_alerts SET status='resolved',resolved_at=NOW() WHERE fingerprint=? AND status='open'")->execute([hash('sha256',$source.'|'.$message)]); } catch(Throwable) {}
    }
}
