CREATE TRIGGER trg_order_addresses_snapshot_city_ibge
BEFORE INSERT ON order_addresses
FOR EACH ROW
SET NEW.city_ibge_code = COALESCE(
    NEW.city_ibge_code,
    (
        SELECT ua.city_ibge_code
        FROM orders o
        JOIN user_addresses ua ON ua.user_id = o.user_id
        WHERE o.id = NEW.order_id
          AND ua.postal_code = NEW.postal_code
          AND ua.street = NEW.street
          AND ua.number = NEW.number
          AND ua.city = NEW.city
          AND ua.state = NEW.state
        ORDER BY ua.is_default DESC, ua.id DESC
        LIMIT 1
    )
);
