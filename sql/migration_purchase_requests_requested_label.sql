-- Staff describe items in their own words; catalog link optional until PO is created.
ALTER TABLE purchase_requests
    ADD COLUMN requested_label VARCHAR(255) NULL AFTER item_key;

ALTER TABLE purchase_requests
    MODIFY item_key VARCHAR(50) NULL;
