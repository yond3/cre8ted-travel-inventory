# Phase 2 migration rehearsal

Status: **PASSED**

This report records the isolated rehearsal. The application database was subsequently upgraded with user approval; see [PHASE2_APPLICATION_ROLLOUT.md](PHASE2_APPLICATION_ROLLOUT.md). No commit or push was performed.

## Verified local backup

- Backup directory: `backups/phase2_before_20261024_000856_a89ccb`
- Database SQL: `backups/phase2_before_20261024_000856_a89ccb/database.sql`
- SQL SHA-256: `daad032c4c7869c5b6f9e500f76935e93d47c1706e220a591e3cce80ce8eac2e`
- Database tables backed up: 21
- Original records: 1270
- Receipt files backed up: 54
- Backup manifest includes per-table record fingerprints and per-file checksums.
- `backups/` is Git-ignored; the SQL contains private application data and password hashes.

## Rehearsal checks

- Restore verified: every original row and column matches the source snapshot by SHA-256.
- Migration preflight passed: prerequisites, InnoDB engines, quantities and unapplied schema.
- Migration preserved every original record, not only row counts or stock totals.
- Every cutover storage/deployment baseline and initial stock revision matches existing data.
- Copied application reads and five report types load existing migrated records through the PHP APIs.
- An existing backed-up receipt is still downloadable through its authenticated API.
- Existing receipt references without a corresponding source backup file: 0. No source files were changed.
- Test-only request fulfillment, stock deduction, reversal and Staff write restrictions work on the migrated copy.
- Test-only procurement, Finance stub, proof review and full receiving work; repeated receiving is rejected.
- All copied item balances still reconcile to movement history after the test workflows.
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
| `audit_log` | 51 |
| `documents` | 200 |
| `equipment_deployments` | 9 |
| `equipment_movements` | 39 |
| `finance_integration_log` | 305 |
| `inventory_retirements` | 17 |
| `items` | 25 |
| `locations` | 10 |
| `login_attempts` | 132 |
| `month_closes` | 1 |
| `purchase_orders` | 98 |
| `purchase_requests` | 102 |
| `stock_issues` | 65 |
| `stock_requests` | 32 |
| `supplier_prices` | 20 |
| `suppliers` | 9 |
| `tour_vouchers` | 3 |
| `usage_log` | 122 |
| `users` | 7 |
| `vendor_application_prices` | 14 |
| `vendor_applications` | 9 |
