-- Cre8ted Travel inventory system - full MySQL schema + demo seed data
-- Regenerate with sql/generate_seed.py after changing the seed data in that file.

CREATE DATABASE IF NOT EXISTS wayfarer_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wayfarer_inventory;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS tour_vouchers;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS purchase_requests;
DROP TABLE IF EXISTS vendor_application_prices;
DROP TABLE IF EXISTS vendor_applications;
DROP TABLE IF EXISTS supplier_prices;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS usage_log;
DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS locations;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    location_type ENUM('storage','in_use') NOT NULL DEFAULT 'storage',
    description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE items (
    item_key VARCHAR(50) PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    item_type ENUM('consumable','equipment') NOT NULL DEFAULT 'consumable',
    location_id INT NULL,
    current_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
    min_qty DECIMAL(10,2) NULL,
    max_qty DECIMAL(10,2) NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE usage_log (
    item_key VARCHAR(50) NOT NULL,
    month DATE NOT NULL,
    usage_qty DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (item_key, month),
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact VARCHAR(150) NULL,
    rating DECIMAL(2,1) NULL,
    procurement_methods VARCHAR(100) NOT NULL DEFAULT 'walk_in',
    notes VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE supplier_prices (
    supplier_id INT NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    last_purchase_date DATE NULL,
    PRIMARY KEY (supplier_id, item_key),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE vendor_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_code VARCHAR(20) NOT NULL UNIQUE,
    company_name VARCHAR(150) NOT NULL,
    contact VARCHAR(150) NULL,
    procurement_methods VARCHAR(100) NOT NULL DEFAULT 'walk_in',
    notes VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    supplier_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE vendor_application_prices (
    application_id INT NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (application_id, item_key),
    FOREIGN KEY (application_id) REFERENCES vendor_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE purchase_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(20) NOT NULL UNIQUE,
    employee VARCHAR(100) NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    notes VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected','Ordered','Completed') NOT NULL DEFAULT 'Pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_key) REFERENCES items(item_key)
) ENGINE=InnoDB;

CREATE TABLE purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_code VARCHAR(20) NOT NULL UNIQUE,
    request_id INT NOT NULL,
    supplier_id INT NULL,
    procurement_method ENUM('walk_in','pickup','delivery','online') NOT NULL DEFAULT 'walk_in',
    assigned_to VARCHAR(100) NULL,
    amount DECIMAL(10,2) NULL,
    status ENUM('Placed','Received','Cancelled') NOT NULL DEFAULT 'Placed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_at DATETIME NULL,
    FOREIGN KEY (request_id) REFERENCES purchase_requests(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB;

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_code VARCHAR(20) NOT NULL UNIQUE,
    doc_type VARCHAR(60) NOT NULL,
    ref_code VARCHAR(20) NULL,
    status VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE tour_vouchers (
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

INSERT INTO locations (name, location_type, description) VALUES
('Cabinet A / Drawer 1', 'storage', 'Ink and toner'),
('Cabinet A / Drawer 2', 'storage', 'Paper stock'),
('Cabinet A / Drawer 3', 'storage', 'Envelopes, receipt books, misc.'),
('Cabinet A', 'storage', 'General / printed materials'),
('Shelf B', 'storage', 'General office supplies'),
('Locker', 'storage', 'Cleaning supplies'),
('Reception', 'in_use', 'Equipment used daily at the front desk'),
('Admin desk', 'in_use', 'Equipment at the admin work area'),
('Meeting room', 'in_use', 'Equipment used during meetings');

INSERT INTO items (item_key, label, unit, item_type, location_id, current_qty, min_qty, max_qty) VALUES
('bondpaper', 'Bond paper (A4)', 'reams', 'consumable', 2, 3, 4, 15),
('printerink', 'Printer ink (black)', 'units', 'consumable', 1, 2, 3, 10),
('ballpointpens', 'Ballpoint pens', 'pcs', 'consumable', 5, 25, 10, 50),
('businesscards', 'Business cards', 'pcs', 'consumable', 4, 100, 50, 200),
('cleaningsupplies', 'Cleaning supplies', 'sets', 'consumable', 6, 2, 3, 10),
('envelopes', 'Envelopes (long)', 'pcs', 'consumable', 3, 3, 5, 20),
('receiptbooks', 'Receipt books', 'pcs', 'consumable', 3, 8, 2, 10),
('printer', 'Printer (HP LaserJet)', 'unit', 'equipment', 7, 1, NULL, NULL),
('extensioncord', 'Extension cord', 'unit', 'equipment', 7, 2, NULL, NULL);

INSERT INTO usage_log (item_key, month, usage_qty) VALUES
('bondpaper', '2024-07-01', 4),
('bondpaper', '2024-08-01', 5),
('bondpaper', '2024-09-01', 4),
('bondpaper', '2024-10-01', 3),
('bondpaper', '2024-11-01', 5),
('bondpaper', '2024-12-01', 7),
('bondpaper', '2025-01-01', 6),
('bondpaper', '2025-02-01', 5),
('bondpaper', '2025-03-01', 7),
('bondpaper', '2025-04-01', 7),
('bondpaper', '2025-05-01', 7),
('bondpaper', '2025-06-01', 5),
('bondpaper', '2025-07-01', 4),
('bondpaper', '2025-08-01', 4),
('bondpaper', '2025-09-01', 4),
('bondpaper', '2025-10-01', 5),
('bondpaper', '2025-11-01', 5),
('bondpaper', '2025-12-01', 7),
('bondpaper', '2026-01-01', 7),
('bondpaper', '2026-02-01', 5),
('bondpaper', '2026-03-01', 7),
('bondpaper', '2026-04-01', 7),
('bondpaper', '2026-05-01', 8),
('bondpaper', '2026-06-01', 6),
('printerink', '2024-07-01', 2),
('printerink', '2024-08-01', 2),
('printerink', '2024-09-01', 2),
('printerink', '2024-10-01', 1),
('printerink', '2024-11-01', 3),
('printerink', '2024-12-01', 4),
('printerink', '2025-01-01', 3),
('printerink', '2025-02-01', 3),
('printerink', '2025-03-01', 4),
('printerink', '2025-04-01', 3),
('printerink', '2025-05-01', 4),
('printerink', '2025-06-01', 2),
('printerink', '2025-07-01', 2),
('printerink', '2025-08-01', 2),
('printerink', '2025-09-01', 3),
('printerink', '2025-10-01', 2),
('printerink', '2025-11-01', 3),
('printerink', '2025-12-01', 3),
('printerink', '2026-01-01', 4),
('printerink', '2026-02-01', 2),
('printerink', '2026-03-01', 4),
('printerink', '2026-04-01', 4),
('printerink', '2026-05-01', 4),
('printerink', '2026-06-01', 3),
('ballpointpens', '2024-07-01', 8),
('ballpointpens', '2024-08-01', 10),
('ballpointpens', '2024-09-01', 10),
('ballpointpens', '2024-10-01', 7),
('ballpointpens', '2024-11-01', 8),
('ballpointpens', '2024-12-01', 10),
('ballpointpens', '2025-01-01', 12),
('ballpointpens', '2025-02-01', 8),
('ballpointpens', '2025-03-01', 12),
('ballpointpens', '2025-04-01', 9),
('ballpointpens', '2025-05-01', 13),
('ballpointpens', '2025-06-01', 8),
('ballpointpens', '2025-07-01', 9),
('ballpointpens', '2025-08-01', 8),
('ballpointpens', '2025-09-01', 8),
('ballpointpens', '2025-10-01', 9),
('ballpointpens', '2025-11-01', 10),
('ballpointpens', '2025-12-01', 11),
('ballpointpens', '2026-01-01', 11),
('ballpointpens', '2026-02-01', 10),
('ballpointpens', '2026-03-01', 10),
('ballpointpens', '2026-04-01', 10),
('ballpointpens', '2026-05-01', 12),
('ballpointpens', '2026-06-01', 8),
('businesscards', '2024-07-01', 15),
('businesscards', '2024-08-01', 17),
('businesscards', '2024-09-01', 17),
('businesscards', '2024-10-01', 17),
('businesscards', '2024-11-01', 19),
('businesscards', '2024-12-01', 19),
('businesscards', '2025-01-01', 20),
('businesscards', '2025-02-01', 12),
('businesscards', '2025-03-01', 21),
('businesscards', '2025-04-01', 24),
('businesscards', '2025-05-01', 21),
('businesscards', '2025-06-01', 17),
('businesscards', '2025-07-01', 11),
('businesscards', '2025-08-01', 16),
('businesscards', '2025-09-01', 14),
('businesscards', '2025-10-01', 20),
('businesscards', '2025-11-01', 18),
('businesscards', '2025-12-01', 24),
('businesscards', '2026-01-01', 22),
('businesscards', '2026-02-01', 17),
('businesscards', '2026-03-01', 23),
('businesscards', '2026-04-01', 21),
('businesscards', '2026-05-01', 21),
('businesscards', '2026-06-01', 16),
('cleaningsupplies', '2024-07-01', 4),
('cleaningsupplies', '2024-08-01', 1),
('cleaningsupplies', '2024-09-01', 4),
('cleaningsupplies', '2024-10-01', 3),
('cleaningsupplies', '2024-11-01', 4),
('cleaningsupplies', '2024-12-01', 4),
('cleaningsupplies', '2025-01-01', 5),
('cleaningsupplies', '2025-02-01', 3),
('cleaningsupplies', '2025-03-01', 3),
('cleaningsupplies', '2025-04-01', 4),
('cleaningsupplies', '2025-05-01', 4),
('cleaningsupplies', '2025-06-01', 3),
('cleaningsupplies', '2025-07-01', 3),
('cleaningsupplies', '2025-08-01', 4),
('cleaningsupplies', '2025-09-01', 4),
('cleaningsupplies', '2025-10-01', 3),
('cleaningsupplies', '2025-11-01', 3),
('cleaningsupplies', '2025-12-01', 2),
('cleaningsupplies', '2026-01-01', 5),
('cleaningsupplies', '2026-02-01', 3),
('cleaningsupplies', '2026-03-01', 4),
('cleaningsupplies', '2026-04-01', 4),
('cleaningsupplies', '2026-05-01', 5),
('cleaningsupplies', '2026-06-01', 4);

INSERT INTO suppliers (name, contact, rating, procurement_methods, notes) VALUES
('National Book Store', '(02) 8123-4567', 4.5, 'walk_in,pickup', NULL),
('Office Warehouse', '(02) 8234-5678', 4.2, 'delivery,pickup', NULL),
('SM Department Store', '(02) 8345-6789', 4.0, 'walk_in', NULL),
('Shopee (online)', 'App / website', 4.3, 'online', NULL),
('Lazada (online)', 'App / website', 4.1, 'online', NULL),
('Local computer shop', '0917-123-4567', 4.4, 'walk_in,delivery', NULL);

INSERT INTO supplier_prices (supplier_id, item_key, price, last_purchase_date) VALUES
(1, 'bondpaper', 195, '2026-07-15'),
(1, 'ballpointpens', 8, '2026-07-15'),
(1, 'businesscards', 350, '2026-07-15'),
(2, 'printerink', 650, '2026-07-15'),
(2, 'bondpaper', 210, '2026-07-15'),
(2, 'cleaningsupplies', 120, '2026-07-15'),
(3, 'cleaningsupplies', 135, '2026-07-15'),
(3, 'businesscards', 380, '2026-07-15'),
(4, 'businesscards', 300, '2026-07-15'),
(4, 'extensioncord', 150, '2026-07-15'),
(5, 'extensioncord', 140, '2026-07-15'),
(5, 'bondpaper', 185, '2026-07-15'),
(6, 'printerink', 600, '2026-07-15'),
(6, 'extensioncord', 160, '2026-07-15'),
(6, 'printer', 3500, '2026-07-15');

INSERT INTO purchase_requests (request_code, employee, item_key, qty, notes, status, created_at) VALUES
('PR-2026-014', 'Maria Santos', 'printerink', 2, NULL, 'Pending', '2026-07-24 09:00:00'),
('PR-2026-013', 'Juan Dela Cruz', 'envelopes', 1, NULL, 'Approved', '2026-07-22 10:15:00'),
('PR-2026-012', 'Maria Santos', 'receiptbooks', 2, NULL, 'Ordered', '2026-07-18 08:30:00'),
('PR-2026-011', 'Ana Reyes', 'bondpaper', 2, NULL, 'Completed', '2026-07-10 14:00:00');

INSERT INTO purchase_orders (po_code, request_id, supplier_id, procurement_method, assigned_to, amount, status, created_at, received_at) VALUES
('PO-2026-009', 3, 1, 'walk_in', 'Maria Santos', 16, 'Placed', '2026-07-18 09:00:00', NULL),
('PO-2026-008', 4, 2, 'delivery', 'Juan Dela Cruz', 420, 'Received', '2026-07-11 09:00:00', '2026-07-18 15:00:00');

INSERT INTO documents (doc_code, doc_type, ref_code, status, created_at) VALUES
('DOC-045', 'Purchase request', 'PR-2026-014', 'Pending', '2026-07-24 09:00:00'),
('DOC-044', 'Purchase request', 'PR-2026-013', 'Approved', '2026-07-22 10:20:00'),
('DOC-043', 'Purchase request', 'PR-2026-012', 'Ordered', '2026-07-18 08:35:00'),
('DOC-042', 'Purchase request', 'PR-2026-011', 'Completed', '2026-07-10 14:05:00'),
('DOC-041', 'Purchase order', 'PO-2026-009', 'Placed', '2026-07-18 09:05:00'),
('DOC-040', 'Purchase order', 'PO-2026-008', 'Received', '2026-07-18 15:05:00');

INSERT INTO tour_vouchers
    (voucher_key, label, tour_package, current_qty, notes, last_added_qty, last_restocked_at)
VALUES
    ('500offdomestictour', CONCAT(CONVERT(0xE282B1 USING utf8mb4), '500 off domestic tour'), 'Any domestic package', 12, 'Valid until Dec 2026', 12, '2026-08-01 09:00:00'),
    ('10groupdiscount', '10% group discount', 'Group bookings (10+ pax)', 3, NULL, 3, '2026-08-01 09:00:00'),
    ('freeairporttransfer', 'Free airport transfer', 'Boracay 3D2N', 0, 'Restock before next promotion', NULL, NULL);
