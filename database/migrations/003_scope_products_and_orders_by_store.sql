ALTER TABLE products ADD COLUMN store_id BIGINT UNSIGNED NULL AFTER seller_id;

UPDATE products p
SET p.store_id = (
    SELECT MIN(s.id) FROM stores s WHERE s.seller_id = p.seller_id
)
WHERE p.store_id IS NULL;

ALTER TABLE products
    MODIFY store_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_products_store FOREIGN KEY (store_id) REFERENCES stores(id),
    ADD INDEX idx_products_store_status (store_id, status);

ALTER TABLE seller_orders
    ADD COLUMN store_id BIGINT UNSIGNED NULL AFTER seller_id,
    ADD CONSTRAINT fk_seller_orders_store FOREIGN KEY (store_id) REFERENCES stores(id),
    ADD INDEX idx_seller_orders_store_status (store_id, status);
