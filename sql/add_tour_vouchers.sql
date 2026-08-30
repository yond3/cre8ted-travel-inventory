USE cre8ted_inventory;

CREATE TABLE IF NOT EXISTS tour_vouchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    voucher_key VARCHAR(60) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    tour_package VARCHAR(150) NULL,
    current_qty INT NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    last_added_qty INT NULL,
    last_restocked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO tour_vouchers
    (voucher_key, label, tour_package, current_qty, notes, last_added_qty, last_restocked_at)
VALUES
    ('500offdomestictour', CONCAT(CONVERT(0xE282B1 USING utf8mb4), '500 off domestic tour'), 'Any domestic package', 12, 'Valid until Dec 2026', 12, NOW()),
    ('10groupdiscount', '10% group discount', 'Group bookings (10+ pax)', 3, NULL, 3, NOW()),
    ('freeairporttransfer', 'Free airport transfer', 'Boracay 3D2N', 0, 'Restock before next promotion', NULL, NULL);
