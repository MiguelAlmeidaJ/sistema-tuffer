ALTER TABLE products
    MODIFY COLUMN product_type ENUM('simple', 'variable', 'kit', 'digital') NOT NULL DEFAULT 'simple',
    MODIFY COLUMN status ENUM('draft', 'pending', 'active', 'paused', 'rejected', 'archived') NOT NULL DEFAULT 'draft',
    ADD COLUMN primary_category_id BIGINT UNSIGNED NULL AFTER brand_id,
    ADD COLUMN retail_enabled BOOLEAN NOT NULL DEFAULT TRUE AFTER featured,
    ADD COLUMN wholesale_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER retail_enabled,
    ADD COLUMN wholesale_min_quantity INT UNSIGNED NULL AFTER wholesale_enabled,
    ADD COLUMN maximum_order_quantity INT UNSIGNED NULL AFTER wholesale_min_quantity,
    ADD COLUMN allow_variant_mix BOOLEAN NOT NULL DEFAULT TRUE AFTER maximum_order_quantity,
    ADD COLUMN allow_backorder BOOLEAN NOT NULL DEFAULT FALSE AFTER allow_variant_mix,
    ADD COLUMN stock_control ENUM('shared', 'separate') NOT NULL DEFAULT 'shared' AFTER allow_backorder,
    ADD COLUMN package_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER length,
    ADD COLUMN original_packaging BOOLEAN NOT NULL DEFAULT FALSE AFTER package_count,
    ADD COLUMN combine_shipping BOOLEAN NOT NULL DEFAULT TRUE AFTER original_packaging,
    ADD COLUMN scheduled_at DATETIME NULL AFTER combine_shipping,
    ADD CONSTRAINT fk_products_primary_category FOREIGN KEY (primary_category_id) REFERENCES categories(id) ON DELETE SET NULL;

ALTER TABLE product_variants
    ADD COLUMN wholesale_price DECIMAL(12,2) NULL AFTER promotional_price;

CREATE TABLE product_wholesale_tiers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
    minimum_quantity INT UNSIGNED NOT NULL,
    maximum_quantity INT UNSIGNED NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wholesale_tier_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_wholesale_tier_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    INDEX idx_wholesale_tier_product_quantity (product_id, minimum_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_specifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    value VARCHAR(500) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_product_specification_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_specification_order (product_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_shipping_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    minimum_quantity INT UNSIGNED NOT NULL,
    maximum_quantity INT UNSIGNED NULL,
    weight DECIMAL(10,3) NOT NULL,
    width DECIMAL(10,2) NOT NULL,
    height DECIMAL(10,2) NOT NULL,
    length DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_product_shipping_rule_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_shipping_rule_quantity (product_id, minimum_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_seo (
    product_id BIGINT UNSIGNED PRIMARY KEY,
    title VARCHAR(190) NULL,
    description VARCHAR(320) NULL,
    keywords VARCHAR(500) NULL,
    share_media_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_seo_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_seo_media FOREIGN KEY (share_media_id) REFERENCES product_media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_inventory_channels (
    variant_id BIGINT UNSIGNED PRIMARY KEY,
    retail_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    wholesale_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_inventory_channel_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
