ALTER TABLE stores
    ADD COLUMN commission_rate DECIMAL(5,2) NULL AFTER seller_id,
    ADD CONSTRAINT chk_stores_commission_rate
        CHECK (commission_rate IS NULL OR (commission_rate >= 0 AND commission_rate <= 100));
