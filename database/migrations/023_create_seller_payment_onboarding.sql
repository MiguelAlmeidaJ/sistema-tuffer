ALTER TABLE sellers
    ADD COLUMN payment_onboarding_status VARCHAR(50) NOT NULL DEFAULT 'not_started' AFTER pagarme_recipient_id,
    ADD COLUMN payment_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_onboarding_status,
    ADD COLUMN payment_block_reason VARCHAR(255) NULL AFTER payment_enabled,
    ADD INDEX idx_sellers_payment_eligibility (status, payment_enabled, payment_onboarding_status);

CREATE TABLE seller_payment_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'pagarme',
    environment VARCHAR(20) NOT NULL,
    recipient_id VARCHAR(100) NULL,
    recipient_status VARCHAR(50) NULL,
    kyc_status VARCHAR(50) NULL,
    kyc_status_reason VARCHAR(100) NULL,
    kyc_url TEXT NULL,
    kyc_url_expires_at DATETIME NULL,
    registration_type VARCHAR(20) NOT NULL DEFAULT 'corporation',
    bank_code VARCHAR(10) NULL,
    bank_branch_masked VARCHAR(20) NULL,
    bank_account_masked VARCHAR(30) NULL,
    bank_account_type VARCHAR(30) NULL,
    onboarding_status VARCHAR(50) NOT NULL DEFAULT 'not_started',
    enabled_for_sales TINYINT(1) NOT NULL DEFAULT 0,
    last_synced_at DATETIME NULL,
    approved_at DATETIME NULL,
    rejected_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_seller_payment_account_seller
        FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    UNIQUE KEY uk_seller_payment_provider (seller_id, provider, environment),
    UNIQUE KEY uk_payment_recipient (recipient_id, environment),
    INDEX idx_seller_payment_status (provider, environment, enabled_for_sales, onboarding_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE stores SET recipient_source_store_id=NULL WHERE recipient_source_store_id IS NOT NULL;

ALTER TABLE stores
    DROP FOREIGN KEY fk_stores_recipient_source,
    DROP COLUMN recipient_source_store_id;
