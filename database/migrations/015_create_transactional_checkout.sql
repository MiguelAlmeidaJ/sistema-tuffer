UPDATE seller_orders so1
SET store_id = (
    SELECT MIN(st.id)
    FROM stores st
    WHERE st.seller_id = so1.seller_id
)
WHERE store_id IS NULL;

ALTER TABLE seller_orders
    DROP INDEX uk_seller_orders_order_seller,
    MODIFY store_id BIGINT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uk_seller_orders_order_store (order_id, store_id);

CREATE TABLE order_addresses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    recipient_name VARCHAR(150) NOT NULL,
    postal_code VARCHAR(12) NOT NULL,
    street VARCHAR(190) NOT NULL,
    number VARCHAR(30) NOT NULL,
    complement VARCHAR(120) NULL,
    neighborhood VARCHAR(120) NOT NULL,
    city VARCHAR(120) NOT NULL,
    state CHAR(2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_addresses_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY uk_order_addresses_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payments
    ADD COLUMN external_checkout_id VARCHAR(100) NULL AFTER provider,
    ADD COLUMN checkout_url TEXT NULL AFTER external_charge_id,
    ADD COLUMN idempotency_key VARCHAR(64) NULL AFTER checkout_url,
    ADD COLUMN expires_at DATETIME NULL AFTER paid_at,
    ADD UNIQUE KEY uk_payments_idempotency (idempotency_key),
    ADD INDEX idx_payments_external_checkout (external_checkout_id);

ALTER TABLE shipments
    ADD COLUMN service_id VARCHAR(100) NULL AFTER external_id,
    ADD COLUMN carrier_name VARCHAR(120) NULL AFTER service_name,
    ADD COLUMN estimated_delivery_min DATE NULL AFTER shipping_cost,
    ADD COLUMN estimated_delivery_max DATE NULL AFTER estimated_delivery_min;
