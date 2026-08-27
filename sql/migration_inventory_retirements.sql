-- Retire / write-off audit trail for consumables and equipment.
CREATE TABLE IF NOT EXISTS inventory_retirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    retirement_code VARCHAR(20) NOT NULL UNIQUE,
    item_key VARCHAR(50) NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    source ENUM('storage', 'department') NOT NULL DEFAULT 'storage',
    department VARCHAR(100) NULL,
    reason ENUM('broken', 'lost', 'expired', 'damaged', 'other') NOT NULL,
    notes VARCHAR(255) NULL,
    recorded_by VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE equipment_movements
    MODIFY movement_type ENUM('issue_from_storage', 'receive_to_storage', 'deploy_from_purchase', 'retired') NOT NULL;

ALTER TABLE equipment_movements
    MODIFY reference_type ENUM('stock_issue', 'purchase_order', 'inventory_retirement') NULL;
