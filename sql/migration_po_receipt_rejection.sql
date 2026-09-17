-- Run once on an existing cre8ted_inventory database:
--   Get-Content ".\sql\migration_po_receipt_rejection.sql" | mysql -u root cre8ted_inventory
--
-- Lets a manager reject an uploaded receipt (with a required note) instead of
-- only accepting-or-ignoring it. While rejected, the order cannot be marked
-- Received; the staff who uploaded it can attach a corrected file, which
-- clears the rejection. Requires migration_po_receipts.sql and
-- migration_po_receipt_waiver.sql to already be applied.

USE cre8ted_inventory;

ALTER TABLE purchase_orders
    ADD COLUMN receipt_rejected TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_waived_by,
    ADD COLUMN receipt_rejection_note VARCHAR(500) NULL AFTER receipt_rejected,
    ADD COLUMN receipt_rejected_at DATETIME NULL AFTER receipt_rejection_note,
    ADD COLUMN receipt_rejected_by VARCHAR(100) NULL AFTER receipt_rejected_at;
