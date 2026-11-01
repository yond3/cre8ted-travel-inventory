-- Phase 3 additive upgrade. Run ONCE with writers paused and a verified backup.
-- Do not import schema.sql into an existing database. Stop on the first error.
-- Prerequisite: migration_stock_integrity.sql and migration_audit_log.sql.
-- No existing quantities or historical values are rewritten. Unknown provenance
-- stays legacy; disagreements must be reviewed rather than silently repaired.
ALTER TABLE usage_log
    ADD COLUMN usage_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN usage_source ENUM('legacy', 'manual', 'month_close') NOT NULL DEFAULT 'legacy';
ALTER TABLE audit_log MODIFY COLUMN entity_id VARCHAR(80) NULL;

-- Read-only reconciliation (also available through the month-close UI/report):
-- SELECT mc.item_key, mc.month, mc.usage_qty AS close_usage, u.usage_qty AS recorded_usage
-- FROM month_closes mc LEFT JOIN usage_log u ON u.item_key=mc.item_key AND u.month=mc.month
-- WHERE u.item_key IS NULL OR u.usage_qty <> mc.usage_qty;
