ALTER TABLE cart_items ADD COLUMN store_id BIGINT UNSIGNED NULL AFTER seller_id;

UPDATE cart_items ci
JOIN product_variants pv ON pv.id = ci.product_variant_id
JOIN products p ON p.id = pv.product_id
SET ci.store_id = p.store_id
WHERE ci.store_id IS NULL;

ALTER TABLE cart_items
    MODIFY store_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_cart_items_store FOREIGN KEY (store_id) REFERENCES stores(id),
    ADD INDEX idx_cart_items_store (cart_id, store_id);
