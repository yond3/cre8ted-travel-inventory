-- Additive upgrade: run ONCE, with inventory writes paused and a verified backup.
-- Never use schema.sql to upgrade an existing database.
-- Preflight (must return no rows):
-- SELECT item_key FROM items WHERE current_qty < 0;
-- SELECT item_key, department FROM equipment_deployments WHERE qty < 0;
-- Confirm affected tables use InnoDB. DDL is not transactionally reversible;
-- stop on the first error, keep writes paused, and investigate before retrying.
ALTER TABLE items ADD COLUMN stock_version BIGINT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_key VARCHAR(50) NOT NULL,
    balance_scope ENUM('storage', 'department') NOT NULL,
    department VARCHAR(100) NOT NULL DEFAULT '',
    movement_type VARCHAR(60) NOT NULL,
    quantity_delta DECIMAL(11,2) NOT NULL,
    quantity_before DECIMAL(10,2) NOT NULL,
    quantity_after DECIMAL(10,2) NOT NULL,
    actor_username VARCHAR(100) NOT NULL,
    actor_name VARCHAR(150) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT NULL,
    reference_code VARCHAR(50) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_stock_item_history (item_key, id),
    INDEX idx_stock_reference (reference_type, reference_id),
    FOREIGN KEY (item_key) REFERENCES items(item_key),
    CHECK (quantity_before >= 0 AND quantity_after >= 0),
    CHECK (quantity_after = quantity_before + quantity_delta)
) ENGINE=InnoDB;

CREATE TABLE inventory_operations (
    actor_username VARCHAR(100) NOT NULL,
    operation_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    response_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (actor_username, operation_key)
) ENGINE=InnoDB;

CREATE TABLE code_counters (
    prefix VARCHAR(10) NOT NULL,
    code_year SMALLINT UNSIGNED NOT NULL,
    counter_value BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (prefix, code_year)
) ENGINE=InnoDB;

-- A cutover baseline, not fabricated historical usage or transactions.
INSERT INTO stock_movements
    (item_key, balance_scope, department, movement_type, quantity_delta, quantity_before, quantity_after, actor_username, actor_name)
SELECT item_key, 'storage', '', 'opening_balance', current_qty, 0, current_qty, 'migration', 'Stock integrity migration'
FROM items;
INSERT INTO stock_movements
    (item_key, balance_scope, department, movement_type, quantity_delta, quantity_before, quantity_after, actor_username, actor_name)
SELECT item_key, 'department', department, 'opening_balance', qty, 0, qty, 'migration', 'Stock integrity migration'
FROM equipment_deployments;

-- Initialize current-year counters while writes are paused. Older codes stay unchanged.
INSERT INTO code_counters (prefix, code_year, counter_value)
SELECT 'PR', YEAR(CURRENT_DATE), COALESCE(MAX(CAST(SUBSTRING_INDEX(request_code, '-', -1) AS UNSIGNED)), 0)
FROM purchase_requests WHERE request_code LIKE CONCAT('PR-', YEAR(CURRENT_DATE), '-%');
INSERT INTO code_counters (prefix, code_year, counter_value)
SELECT 'PO', YEAR(CURRENT_DATE), COALESCE(MAX(CAST(SUBSTRING_INDEX(po_code, '-', -1) AS UNSIGNED)), 0)
FROM purchase_orders WHERE po_code LIKE CONCAT('PO-', YEAR(CURRENT_DATE), '-%');
INSERT INTO code_counters (prefix, code_year, counter_value)
SELECT 'DOC', YEAR(CURRENT_DATE), COALESCE(MAX(CAST(SUBSTRING_INDEX(doc_code, '-', -1) AS UNSIGNED)), 0)
FROM documents WHERE doc_code LIKE CONCAT('DOC-', YEAR(CURRENT_DATE), '-%');
INSERT INTO code_counters (prefix, code_year, counter_value)
SELECT 'ISS', YEAR(CURRENT_DATE), COALESCE(MAX(CAST(SUBSTRING_INDEX(issue_code, '-', -1) AS UNSIGNED)), 0)
FROM stock_issues WHERE issue_code LIKE CONCAT('ISS-', YEAR(CURRENT_DATE), '-%');
INSERT INTO code_counters (prefix, code_year, counter_value)
SELECT 'SR', YEAR(CURRENT_DATE), COALESCE(MAX(CAST(SUBSTRING_INDEX(request_code, '-', -1) AS UNSIGNED)), 0)
FROM stock_requests WHERE request_code LIKE CONCAT('SR-', YEAR(CURRENT_DATE), '-%');
INSERT INTO code_counters (prefix, code_year, counter_value)
SELECT 'EM', YEAR(CURRENT_DATE), COALESCE(MAX(CAST(SUBSTRING_INDEX(movement_code, '-', -1) AS UNSIGNED)), 0)
FROM equipment_movements WHERE movement_code LIKE CONCAT('EM-', YEAR(CURRENT_DATE), '-%');
INSERT INTO code_counters (prefix, code_year, counter_value)
SELECT 'RET', YEAR(CURRENT_DATE), COALESCE(MAX(CAST(SUBSTRING_INDEX(retirement_code, '-', -1) AS UNSIGNED)), 0)
FROM inventory_retirements WHERE retirement_code LIKE CONCAT('RET-', YEAR(CURRENT_DATE), '-%');
INSERT INTO code_counters (prefix, code_year, counter_value)
SELECT 'VQ', YEAR(CURRENT_DATE), COALESCE(MAX(CAST(SUBSTRING_INDEX(application_code, '-', -1) AS UNSIGNED)), 0)
FROM vendor_applications WHERE application_code LIKE CONCAT('VQ-', YEAR(CURRENT_DATE), '-%');
