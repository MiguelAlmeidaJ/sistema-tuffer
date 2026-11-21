# Tuffer — entrada obrigatória e login com Google

## Experiência pública
- Apenas visitantes sem sessão recebem uma caixa de autenticação após **5 segundos** nas páginas que usam `layouts/public`.
- O diálogo é modal (bloqueia interação com o conteúdo por trás), não tem minimizar/fechar e ignora Escape; quem estiver autenticado não vê a caixa.
- O formulário faz POST para `/entrar` com CSRF e preserva a página original no campo `redirect`. Links levam a `/cadastro` e ao Google; após a autenticação bem-sucedida o cliente volta para a página visitada.
- O Google só aparece se as credenciais OAuth estiverem configuradas. O fluxo de e-mail e senha continua disponível.
- Não se aplica às telas de autenticação, painel administrativo nem central do vendedor.

## Configuração Google OAuth 2.0
1. No Google Cloud Console, crie ou selecione um projeto e configure a tela de consentimento OAuth (nome da aplicação, usuários de teste enquanto estiver em modo de testes).
2. Em Credenciais, crie um **OAuth client ID de aplicação Web**.
3. Adicione a origem JavaScript autorizada `https://tuffer.com.br` se solicitada pela configuração.
4. Adicione exatamente `https://tuffer.com.br/auth/google/callback` como **URI de redirecionamento autorizado** (esquema e domínio precisam coincidir com o `APP_URL` público).
5. No arquivo privado `.env` do servidor, configure:
   ```dotenv
   GOOGLE_CLIENT_ID=seu_client_id
   GOOGLE_CLIENT_SECRET=seu_client_secret
   ```
6. O sistema usa o fluxo Authorization Code OAuth no servidor, com `state` de uso único, validação do e-mail verificado do Google e vínculo do usuário com `social_identities`. A migração 020 já fornece a tabela.
7. Em homologação, teste o cadastro Google de um cliente novo, login Google em cliente existente e retorno seguro para a página original. Não cole o Client Secret em mensagens ou repositório.

**Aviso de UX:** um diálogo obrigatório após 5 segundos pode aumentar rejeição, prejudicar acessibilidade e reduzir a descoberta orgânica de produtos. Avalie uma versão dispensável com lembrete no checkout antes de usar amplamente em produção.
