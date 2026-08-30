-- Run once on an existing cre8ted_inventory database:
--   mysql -u root cre8ted_inventory < sql/migration_items_locations_active.sql

USE cre8ted_inventory;

ALTER TABLE items
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER max_qty;

ALTER TABLE locations
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER description;
