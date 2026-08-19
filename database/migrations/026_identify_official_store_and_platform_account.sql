ALTER TABLE sellers
    ADD COLUMN is_official_store TINYINT(1) NOT NULL DEFAULT 0 AFTER commission_rate,
    ADD COLUMN active_official_store_marker TINYINT
        GENERATED ALWAYS AS (CASE WHEN is_official_store=1 AND status='active' THEN 1 ELSE NULL END) STORED,
    ADD UNIQUE KEY uk_single_active_official_store (active_official_store_marker),
    ADD INDEX idx_sellers_official_status (is_official_store,status);

CREATE TABLE marketplace_payment_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(30) NOT NULL DEFAULT 'pagarme',
    environment VARCHAR(20) NOT NULL,
    recipient_id VARCHAR(191) NOT NULL,
    recipient_status VARCHAR(50) NULL,
    kyc_status VARCHAR(50) NULL,
    payment_enabled TINYINT(1) NOT NULL DEFAULT 0,
    bank_name_masked VARCHAR(100) NULL,
    bank_account_masked VARCHAR(40) NULL,
    last_synced_at DATETIME NULL,
    last_sync_status ENUM('never','success','failed') NOT NULL DEFAULT 'never',
    last_sync_error VARCHAR(500) NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_marketplace_payment_provider_environment (provider,environment),
    UNIQUE KEY uk_marketplace_payment_recipient_environment (recipient_id,environment),
    INDEX idx_marketplace_payment_eligibility (provider,environment,payment_enabled,recipient_status,kyc_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
