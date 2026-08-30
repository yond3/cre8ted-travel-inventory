-- Run once on an existing cre8ted_inventory database:
--   Get-Content ".\sql\migration_po_receipt_waiver.sql" | mysql -u root cre8ted_inventory
--
-- Lets a manager record a lost receipt (with note) on a funded Placed PO
-- instead of uploading a file. Requires migration_po_receipts.sql and
-- migration_po_finance_status.sql to already be applied.

USE cre8ted_inventory;

ALTER TABLE purchase_orders
    ADD COLUMN receipt_waived TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_uploaded_by,
    ADD COLUMN receipt_waiver_note VARCHAR(500) NULL AFTER receipt_waived,
    ADD COLUMN receipt_waived_at DATETIME NULL AFTER receipt_waiver_note,
    ADD COLUMN receipt_waived_by VARCHAR(100) NULL AFTER receipt_waived_at;
