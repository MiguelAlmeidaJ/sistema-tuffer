ALTER TABLE products
    ADD COLUMN moderation_status ENUM('pending','under_review','approved','changes_requested','rejected') NOT NULL DEFAULT 'pending' AFTER status,
    ADD COLUMN platform_paused BOOLEAN NOT NULL DEFAULT FALSE AFTER moderation_status,
    ADD COLUMN moderation_reason TEXT NULL AFTER platform_paused,
    ADD COLUMN moderated_by BIGINT UNSIGNED NULL AFTER moderation_reason,
    ADD COLUMN moderated_at DATETIME NULL AFTER moderated_by,
    ADD CONSTRAINT fk_products_moderated_by FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_products_moderation (moderation_status, platform_paused);

UPDATE products
SET moderation_status = CASE
        WHEN status = 'active' THEN 'approved'
        ELSE 'pending'
    END,
    moderated_at = CASE WHEN status = 'active' THEN updated_at ELSE NULL END;

CREATE TABLE product_moderation_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    admin_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    previous_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NULL,
    reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_moderation_history_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_moderation_history_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_product_moderation_history_product (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    reason VARCHAR(120) NOT NULL,
    description TEXT NULL,
    status ENUM('open','under_review','resolved','dismissed') NOT NULL DEFAULT 'open',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_reports_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_product_reports_product_status (product_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (slug, name) VALUES
    ('catalog.view', 'Visualizar catálogo global'),
    ('catalog.review', 'Revisar anúncios'),
    ('catalog.approve', 'Aprovar anúncios'),
    ('catalog.request_changes', 'Solicitar correções em anúncios'),
    ('catalog.pause', 'Pausar anúncios pela plataforma'),
    ('catalog.reject', 'Rejeitar anúncios'),
    ('catalog.edit_global_data', 'Editar dados globais do catálogo'),
    ('catalog.feature', 'Destacar produtos'),
    ('catalog.export', 'Exportar catálogo global')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug = 'admin' AND p.slug LIKE 'catalog.%';
