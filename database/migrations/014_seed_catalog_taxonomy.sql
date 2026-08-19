SET @taxonomy_type_exists=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tags' AND column_name='type');
SET @taxonomy_sql=IF(@taxonomy_type_exists=0,"ALTER TABLE tags ADD COLUMN type ENUM('material','feature','style','occasion','commercial','audience','collection','administrative') NOT NULL DEFAULT 'feature' AFTER slug",'SELECT 1');
PREPARE taxonomy_stmt FROM @taxonomy_sql; EXECUTE taxonomy_stmt; DEALLOCATE PREPARE taxonomy_stmt;
SET @taxonomy_access_exists=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tags' AND column_name='is_admin_only');
SET @taxonomy_sql=IF(@taxonomy_access_exists=0,'ALTER TABLE tags ADD COLUMN is_admin_only BOOLEAN NOT NULL DEFAULT FALSE AFTER type','SELECT 1');
PREPARE taxonomy_stmt FROM @taxonomy_sql; EXECUTE taxonomy_stmt; DEALLOCATE PREPARE taxonomy_stmt;
SET @taxonomy_index_exists=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tags' AND index_name='idx_tags_type_access');
SET @taxonomy_sql=IF(@taxonomy_index_exists=0,'ALTER TABLE tags ADD INDEX idx_tags_type_access (type, is_admin_only, status)','SELECT 1');
PREPARE taxonomy_stmt FROM @taxonomy_sql; EXECUTE taxonomy_stmt; DEALLOCATE PREPARE taxonomy_stmt;

INSERT INTO categories(parent_id,name,slug,description,sort_order,status) VALUES
    (NULL,'Moda Masculina','moda-masculina','Moda íntima, roupas e acessórios masculinos.',10,'active'),
    (NULL,'Moda Feminina','moda-feminina','Moda íntima, roupas e acessórios femininos.',20,'active'),
    (NULL,'Infantil','infantil','Linha infantil preparada para lançamentos futuros.',30,'inactive'),
    (NULL,'Kits','kits','Conjuntos de produtos organizados por público e finalidade.',40,'active'),
    (NULL,'Plus Size','plus-size','Coleção comercial de produtos com tamanhos especiais.',50,'active')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),sort_order=VALUES(sort_order);

