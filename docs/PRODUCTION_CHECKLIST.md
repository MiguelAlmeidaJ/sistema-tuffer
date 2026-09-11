# Checklist de produção

## Infraestrutura

- [ ] Usar PHP 8.3+ com as extensões declaradas no `composer.json` e MySQL 8.
- [ ] Apontar o DocumentRoot exclusivamente para `public/` e desabilitar listagem de diretórios.
- [ ] Publicar somente por HTTPS e redirecionar HTTP para HTTPS no proxy/servidor web.
- [ ] Definir `TRUST_PROXY_HEADERS=true` somente quando a aplicação estiver atrás de um proxy confiável que sobrescreva esses cabeçalhos.
- [ ] Restringir o banco e o diretório `storage/` à conta da aplicação.

## Ambiente e dados

- [ ] Criar um `.env` de produção fora do versionamento, com `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...` e `APP_KEY` aleatória forte.
- [ ] Usar usuário MySQL exclusivo, senha forte e privilégios mínimos; nunca `root`.
- [ ] Definir `ADMIN_EMAIL` e `ADMIN_PASSWORD` próprios e remover/bloquear todas as contas demonstrativas.
- [ ] Manter `ALLOW_DATABASE_SEED=false` e `SEED_DEMO_DATA=false` depois da instalação.
- [ ] Preencher razão social, CNPJ, endereço, contatos e textos jurídicos reais no painel.
- [ ] Executar `composer install --no-dev --classmap-authoritative` e `php database/migrate.php`.

## Integrações

- [ ] Configurar e validar SMTP, Pagar.me, Melhor Envio e Cloudinary separadamente.
- [ ] Cadastrar o webhook HTTPS da Pagar.me e validar o segredo de assinatura.
- [ ] Confirmar que a conta está habilitada como PSP/Marketplace, identificar `PAGARME_PLATFORM_RECIPIENT_ID` e habilitar os eventos necessários de recipient, pedido e cobrança.
- [ ] Validar em sandbox a criação do recebedor, a geração do link KYC e a transição até recipient/KYC elegíveis.
- [ ] Configurar `PAGARME_PLATFORM_RECIPIENT_ID`, validar a afiliação PSP/Marketplace e executar split para dois ou mais vendedores.
- [ ] Manter `PAGARME_ORDERS_PIX_ENABLED=false` e `PAGARME_SPLIT_ENABLED=false` até concluir a homologação.
- [ ] Confirmar que a elegibilidade de cada seller é liberada automaticamente pelo onboarding e pelos estados persistidos de pagamento; não manter IDs de sellers no `.env`.
- [ ] Para cartão direto, configurar a public key da Pagar.me e validar tokenização no navegador sem enviar PAN/CVV ao backend da Tuffer.
- [ ] Habilitar eventos de pedido, cobrança e chargeback e confirmar reprocessamento/idempotência no mesmo pedido.
- [ ] Validar `gateway_id` alfanumérico, expiração Pix e execução periódica das rotinas operacionais.
- [ ] Ativar cada integração no painel somente depois do teste correspondente.
- [ ] Confirmar que nenhuma chave de produção está presente em máquinas locais ou no histórico Git.

## Processos operacionais

- [ ] Manter `scripts/queue-worker.php` supervisionado continuamente.
- [ ] Agendar `scripts/expire-pending-orders.php` e `scripts/monitor-health.php` conforme o volume da operação.
- [ ] Agendar `scripts/sync-pagarme-recipients.php` para reconciliar bloqueios e aprovações de recebedores.
- [ ] Agendar o reconciliador Pagar.me para recuperar estados remotos e detectar divergências.
- [ ] Agendar `scripts/backup-database.php` diariamente e testar restauração em ambiente isolado.
- [ ] Configurar monitor externo para `/health`, alertas e rotação/retenção de logs.

## Liberação

- [ ] Executar `composer validate --strict`, testes, verificação de sintaxe e `composer audit`.
- [ ] Verificar cadastro, login, recuperação de senha, compra, webhook, cancelamento, estoque, cupom, frete e e-mail em homologação.
- [ ] Validar um seller recém-aprovado no onboarding sem qualquer alteração manual no `.env`.
- [ ] Ativar modo de manutenção durante migrações que alterem esquema ou dados.
- [ ] Fazer smoke test após a implantação e manter um plano de rollback com backup válido.
