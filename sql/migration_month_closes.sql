-- Run once on an existing wayfarer_inventory database:
--   mysql -u root wayfarer_inventory < sql/migration_month_closes.sql
--
-- Stores opening/received/closing each time a month is closed so the next
-- month's opening is remembered automatically (no manual recall needed).

USE wayfarer_inventory;

CREATE TABLE IF NOT EXISTS month_closes (
    item_key VARCHAR(50) NOT NULL,
    month DATE NOT NULL,
    opening_qty DECIMAL(10,2) NOT NULL,
    received_qty DECIMAL(10,2) NOT NULL,
    closing_qty DECIMAL(10,2) NOT NULL,
    usage_qty DECIMAL(10,2) NOT NULL,
    closed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (item_key, month),
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;
