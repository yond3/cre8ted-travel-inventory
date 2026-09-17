-- Run once on an existing cre8ted_inventory database:
--   Get-Content ".\sql\migration_items_assigned_department.sql" | mysql -u root cre8ted_inventory
--
-- Equipment "in use" is assigned to an official department (not a desk/room
-- location). Storage equipment still uses location_id on a storage shelf.

USE cre8ted_inventory;

ALTER TABLE items
    ADD COLUMN assigned_department VARCHAR(100) NULL AFTER location_id;
