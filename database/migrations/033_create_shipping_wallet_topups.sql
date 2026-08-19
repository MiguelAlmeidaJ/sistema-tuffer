CREATE TABLE melhor_envio_wallet_topups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    method ENUM('pix','boleto') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    provider_reference VARCHAR(190) NULL,
    payment_url TEXT NULL,
    provider_status VARCHAR(50) NOT NULL DEFAULT 'created',
    response_payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_me_wallet_topups_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_me_wallet_topups_created_at (created_at),
    INDEX idx_me_wallet_topups_status (provider_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
