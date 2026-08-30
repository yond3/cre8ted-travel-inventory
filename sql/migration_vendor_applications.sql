-- Run once on an existing cre8ted_inventory database:
--   mysql -u root cre8ted_inventory < sql/migration_vendor_applications.sql

USE cre8ted_inventory;

CREATE TABLE IF NOT EXISTS vendor_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_code VARCHAR(20) NOT NULL UNIQUE,
    company_name VARCHAR(150) NOT NULL,
    contact VARCHAR(150) NULL,
    procurement_methods VARCHAR(100) NOT NULL DEFAULT 'walk_in',
    notes VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    supplier_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendor_application_prices (
    application_id INT NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (application_id, item_key),
    FOREIGN KEY (application_id) REFERENCES vendor_applications(id) ON DELETE CASCADE,
    FOREIGN KEY (item_key) REFERENCES items(item_key) ON DELETE CASCADE
) ENGINE=InnoDB;
