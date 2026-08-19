ALTER TABLE product_media
    DROP INDEX uk_product_media_public_id,
    ADD INDEX idx_product_media_public_id (public_id);
