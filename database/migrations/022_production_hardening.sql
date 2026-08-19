CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until DATETIME NULL,
    UNIQUE KEY login_attempt_identity_unique (email_hash, ip_hash),
    INDEX login_attempt_blocked_idx (blocked_until),
    INDEX login_attempt_last_idx (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_coupons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    seller_order_id BIGINT UNSIGNED NOT NULL,
    coupon_id BIGINT UNSIGNED NOT NULL,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    redeemed_at DATETIME NULL,
    released_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY order_coupon_unique (order_id, coupon_id),
    INDEX order_coupon_status_idx (order_id, released_at, redeemed_at),
    CONSTRAINT order_coupons_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT order_coupons_seller_order_fk FOREIGN KEY (seller_order_id) REFERENCES seller_orders(id) ON DELETE CASCADE,
    CONSTRAINT order_coupons_coupon_fk FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN terms_version VARCHAR(40) NULL AFTER order_type,
    ADD COLUMN terms_accepted_at DATETIME NULL AFTER terms_version,
    ADD COLUMN terms_ip_hash CHAR(64) NULL AFTER terms_accepted_at;
