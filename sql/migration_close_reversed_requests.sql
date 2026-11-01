-- Approved Option A. Run only after Phase 5, in the coordinated maintenance window.
-- Specific reviewed records only; never part of schema.sql or a fresh installation.
DROP PROCEDURE IF EXISTS phase5_close_reversed_requests;
DELIMITER //
CREATE PROCEDURE phase5_close_reversed_requests()
main: BEGIN
 DECLARE n INT DEFAULT 0;
 DECLARE gate_id INT;
 DECLARE reason_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Issuance reversed; historical request closed under approved Phase 5 migration (Option A).';
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 SELECT id INTO gate_id FROM inventory_flow_lock WHERE id=1 FOR UPDATE;
 IF gate_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Inventory transaction gate missing'; END IF;
 -- A complete, previously audited correction is a safe retry. Partial states fail.
 SELECT COUNT(*) INTO n FROM stock_requests sr
 JOIN stock_request_items ri ON ri.request_id=sr.id
 JOIN stock_issues si ON si.id=sr.fulfilled_issue_id AND si.request_item_id=ri.id
 WHERE sr.id IN (18,26,27,31) AND sr.status='Closed' AND sr.legacy_status='Fulfilled'
 AND sr.closed_reason=reason_text AND sr.request_revision=1 AND si.status='Voided'
 AND ri.approval_source='legacy' AND ri.released_qty=ri.approved_qty
 AND EXISTS(SELECT 1 FROM audit_log a WHERE a.action='stock_request.migration_close'
 AND a.entity_type='stock_request' AND a.entity_id=CONVERT(sr.id USING utf8mb4) COLLATE utf8mb4_unicode_ci);
 IF n=4 THEN COMMIT; LEAVE main; END IF;
 SELECT COUNT(*) INTO n FROM stock_requests sr
 JOIN stock_request_items ri ON ri.request_id=sr.id
 JOIN stock_issues si ON si.id=sr.fulfilled_issue_id AND si.request_item_id=ri.id
 WHERE sr.id IN (18,26,27,31) AND sr.status='Fulfilled' AND sr.legacy_status='Fulfilled'
 AND sr.closed_reason IS NULL AND sr.request_revision=0 AND si.status='Voided'
 AND ri.approval_source='legacy' AND ri.approved_qty=si.qty AND ri.released_qty=0
 AND NOT EXISTS(SELECT 1 FROM stock_issues active_issue
 WHERE active_issue.request_item_id=ri.id AND active_issue.status='Active');
 IF n<>4 OR (SELECT COUNT(*) FROM stock_request_items WHERE request_id IN (18,26,27,31))<>4
 OR EXISTS(SELECT 1 FROM audit_log WHERE action='stock_request.migration_close'
 AND entity_type='stock_request' AND entity_id IN ('18','26','27','31')) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviewed historical requests changed; stop and inspect before correction';
 END IF;
 INSERT INTO audit_log(actor_username,actor_name,actor_role,action,entity_type,entity_id,before_json,after_json)
 SELECT 'phase5_migration','Approved historical correction','system','stock_request.migration_close','stock_request',CAST(sr.id AS CHAR),
 JSON_OBJECT('status',sr.status,'legacy_status',sr.legacy_status,'request_revision',sr.request_revision,
 'issue_id',si.id,'issue_status',si.status,'line_id',ri.id,'approved_qty',ri.approved_qty,'released_qty',ri.released_qty),
 JSON_OBJECT('status','Closed','legacy_status',sr.legacy_status,'request_revision',sr.request_revision+1,
 'issue_id',si.id,'issue_status',si.status,'line_id',ri.id,'approved_qty',ri.approved_qty,'released_qty',ri.approved_qty,
 'reason',reason_text,'authorization','User approved Option A; executed by migration tooling, not a historical manager approval')
 FROM stock_requests sr JOIN stock_request_items ri ON ri.request_id=sr.id
 JOIN stock_issues si ON si.id=sr.fulfilled_issue_id WHERE sr.id IN (18,26,27,31);
 UPDATE stock_request_items SET released_qty=approved_qty WHERE request_id IN (18,26,27,31);
 UPDATE stock_requests SET status='Closed',closed_reason=reason_text,request_revision=request_revision+1
 WHERE id IN (18,26,27,31);
 COMMIT;
END//
DELIMITER ;
CALL phase5_close_reversed_requests();
DROP PROCEDURE phase5_close_reversed_requests;
