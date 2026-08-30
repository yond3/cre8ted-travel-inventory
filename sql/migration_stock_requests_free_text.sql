-- Run once on an existing cre8ted_inventory database:
--   Get-Content ".\sql\migration_stock_requests_free_text.sql" | mysql -u root cre8ted_inventory
--
-- Allows department API users to submit free-text stock requests (no catalog item_key).

USE cre8ted_inventory;

ALTER TABLE stock_requests
    ADD COLUMN requested_label VARCHAR(100) NULL AFTER item_key,
    ADD COLUMN requested_unit VARCHAR(15) NULL AFTER requested_label;

ALTER TABLE stock_requests
    DROP FOREIGN KEY stock_requests_ibfk_1;

ALTER TABLE stock_requests
    MODIFY item_key VARCHAR(50) NULL;

ALTER TABLE stock_requests
    ADD CONSTRAINT stock_requests_item_key_fk
        FOREIGN KEY (item_key) REFERENCES items(item_key);
