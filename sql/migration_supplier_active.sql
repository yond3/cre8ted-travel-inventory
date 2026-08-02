-- Run once on an existing wayfarer_inventory database:
--   mysql -u root wayfarer_inventory < sql/migration_supplier_active.sql

USE wayfarer_inventory;

ALTER TABLE suppliers
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes;
