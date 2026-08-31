-- Audit trail for admin/config-change actions (user management, item/supplier/
-- location edits and deactivation, voucher quantity edits, PO cancellation,
-- month closes, report exports, bulk request actions). See record_audit()
-- in php/api/config.php and GET /api/audit_log.php (super admin only).
USE cre8ted_inventory;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_username VARCHAR(50) NOT NULL,
    actor_name VARCHAR(100) NOT NULL,
    actor_role VARCHAR(20) NOT NULL,
    action VARCHAR(60) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id VARCHAR(40) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_actor (actor_username, created_at)
) ENGINE=InnoDB;
