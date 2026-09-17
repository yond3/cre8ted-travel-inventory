-- Run once on an existing cre8ted_inventory database:
--   Get-Content ".\sql\migration_po_receipts.sql" | mysql -u root cre8ted_inventory
--
-- Adds receipt upload fields to purchase_orders. A receipt must be attached
-- before a Placed order can be marked Received (see purchase_orders.php).

USE cre8ted_inventory;

ALTER TABLE purchase_orders
    ADD COLUMN receipt_filename VARCHAR(255) NULL AFTER received_at,
    ADD COLUMN receipt_original_name VARCHAR(255) NULL AFTER receipt_filename,
    ADD COLUMN receipt_mime VARCHAR(100) NULL AFTER receipt_original_name,
    ADD COLUMN receipt_amount DECIMAL(10,2) NULL AFTER receipt_mime,
    ADD COLUMN receipt_number VARCHAR(80) NULL AFTER receipt_amount,
    ADD COLUMN receipt_notes VARCHAR(255) NULL AFTER receipt_number,
    ADD COLUMN receipt_uploaded_at DATETIME NULL AFTER receipt_notes,
    ADD COLUMN receipt_uploaded_by VARCHAR(100) NULL AFTER receipt_uploaded_at;
