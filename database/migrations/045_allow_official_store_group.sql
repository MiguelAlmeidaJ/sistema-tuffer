ALTER TABLE stores
    DROP INDEX uk_stores_single_official,
    DROP COLUMN official_store_guard;

UPDATE stores
SET is_official_store=1
WHERE LOWER(TRIM(name))='linda flor'
   OR LOWER(TRIM(slug))='linda-flor';
