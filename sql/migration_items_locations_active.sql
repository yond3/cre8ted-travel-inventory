-- Run once on an existing wayfarer_inventory database:
--   mysql -u root wayfarer_inventory < sql/migration_items_locations_active.sql

USE wayfarer_inventory;

ALTER TABLE items
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER max_qty;

ALTER TABLE locations
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER description;
