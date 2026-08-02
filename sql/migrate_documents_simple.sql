-- One-time cleanup: one document row per PR/PO, status synced with live records.
USE wayfarer_inventory;

DELETE FROM documents WHERE doc_type = 'Delivery receipt';

UPDATE documents d
JOIN purchase_orders po ON po.po_code = d.ref_code
SET d.status = po.status
WHERE d.doc_type = 'Purchase order';

UPDATE documents d
JOIN purchase_requests pr ON pr.request_code = d.ref_code
SET d.status = pr.status
WHERE d.doc_type = 'Purchase request';

DELETE d1 FROM documents d1
INNER JOIN documents d2
  ON d1.doc_type = d2.doc_type
 AND d1.ref_code = d2.ref_code
 AND d1.id < d2.id;
