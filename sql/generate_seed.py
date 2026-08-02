"""Generates sql/schema.sql — the full MySQL schema for the Cre8ted Travel
system: locations, items (consumable + equipment), usage history (for
Prophet), suppliers + prices, and the full procurement flow (requests ->
orders -> documents). Re-run this after changing the seed data below."""
import pandas as pd

_SEED_MONTHS = [d.strftime("%Y-%m-%d") for d in pd.date_range("2024-07-01", periods=24, freq="MS")]

# ---------------------------------------------------------------------------
# Locations — Storage (cabinets/shelves) vs In use (desks/rooms where an item
# lives permanently because it's always needed there, e.g. a printer).
# ---------------------------------------------------------------------------
_LOCATIONS = [
    # (name, type, description)
    ("Cabinet A / Drawer 1", "storage", "Ink and toner"),
    ("Cabinet A / Drawer 2", "storage", "Paper stock"),
    ("Cabinet A / Drawer 3", "storage", "Envelopes, receipt books, misc."),
    ("Cabinet A", "storage", "General / printed materials"),
    ("Shelf B", "storage", "General office supplies"),
    ("Locker", "storage", "Cleaning supplies"),
    # In-use locations are places, not people — assignees belong on requests/
    # POs, not baked into a location name (people change desks; places don't).
    ("Reception", "in_use", "Equipment used daily at the front desk"),
    ("Admin desk", "in_use", "Equipment at the admin work area"),
    ("Meeting room", "in_use", "Equipment used during meetings"),
]

# ---------------------------------------------------------------------------
# Items — consumables have min/max and feed the AI forecast; equipment does
# not (nothing to "reorder" for a printer — you track quantity & location only).
# ---------------------------------------------------------------------------
_CONSUMABLES = {
    "bondpaper": {
        "label": "Bond paper (A4)", "unit": "reams", "location": "Cabinet A / Drawer 2",
        "current": 3, "min": 4, "max": 15,
        "values": [4, 5, 4, 3, 5, 7, 6, 5, 7, 7, 7, 5, 4, 4, 4, 5, 5, 7, 7, 5, 7, 7, 8, 6],
    },
    "printerink": {
        "label": "Printer ink (black)", "unit": "units", "location": "Cabinet A / Drawer 1",
        "current": 2, "min": 3, "max": 10,
        "values": [2, 2, 2, 1, 3, 4, 3, 3, 4, 3, 4, 2, 2, 2, 3, 2, 3, 3, 4, 2, 4, 4, 4, 3],
    },
    "ballpointpens": {
        "label": "Ballpoint pens", "unit": "pcs", "location": "Shelf B",
        "current": 25, "min": 10, "max": 50,
        "values": [8, 10, 10, 7, 8, 10, 12, 8, 12, 9, 13, 8, 9, 8, 8, 9, 10, 11, 11, 10, 10, 10, 12, 8],
    },
    "businesscards": {
        "label": "Business cards", "unit": "pcs", "location": "Cabinet A",
        "current": 100, "min": 50, "max": 200,
        "values": [15, 17, 17, 17, 19, 19, 20, 12, 21, 24, 21, 17, 11, 16, 14, 20, 18, 24, 22, 17, 23, 21, 21, 16],
    },
    "cleaningsupplies": {
        "label": "Cleaning supplies", "unit": "sets", "location": "Locker",
        "current": 2, "min": 3, "max": 10,
        "values": [4, 1, 4, 3, 4, 4, 5, 3, 3, 4, 4, 3, 3, 4, 4, 3, 3, 2, 5, 3, 4, 4, 5, 4],
    },
    # New items with no usage history yet — demonstrates items too new for a
    # forecast (Prophet needs 2+ months); "not enough usage history" case.
    "envelopes": {
        "label": "Envelopes (long)", "unit": "pcs", "location": "Cabinet A / Drawer 3",
        "current": 3, "min": 5, "max": 20, "values": [],
    },
    "receiptbooks": {
        "label": "Receipt books", "unit": "pcs", "location": "Cabinet A / Drawer 3",
        "current": 8, "min": 2, "max": 10, "values": [],
    },
}

# Equipment: no min/max, no forecast — quantity + location only.
_EQUIPMENT = {
    "printer": {"label": "Printer (HP LaserJet)", "unit": "unit", "location": "Reception", "current": 1},
    "extensioncord": {"label": "Extension cord", "unit": "unit", "location": "Reception", "current": 2},
}

