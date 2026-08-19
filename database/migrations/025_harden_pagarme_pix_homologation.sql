CREATE TABLE payment_financial_snapshot_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    seller_order_id BIGINT UNSIGNED NOT NULL,
    seller_id BIGINT UNSIGNED NOT NULL,
    recipient_id VARCHAR(191) NOT NULL,
    products_amount_cents BIGINT UNSIGNED NOT NULL,
    seller_discount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    platform_discount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    shipping_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    shipping_recipient ENUM('seller','platform') NOT NULL,
    commission_rate_basis_points INT UNSIGNED NOT NULL,
    commission_amount_cents BIGINT UNSIGNED NOT NULL,
    seller_net_amount_cents BIGINT UNSIGNED NOT NULL,
    platform_contribution_cents BIGINT NOT NULL,
    policy_version VARCHAR(50) NOT NULL,
    liability_rules JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_financial_snapshot_line_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_snapshot_line_seller_order
        FOREIGN KEY (seller_order_id) REFERENCES seller_orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_snapshot_line_seller
        FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_financial_snapshot_line (payment_id, seller_order_id),
    INDEX idx_financial_snapshot_seller (seller_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_financial_snapshot_coupons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    financial_snapshot_line_id BIGINT UNSIGNED NOT NULL,
    order_coupon_id BIGINT UNSIGNED NOT NULL,
    coupon_id BIGINT UNSIGNED NOT NULL,
    coupon_code VARCHAR(100) NOT NULL,
    funding_source ENUM('seller','platform') NOT NULL,
    discount_amount_cents BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_financial_snapshot_coupon_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_snapshot_coupon_line
        FOREIGN KEY (financial_snapshot_line_id) REFERENCES payment_financial_snapshot_lines(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_snapshot_coupon_order_coupon
        FOREIGN KEY (order_coupon_id) REFERENCES order_coupons(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_financial_snapshot_coupon (payment_id, order_coupon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_financial_snapshot_lines_no_update
BEFORE UPDATE ON payment_financial_snapshot_lines
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT='Financial snapshot lines are immutable';

CREATE TRIGGER trg_financial_snapshot_lines_no_delete
BEFORE DELETE ON payment_financial_snapshot_lines
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT='Financial snapshot lines are immutable';

CREATE TRIGGER trg_financial_snapshot_coupons_no_update
BEFORE UPDATE ON payment_financial_snapshot_coupons
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT='Financial snapshot coupons are immutable';

CREATE TRIGGER trg_financial_snapshot_coupons_no_delete
BEFORE DELETE ON payment_financial_snapshot_coupons
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT='Financial snapshot coupons are immutable';

CREATE TABLE pagarme_order_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    attempt_key VARCHAR(191) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL,
    status ENUM('pending','creating','uncertain','created','failed') NOT NULL DEFAULT 'pending',
    lock_token CHAR(64) NULL,
    lock_expires_at DATETIME NULL,
    external_order_id VARCHAR(191) NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    last_attempt_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pagarme_order_attempt_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_pagarme_order_attempt_payment (payment_id),
    UNIQUE KEY uk_pagarme_order_attempt_key (attempt_key),
    INDEX idx_pagarme_order_attempt_recovery (status, lock_expires_at, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pagarme_reconciliation_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    environment VARCHAR(20) NOT NULL,
    status ENUM('running','completed','completed_with_errors','failed') NOT NULL DEFAULT 'running',
    checked_count INT UNSIGNED NOT NULL DEFAULT 0,
    recovered_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    divergence_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    INDEX idx_pagarme_reconciliation_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pagarme_reconciliation_divergences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reconciliation_run_id BIGINT UNSIGNED NULL,
    payment_id BIGINT UNSIGNED NULL,
    external_order_id VARCHAR(191) NULL,
    external_charge_id VARCHAR(191) NULL,
    divergence_type VARCHAR(80) NOT NULL,
    local_status VARCHAR(80) NULL,
    remote_status VARCHAR(80) NULL,
    safe_details JSON NULL,
    review_status ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    CONSTRAINT fk_pagarme_divergence_run
        FOREIGN KEY (reconciliation_run_id) REFERENCES pagarme_reconciliation_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_pagarme_divergence_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    INDEX idx_pagarme_divergence_review (review_status, detected_at),
    INDEX idx_pagarme_divergence_payment (payment_id, detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pagarme_refund_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    pagarme_charge_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    refund_type ENUM('full','partial') NOT NULL,
    amount_cents BIGINT UNSIGNED NULL,
    status ENUM('pending','requested','confirmed','uncertain','failed','disabled') NOT NULL DEFAULT 'pending',
    external_refund_id VARCHAR(191) NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pagarme_refund_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pagarme_refund_charge
        FOREIGN KEY (pagarme_charge_id) REFERENCES pagarme_charges(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_pagarme_refund_idempotency (idempotency_key),
    INDEX idx_pagarme_refund_payment (payment_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
