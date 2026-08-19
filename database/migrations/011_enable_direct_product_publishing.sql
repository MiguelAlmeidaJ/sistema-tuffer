UPDATE products SET status = 'active' WHERE status = 'pending';
UPDATE products SET status = 'draft' WHERE status = 'rejected';

ALTER TABLE products
    MODIFY COLUMN status ENUM('draft', 'active', 'paused', 'archived') NOT NULL DEFAULT 'draft';
