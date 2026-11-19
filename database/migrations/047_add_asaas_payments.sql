ALTER TABLE payments ADD COLUMN asaas_pix_image MEDIUMTEXT NULL AFTER pix_qr_code_url;

CREATE TABLE asaas_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(120) NOT NULL,
    external_payment_id VARCHAR(191) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_asaas_webhook_event (event_id),
    INDEX idx_asaas_webhook_payment (external_payment_id, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
