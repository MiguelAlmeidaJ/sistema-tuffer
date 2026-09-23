<?php

declare(strict_types=1);

use App\Core\Logger;
use App\Services\Queue\JobProcessor;
use App\Services\Queue\JobQueue;
use App\Services\Shipping\ShipmentTrackingScheduler;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
date_default_timezone_set('America/Sao_Paulo');
Logger::register();

$options = getopt('', ['once','sleep::','max-jobs::','max-runtime::','queues::']);
$once = array_key_exists('once', $options);
$sleep = max(1, (int) ($options['sleep'] ?? 2));
$maximumJobs = max(1, (int) ($options['max-jobs'] ?? ($once ? 100 : PHP_INT_MAX)));
$maximumRuntime = max(1, (int) ($options['max-runtime'] ?? ($once ? 60 : 3600)));
$queues = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($options['queues'] ?? 'payment,webhook,fiscal,mail,shipping,default'))
)));

$workerId = substr(gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4)), 0, 100);
$queue = new JobQueue();
$processor = new JobProcessor();
$trackingScheduler = new ShipmentTrackingScheduler();
$nextTrackingScheduleAt = 0;
$started = time();
$processed = 0;
$failures = 0;

while ($processed < $maximumJobs && time() - $started < $maximumRuntime) {
    if (in_array('shipping', $queues, true) && time() >= $nextTrackingScheduleAt) {
        try {
            $scheduled = $trackingScheduler->enqueueDue();
            if ($scheduled > 0) {
                Logger::info(
                    'Rastreamentos agendados automaticamente.',
                    ['shipments' => $scheduled],
                    'shipping'
                );
            }
        } catch (Throwable $exception) {
            Logger::exception($exception, ['operation' => 'schedule_tracking_sync'], 'shipping');
        }
        $nextTrackingScheduleAt = time() + 60;
    }

    $job = $queue->reserve($queues, $workerId);
    if ($job === null) {
        if ($once) {
            break;
        }
        sleep($sleep);
        continue;
    }

    try {
        $processor->process($job);
        $queue->complete((int) $job['id']);
        Logger::info(
            'Job concluído.',
            ['job_id' => $job['id'], 'job_type' => $job['job_type'], 'attempt' => $job['attempts']],
            'queue'
        );
    } catch (Throwable $exception) {
        $failures++;
        $queue->fail($job, $exception);
        Logger::exception(
            $exception,
            ['job_id' => $job['id'], 'job_type' => $job['job_type'], 'attempt' => $job['attempts']],
            'queue'
        );
    }
    $processed++;
}

echo "QUEUE_WORKER processed={$processed} failures={$failures}\n";
exit($failures > 0 ? 1 : 0);
