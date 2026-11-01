# Phase 3: historical month closing and consistent usage

Status: implemented, automated tests passed and user browser testing accepted. The authorized normal-database migration was applied and verified; maintenance is off. See PHASE3_APPLICATION_ROLLOUT.md. Final post-rollout browser confirmation and commit approval remain pending. No commit or push performed. Phase 2 was accepted by the user.

## Behavior and scope

Historical closing/correction saves `month_closes` and `usage_log` together, without changing current stock, stock revisions or movement history. Current physical corrections continue through the existing inventory/stock APIs and require a reason. Only completed calendar months can be written as final usage; boundaries follow the database calendar used by receiving.

The existing monthly dataset remains authoritative for readers. Direct manual entry remains available for months without a close. A saved close owns its matching usage value. Legacy disagreements are shown, not silently repaired. Corrections preserve before/after values, actor and reason in the existing audit log within the transaction; audit failure rolls back the action. Super Admin retains the existing full audit-history permission.

Monthly forms capture both a usage revision and supporting-context token. Concurrent corrections or changes to suggested opening/receipts require review. Historical writes use the established operation-key then item-lock order, but do not register a physical stock movement. Optional retry keys reuse the Phase 2 operation store. No duplicate stock or request workflow was introduced.

Unknown historical opening balances remain blank. Impossible arithmetic is rejected. Saved receipt overrides are retained on reopening and distinguished from PO receipts. Adjacent-month mismatches generate review warnings without cascading changes. Monthly reports show both stored usage values, source and discrepancies. Dashboard averages use recorded observations within the last six completed calendar months; zero and missing data remain distinct, with coverage shown in the UI.

## Changed files for this phase

| File | Change |
|---|---|
| `php/api/usage_service.php` (new) | Monthly schema guard, calendar, snapshots, revisions, shared transaction/replay handling, reasons and adjacent-month warnings |
| `php/api/close_month.php` | Historical-only close/correction, coherent GET snapshot, opening/receipt suggestions, validation and mandatory audit |
| `php/api/usage.php` | Revisioned manual history; prevent independent changes to close-owned usage |
| `php/api/config.php` | Load monthly helper and opt-in required audit mode |
| `php/api/inventory.php`, `php/api/stock.php` | Reasons for current quantity corrections; audit within the transaction |
| `php/api/reports.php` | Recorded/close usage, provenance and reconciliation warnings |
| `php/api/demand_insights.php` | Completed-calendar-period lookbacks and observation coverage |
| `php/index.html` | Historical wording, correction reasons, tokens, validation, safe asynchronous loading, delivery hints, coverage and scrollable close modal |
| `sql/migration_usage_consistency.sql` (new) | Add monthly revision/source and widen audit reference capacity |
| `sql/apply_stock_integrity.py` | Reuse the existing backup-verified rollout tool with explicit Phase 3 selection, matching migration hash, maintenance ownership and preservation checks |
| `sql/schema.sql` | Fresh-install equivalent; never an existing-database upgrade |
| `tests/phase1_security.py` | Updated client contracts, Phase 3 runner/preview and Python loopback-only connection guard |
| `tests/phase2_concurrency.py` | Retain stock regressions; update month-close assertions for intentionally historical-only behavior |
| `tests/phase3_usage.py` (new) | Monthly integrity, migration, permission, concurrency, rollback and reporting checks |
| `tests/phase3_browser.cjs` (new) | Execute actual monthly JS handlers with a mocked DOM |
| `tests/phase2_migration_rehearsal.py` | Reuse established safe backup/restore machinery with explicit Phase 3 selection and copy-only monthly checks |
| `API.md`, `README.md`, this report, `PHASE3_MIGRATION_REHEARSAL.md` | Contracts, deployment boundaries, verification and manual checklist |
| `PHASE2_REVIEW.md` | Record the user's Phase 2 manual acceptance |

Phase 1/2 work and the pre-existing UI edits and OCR file deletions remain in the worktree. They have not been discarded or committed.

## Database migration and compatibility

`sql/migration_usage_consistency.sql` adds `usage_log.usage_version` (unsigned bigint, initial zero), `usage_log.usage_source` (legacy/manual/month_close, initially legacy) and widens `audit_log.entity_id` from 40 to 80 characters to fit full item/month references. No new tables, history fabrication, quantity updates or record deletions are included. Existing item/month primary keys and foreign keys remain.

The authorized normal-database rollout is complete: all 1,328 original records and 54 receipt files were preserved. Monthly schema defaults and stock reconciliation were verified, maintenance was removed and normal access restored. See PHASE3_APPLICATION_ROLLOUT.md. Do not rerun the migration. Code and schema must be deployed together on other installations.

