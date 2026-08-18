-- One catalog row per equipment product; department counts live in equipment_deployments.
CREATE TABLE IF NOT EXISTS equipment_deployments (
    item_key VARCHAR(50) NOT NULL,
    department VARCHAR(100) NOT NULL,
    qty DECIMAL(10,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (item_key, department),
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS _migration_equipment_canonical (
    label VARCHAR(100) NOT NULL PRIMARY KEY,
    canonical_key VARCHAR(50) NOT NULL
);

INSERT INTO _migration_equipment_canonical (label, canonical_key)
SELECT label,
       COALESCE(
           MAX(CASE WHEN item_key = 'extensioncord' THEN item_key END),
           MAX(CASE WHEN item_key = 'extensionchord' THEN 'extensioncord' END),
           MAX(CASE WHEN location_id IS NOT NULL AND (assigned_department IS NULL OR assigned_department = '') THEN item_key END),
           MIN(item_key)
       ) AS canonical_key
FROM items
WHERE item_type = 'equipment'
GROUP BY label
ON DUPLICATE KEY UPDATE canonical_key = VALUES(canonical_key);

INSERT INTO equipment_deployments (item_key, department, qty)
SELECT c.canonical_key, i.assigned_department, SUM(i.current_qty)
FROM items i
INNER JOIN _migration_equipment_canonical c ON c.label = i.label
WHERE i.item_type = 'equipment'
  AND i.assigned_department IS NOT NULL
  AND TRIM(i.assigned_department) != ''
  AND i.current_qty > 0
GROUP BY c.canonical_key, i.assigned_department
ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty);

UPDATE items canon
INNER JOIN _migration_equipment_canonical c ON c.canonical_key = canon.item_key
INNER JOIN (
    SELECT c2.label, COALESCE(SUM(i2.current_qty), 0) AS storage_qty
    FROM items i2
    INNER JOIN _migration_equipment_canonical c2 ON c2.label = i2.label
    WHERE i2.item_type = 'equipment'
      AND i2.location_id IS NOT NULL
      AND (i2.assigned_department IS NULL OR TRIM(i2.assigned_department) = '')
    GROUP BY c2.label
) storage_totals ON storage_totals.label = c.label
LEFT JOIN (
    SELECT c3.label, MIN(i3.location_id) AS pick_loc
    FROM items i3
    INNER JOIN _migration_equipment_canonical c3 ON c3.label = i3.label
    WHERE i3.location_id IS NOT NULL
    GROUP BY c3.label
) loc_pick ON loc_pick.label = c.label
SET canon.current_qty = storage_totals.storage_qty,
    canon.location_id = COALESCE(canon.location_id, loc_pick.pick_loc),
    canon.assigned_department = NULL,
    canon.active = 1;

UPDATE items i
INNER JOIN _migration_equipment_canonical c ON c.label = i.label
SET i.active = 0, i.current_qty = 0, i.assigned_department = NULL, i.location_id = NULL
WHERE i.item_type = 'equipment'
  AND i.item_key != c.canonical_key;

UPDATE stock_issues si
INNER JOIN items old ON old.item_key = si.item_key
INNER JOIN _migration_equipment_canonical c ON c.label = old.label
SET si.item_key = c.canonical_key
WHERE old.item_type = 'equipment' AND si.item_key != c.canonical_key;

UPDATE stock_requests sr
INNER JOIN items old ON old.item_key = sr.item_key
INNER JOIN _migration_equipment_canonical c ON c.label = old.label
SET sr.item_key = c.canonical_key
WHERE old.item_type = 'equipment' AND sr.item_key != c.canonical_key;

UPDATE purchase_requests pr
INNER JOIN items old ON old.item_key = pr.item_key
INNER JOIN _migration_equipment_canonical c ON c.label = old.label
SET pr.item_key = c.canonical_key
WHERE old.item_type = 'equipment' AND pr.item_key IS NOT NULL AND pr.item_key != c.canonical_key;

UPDATE items canon
INNER JOIN _migration_equipment_canonical c ON c.canonical_key = canon.item_key
SET canon.assigned_department = NULL
WHERE canon.item_type = 'equipment'
  AND canon.assigned_department IS NOT NULL;

UPDATE items canon
INNER JOIN _migration_equipment_canonical c ON c.canonical_key = canon.item_key
SET canon.current_qty = 0
WHERE canon.item_type = 'equipment'
  AND canon.location_id IS NULL
  AND EXISTS (
      SELECT 1 FROM equipment_deployments ed
      WHERE ed.item_key = canon.item_key AND ed.qty > 0
  );

UPDATE supplier_prices sp
INNER JOIN items old ON old.item_key = sp.item_key
INNER JOIN _migration_equipment_canonical c ON c.label = old.label
SET sp.item_key = c.canonical_key
WHERE old.item_type = 'equipment' AND sp.item_key != c.canonical_key;

DROP TABLE IF EXISTS _migration_equipment_canonical;
