-- A Tuffer não emite NF-e. Cada loja é responsável pelo próprio emissor.
-- Valores legados de `platform` são convertidos para registro manual.

UPDATE store_fiscal_profiles
SET issuance_mode = 'manual',
    provider = 'manual'
WHERE issuance_mode = 'platform';

UPDATE fiscal_documents
SET issuance_mode = 'manual',
    provider = CASE WHEN provider = 'disabled' THEN 'manual' ELSE provider END,
    requires_action = CASE
        WHEN status IN ('ready','validation_failed','error','configuration_required','pending','submitting','processing','rejected') THEN 1
        ELSE requires_action
    END,
    action_reason = CASE
        WHEN status IN ('ready','validation_failed','error','configuration_required','pending','submitting','processing','rejected') THEN 'A loja deve emitir a NF-e no próprio sistema e vinculá-la à Tuffer.'
        ELSE action_reason
    END,
    status = CASE
        WHEN status IN ('ready','validation_failed','error','configuration_required','pending','submitting','processing','rejected') THEN 'awaiting_manual'
        ELSE status
    END
WHERE issuance_mode = 'platform';

ALTER TABLE store_fiscal_profiles
    MODIFY COLUMN issuance_mode ENUM('manual','external') NOT NULL DEFAULT 'manual',
    MODIFY COLUMN provider VARCHAR(60) NOT NULL DEFAULT 'manual',
    DROP COLUMN auto_issue;
