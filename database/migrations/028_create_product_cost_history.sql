CREATE TABLE product_cost_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    product_variant_id BIGINT UNSIGNED NOT NULL,
    cost_amount_cents BIGINT UNSIGNED NOT NULL,
    effective_from DATETIME NOT NULL,
    effective_until DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_cost_history_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_product_cost_history_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_product_cost_history_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_product_cost_effective (product_variant_id,effective_from),
    INDEX idx_product_cost_lookup (product_variant_id,effective_from,effective_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER trg_financial_entries_protect_values;

CREATE TRIGGER trg_financial_entries_protect_values
BEFORE UPDATE ON financial_entries
FOR EACH ROW
BEGIN
    IF OLD.status='reversed'
       OR (OLD.status='confirmed' AND NEW.status<>'reversed')
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
