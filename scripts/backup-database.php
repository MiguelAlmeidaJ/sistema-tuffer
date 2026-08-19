<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Logger;
use App\Services\Monitoring\AlertService;
use Dotenv\Dotenv;

$root=dirname(__DIR__);require $root.'/vendor/autoload.php';Dotenv::createImmutable($root)->safeLoad();date_default_timezone_set('America/Sao_Paulo');Logger::register();
$pdo=Database::connection();$run=$pdo->prepare("INSERT INTO backup_runs(status) VALUES('running')");$run->execute();$runId=(int)$pdo->lastInsertId();
$directory=rtrim((string)($_ENV['BACKUP_PATH']??($root.'/storage/backups')),'/\\');
$isWindowsPath=strlen($directory)>2&&ctype_alpha($directory[0])&&$directory[1]===':'&&in_array($directory[2],['\\','/'],true);
$directory=(str_starts_with($directory,'/')||$isWindowsPath)?$directory:$root.DIRECTORY_SEPARATOR.$directory;
$retention=max(1,(int)($_ENV['BACKUP_RETENTION_DAYS']??14));
$database=(string)($_ENV['DB_DATABASE']??'tuffer_new');$safeDatabase=preg_replace('/[^A-Za-z0-9_-]/','_',$database)?:'database';$filename=$safeDatabase.'-'.date('Ymd-His').'.sql.gz';$target=$directory.DIRECTORY_SEPARATOR.$filename;

try{
    if(!is_dir($directory)&&!mkdir($directory,0770,true))throw new RuntimeException('Não foi possível criar o diretório de backups.');
    $resolved=realpath($directory);if(!$resolved||in_array(rtrim(str_replace('\\','/',$resolved),'/'),['','C:'],true))throw new RuntimeException('Diretório de backup inseguro.');
    $binary=trim((string)($_ENV['MYSQLDUMP_BINARY']??''));
    if($binary===''||!is_file($binary)){$candidates=glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe')?:[];rsort($candidates);$binary=$candidates[0]??'mysqldump';}
    $command=[$binary,'--host='.(string)($_ENV['DB_HOST']??'127.0.0.1'),'--port='.(string)($_ENV['DB_PORT']??'3306'),'--user='.(string)($_ENV['DB_USERNAME']??'root'),'--single-transaction','--quick','--routines','--events','--triggers','--default-character-set=utf8mb4',$database];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,array_merge(getenv()?:[],['MYSQL_PWD'=>(string)($_ENV['DB_PASSWORD']??'')]));
    if(!is_resource($process))throw new RuntimeException('Não foi possível iniciar o mysqldump.');fclose($pipes[0]);$gzip=gzopen($target,'wb9');if(!$gzip)throw new RuntimeException('Não foi possível criar o arquivo compactado.');
    while(!feof($pipes[1])){$chunk=fread($pipes[1],1048576);if($chunk!==false&&$chunk!=='')gzwrite($gzip,$chunk);}fclose($pipes[1]);gzclose($gzip);$error=trim(stream_get_contents($pipes[2])?:'');fclose($pipes[2]);$exit=proc_close($process);
    if($exit!==0)throw new RuntimeException('mysqldump falhou: '.mb_substr($error?:'código '.$exit,0,500));
    $size=filesize($target);if($size===false||$size<100)throw new RuntimeException('O arquivo de backup ficou vazio.');$checksum=hash_file('sha256',$target);
    $pdo->prepare("UPDATE backup_runs SET filename=?,status='completed',size_bytes=?,checksum_sha256=?,completed_at=NOW() WHERE id=?")->execute([$filename,$size,$checksum,$runId]);
    $pdo->prepare("UPDATE system_alerts SET status='resolved',resolved_at=NOW() WHERE source='database_backup' AND status='open'")->execute();
    foreach(glob($directory.DIRECTORY_SEPARATOR.'*.sql.gz')?:[] as $file)if(is_file($file)&&filemtime($file)<time()-($retention*86400))@unlink($file);
    Logger::info('Backup do banco concluído.',['filename'=>$filename,'size_bytes'=>$size],'backup');echo 'BACKUP=COMPLETED file='.$filename.' bytes='.$size.PHP_EOL;
}catch(Throwable $exception){if(is_file($target))@unlink($target);$pdo->prepare("UPDATE backup_runs SET status='failed',error_message=?,completed_at=NOW() WHERE id=?")->execute([mb_substr($exception->getMessage(),0,1000),$runId]);(new AlertService())->raise('database_backup','Falha no backup do banco: '.$exception->getMessage(),'critical');Logger::exception($exception,[],'backup');fwrite(STDERR,'BACKUP=FAILED ERROR='.$exception->getMessage().PHP_EOL);exit(1);}
