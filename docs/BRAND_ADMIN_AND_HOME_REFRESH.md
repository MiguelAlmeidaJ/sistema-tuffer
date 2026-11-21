# Gestão de marcas e vitrine da home

Administração > Catálogo > Marcas (/admin/marcas): criar/editar nome, slug, status e capa.
A tabela brands já existia; a migração 051_add_brand_cover.sql adiciona apenas cover_image_path opcional.
Os produtos e pedidos vinculados à marca não são alterados.

O upload reutiliza SiteUploadService no diretório institucional platform/site com limite de 3 MB.
Capa anterior só é removida após o novo cadastro ser confirmado.

Na home, categorias sem imagem têm fundo neutro, sem letras. O carrossel exibe até 12 produtos, avança
um produto por clique, pausa ao interagir, respeita preferência de movimento reduzido e usa
indicador discreto. Os cartões Tuffer e Linda Flor exibem a capa cadastrada quando disponível.

PUBLICAÇÃO: execute git pull origin main e composer migrate (migração 051 é obrigatória antes de carregar a home).
Teste CRUD de marcas, upload/remoção de capa, filtros, categorias e carrossel em celular e desktop.
