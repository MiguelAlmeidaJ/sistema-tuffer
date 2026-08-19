ALTER TABLE financial_entries
    MODIFY order_id BIGINT UNSIGNED NULL;

CREATE TABLE financial_transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_id BIGINT UNSIGNED NOT NULL,
    financial_owner ENUM('official_store','marketplace','consolidated') NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    transfer_type ENUM('manual_bank_transfer','pix','ted','internal_adjustment','future_provider_transfer') NOT NULL,
    source_account VARCHAR(80) NOT NULL,
    destination_account_masked VARCHAR(80) NOT NULL,
    destination_account_name VARCHAR(120) NOT NULL,
    status ENUM('pending','approved','completed','failed','canceled') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL,
    approved_at DATETIME NULL,
    transferred_at DATETIME NULL,
    bank_reference VARCHAR(120) NULL,
    proof_file VARCHAR(255) NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_financial_transfer_settlement FOREIGN KEY (settlement_id) REFERENCES financial_settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_transfer_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_financial_transfer_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_financial_transfer_idempotency (idempotency_key),
    INDEX idx_financial_transfer_settlement (settlement_id,status),
    INDEX idx_financial_transfer_owner_date (financial_owner,transferred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_financial_transfers_no_delete
BEFORE DELETE ON financial_transfers
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Financial transfers cannot be deleted';
