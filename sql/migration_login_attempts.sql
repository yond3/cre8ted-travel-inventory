-- Adds login rate limiting support (5 failed attempts in 10 minutes locks
-- out that username/IP for 15 minutes — see config.php + auth.php).
-- Safe to re-run: only creates the table if it doesn't already exist.

USE wayfarer_inventory;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_username (username, created_at),
    INDEX idx_login_attempts_ip (ip_address, created_at)
) ENGINE=InnoDB;
