-- Conector fiscal gerenciado pela Tuffer usando a conta Tiny/Olist de cada loja.
-- A Tuffer orquestra a API do ERP, mas a emissão continua ocorrendo na conta fiscal do vendedor.

ALTER TABLE store_fiscal_profiles
    MODIFY COLUMN issuance_mode ENUM('manual','external','connector') NOT NULL DEFAULT 'manual';

CREATE TABLE store_fiscal_connector_configs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id BIGINT UNSIGNED NOT NULL,
    seller_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(60) NOT NULL,
    credentials_ciphertext TEXT NOT NULL,
    credential_prefix VARCHAR(24) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    auto_emit BOOLEAN NOT NULL DEFAULT TRUE,
    send_email BOOLEAN NOT NULL DEFAULT FALSE,
    account_name VARCHAR(120) NULL,
    account_document VARCHAR(40) NULL,
    settings JSON NULL,
    last_test_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_store_fiscal_connector_configs_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    CONSTRAINT fk_store_fiscal_connector_configs_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    UNIQUE KEY uk_store_fiscal_connector_configs_store_provider (store_id, provider),
    INDEX idx_store_fiscal_connector_configs_active (store_id, enabled, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fiscal_connector_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seller_order_id BIGINT UNSIGNED NOT NULL,
    fiscal_document_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    seller_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(60) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    provider_order_id VARCHAR(100) NULL,
    provider_order_number VARCHAR(100) NULL,
    provider_document_id VARCHAR(100) NULL,
    provider_document_number VARCHAR(100) NULL,
    provider_document_series VARCHAR(30) NULL,
    provider_document_url VARCHAR(500) NULL,
    provider_status VARCHAR(100) NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(1000) NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fiscal_connector_runs_seller_order FOREIGN KEY (seller_order_id) REFERENCES seller_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_fiscal_connector_runs_fiscal_document FOREIGN KEY (fiscal_document_id) REFERENCES fiscal_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_fiscal_connector_runs_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    CONSTRAINT fk_fiscal_connector_runs_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    UNIQUE KEY uk_fiscal_connector_runs_order_provider (seller_order_id, provider),
    INDEX idx_fiscal_connector_runs_status (provider, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
