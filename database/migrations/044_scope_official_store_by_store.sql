ALTER TABLE stores
    ADD COLUMN is_official_store TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN official_store_guard TINYINT
        GENERATED ALWAYS AS (CASE WHEN is_official_store = 1 THEN 1 ELSE NULL END) STORED,
    ADD UNIQUE KEY uk_stores_single_official (official_store_guard);

UPDATE stores st
JOIN sellers s ON s.id=st.seller_id
SET st.is_official_store=1
WHERE s.is_official_store=1
  AND st.id=(
      SELECT chosen.id
      FROM (
          SELECT st2.id
          FROM stores st2
          JOIN sellers s2 ON s2.id=st2.seller_id
          WHERE s2.is_official_store=1
          ORDER BY
              (LOWER(st2.slug)='tuffer-oficial') DESC,
              (LOWER(st2.name)='tuffer oficial') DESC,
              (LOWER(st2.slug) LIKE 'tuffer%') DESC,
              (LOWER(st2.name) LIKE 'tuffer%') DESC,
              (st2.status='active') DESC,
              st2.id ASC
          LIMIT 1
      ) chosen
  );
