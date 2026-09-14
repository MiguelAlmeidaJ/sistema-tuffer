ALTER TABLE payments
    ADD COLUMN card_installments TINYINT UNSIGNED NULL AFTER method,
    ADD COLUMN card_base_amount_cents BIGINT UNSIGNED NULL AFTER amount_cents,
    ADD COLUMN card_installment_surcharge_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER card_base_amount_cents,
    ADD COLUMN card_base_provider_rate DECIMAL(7,4) NULL AFTER card_installment_surcharge_cents,
    ADD COLUMN card_provider_rate DECIMAL(7,4) NULL AFTER card_base_provider_rate;

ALTER TABLE orders
    ADD COLUMN payment_surcharge_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_total;