# ---------------------------------------------------------------------------
# Suppliers + prices (per item). procurement_methods: comma list of
# walk_in | pickup | delivery | online — what the office can actually do
# with that vendor.
# ---------------------------------------------------------------------------
_SUPPLIERS = [
    {
        "name": "National Book Store", "contact": "(02) 8123-4567", "rating": 4.5,
        "methods": "walk_in,pickup",
        "prices": {"bondpaper": 195, "ballpointpens": 8, "businesscards": 350},
    },
    {
        "name": "Office Warehouse", "contact": "(02) 8234-5678", "rating": 4.2,
        "methods": "delivery,pickup",
        "prices": {"printerink": 650, "bondpaper": 210, "cleaningsupplies": 120},
    },
    {
        "name": "SM Department Store", "contact": "(02) 8345-6789", "rating": 4.0,
        "methods": "walk_in",
        "prices": {"cleaningsupplies": 135, "businesscards": 380},
    },
    {
        "name": "Shopee (online)", "contact": "App / website", "rating": 4.3,
        "methods": "online",
        "prices": {"businesscards": 300, "extensioncord": 150},
    },
    {
        "name": "Lazada (online)", "contact": "App / website", "rating": 4.1,
        "methods": "online",
        "prices": {"extensioncord": 140, "bondpaper": 185},
    },
    {
        "name": "Local computer shop", "contact": "0917-123-4567", "rating": 4.4,
        "methods": "walk_in,delivery",
        "prices": {"printerink": 600, "extensioncord": 160, "printer": 3500},
    },
]

sql = []


def esc(s):
    return str(s).replace("'", "''")


