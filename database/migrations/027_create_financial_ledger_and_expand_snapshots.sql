ALTER TABLE payment_financial_snapshot_lines
    ADD COLUMN seller_type ENUM('official_store','external') NULL AFTER seller_id,
    ADD COLUMN is_official_store TINYINT(1) NOT NULL DEFAULT 0 AFTER seller_type,
    ADD COLUMN gross_revenue_cents BIGINT UNSIGNED NULL AFTER shipping_recipient,
    ADD COLUMN fixed_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER commission_amount_cents,
    ADD COLUMN expected_provider_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER fixed_fee_cents,
    ADD COLUMN tax_provision_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER expected_provider_fee_cents,
    ADD COLUMN product_cost_cents BIGINT UNSIGNED NULL AFTER tax_provision_cents,
    ADD COLUMN product_cost_known TINYINT(1) NOT NULL DEFAULT 0 AFTER product_cost_cents,
    ADD COLUMN reserve_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER product_cost_known,
    ADD COLUMN transferable_amount_cents BIGINT NULL AFTER reserve_amount_cents,
    ADD COLUMN policy_applied_at DATETIME NULL AFTER policy_version;

CREATE TABLE payment_financial_snapshot_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    financial_snapshot_line_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_variant_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_revenue_cents BIGINT UNSIGNED NOT NULL,
    total_revenue_cents BIGINT UNSIGNED NOT NULL,
    unit_cost_cents BIGINT UNSIGNED NULL,
    total_cost_cents BIGINT UNSIGNED NULL,
    cost_known TINYINT(1) NOT NULL DEFAULT 0,
    cost_effective_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_financial_snapshot_item_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_snapshot_item_line FOREIGN KEY (financial_snapshot_line_id) REFERENCES payment_financial_snapshot_lines(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_snapshot_item_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_financial_snapshot_order_item (payment_id,order_item_id),
    INDEX idx_financial_snapshot_item_product (product_id,product_variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_financial_snapshot_items_no_update
BEFORE UPDATE ON payment_financial_snapshot_items
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Financial snapshot items are immutable';

CREATE TRIGGER trg_financial_snapshot_items_no_delete
BEFORE DELETE ON payment_financial_snapshot_items
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Financial snapshot items are immutable';

CREATE TABLE financial_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    seller_order_id BIGINT UNSIGNED NULL,
    payment_id BIGINT UNSIGNED NULL,
    external_charge_id VARCHAR(191) NULL,
    seller_id BIGINT UNSIGNED NULL,
    recipient_id VARCHAR(191) NOT NULL,
    financial_owner ENUM('official_store','marketplace','external_seller','payment_provider','shipping','tax','reserve') NOT NULL,
    entry_type VARCHAR(80) NOT NULL,
    direction ENUM('credit','debit') NOT NULL,
    gross_amount_cents BIGINT UNSIGNED NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    status ENUM('pending','confirmed','void','reversed') NOT NULL DEFAULT 'pending',
    is_split_component TINYINT(1) NOT NULL DEFAULT 0,
    accounting_period CHAR(7) NOT NULL,
    source_type VARCHAR(80) NOT NULL,
    source_id VARCHAR(191) NOT NULL,
    sequence_no INT UNSIGNED NOT NULL DEFAULT 1,
    idempotency_key VARCHAR(191) NOT NULL,
    description VARCHAR(500) NULL,
    metadata JSON NULL,
    occurred_at DATETIME NOT NULL,
    settled_at DATETIME NULL,
    reversed_entry_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_financial_entry_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_entry_seller_order FOREIGN KEY (seller_order_id) REFERENCES seller_orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_entry_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_entry_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_entry_reversed FOREIGN KEY (reversed_entry_id) REFERENCES financial_entries(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_financial_entry_idempotency (idempotency_key),
    UNIQUE KEY uk_financial_entry_natural (order_id,entry_type,seller_id,source_id,sequence_no),
    INDEX idx_financial_entry_owner_period (financial_owner,accounting_period,status),
    INDEX idx_financial_entry_payment_status (payment_id,status),
    INDEX idx_financial_entry_recipient_split (payment_id,recipient_id,is_split_component,status),
    INDEX idx_financial_entry_charge (external_charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_financial_entries_no_delete
BEFORE DELETE ON financial_entries
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Financial entries cannot be deleted';

CREATE TRIGGER trg_financial_entries_protect_values
BEFORE UPDATE ON financial_entries
FOR EACH ROW
BEGIN
    IF OLD.status IN ('confirmed','reversed')
       OR NEW.order_id<>OLD.order_id
       OR NOT (NEW.seller_order_id <=> OLD.seller_order_id)
       OR NOT (NEW.payment_id <=> OLD.payment_id)
       OR NOT (NEW.seller_id <=> OLD.seller_id)
       OR NEW.recipient_id<>OLD.recipient_id
       OR NEW.financial_owner<>OLD.financial_owner
       OR NEW.entry_type<>OLD.entry_type
       OR NEW.direction<>OLD.direction
       OR NEW.gross_amount_cents<>OLD.gross_amount_cents
       OR NEW.amount_cents<>OLD.amount_cents
       OR NEW.currency<>OLD.currency
       OR NEW.is_split_component<>OLD.is_split_component
       OR NEW.source_type<>OLD.source_type
       OR NEW.source_id<>OLD.source_id
       OR NEW.sequence_no<>OLD.sequence_no
       OR NEW.idempotency_key<>OLD.idempotency_key
       OR NOT (NEW.metadata <=> OLD.metadata)
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Confirmed financial entries are immutable';
    END IF;
END;
