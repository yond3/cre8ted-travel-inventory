-- Run once on an existing cre8ted_inventory database:
--   Get-Content ".\sql\migration_stock_issues.sql" | mysql -u root cre8ted_inventory
--
-- Stock issue / checkout log: records who took stock and for which
-- department at the moment items leave storage. Decrements items.current_qty
-- immediately (separate from Close month, which reconciles against a
-- physical shelf count at month end).

USE cre8ted_inventory;

CREATE TABLE IF NOT EXISTS stock_issues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_code VARCHAR(20) NOT NULL UNIQUE,
    item_key VARCHAR(50) NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    department VARCHAR(100) NOT NULL,
    issued_to VARCHAR(100) NULL,
    notes VARCHAR(255) NULL,
    recorded_by VARCHAR(100) NOT NULL,
    status ENUM('Active','Voided') NOT NULL DEFAULT 'Active',
    voided_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_at DATETIME NULL,
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;
