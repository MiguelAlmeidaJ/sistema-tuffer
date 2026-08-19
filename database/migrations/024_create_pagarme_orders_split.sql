ALTER TABLE coupons
    ADD COLUMN funding_source ENUM('seller','platform') NOT NULL DEFAULT 'seller' AFTER discount_value;

ALTER TABLE order_coupons
    ADD COLUMN funding_source ENUM('seller','platform') NOT NULL DEFAULT 'seller' AFTER coupon_id,
    ADD COLUMN discount_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_amount;

UPDATE order_coupons
SET discount_amount_cents=ROUND(discount_amount*100);

ALTER TABLE payments
    MODIFY external_checkout_id VARCHAR(191) NULL,
    MODIFY external_order_id VARCHAR(191) NULL,
    MODIFY external_charge_id VARCHAR(191) NULL,
    ADD COLUMN integration_type ENUM('payment_link','orders') NOT NULL DEFAULT 'payment_link' AFTER provider,
    ADD COLUMN amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER amount,
    ADD COLUMN refunded_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER refunded_amount,
    ADD COLUMN pix_qr_code TEXT NULL AFTER checkout_url,
    ADD COLUMN pix_qr_code_url TEXT NULL AFTER pix_qr_code,
    ADD COLUMN pix_expires_at DATETIME NULL AFTER pix_qr_code_url,
    MODIFY status ENUM(
        'pending','waiting_payment','processing','paid','failed','expired',
        'cancelled','partially_refunded','refunded'
    ) NOT NULL DEFAULT 'pending';

UPDATE payments
SET amount_cents=ROUND(amount*100),
    refunded_amount_cents=ROUND(refunded_amount*100);

CREATE TABLE pagarme_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(191) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    status VARCHAR(80) NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pagarme_orders_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_pagarme_orders_payment (payment_id),
    UNIQUE KEY uk_pagarme_orders_external (external_order_id),
    UNIQUE KEY uk_pagarme_orders_idempotency (idempotency_key),
    INDEX idx_pagarme_orders_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pagarme_charges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pagarme_order_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    external_charge_id VARCHAR(191) NOT NULL,
    external_transaction_id VARCHAR(191) NULL,
    charge_gateway_id VARCHAR(128) NULL,
    transaction_gateway_id VARCHAR(128) NULL,
    payment_method VARCHAR(40) NULL,
    status VARCHAR(80) NULL,
    amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    paid_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    refunded_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    paid_at DATETIME NULL,
    last_event_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pagarme_charges_order
        FOREIGN KEY (pagarme_order_id) REFERENCES pagarme_orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pagarme_charges_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_pagarme_charges_external (external_charge_id),
    INDEX idx_pagarme_charges_order (pagarme_order_id, created_at),
    INDEX idx_pagarme_charges_payment_status (payment_id, status),
    INDEX idx_pagarme_charges_transaction (external_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_split_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    participant_key VARCHAR(191) NOT NULL,
    participant_type ENUM('seller','platform') NOT NULL,
    seller_id BIGINT UNSIGNED NULL,
    recipient_id VARCHAR(191) NOT NULL,
    products_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    shipping_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    seller_discount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    platform_discount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    commission_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    split_amount_cents BIGINT UNSIGNED NOT NULL,
    liable TINYINT(1) NOT NULL DEFAULT 0,
    charge_processing_fee TINYINT(1) NOT NULL DEFAULT 0,
    charge_remainder_fee TINYINT(1) NOT NULL DEFAULT 0,
    policy_version VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_split_snapshots_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_split_snapshots_seller
        FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_payment_split_snapshot_participant (payment_id, participant_key),
    INDEX idx_payment_split_snapshot_recipient (recipient_id),
    INDEX idx_payment_split_snapshot_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_payment_split_snapshots_no_update
BEFORE UPDATE ON payment_split_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT='Payment split snapshots are immutable';

CREATE TRIGGER trg_payment_split_snapshots_no_delete
BEFORE DELETE ON payment_split_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT='Payment split snapshots are immutable';
