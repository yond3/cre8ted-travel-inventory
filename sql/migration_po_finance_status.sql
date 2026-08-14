-- Run once on an existing wayfarer_inventory database:
--   Get-Content ".\sql\migration_po_finance_status.sql" | mysql -u root wayfarer_inventory
--
-- Adds the Financial Management integration state machine to purchase
-- orders (disbursement request -> funded -> expense sent -> recorded) and a
-- log table for every outbound/inbound call. Requires
-- migration_po_receipts.sql to already be applied.

USE wayfarer_inventory;

ALTER TABLE purchase_orders
    ADD COLUMN finance_status ENUM(
        'not_sent',
        'pending_disbursement',
        'funded',
        'disbursement_rejected',
        'expense_pending',
        'expense_recorded'
    ) NOT NULL DEFAULT 'not_sent' AFTER status,
    ADD COLUMN finance_disbursement_id VARCHAR(64) NULL AFTER finance_status,
    ADD COLUMN finance_expense_id VARCHAR(64) NULL AFTER finance_disbursement_id,
    ADD COLUMN expense_category VARCHAR(50) NULL AFTER finance_expense_id,
    ADD COLUMN finance_sent_at DATETIME NULL AFTER expense_category,
    ADD COLUMN finance_funded_at DATETIME NULL AFTER finance_sent_at,
    ADD COLUMN finance_expense_sent_at DATETIME NULL AFTER finance_funded_at;

CREATE TABLE IF NOT EXISTS finance_integration_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    direction ENUM('outbound', 'inbound') NOT NULL,
    payload JSON NULL,
    response_status INT NULL,
    response_body TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    INDEX idx_finance_log_po (po_id)
) ENGINE=InnoDB;
