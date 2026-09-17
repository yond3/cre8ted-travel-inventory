-- Audit trail for equipment: storage issues, PO receive, deploy-on-receive.
CREATE TABLE IF NOT EXISTS equipment_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    movement_code VARCHAR(20) NOT NULL UNIQUE,
    item_key VARCHAR(50) NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    movement_type ENUM('issue_from_storage', 'receive_to_storage', 'deploy_from_purchase') NOT NULL,
    department VARCHAR(100) NULL,
    location_id INT NULL,
    issued_to VARCHAR(100) NULL,
    notes VARCHAR(255) NULL,
    recorded_by VARCHAR(100) NOT NULL,
    reference_type ENUM('stock_issue', 'purchase_order') NULL,
    reference_id INT NULL,
    reference_code VARCHAR(20) NULL,
    status ENUM('Active', 'Voided') NOT NULL DEFAULT 'Active',
    voided_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_at DATETIME NULL,
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB;