UPDATE categories child JOIN categories parent ON parent.slug='moda-masculina' SET child.parent_id=parent.id,child.sort_order=10 WHERE child.slug='cuecas';
UPDATE categories child JOIN categories parent ON parent.slug='moda-feminina' SET child.parent_id=parent.id,child.sort_order=10 WHERE child.slug='calcinhas';
UPDATE categories child JOIN categories parent ON parent.slug='cuecas' SET child.parent_id=parent.id,child.sort_order=10 WHERE child.slug='boxer';
UPDATE categories child JOIN categories parent ON parent.slug='cuecas' SET child.parent_id=parent.id,child.sort_order=20 WHERE child.slug='slip';
UPDATE categories child JOIN categories parent ON parent.slug='cuecas' SET child.parent_id=parent.id,child.name='Samba-canção',child.sort_order=30 WHERE child.slug='samba-cancao';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'active' FROM categories p CROSS JOIN (
    SELECT 'Meias' name,'meias-masculinas' slug,20 sort_order UNION ALL SELECT 'Pijamas','pijamas-masculinos',30 UNION ALL SELECT 'Camisetas','camisetas-masculinas',40 UNION ALL SELECT 'Regatas','regatas-masculinas',50 UNION ALL SELECT 'Acessórios','acessorios-masculinos',60
) x WHERE p.slug='moda-masculina'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='active';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'active' FROM categories p CROSS JOIN (
    SELECT 'Long leg' name,'long-leg' slug,40 sort_order UNION ALL SELECT 'Modeladora','cueca-modeladora',50
) x WHERE p.slug='cuecas'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='active';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'active' FROM categories p CROSS JOIN (
    SELECT 'Cano curto' name,'meias-masculinas-cano-curto' slug,10 sort_order UNION ALL SELECT 'Cano médio','meias-masculinas-cano-medio',20 UNION ALL SELECT 'Cano longo','meias-masculinas-cano-longo',30 UNION ALL SELECT 'Sapatilha','meias-masculinas-sapatilha',40 UNION ALL SELECT 'Esportiva','meias-masculinas-esportivas',50
) x WHERE p.slug='meias-masculinas'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='active';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'active' FROM categories p CROSS JOIN (
    SELECT 'Sutiãs' name,'sutias' slug,20 sort_order UNION ALL SELECT 'Meias','meias-femininas',30 UNION ALL SELECT 'Pijamas','pijamas-femininos',40 UNION ALL SELECT 'Camisolas','camisolas',50 UNION ALL SELECT 'Tops','tops-femininos',60 UNION ALL SELECT 'Modeladores','modeladores-femininos',70 UNION ALL SELECT 'Acessórios','acessorios-femininos',80
) x WHERE p.slug='moda-feminina'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='active';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'active' FROM categories p CROSS JOIN (
    SELECT 'Tanga' name,'calcinha-tanga' slug,10 sort_order UNION ALL SELECT 'Fio dental','calcinha-fio-dental',20 UNION ALL SELECT 'Cintura alta','calcinha-cintura-alta',30 UNION ALL SELECT 'Biquíni','calcinha-biquini',40 UNION ALL SELECT 'Boxer feminina','calcinha-boxer-feminina',50 UNION ALL SELECT 'Sem costura','calcinha-sem-costura',60 UNION ALL SELECT 'Modeladora','calcinha-modeladora',70
) x WHERE p.slug='calcinhas'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='active';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'active' FROM categories p CROSS JOIN (
    SELECT 'Com bojo' name,'sutias-com-bojo' slug,10 sort_order UNION ALL SELECT 'Sem bojo','sutias-sem-bojo',20 UNION ALL SELECT 'Top','sutias-top',30 UNION ALL SELECT 'Nadador','sutias-nadador',40 UNION ALL SELECT 'Amamentação','sutias-amamentacao',50 UNION ALL SELECT 'Tomara que caia','sutias-tomara-que-caia',60
) x WHERE p.slug='sutias'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='active';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'active' FROM categories p CROSS JOIN (
    SELECT 'Cano curto' name,'meias-femininas-cano-curto' slug,10 sort_order UNION ALL SELECT 'Cano médio','meias-femininas-cano-medio',20 UNION ALL SELECT 'Sapatilha','meias-femininas-sapatilha',30 UNION ALL SELECT 'Esportiva','meias-femininas-esportivas',40
) x WHERE p.slug='meias-femininas'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='active';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'inactive' FROM categories p CROSS JOIN (
    SELECT 'Meninos' name,'infantil-meninos' slug,10 sort_order UNION ALL SELECT 'Meninas','infantil-meninas',20
) x WHERE p.slug='infantil'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='inactive';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'inactive' FROM categories p CROSS JOIN (
    SELECT 'Cuecas' name,'cuecas-infantis' slug,10 sort_order UNION ALL SELECT 'Meias','meias-meninos',20 UNION ALL SELECT 'Pijamas','pijamas-meninos',30
) x WHERE p.slug='infantil-meninos'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='inactive';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'inactive' FROM categories p CROSS JOIN (
    SELECT 'Calcinhas' name,'calcinhas-infantis' slug,10 sort_order UNION ALL SELECT 'Tops','tops-infantis',20 UNION ALL SELECT 'Meias','meias-meninas',30 UNION ALL SELECT 'Pijamas','pijamas-meninas',40
) x WHERE p.slug='infantil-meninas'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='inactive';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'active' FROM categories p CROSS JOIN (
    SELECT 'Kits de cuecas' name,'kits-de-cuecas' slug,10 sort_order UNION ALL SELECT 'Kits de calcinhas','kits-de-calcinhas',20 UNION ALL SELECT 'Kits de meias','kits-de-meias',30 UNION ALL SELECT 'Kits masculinos','kits-masculinos',40 UNION ALL SELECT 'Kits femininos','kits-femininos',50 UNION ALL SELECT 'Kits infantis','kits-infantis',60 UNION ALL SELECT 'Kits presenteáveis','kits-presenteaveis',70
) x WHERE p.slug='kits'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='active';

