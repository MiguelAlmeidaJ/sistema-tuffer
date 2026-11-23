<?php
declare(strict_types=1);

/** Diagnóstico local e somente leitura: não conecta nem altera contas externas. */
use App\Services\Shipping\MelhorEnvioEnvironment;
use Dotenv\Dotenv;

$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$asaasEnv=strtolower(trim((string)($_ENV['ASAAS_ENV']??'sandbox')));
$shippingSandbox=MelhorEnvioEnvironment::sandbox();
$yes=static fn(string $key):bool=>filter_var($_ENV[$key]??false,FILTER_VALIDATE_BOOL);
$present=static fn(string $key):bool=>trim((string)($_ENV[$key]??''))!=='';
$asaasKey=$present('ASAAS_API_KEY');
$webhook=$present('ASAAS_WEBHOOK_TOKEN');
$shippingToken=$present('MELHOR_ENVIO_TOKEN')||$present('MELHOR_ENVIO_ACCESS_TOKEN');
$errors=[];

if(!in_array($asaasEnv,['sandbox','production'],true)) $errors[]='ASAAS_ENV invalido';
if(!$asaasKey||!$webhook) $errors[]='Credenciais Asaas ausentes';
if(!$shippingToken) $errors[]='Token Melhor Envio ausente';
try{$shippingUrl=MelhorEnvioEnvironment::baseUrl();}catch(Throwable $exception){$shippingUrl='INVALID';$errors[]='Configuracao do host Melhor Envio incompatível com sandbox';}
if($asaasEnv==='production'&&$yes('ASAAS_CHECKOUT_ENABLED')&&!$yes('ASAAS_PRODUCTION_APPROVED'))$errors[]='Checkout real habilitado mas sem aprovacao de producao';
if($asaasEnv==='production'&&$yes('ASAAS_PRODUCTION_APPROVED')&&!$yes('ASAAS_CHECKOUT_ENABLED'))$errors[]='Autorizacao de producao ativa com checkout desabilitado';
echo 'APPLICATION='.strtolower(trim((string)($_ENV['APP_ENV']??'local'))).' debug='.($yes('APP_DEBUG')?'on':'off').PHP_EOL;
echo 'ASAAS='.$asaasEnv.' checkout='.($yes('ASAAS_CHECKOUT_ENABLED')?'on':'off').' production_approved='.($yes('ASAAS_PRODUCTION_APPROVED')?'yes':'no').' credentials='.($asaasKey&&$webhook?'present':'missing').PHP_EOL;
echo 'SHIPPING='.($shippingSandbox?'sandbox':'production').' host='.$shippingUrl.' token='.($shippingToken?'present':'missing').PHP_EOL;
echo 'MODE_CHECK='.($errors?'WARNING':'OK').PHP_EOL;
foreach($errors as $warning)echo 'WARNING='.$warning.PHP_EOL;
echo 'NOTE=No API requests performed; pending orders and webhooks must be reconciled before switching environments.'.PHP_EOL;
exit($errors?1:0);
