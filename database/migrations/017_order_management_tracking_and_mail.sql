ALTER TABLE shipments
    ADD COLUMN tracking_url TEXT NULL AFTER tracking_code,
    ADD COLUMN raw_status VARCHAR(80) NULL AFTER status,
    ADD COLUMN last_synced_at DATETIME NULL AFTER raw_status;

ALTER TABLE shipment_tracking_events
    ADD COLUMN provider_event_key VARCHAR(191) NULL AFTER shipment_id,
    ADD COLUMN raw_payload JSON NULL AFTER occurred_at,
    ADD UNIQUE KEY uk_tracking_events_provider_key (provider_event_key);

CREATE TABLE mail_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(190) NOT NULL,
    recipient_name VARCHAR(150) NULL,
    template VARCHAR(100) NOT NULL,
    subject VARCHAR(190) NOT NULL,
    related_type VARCHAR(60) NULL,
    related_id BIGINT UNSIGNED NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mail_deliveries_status_created (status, created_at),
    INDEX idx_mail_deliveries_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
