ALTER TABLE shipments
    ADD COLUMN quote_payload JSON NULL AFTER shipping_cost,
    ADD COLUMN invoice_key CHAR(44) NULL AFTER quote_payload,
    ADD COLUMN label_purchase_status ENUM('not_requested','processing','cart','purchased','generated','ready','failed') NOT NULL DEFAULT 'not_requested' AFTER invoice_key,
    ADD COLUMN label_actual_cost DECIMAL(12,2) NULL AFTER label_purchase_status,
    ADD COLUMN label_error VARCHAR(1000) NULL AFTER label_actual_cost,
    ADD COLUMN label_attempted_at DATETIME NULL AFTER label_error,
    ADD COLUMN purchased_at DATETIME NULL AFTER label_attempted_at,
    ADD COLUMN generated_at DATETIME NULL AFTER purchased_at,
    ADD INDEX idx_shipments_label_purchase_status (label_purchase_status);
