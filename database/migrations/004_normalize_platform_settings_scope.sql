UPDATE settings SET scope_id = 0 WHERE scope_type = 'platform' AND scope_id IS NULL;

ALTER TABLE settings MODIFY scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0;