Before rollout: refresh/verify the backup against current data, pause writers, check InnoDB/schema prerequisites, run the additive SQL once with stop-on-error, compare every original column/row and receipt checksum, then restore access and refresh browser assets. DDL is not transactionally reversible. On partial failure, keep writes paused and inspect; do not blindly retry or import `schema.sql`. After new history is written, do not restore an old backup without accounting for intervening activity.

## Verification

- Combined API suite: **231 checks passed**, including Phase 1 security and Phase 2 stock/procurement regressions.
- Existing JS request helper tests passed for central and department pages, including script parsing.
- Phase 3 form-handler tests passed: unknown opening, blank count validation, impossible arithmetic, monthly tokens, correction reasons, stale response rejection, failed-load behavior, changed confirmation context and success wording.
- All PHP API files passed syntax checks.
- Actual-data copy rehearsal passed: **24 tables, 1,328 original records and 54 receipt files**. Original rows/columns were hash-verified before/after the copied migration. The source database remained unchanged. Zero close/usage disagreements and zero adjacent-month mismatches were found in that snapshot.
- Copied existing API/report/receipt reads, fulfillment/reversal, procurement/full receiving and monthly correction checks passed. Finance was stubbed; forecasting/OCR services were not called.
- An initial native-MySQL test query hit a collation mismatch; the copy-verification query was corrected without changing application collations. An interrupted test-status operation was rejected by automatic approval review over possible external telemetry. The test network paths were inspected, shell profiles were disabled and Python test connections restricted to loopback; the subsequent full suite passed.

These are automated results, not a claim of manual Chrome acceptance. Backup freshness must be checked again at rollout.

## Manual browser checklist

From your own PowerShell window:

```powershell
cd "C:\Users\Dion\OneDrive\Desktop\forecasting-backend"
python tests/phase1_security.py --phase3 --preview --temp-parent C:/Users/Dion/AppData/Local/Temp
```

After checks pass, open the printed `MANUAL TEST URL` in Chrome. Keep the terminal open. Preview-only seeded accounts: Manager `maria / manager123`, Staff `juan / staff123`, Super Admin `admin / admin123`. Use an Incognito window for an independent session. Do not assume port 8000 is this isolated preview. Press Enter or Ctrl+C to end it and discard its data.

| Check | Expected result |
|---|---|
| Create a unique test consumable with current stock 75; close a completed month with opening 30, receipts 0 and closing 20 | Usage 10; current stock remains 75 |
| Reopen that month; change closing to 18 without a reason, then with a reason | First save blocked; second saves usage 12 while current stock remains 75 |
| Open the same month in two independent sessions; save a correction in one, then submit the older form | Older correction rejected; reload/review required |
| Enter closing 31 with opening 30 and receipts 0 | Validation error, not zero usage |
| Select the current/future month | Cannot finalize it |
| Open a first historical close without a prior close | Opening is blank and must be entered explicitly |
| Switch between months quickly or simulate a failed load | Old values/tokens cannot be submitted for the new month |
| Close the next completed month with opening matching the previous close; later correct the earlier close | A mismatch warning appears; later records are not silently rewritten |
| Edit current stock from 75 to 70 without/with a reason | Reason required; successful correction changes stock once and records history |
| Review monthly report and Super Admin audit history | Historical counts, recorded usage, source, actor, reason and before/after information are available in the appropriate views |
| Use Staff/department accounts | Existing role restrictions remain enforced |
| Create/fulfill a stock request, reverse an issue, create/approve a PR and receive its PO | Correct single stock changes; existing request/PO/receipt/history screens still work |
| Open inventory, reports and demand insights; log out and reopen protected pages | Data loads, coverage is shown, and existing authentication remains effective |

Record Pass/Fail/Not tested. For a failure, send the role, steps, starting balance, expected/actual result and any error message.

## Limits and remaining risks

- User browser testing was accepted and the normal-database rollout was applied and verified. Final post-rollout browser confirmation and commit approval remain pending. No commit or push.
- Opening + receipts - closing is depletion, not necessarily pure departmental demand; losses/adjustments can be included. Historical values are preserved and are not retroactively reconstructed from the Phase 2 ledger.
- Previous historical closes that already affected current stock are not automatically reversed; the correct physical quantity cannot safely be inferred.
- Prophet's model and 12-month eligibility requirement remain Phase 10. Monthly input columns remain compatible; Phase 3 does not claim to validate forecast accuracy.
- Old API clients must adopt the revised monthly tokens, response semantics and correction reasons. Historical closes no longer adjust stock by design.
- No automatic cascade repairs or new lifecycle/status system are introduced. Legacy discrepancies require explicit review.
- Native browser appearance, real Finance connectivity and remote deployment behavior still require environment-specific checks.

Suggested commit message after manual acceptance and explicit approval:

`fix(inventory): separate historical month closing from live stock`
