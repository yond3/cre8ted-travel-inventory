# Phase 4 migration rehearsal

Status: **PASSED**

Application database was not migrated. No commit or push was performed.

## Verified local backup

- Backup directory: `backups/phase4_before_20261025_163620_313b9d`
- Database SQL: `backups/phase4_before_20261025_163620_313b9d/database.sql`
- SQL SHA-256: `62c657f5741382920b7bcb3508b55850846851abf2a3407ba3acaa3b1d29766f`
- Database tables backed up: 24
- Original records: 1329
- Receipt files backed up: 54
- Backup manifest includes per-table record fingerprints and per-file checksums.
- `backups/` is Git-ignored; the SQL contains private application data and password hashes.

## Rehearsal checks

- Restore verified: every original row and column matches the source snapshot by SHA-256.
- Migration preflight passed: prerequisites, InnoDB engines, quantities and unapplied schema.
- Migration preserved every original record, not only row counts or stock totals.
- All original items remain uncategorized at category revision zero; 15 initial categories exist.
- Copied application reads and five report types load existing migrated records through the PHP APIs.
- An existing backed-up receipt is still downloadable through its authenticated API.
- Existing receipt references without a corresponding source backup file: 0. No source files were changed.
- Category assignment and parent filtering work on native MySQL without changing stock; downstream request and receiving use the same classified item.
- Test-only request fulfillment, stock deduction, reversal and Staff write restrictions work on the migrated copy.
- Test-only procurement, Finance stub, proof review and full receiving work; repeated receiving is rejected.
- All copied item balances still reconcile to movement history after the test workflows.
- Test-only historical close, correction and direct-edit guard work on the actual-data copy without changing current stock or its revision.
- Application database records, schema and server identity remain unchanged after rehearsal.

## Findings / limits

- This rehearsal uses a separate local MySQL process/data directory with its event scheduler disabled.
- Runtime checks create disposable accounts and fixture records only inside that isolated copy. Existing application accounts/passwords are unchanged.
- Finance is stubbed, and copied forecast/OCR URLs do not target live services.
- Comparing backup-time and post-rehearsal fingerprints detected no source changes only when the corresponding checks passed.
- A normal application-database migration still needs an approved maintenance window and matching code/frontend deployment.

## Existing record inventory

| Table | Original rows |
|---|---:|
| `audit_log` | 54 |
| `code_counters` | 8 |
| `documents` | 203 |
| `equipment_deployments` | 9 |
| `equipment_movements` | 39 |
| `finance_integration_log` | 305 |
| `inventory_operations` | 4 |
| `inventory_retirements` | 17 |
| `items` | 25 |
| `locations` | 10 |
| `login_attempts` | 133 |
| `month_closes` | 1 |
| `purchase_orders` | 98 |
| `purchase_requests` | 105 |
| `stock_issues` | 66 |
| `stock_movements` | 36 |
| `stock_requests` | 32 |
| `supplier_prices` | 20 |
| `suppliers` | 9 |
| `tour_vouchers` | 3 |
| `usage_log` | 122 |
| `users` | 7 |
| `vendor_application_prices` | 14 |
| `vendor_applications` | 9 |
