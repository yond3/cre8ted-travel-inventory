-- Apply ONCE after exception preflight, verified backup and pausing writers.
CREATE TABLE inventory_flow_lock (id TINYINT PRIMARY KEY, CHECK(id=1)) ENGINE=InnoDB;
INSERT INTO inventory_flow_lock VALUES (1);
ALTER TABLE items ADD COLUMN protected_qty DECIMAL(10,2) NULL,
 ADD CONSTRAINT item_protected_nonnegative CHECK(protected_qty IS NULL OR protected_qty>=0);
ALTER TABLE stock_requests
 MODIFY status ENUM('Pending','Approved','Partially Issued','Fulfilled','Rejected','Cancelled','Closed') NOT NULL DEFAULT 'Pending',
 ADD COLUMN purpose TEXT NULL,
 ADD COLUMN requested_by_id INT NULL,
 ADD COLUMN request_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN reviewed_by_id INT NULL,
 ADD COLUMN reviewed_by_name VARCHAR(150) NULL,
 ADD COLUMN reviewed_role VARCHAR(30) NULL,
 ADD COLUMN reviewed_at DATETIME NULL,
 ADD COLUMN review_reason TEXT NULL,
 ADD COLUMN closed_reason TEXT NULL,
 ADD COLUMN expected_collection DATE NULL,
 ADD COLUMN legacy_status VARCHAR(30) NULL,
 ADD KEY request_review_queue(status,created_at);
CREATE TABLE stock_request_items (
 id INT AUTO_INCREMENT PRIMARY KEY,
 request_id INT NOT NULL,
 line_no INT NOT NULL,
 item_key VARCHAR(50) NULL,
 item_label VARCHAR(150) NOT NULL,
 unit VARCHAR(30) NOT NULL,
 requested_qty DECIMAL(10,2) NOT NULL,
 approved_qty DECIMAL(10,2) NULL,
 released_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
 decision_reason TEXT NULL,
 line_notes TEXT NULL,
 approval_source ENUM('manager','legacy') NULL,
 protected_override_reason TEXT NULL,
 UNIQUE KEY request_line_number(request_id,line_no),
 UNIQUE KEY request_item_identity(request_id,item_key),
 KEY item_commitments(item_key,request_id),
 FOREIGN KEY(request_id) REFERENCES stock_requests(id) ON DELETE RESTRICT,
 FOREIGN KEY(item_key) REFERENCES items(item_key) ON DELETE RESTRICT,
 CHECK(requested_qty>0),
 CHECK(approved_qty IS NULL OR approved_qty>=0),
 CHECK(released_qty>=0 AND released_qty<=COALESCE(approved_qty,0)),
 CHECK(approval_source='legacy' OR approved_qty IS NULL OR approved_qty<=requested_qty)
) ENGINE=InnoDB;
ALTER TABLE stock_issues ADD COLUMN request_item_id INT NULL,
 ADD KEY issue_request_line(request_item_id,status),
 ADD CONSTRAINT issue_request_line_fk FOREIGN KEY(request_item_id) REFERENCES stock_request_items(id) ON DELETE RESTRICT;
INSERT INTO stock_request_items(request_id,line_no,item_key,item_label,unit,requested_qty,approved_qty,approval_source,line_notes)
 SELECT sr.id,1,sr.item_key,COALESCE(i.label,sr.requested_label,'Legacy item'),COALESCE(i.unit,sr.requested_unit,'unit(s)'),
 sr.qty,CASE WHEN sr.status='Fulfilled' THEN si.qty ELSE NULL END,
 CASE WHEN sr.status='Fulfilled' THEN 'legacy' ELSE NULL END,sr.notes
 FROM stock_requests sr LEFT JOIN items i ON i.item_key=sr.item_key LEFT JOIN stock_issues si ON si.id=sr.fulfilled_issue_id;
UPDATE stock_issues si JOIN stock_requests sr ON sr.fulfilled_issue_id=si.id
 JOIN stock_request_items ri ON ri.request_id=sr.id SET si.request_item_id=ri.id;
UPDATE stock_requests SET legacy_status=status;
