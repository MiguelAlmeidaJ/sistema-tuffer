ALTER TABLE products
  ADD COLUMN shipping_box_capacity INT UNSIGNED NULL AFTER piece_weight_grams,
  ADD COLUMN shipping_box_tare_kg DECIMAL(10,3) NULL AFTER shipping_box_capacity,
  ADD COLUMN shipping_box_width DECIMAL(10,2) NULL AFTER shipping_box_tare_kg,
  ADD COLUMN shipping_box_height DECIMAL(10,2) NULL AFTER shipping_box_width,
  ADD COLUMN shipping_box_length DECIMAL(10,2) NULL AFTER shipping_box_height;