INSERT INTO categories(parent_id,name,slug,description,sort_order,status)
SELECT p.id,x.name,x.slug,NULL,x.sort_order,'active' FROM categories p CROSS JOIN (
    SELECT 'Cuecas plus size' name,'cuecas-plus-size' slug,10 sort_order UNION ALL SELECT 'Calcinhas plus size','calcinhas-plus-size',20 UNION ALL SELECT 'Sutiãs plus size','sutias-plus-size',30 UNION ALL SELECT 'Modeladores plus size','modeladores-plus-size',40 UNION ALL SELECT 'Pijamas plus size','pijamas-plus-size',50
) x WHERE p.slug='plus-size'
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id),name=VALUES(name),sort_order=VALUES(sort_order),status='active';

INSERT INTO tags(name,slug,type,is_admin_only,status) VALUES
('Masculino','masculino','audience',0,'active'),('Feminino','feminino','audience',0,'active'),('Unissex','unissex','audience',0,'active'),('Adulto','adulto','audience',0,'active'),('Juvenil','juvenil','audience',0,'active'),('Infantil','infantil','audience',0,'active'),
('Plus size','plus-size','feature',0,'active'),('Cintura alta','cintura-alta','feature',0,'active'),('Cintura baixa','cintura-baixa','feature',0,'active'),('Cintura média','cintura-media','feature',0,'active'),('Modelagem tradicional','modelagem-tradicional','feature',0,'active'),('Modelagem anatômica','modelagem-anatomica','feature',0,'active'),('Modeladora','modeladora','feature',0,'active'),('Compressão leve','compressao-leve','feature',0,'active'),('Compressão média','compressao-media','feature',0,'active'),('Sem costura','sem-costura','feature',0,'active'),('Costura reforçada','costura-reforcada','feature',0,'active'),('Perna longa','perna-longa','feature',0,'active'),
('Algodão','algodao','material',0,'active'),('Algodão premium','algodao-premium','material',0,'active'),('Cotton','cotton','material',0,'active'),('Microfibra','microfibra','material',0,'active'),('Poliamida','poliamida','material',0,'active'),('Poliéster','poliester','material',0,'active'),('Elastano','elastano','material',0,'active'),('Modal','modal','material',0,'active'),('Renda','renda','material',0,'active'),('Dry fit','dry-fit','material',0,'active'),('Tecido respirável','tecido-respiravel','material',0,'active'),('Tecido canelado','tecido-canelado','material',0,'active'),
('Confortável','confortavel','feature',0,'active'),('Respirável','respiravel','feature',0,'active'),('Alta elasticidade','alta-elasticidade','feature',0,'active'),('Secagem rápida','secagem-rapida','feature',0,'active'),('Toque macio','toque-macio','feature',0,'active'),('Não enrola nas pernas','nao-enrola-nas-pernas','feature',0,'active'),('Não marca sob a roupa','nao-marca-sob-a-roupa','feature',0,'active'),('Elástico reforçado','elastico-reforcado','feature',0,'active'),('Forro de algodão','forro-de-algodao','feature',0,'active'),('Antiodor','antiodor','feature',1,'active'),('Antialérgico','antialergico','feature',1,'active'),('Sem etiqueta','sem-etiqueta','feature',0,'active'),('Cós largo','cos-largo','feature',0,'active'),('Ajuste anatômico','ajuste-anatomico','feature',0,'active'),
('Dia a dia','dia-a-dia','occasion',0,'active'),('Trabalho','trabalho','occasion',0,'active'),('Academia','academia','occasion',0,'active'),('Esporte','esporte','occasion',0,'active'),('Dormir','dormir','occasion',0,'active'),('Viagem','viagem','occasion',0,'active'),('Presente','presente','occasion',0,'active'),('Uso prolongado','uso-prolongado','occasion',0,'active'),('Pós-parto','pos-parto','occasion',0,'active'),('Gestante','gestante','occasion',0,'active'),
('Básico','basico','style',0,'active'),('Clássico','classico','style',0,'active'),('Esportivo','esportivo','style',0,'active'),('Casual','casual','style',0,'active'),('Sensual','sensual','style',0,'active'),('Minimalista','minimalista','style',0,'active'),('Estampado','estampado','style',0,'active'),('Liso','liso','style',0,'active'),('Com renda','com-renda','style',0,'active'),
('Cores neutras','cores-neutras','collection',0,'active'),('Cores variadas','cores-variadas','collection',0,'active'),('Cores sortidas','cores-sortidas','collection',0,'active'),('Tons escuros','tons-escuros','collection',0,'active'),('Tons claros','tons-claros','collection',0,'active'),('Preto','preto','collection',0,'active'),('Branco','branco','collection',0,'active'),('Cinza','cinza','collection',0,'active'),('Azul','azul','collection',0,'active'),('Vermelho','vermelho','collection',0,'active'),('Rosa','rosa','collection',0,'active'),('Nude','nude','collection',0,'active'),
('Kit','kit','commercial',0,'active'),('Kit com 2','kit-com-2','commercial',0,'active'),('Kit com 3','kit-com-3','commercial',0,'active'),('Kit com 5','kit-com-5','commercial',0,'active'),('Kit com 6','kit-com-6','commercial',0,'active'),('Kit com 10','kit-com-10','commercial',0,'active'),('Kit com 12','kit-com-12','commercial',0,'active'),
('Varejo','varejo','commercial',0,'active'),('Atacado','atacado','commercial',0,'active'),('Varejo e atacado','varejo-e-atacado','commercial',0,'active'),('Preço por quantidade','preco-por-quantidade','commercial',0,'active'),('Pedido mínimo','pedido-minimo','commercial',0,'active'),('Frete grátis','frete-gratis','commercial',0,'active'),('Promoção','promocao','commercial',0,'active'),('Últimas unidades','ultimas-unidades','commercial',0,'active'),('Tamanhos especiais','tamanhos-especiais','feature',0,'active'),('Tamanho especial','tamanho-especial','feature',0,'active'),('GG','gg','feature',0,'active'),('XG','xg','feature',0,'active'),('G1','g1','feature',0,'active'),('G2','g2','feature',0,'active'),('G3','g3','feature',0,'active'),('G4','g4','feature',0,'active'),
('Fabricação própria','fabricacao-propria','administrative',1,'active'),('Pronta entrega','pronta-entrega','commercial',0,'active'),('Loja oficial','loja-oficial','administrative',1,'active'),('Vendedor verificado','vendedor-verificado','administrative',1,'active'),('Produto exclusivo','produto-exclusivo','administrative',1,'active'),('Produto em destaque','produto-em-destaque','administrative',1,'active'),('Mais vendido','mais-vendido','administrative',1,'active'),('Lançamento','lancamento','administrative',1,'active'),('Oferta','oferta','administrative',1,'active'),('Produto verificado','produto-verificado','administrative',1,'active'),('Exclusivo Tuffer','exclusivo-tuffer','administrative',1,'active'),('Exclusivo atacadista','exclusivo-atacadista','administrative',1,'active'),('Campanha ativa','campanha-ativa','administrative',1,'active'),('Produto recomendado','produto-recomendado','administrative',1,'active')
ON DUPLICATE KEY UPDATE name=VALUES(name),type=VALUES(type),is_admin_only=VALUES(is_admin_only),status=VALUES(status);
