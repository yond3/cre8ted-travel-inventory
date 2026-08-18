-- Department + reason on purchase requests (equipment replacement / new need).
ALTER TABLE purchase_requests
    ADD COLUMN department VARCHAR(100) NULL AFTER employee,
    ADD COLUMN reason ENUM('replacement', 'new_need', 'other') NULL AFTER notes;
