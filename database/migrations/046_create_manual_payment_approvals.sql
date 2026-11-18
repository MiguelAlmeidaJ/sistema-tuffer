CREATE TABLE manual_payment_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    amount_cents BIGINT UNSIGNED NOT NULL,
    external_reference VARCHAR(150) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_manual_payment_approvals_order (order_id),
    UNIQUE KEY uk_manual_payment_approvals_payment (payment_id),
    INDEX idx_manual_payment_approvals_created (created_at),
    CONSTRAINT fk_manual_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_manual_payment_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_manual_payment_admin FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_manual_payment_approvals_no_update BEFORE UPDATE ON manual_payment_approvals
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manual payment approvals are immutable';

CREATE TRIGGER trg_manual_payment_approvals_no_delete BEFORE DELETE ON manual_payment_approvals
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manual payment approvals are immutable';
