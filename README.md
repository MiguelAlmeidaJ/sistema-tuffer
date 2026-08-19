# Tuffer

Base modular de um marketplace multivendedor em PHP 8.3+ e MySQL 8.

## Ambiente local

1. Copie `.env.example` para `.env`, gere `APP_KEY`, defina credenciais administrativas fortes e ajuste o banco.
2. Execute `composer install`.
3. Crie o banco indicado em `DB_DATABASE`.
4. Execute `composer setup` para aplicar as migrações e os dados iniciais.
5. Aponte o DocumentRoot do servidor para `public/`.

No Laragon, use o VirtualHost `http://tuffer-new.anoar`, cujo DocumentRoot aponta exclusivamente para `public/`. Não publique nem acesse a aplicação como subdiretório de um DocumentRoot que exponha a raiz do projeto.

## Comandos

```bash
composer migrate
composer seed
composer test
```

As chaves das integrações Pagar.me, Melhor Envio, Cloudinary e e-mail permanecem vazias no `.env` até serem configuradas. O `.env`, dumps SQL, logs, backups e documentos privados nunca devem ser versionados ou servidos pelo servidor web.

## Áreas da aplicação

- Loja pública: `/`
- Autenticação: `/entrar`, `/cadastro` e `/quero-vender`
- Cliente: `/minha-conta`
- Vendedor: `/vendedor`
- Administração: `/admin`

As três áreas privadas usam sessão, CSRF e autorização por perfil. Vendedores pendentes são direcionados ao onboarding até a aprovação.

## Login social

O login de clientes aceita Google e Facebook. Configure no `.env`:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
```

Cadastre nos provedores as URLs de retorno `${APP_URL}/auth/google/callback` e `${APP_URL}/auth/facebook/callback`. Identidades sociais são vinculadas pelo e-mail verificado apenas a contas de cliente; administradores, vendedores e operadores continuam entrando com e-mail e senha.

## Dados demonstrativos locais

O seed exige `ADMIN_EMAIL` e uma `ADMIN_PASSWORD` com pelo menos 12 caracteres. Dados demonstrativos só são criados quando `SEED_DEMO_DATA=true`; em produção, o comando também exige a liberação temporária `ALLOW_DATABASE_SEED=true`.

Quando os dados demonstrativos forem explicitamente habilitados, são criadas as contas abaixo. Nunca use essas credenciais fora de desenvolvimento:

- Administrador: `admin@tuffer.local` / `Admin@123!`
- Vendedor Tuffer Oficial: `vendedor@tuffer.local` / `Vendedor@123!`
- Cliente: `cliente@tuffer.local` / `Cliente@123!`
- Operador da Tuffer Oficial: `operador@tuffer.local` / `Operador@123!`

O seed também cria a loja Tuffer Oficial, oito categorias, um depósito e seis produtos com estoque.

## Política de mídias

- Imagens e vídeos de produtos: exclusivamente Cloudinary, registrados em `product_media` com `public_id` e `secure_url`.
- Banners, logos, favicon e imagens institucionais: `public/uploads/`.
- Os caminhos institucionais salvos no banco são relativos, por exemplo `platform/logos/tuffer-logo.svg`.

O diretório `public/uploads` bloqueia listagem e execução de scripts. Arquivos globais ficam em `uploads/platform/{logos,banners,favicon,site}` e cada loja recebe `uploads/stores/{nome-da-loja}-{id}`. Uploads institucionais passam por `SiteUploadService`, arquivos de lojas por `StoreUploadService` e respostas do Cloudinary são validadas por `ProductMediaRepository`.

## Gestão interna

### Administração

- Relatórios e financeiro da plataforma
- CRUD de lojas com inativação e exclusão protegida
- Categorias e subcategorias
- Tags
- Usuários e vínculo operacional com uma loja
- Configurações de identidade, banners, SEO, integrações e regras gerais

### Vendedor

- Seleção de loja no mesmo acesso
- Relatórios e financeiro isolados por loja
- CRUD de produtos, seleção/exclusão em massa e ajuste de estoque
- Central de importação e exportação de produtos com organização prévia, mapeamento CSV/SQL/XML e saída em planilha CSV, SQL ou XML
- CRUD de cupons
- Configuração da loja e do cadastro do vendedor
- Herança opcional de recebedor e frete de outra loja do mesmo vendedor

### Cliente

- Pedidos, endereços, favoritos, perfil e mensagens
- Chat direto com a loja, com histórico e leitura por participante

Cada registro de `stores` possui exatamente um `seller_id`; o mesmo vendedor pode possuir várias lojas. Operadores usam `store_users` e não se tornam proprietários da loja.

## Contas atacadistas

O acesso ao atacado é uma evolução da conta de cliente e fica em `wholesale_accounts`; `users.type` continua como `customer`. A jornada em `/minha-conta/atacado` reúne empresa, responsável, endereço, documentos privados, revisão e status. A equipe administrativa analisa em `/admin/atacadistas`, com a permissão `wholesale.manage`, histórico e notificações.

Os documentos ficam fora de `public`, em `storage/private/wholesale/{account_id}`, e só podem ser baixados por uma rota administrativa autorizada. O carrinho utiliza `cart_type` para manter varejo e atacado separados.

## Carrinho e checkout

O carrinho suporta cupons por loja, cálculo de totais e separação entre varejo e atacado. A cotação de entrega usa a API v2 do Melhor Envio por produto e mantém as modalidades separadas por loja. Configure `MELHOR_ENVIO_ACCESS_TOKEN` (ou `MELHOR_ENVIO_TOKEN`), `MELHOR_ENVIO_BASE_URL`, `MELHOR_ENVIO_SANDBOX` e `MELHOR_ENVIO_USER_AGENT` no ambiente para habilitar valores e prazos reais. O CEP, a cidade e a UF de origem podem ser atualizados nas configurações da loja.

O checkout apresenta endereço, entrega e pagamento em etapas progressivas. A cobrança permanece bloqueada até que `PAGARME_SECRET_KEY` esteja configurada; dados brutos de cartão não são coletados pela aplicação.

## Produção

Antes de publicar, siga o [checklist de produção](docs/PRODUCTION_CHECKLIST.md) e as instruções de [operação](docs/OPERATIONS.md). O repositório inclui uma rotina de CI para validar metadados do Composer, sintaxe PHP, migrações, testes e vulnerabilidades conhecidas das dependências.
