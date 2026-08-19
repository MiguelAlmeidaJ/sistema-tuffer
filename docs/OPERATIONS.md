# Operação da Tuffer

## Monitoramento

Execute a cada cinco minutos:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\tuffer-new\scripts\monitor-health.php
```

O comando verifica banco, armazenamento, backup recente e conectividade SMTP. Resultados ficam em `service_health_checks`; falhas geram `system_alerts` e aviso por e-mail com deduplicação de uma hora.

O endpoint `/health` pode ser usado por um monitor externo. Considere saudável apenas HTTP 200 e `status: ok`.

## Fila de jobs

E-mails, criação de links de pagamento e processamento de webhooks são executados pela fila persistida em `async_jobs`. O worker precisa permanecer ativo; sem ele, pedidos continuam salvos com segurança, mas o link de pagamento e os e-mails ficam aguardando.

Para desenvolvimento ou para uma tarefa agendada a cada minuto:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\tuffer-new\scripts\queue-worker.php --once --max-jobs=100
```

Para um processo contínuo supervisionado:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\tuffer-new\scripts\queue-worker.php --sleep=2 --max-runtime=3600
```

Em produção, reinicie automaticamente o processo contínuo com Supervisor, systemd, NSSM ou serviço equivalente. Jobs falhos usam backoff exponencial, jobs travados há mais de 15 minutos são recuperados e o painel `/admin/monitoramento` mostra estados e tentativas. O monitor operacional alerta quando houver jobs falhos, travados ou atrasados.

## Pedidos com pagamento expirado

Execute a cada cinco minutos para cancelar localmente pagamentos vencidos e liberar estoque e cupons reservados:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\tuffer-new\scripts\expire-pending-orders.php
```

O processo é idempotente e atua somente em pedidos ainda pendentes cujo pagamento ultrapassou `expires_at`.

## Backups

Execute diariamente, de preferência fora do horário de pico:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\tuffer-new\scripts\backup-database.php
```

Os arquivos compactados são gravados fora da pasta pública, em `storage/backups`. A retenção padrão é de 14 dias e cada execução registra tamanho e SHA-256 em `backup_runs`.

No Windows, cadastre os dois comandos no Agendador de Tarefas usando a conta de serviço da aplicação. Em produção Linux, use cron ou timers do systemd. Restrinja acesso ao diretório de backups e mantenha uma cópia externa criptografada.

## Logs e alertas

Logs estruturados são gravados em `storage/logs/application-AAAA-MM-DD.jsonl` e centralizados em `application_logs`. O painel fica em `/admin/monitoramento`. Campos com nomes associados a senhas, tokens, cookies, cartões e autorização são removidos automaticamente.

Configure `ALERT_EMAIL`, retenção e caminhos no `.env`. Nunca armazene o `.env` ou os backups no repositório.
