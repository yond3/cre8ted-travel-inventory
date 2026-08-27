-- Apply on an existing database:
--   Get-Content ".\sql\migration_po_lost_receipt_report.sql" | mysql -u root wayfarer_inventory
--
-- Staff can report a lost receipt; a manager must approve before it counts
-- as proof and is sent to Financial Management.

ALTER TABLE purchase_orders
    ADD COLUMN receipt_lost_report_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_rejected_by,
    ADD COLUMN receipt_lost_report_amount DECIMAL(10,2) NULL AFTER receipt_lost_report_pending,
    ADD COLUMN receipt_lost_report_note VARCHAR(500) NULL AFTER receipt_lost_report_amount,
    ADD COLUMN receipt_lost_report_at DATETIME NULL AFTER receipt_lost_report_note,
    ADD COLUMN receipt_lost_report_by VARCHAR(100) NULL AFTER receipt_lost_report_at,
    ADD COLUMN receipt_lost_report_rejected TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_lost_report_by,
    ADD COLUMN receipt_lost_report_rejection_note VARCHAR(500) NULL AFTER receipt_lost_report_rejected,
    ADD COLUMN receipt_lost_report_rejected_at DATETIME NULL AFTER receipt_lost_report_rejection_note,
    ADD COLUMN receipt_lost_report_rejected_by VARCHAR(100) NULL AFTER receipt_lost_report_rejected_at;
