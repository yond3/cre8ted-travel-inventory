-- Run once on an existing cre8ted_inventory database:
--   mysql -u root cre8ted_inventory < sql/migration_supplier_active.sql

USE cre8ted_inventory;

ALTER TABLE suppliers
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes;
