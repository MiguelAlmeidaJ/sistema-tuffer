# Segurança

Não abra uma issue pública para relatar uma vulnerabilidade. Envie o relato de forma privada ao responsável técnico do projeto, incluindo impacto, passos de reprodução e, quando possível, uma sugestão de correção.

Nunca inclua no relato chaves de API, senhas, cookies, dados pessoais, dumps do banco ou conteúdo de arquivos `.env`. Revogue imediatamente qualquer credencial que tenha sido exposta.

Somente a versão mantida na branch `main` recebe correções de segurança. O responsável pelo ambiente deve manter PHP, MySQL e dependências do Composer atualizados, executar `composer audit` e aplicar as migrações antes de cada implantação.
