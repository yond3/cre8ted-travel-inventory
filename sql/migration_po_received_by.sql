-- Who verified and marked each purchase order as received (manager sign-off).
ALTER TABLE purchase_orders
    ADD COLUMN received_by VARCHAR(100) NULL AFTER received_at;
