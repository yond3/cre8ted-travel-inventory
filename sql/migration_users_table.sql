-- Moves login accounts out of php/api/config.php's hardcoded AUTH_USERS
-- array into a real table, managed via the Manage Users page / users.php
-- (super admin only). Safe to run on an existing database — creates the
-- table and seeds it with the same 5 demo accounts (same password hashes,
-- same passwords: staff123 / manager123 / admin123) so nothing breaks.
USE cre8ted_inventory;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    role ENUM('department', 'staff', 'manager', 'super_admin') NOT NULL,
    department VARCHAR(100) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by VARCHAR(100) NULL
) ENGINE=InnoDB;

INSERT INTO users (username, password_hash, name, role, department) VALUES
    ('juan', '$2y$10$7pMcPfUMLtT6aWZ.ojuzu.moTtrpFV0TiiacECtIaFHOf0H/I464a', 'Juan Dela Cruz', 'staff', NULL),
    ('maria', '$2y$10$x1iYBp20Zhvbq798nnUnAemwxW8A0CDA8TApsGkoH.bj0CZaan1P.', 'Maria Santos', 'manager', NULL),
    ('admin', '$2y$10$aZHU4bDaT0CaqOOt3GVyu.Gk2EM7tYI7JaLuN12qR5S1vGBoSdcUq', 'System Administrator', 'super_admin', NULL),
    ('fleet_dept', '$2y$10$7pMcPfUMLtT6aWZ.ojuzu.moTtrpFV0TiiacECtIaFHOf0H/I464a', 'Fleet Department', 'department', 'Fleet & Transportation management'),
    ('tour_ops_dept', '$2y$10$7pMcPfUMLtT6aWZ.ojuzu.moTtrpFV0TiiacECtIaFHOf0H/I464a', 'Tour Operations Department', 'department', 'Tour Operations')
ON DUPLICATE KEY UPDATE username = username;
