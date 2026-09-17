-- Vendor portal: separate phones, emails, and business address.
ALTER TABLE vendor_applications
    ADD COLUMN phones VARCHAR(500) NULL AFTER contact,
    ADD COLUMN emails VARCHAR(500) NULL AFTER phones,
    ADD COLUMN address VARCHAR(500) NULL AFTER emails,
    MODIFY contact VARCHAR(500) NULL;

ALTER TABLE suppliers
    ADD COLUMN address VARCHAR(500) NULL AFTER contact,
    MODIFY contact VARCHAR(500) NULL;
