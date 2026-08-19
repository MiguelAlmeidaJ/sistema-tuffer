ALTER TABLE payment_webhooks
    ADD COLUMN order_id BIGINT UNSIGNED NULL AFTER provider_event_id,
    ADD COLUMN payment_id BIGINT UNSIGNED NULL AFTER order_id,
    ADD COLUMN payload_sha256 CHAR(64) NULL AFTER payload,
    ADD COLUMN signature_algorithm VARCHAR(20) NULL AFTER payload_sha256,
    ADD COLUMN signature_validated_at DATETIME NULL AFTER signature_algorithm,
    ADD COLUMN event_created_at DATETIME NULL AFTER signature_validated_at,
    ADD COLUMN delivery_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN last_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER delivery_count,
    ADD CONSTRAINT fk_payment_webhooks_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_payment_webhooks_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    ADD INDEX idx_payment_webhooks_order (order_id),
    ADD INDEX idx_payment_webhooks_payment (payment_id),
    ADD INDEX idx_payment_webhooks_payload_hash (payload_sha256);

ALTER TABLE payments
    ADD COLUMN refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount,
    ADD COLUMN last_event_at DATETIME NULL AFTER expires_at;
