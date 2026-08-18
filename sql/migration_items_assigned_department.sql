-- Run once on an existing wayfarer_inventory database:
--   Get-Content ".\sql\migration_items_assigned_department.sql" | mysql -u root wayfarer_inventory
--
-- Equipment "in use" is assigned to an official department (not a desk/room
-- location). Storage equipment still uses location_id on a storage shelf.

USE wayfarer_inventory;

ALTER TABLE items
    ADD COLUMN assigned_department VARCHAR(100) NULL AFTER location_id;
