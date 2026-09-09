CREATE TABLE store_fiscal_api_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    seller_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_prefix VARCHAR(24) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    last_used_at DATETIME NULL,
    rotated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_store_fiscal_api_credentials_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_fiscal_api_credentials_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    UNIQUE KEY uk_store_fiscal_api_credentials_store (store_id),
    UNIQUE KEY uk_store_fiscal_api_credentials_hash (token_hash),
    INDEX idx_store_fiscal_api_credentials_active (enabled, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fiscal_documents
    ADD COLUMN external_reference VARCHAR(150) NULL AFTER provider_document_id,
    ADD COLUMN cancellation_protocol VARCHAR(100) NULL AFTER protocol,
    ADD COLUMN cancellation_reason VARCHAR(1000) NULL AFTER cancellation_protocol;
