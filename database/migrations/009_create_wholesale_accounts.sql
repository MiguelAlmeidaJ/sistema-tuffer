CREATE TABLE wholesale_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    cnpj VARCHAR(14) NOT NULL,
    legal_name VARCHAR(190) NOT NULL,
    trade_name VARCHAR(190) NULL,
    state_registration VARCHAR(50) NULL,
    state_registration_status ENUM('taxpayer','exempt','non_taxpayer') NULL,
    opened_at DATE NULL,
    business_phone VARCHAR(30) NULL,
    business_email VARCHAR(190) NULL,
    website VARCHAR(255) NULL,
    business_segment VARCHAR(100) NULL,
    average_monthly_volume INT UNSIGNED NULL,
    status ENUM('draft','pending','under_review','approved','rejected','suspended','cancelled') NOT NULL DEFAULT 'draft',
    rejection_reason TEXT NULL,
    submitted_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    approved_at DATETIME NULL,
    suspended_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    terms_accepted_at DATETIME NULL,
    terms_version VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wholesale_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wholesale_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_wholesale_cnpj (cnpj),
    UNIQUE KEY uk_wholesale_user (user_id),
    INDEX idx_wholesale_status (status, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wholesale_responsibles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wholesale_account_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    cpf VARCHAR(11) NOT NULL,
    position VARCHAR(100) NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wholesale_responsible_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
    INDEX idx_wholesale_responsible_account (wholesale_account_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wholesale_addresses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wholesale_account_id BIGINT UNSIGNED NOT NULL,
    type ENUM('billing','shipping','both') NOT NULL DEFAULT 'both',
    postal_code VARCHAR(8) NOT NULL,
    street VARCHAR(190) NOT NULL,
    number VARCHAR(30) NOT NULL,
    complement VARCHAR(100) NULL,
    district VARCHAR(100) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state CHAR(2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wholesale_address_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
    INDEX idx_wholesale_address_account (wholesale_account_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wholesale_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wholesale_account_id BIGINT UNSIGNED NOT NULL,
    type ENUM('cnpj_card','articles_of_association','responsible_document','business_address_proof','state_registration','other') NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    CONSTRAINT fk_wholesale_document_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
    INDEX idx_wholesale_document_account (wholesale_account_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wholesale_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wholesale_account_id BIGINT UNSIGNED NOT NULL,
    previous_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NOT NULL,
    reason TEXT NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wholesale_history_account FOREIGN KEY (wholesale_account_id) REFERENCES wholesale_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_wholesale_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_wholesale_history_account (wholesale_account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(80) NOT NULL,
    title VARCHAR(190) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    action_url VARCHAR(255) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_notification_unread (user_id, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_email_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_email_verification_user (user_id, used_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE carts
    ADD COLUMN cart_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER currency,
    ADD INDEX idx_carts_user_type_status (user_id, cart_type, status);

ALTER TABLE orders
    ADD COLUMN order_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER currency,
    ADD INDEX idx_orders_user_type_created (user_id, order_type, created_at);

ALTER TABLE stores
    ADD COLUMN wholesale_min_quantity INT UNSIGNED NULL AFTER shipping_source_store_id,
    ADD COLUMN wholesale_min_total DECIMAL(12,2) NULL AFTER wholesale_min_quantity;

INSERT INTO permissions (slug, name) VALUES ('wholesale.manage', 'Gerenciar cadastros atacadistas')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug='admin' AND p.slug='wholesale.manage';
