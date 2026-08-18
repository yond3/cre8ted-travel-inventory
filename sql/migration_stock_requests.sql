-- Run once on an existing wayfarer_inventory database:
--   Get-Content ".\sql\migration_stock_requests.sql" | mysql -u root wayfarer_inventory
--
-- Department stock requests: formal in-app requests before inventory staff
-- issue items from shelf. Fulfill creates a stock_issues row and links back.

USE wayfarer_inventory;

CREATE TABLE IF NOT EXISTS stock_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(20) NOT NULL UNIQUE,
    department VARCHAR(100) NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    requested_by VARCHAR(100) NOT NULL,
    notes VARCHAR(255) NULL,
    status ENUM('Pending','Fulfilled','Cancelled') NOT NULL DEFAULT 'Pending',
    fulfilled_issue_id INT NULL,
    fulfilled_by VARCHAR(100) NULL,
    fulfilled_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_key) REFERENCES items(item_key),
    FOREIGN KEY (fulfilled_issue_id) REFERENCES stock_issues(id) ON DELETE SET NULL
) ENGINE=InnoDB;
