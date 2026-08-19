CREATE TABLE financial_settlements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_type ENUM('official_store','marketplace','consolidated') NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    financial_owner ENUM('official_store','marketplace','consolidated') NOT NULL,
    gross_revenue_cents BIGINT NOT NULL DEFAULT 0,
    discounts_cents BIGINT NOT NULL DEFAULT 0,
    coupons_cents BIGINT NOT NULL DEFAULT 0,
    shipping_revenue_cents BIGINT NOT NULL DEFAULT 0,
    shipping_cost_cents BIGINT NOT NULL DEFAULT 0,
    product_cost_cents BIGINT NULL,
    processing_fees_cents BIGINT NOT NULL DEFAULT 0,
    tax_provision_cents BIGINT NOT NULL DEFAULT 0,
    refunds_cents BIGINT NOT NULL DEFAULT 0,
    chargebacks_cents BIGINT NOT NULL DEFAULT 0,
    reserve_amount_cents BIGINT NOT NULL DEFAULT 0,
    net_revenue_cents BIGINT NOT NULL DEFAULT 0,
    estimated_profit_cents BIGINT NULL,
    previous_adjustments_cents BIGINT NOT NULL DEFAULT 0,
    transferable_amount_cents BIGINT NOT NULL DEFAULT 0,
    transferred_amount_cents BIGINT NOT NULL DEFAULT 0,
    status ENUM('calculating','awaiting_review','approved','partially_transferred','transferred','canceled') NOT NULL DEFAULT 'calculating',
    policy_version VARCHAR(50) NOT NULL,
    calculated_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL,
    transferred_at DATETIME NULL,
    transferred_by BIGINT UNSIGNED NULL,
    destination_account_name VARCHAR(120) NULL,
    destination_account_masked VARCHAR(80) NULL,
    bank_reference VARCHAR(120) NULL,
    proof_file VARCHAR(255) NULL,
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_financial_settlement_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_financial_settlement_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_financial_settlement_transfer_user FOREIGN KEY (transferred_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_financial_settlement_period (settlement_type,financial_owner,period_start,period_end),
    INDEX idx_financial_settlement_status (status,period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_settlement_entries (
    settlement_id BIGINT UNSIGNED NOT NULL,
    financial_entry_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (settlement_id,financial_entry_id),
    UNIQUE KEY uk_financial_entry_single_settlement (financial_entry_id,settlement_id),
    CONSTRAINT fk_settlement_entry_settlement FOREIGN KEY (settlement_id) REFERENCES financial_settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_entry_entry FOREIGN KEY (financial_entry_id) REFERENCES financial_entries(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_settlement_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(60) NOT NULL,
    previous_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    notes VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_settlement_history_settlement FOREIGN KEY (settlement_id) REFERENCES financial_settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_history_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_settlement_history (settlement_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_financial_settlements_no_delete
BEFORE DELETE ON financial_settlements
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Financial settlements cannot be deleted';
