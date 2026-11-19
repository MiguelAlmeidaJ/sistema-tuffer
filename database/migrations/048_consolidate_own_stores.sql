-- Consolidação operacional de Tuffer Oficial e Linda Flor, preservando
-- integralmente pedidos históricos, notas, remessas e lançamentos financeiros.
CREATE TABLE store_consolidations (
 source_store_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 target_store_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_store_consolidations_source FOREIGN KEY(source_store_id) REFERENCES stores(id) ON DELETE RESTRICT,
 CONSTRAINT fk_store_consolidations_target FOREIGN KEY(target_store_id) REFERENCES stores(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO brands(name,slug,status) VALUES
 ('Tuffer','tuffer','active'),('Linda Flor','linda-flor','active')
ON DUPLICATE KEY UPDATE name=VALUES(name),status='active';

-- Identifica o destino Tuffer e a origem Linda Flor sem depender dos IDs.
INSERT INTO store_consolidations(source_store_id,target_store_id)
SELECT lf.id, tf.id
FROM stores lf
JOIN stores tf ON (LOWER(TRIM(tf.name)) IN ('tuffer','tuffer oficial','loja tuffer')
                   OR LOWER(TRIM(tf.slug)) IN ('tuffer','tuffer-oficial'))
WHERE (LOWER(TRIM(lf.name))='linda flor' OR LOWER(TRIM(lf.slug))='linda-flor')
AND lf.id<>tf.id
AND tf.id=(SELECT MIN(t2.id) FROM stores t2
 WHERE LOWER(TRIM(t2.name)) IN ('tuffer','tuffer oficial','loja tuffer')
 OR LOWER(TRIM(t2.slug)) IN ('tuffer','tuffer-oficial'))
ON DUPLICATE KEY UPDATE target_store_id=VALUES(target_store_id);

-- Mantém rastreabilidade da marca e do vínculo original de cada produto.
CREATE TABLE product_store_consolidation_backup (
 product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 old_store_id BIGINT UNSIGNED NOT NULL,
 old_seller_id BIGINT UNSIGNED NOT NULL,
 old_brand_id BIGINT UNSIGNED NULL,
 captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO product_store_consolidation_backup(product_id,old_store_id,old_seller_id,old_brand_id)
SELECT p.id,p.store_id,p.seller_id,p.brand_id FROM products p
JOIN stores st ON st.id=p.store_id
WHERE LOWER(TRIM(st.name)) IN ('tuffer','tuffer oficial','loja tuffer','linda flor')
 OR LOWER(TRIM(st.slug)) IN ('tuffer','tuffer-oficial','linda-flor');

-- Atribui Tuffer / Linda Flor como marcas comerciais de todos os produtos destas lojas.
UPDATE products p JOIN stores st ON st.id=p.store_id
JOIN brands b ON b.slug='linda-flor'
SET p.brand_id=b.id
WHERE (LOWER(TRIM(st.name))='linda flor' OR LOWER(TRIM(st.slug))='linda-flor');

UPDATE products p JOIN stores st ON st.id=p.store_id
JOIN brands b ON b.slug='tuffer'
SET p.brand_id=b.id
WHERE (LOWER(TRIM(st.name)) IN ('tuffer','tuffer oficial','loja tuffer')
 OR LOWER(TRIM(st.slug)) IN ('tuffer','tuffer-oficial'));

-- Produtos se tornam gerenciáveis pelo único cadastro da loja operacional.
UPDATE products p
JOIN store_consolidations sc ON sc.source_store_id=p.store_id
JOIN stores destination ON destination.id=sc.target_store_id
SET p.store_id=sc.target_store_id,p.seller_id=destination.seller_id;

-- Carrinhos em andamento preservam produtos, sem dependência da loja anterior.
UPDATE cart_items ci JOIN store_consolidations sc ON sc.source_store_id=ci.store_id
JOIN stores destination ON destination.id=sc.target_store_id
SET ci.store_id=sc.target_store_id,ci.seller_id=destination.seller_id;

-- Não alterar seller_orders: as referências históricas fiscal, financeira
-- e logística representam a origem real do pedido.
UPDATE stores st JOIN store_consolidations sc ON sc.source_store_id=st.id
SET st.status='inactive';

-- Só a loja operacional permanece visível como loja ativa.
UPDATE stores st JOIN store_consolidations sc ON sc.target_store_id=st.id
SET st.status='active',st.is_official_store=1;
