ALTER TABLE categories
    ADD COLUMN image_path VARCHAR(255) NULL AFTER description,
    ADD COLUMN support_text VARCHAR(300) NULL AFTER image_path,
    ADD COLUMN meta_title VARCHAR(190) NULL AFTER support_text,
    ADD COLUMN meta_description VARCHAR(500) NULL AFTER meta_title,
    ADD COLUMN show_in_menu BOOLEAN NOT NULL DEFAULT TRUE AFTER sort_order,
    ADD COLUMN show_in_home BOOLEAN NOT NULL DEFAULT FALSE AFTER show_in_menu,
    ADD COLUMN is_featured BOOLEAN NOT NULL DEFAULT FALSE AFTER show_in_home,
    ADD COLUMN allow_products BOOLEAN NOT NULL DEFAULT TRUE AFTER is_featured,
    ADD COLUMN customer_visible BOOLEAN NOT NULL DEFAULT TRUE AFTER allow_products,
    ADD INDEX idx_categories_public_home (status, customer_visible, show_in_home, is_featured, sort_order),
    ADD INDEX idx_categories_public_menu (status, customer_visible, show_in_menu, sort_order);
