UPDATE settings
SET setting_value = REPLACE(setting_value, '"logos/', '"platform/logos/')
WHERE scope_type = 'platform' AND setting_value LIKE '"logos/%';

UPDATE settings
SET setting_value = REPLACE(setting_value, '"banners/', '"platform/banners/')
WHERE scope_type = 'platform' AND setting_value LIKE '"banners/%';

UPDATE settings
SET setting_value = REPLACE(setting_value, '"favicon/', '"platform/favicon/')
WHERE scope_type = 'platform' AND setting_value LIKE '"favicon/%';