sql.append("-- Cre8ted Travel inventory system - full MySQL schema + demo seed data")
sql.append("-- Regenerate with sql/generate_seed.py after changing the seed data in that file.")
sql.append("")
sql.append("CREATE DATABASE IF NOT EXISTS wayfarer_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
sql.append("USE wayfarer_inventory;")
sql.append("")
sql.append("SET FOREIGN_KEY_CHECKS = 0;")
for t in ["tour_vouchers", "documents", "purchase_orders", "purchase_requests", "vendor_application_prices",
          "vendor_applications", "supplier_prices", "suppliers",
          "usage_log", "items", "locations"]:
    sql.append(f"DROP TABLE IF EXISTS {t};")
sql.append("SET FOREIGN_KEY_CHECKS = 1;")
sql.append("")

# --- locations ---
sql.append("""CREATE TABLE locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    location_type ENUM('storage','in_use') NOT NULL DEFAULT 'storage',
    description VARCHAR(255) NULL
) ENGINE=InnoDB;""")
sql.append("")

# --- items ---
sql.append("""CREATE TABLE items (
    item_key VARCHAR(50) PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    item_type ENUM('consumable','equipment') NOT NULL DEFAULT 'consumable',
    location_id INT NULL,
    current_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
    min_qty DECIMAL(10,2) NULL,
    max_qty DECIMAL(10,2) NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB;""")
sql.append("")

# --- usage_log (forecast history, consumables only) ---
sql.append("""CREATE TABLE usage_log (
    item_key VARCHAR(50) NOT NULL,
    month DATE NOT NULL,
    usage_qty DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (item_key, month),
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;""")
sql.append("")

# --- suppliers + prices ---
sql.append("""CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact VARCHAR(150) NULL,
    rating DECIMAL(2,1) NULL,
    procurement_methods VARCHAR(100) NOT NULL DEFAULT 'walk_in',
    notes VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;""")
sql.append("")
sql.append("""CREATE TABLE supplier_prices (
    supplier_id INT NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    last_purchase_date DATE NULL,
    PRIMARY KEY (supplier_id, item_key),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;""")
sql.append("")

# --- vendor quotation applications (public link, no login) ---
sql.append("""CREATE TABLE vendor_applications (
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
) ENGINE=InnoDB;""")
sql.append("")
sql.append("""CREATE TABLE vendor_application_prices (
    application_id INT NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (application_id, item_key),
    FOREIGN KEY (application_id) REFERENCES vendor_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;""")
sql.append("")

# --- procurement flow ---
sql.append("""CREATE TABLE purchase_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(20) NOT NULL UNIQUE,
    employee VARCHAR(100) NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    notes VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected','Ordered','Completed') NOT NULL DEFAULT 'Pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_key) REFERENCES items(item_key)
) ENGINE=InnoDB;""")
sql.append("")
sql.append("""CREATE TABLE purchase_orders (
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
) ENGINE=InnoDB;""")
sql.append("")
sql.append("""CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_code VARCHAR(20) NOT NULL UNIQUE,
    doc_type VARCHAR(60) NOT NULL,
    ref_code VARCHAR(20) NULL,
    status VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;""")
sql.append("")
sql.append("""CREATE TABLE tour_vouchers (
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
) ENGINE=InnoDB;""")
sql.append("")

# --- seed: locations ---
loc_rows = [f"('{esc(n)}', '{t}', '{esc(d)}')" for n, t, d in _LOCATIONS]
sql.append("INSERT INTO locations (name, location_type, description) VALUES")
sql.append(",\n".join(loc_rows) + ";")
sql.append("")
loc_id_by_name = {n: i + 1 for i, (n, _, _) in enumerate(_LOCATIONS)}  # AUTO_INCREMENT starts at 1, inserted in order

# --- seed: items ---
item_rows = []
for key, it in _CONSUMABLES.items():
    loc_id = loc_id_by_name[it["location"]]
    item_rows.append(
        f"('{key}', '{esc(it['label'])}', '{it['unit']}', 'consumable', {loc_id}, "
        f"{it['current']}, {it['min']}, {it['max']})"
    )
for key, it in _EQUIPMENT.items():
    loc_id = loc_id_by_name[it["location"]]
    item_rows.append(
        f"('{key}', '{esc(it['label'])}', '{it['unit']}', 'equipment', {loc_id}, "
        f"{it['current']}, NULL, NULL)"
    )
sql.append("INSERT INTO items (item_key, label, unit, item_type, location_id, current_qty, min_qty, max_qty) VALUES")
sql.append(",\n".join(item_rows) + ";")
sql.append("")

# --- seed: usage_log ---
usage_rows = []
for key, it in _CONSUMABLES.items():
    for month, usage in zip(_SEED_MONTHS, it["values"]):
        usage_rows.append(f"('{key}', '{month}', {usage})")
if usage_rows:
    sql.append("INSERT INTO usage_log (item_key, month, usage_qty) VALUES")
    sql.append(",\n".join(usage_rows) + ";")
    sql.append("")

# --- seed: suppliers + prices ---
supplier_rows = []
for s in _SUPPLIERS:
    supplier_rows.append(
        f"('{esc(s['name'])}', '{esc(s['contact'])}', {s['rating']}, '{s['methods']}', NULL)"
    )
sql.append("INSERT INTO suppliers (name, contact, rating, procurement_methods, notes) VALUES")
sql.append(",\n".join(supplier_rows) + ";")
sql.append("")

price_rows = []
for i, s in enumerate(_SUPPLIERS):
    supplier_id = i + 1
    for item_key, price in s["prices"].items():
        price_rows.append(f"({supplier_id}, '{item_key}', {price}, '2026-07-15')")
sql.append("INSERT INTO supplier_prices (supplier_id, item_key, price, last_purchase_date) VALUES")
sql.append(",\n".join(price_rows) + ";")
sql.append("")

# --- seed: procurement flow demo data (one example of each status) ---
sql.append("""INSERT INTO purchase_requests (request_code, employee, item_key, qty, notes, status, created_at) VALUES
('PR-2026-014', 'Maria Santos', 'printerink', 2, NULL, 'Pending', '2026-07-24 09:00:00'),
('PR-2026-013', 'Juan Dela Cruz', 'envelopes', 1, NULL, 'Approved', '2026-07-22 10:15:00'),
('PR-2026-012', 'Maria Santos', 'receiptbooks', 2, NULL, 'Ordered', '2026-07-18 08:30:00'),
('PR-2026-011', 'Ana Reyes', 'bondpaper', 2, NULL, 'Completed', '2026-07-10 14:00:00');""")
sql.append("")

sql.append("""INSERT INTO purchase_orders (po_code, request_id, supplier_id, procurement_method, assigned_to, amount, status, created_at, received_at) VALUES
('PO-2026-009', 3, 1, 'walk_in', 'Maria Santos', 16, 'Placed', '2026-07-18 09:00:00', NULL),
('PO-2026-008', 4, 2, 'delivery', 'Juan Dela Cruz', 420, 'Received', '2026-07-11 09:00:00', '2026-07-18 15:00:00');""")
sql.append("")

sql.append("""INSERT INTO documents (doc_code, doc_type, ref_code, status, created_at) VALUES
('DOC-045', 'Purchase request', 'PR-2026-014', 'Pending', '2026-07-24 09:00:00'),
('DOC-044', 'Purchase request', 'PR-2026-013', 'Approved', '2026-07-22 10:20:00'),
('DOC-043', 'Purchase request', 'PR-2026-012', 'Ordered', '2026-07-18 08:35:00'),
('DOC-042', 'Purchase request', 'PR-2026-011', 'Completed', '2026-07-10 14:05:00'),
('DOC-041', 'Purchase order', 'PO-2026-009', 'Placed', '2026-07-18 09:05:00'),
('DOC-040', 'Purchase order', 'PO-2026-008', 'Received', '2026-07-18 15:05:00');""")
sql.append("")

sql.append("""INSERT INTO tour_vouchers
    (voucher_key, label, tour_package, current_qty, notes, last_added_qty, last_restocked_at)
VALUES
    ('500offdomestictour', CONCAT(CONVERT(0xE282B1 USING utf8mb4), '500 off domestic tour'), 'Any domestic package', 12, 'Valid until Dec 2026', 12, '2026-08-01 09:00:00'),
    ('10groupdiscount', '10% group discount', 'Group bookings (10+ pax)', 3, NULL, 3, '2026-08-01 09:00:00'),
    ('freeairporttransfer', 'Free airport transfer', 'Boracay 3D2N', 0, 'Restock before next promotion', NULL, NULL);""")
sql.append("")

with open("sql/schema.sql", "w", encoding="utf-8") as f:
    f.write("\n".join(sql))

print("Wrote sql/schema.sql")
