-- Equipment groups: one catalog row per variant; equipment_group is the expandable parent label.
ALTER TABLE items ADD COLUMN equipment_group VARCHAR(100) NULL AFTER label;

-- "Printer (HP LaserJet)" -> group Printer, variant HP LaserJet
UPDATE items
SET equipment_group = TRIM(SUBSTRING_INDEX(label, '(', 1)),
    label = TRIM(TRAILING ')' FROM TRIM(SUBSTRING(label, LOCATE('(', label) + 1)))
WHERE item_type = 'equipment'
  AND label LIKE '%(%'
  AND label LIKE '%)%'
  AND LOCATE('(', label) > 0;

-- Everything else: group = product name, variant = Standard (rename in Edit when you know model/length)
UPDATE items
SET equipment_group = label,
    label = 'Standard'
WHERE item_type = 'equipment'
  AND (equipment_group IS NULL OR TRIM(equipment_group) = '');
