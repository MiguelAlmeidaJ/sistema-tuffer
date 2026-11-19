ALTER TABLE products
 ADD COLUMN pieces_per_kit INT UNSIGNED NOT NULL DEFAULT 1 AFTER package_count,
 ADD COLUMN piece_weight_grams DECIMAL(10,3) NULL AFTER pieces_per_kit;
