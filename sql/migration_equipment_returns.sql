-- Equipment returns: departments hand units back; track condition and audit in equipment_movements.
ALTER TABLE equipment_movements
    MODIFY movement_type ENUM(
        'issue_from_storage',
        'receive_to_storage',
        'deploy_from_purchase',
        'retired',
        'return_to_storage'
    ) NOT NULL;

ALTER TABLE equipment_movements
    ADD COLUMN return_condition ENUM('good', 'damaged', 'broken') NULL AFTER notes;
