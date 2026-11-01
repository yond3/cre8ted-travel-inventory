# Phase 2: inventory concurrency and required movement history

Status: implementation, automated verification and actual-data rehearsal complete. The user approved the application-database upgrade; it was applied and verified on October 24, 2026 (Asia/Manila). Maintenance is off. The user accepted the final browser review. Commit approval remains pending.

Phase 1 manual testing was reported passed by the user. Nothing has been committed or pushed. The Phase 2 upgrade has been rehearsed successfully on an isolated restore of the actual application data. It has now been applied to the configured application database under explicit user approval; see [PHASE2_APPLICATION_ROLLOUT.md](PHASE2_APPLICATION_ROLLOUT.md). No dependencies or application credentials were changed.

## Behavior

- Stock issuance, request fulfillment/cancellation, issue reversal, PO creation/receiving/cancellation, receipt review/upload, equipment returns and retirements serialize their decisions under database row locks.
- Normal storage changes use one guarded delta helper. Checks run against locked current balances; stock cannot become negative or exceed its decimal range.
- Storage and department balance changes write `stock_movements` before the same transaction commits. Failure to save required history rolls back balances, business records and operation identifiers together.
- `items.current_qty` and `equipment_deployments` remain the current-balance projections used by the existing app. The new history records how those balances changed. Existing issue, equipment movement, retirement, PO, document and report records keep their existing business meanings.
- Direct quantity edits and month close require the item revision displayed when the form was opened. Stale revisions return HTTP 409; missing revisions return HTTP 428. The user must refresh/reopen and review current data.
- Unbound issuance, return and retirement POSTs require `X-Idempotency-Key`. Retrying the same authorized operation returns the saved response instead of repeating its stock effect. Reusing the key for a different payload, route or permission scope returns HTTP 409.
- Stock-request and purchase-request creation also honor optional operation identifiers. The bundled central and department pages provide them and coalesce simultaneous identical submissions.
- Browser retry identifiers survive network errors and a reload in the same tab through session storage. If browser storage is disabled, retries are protected within the current page only. Independent intentional operations must use different identifiers.
- Sequential codes retain their existing prefixes, year and minimum three-digit format. Counters use numeric suffixes and atomic allocation, including codes after 999.
- Inventory reads keep quantities, revisions and department totals in one consistent database snapshot.
- Finance HTTP calls occur after local transaction commits. Live Finance's own retry/idempotency behavior is not redesigned here.

## Files changed for Phase 2

| File | Change |
|---|---|
| `php/api/stock_service.php` (new) | Shared transaction, row locking, quantity delta, revision, replay, required-history and rollback helpers |
| `php/api/config.php` | Runtime maintenance gate; load helpers; safe code allocation; lock-aware equipment balances and guarded returns/retirements; roll back JSON errors |
| `php/api/security.php` | Allow the operation identifier header through the existing CORS policy |
| `php/api/stock_issues.php` | Atomic issuance and one-time reversal |
| `php/api/stock_requests.php` | Atomic fulfillment/cancellation; optional request-creation replay |
| `php/api/equipment_returns.php` | Return the response through the atomic replay boundary |
| `php/api/inventory_retirements.php` | Return the response through the atomic replay boundary |
| `php/api/purchase_orders.php` | Lock request/PO status transitions; receive stock once; coordinate receipt review |
| `php/api/purchase_requests.php` | Atomic creation/document writes and locked approval/rejection, preserving bulk skip behavior |
| `php/api/receipts.php` | Coordinate proof replacement with receiving; retain the previous file until commit; clean up unsuccessful replacement files |
| `php/api/inventory.php` | Consistent reads, revision validation, opening-balance history, protected edits |
| `php/api/stock.php` | Locked edits with revision checks and required history |
| `php/api/close_month.php` | Locked stock reconciliation, revision validation and quantity/date checks |
| `php/index.html` | Operation identifiers, duplicate submission handling, safe retries and stale-form revisions |
| `php/department-api.html` | Same request retry protections in the existing department UI |
| `sql/migration_stock_integrity.sql` (new) | Additive upgrade and balance baseline |
| `sql/apply_stock_integrity.py` (new) | Explicit, backup-verified application rollout with an operator-owned maintenance gate and before/after data checks |
| `sql/schema.sql` | Equivalent structures for fresh installations; accurate warning about the legacy generator |
| `tests/phase1_security.py` | Existing regression suite updated for required API contracts; isolated browser preview |
| `tests/phase2_concurrency.py` (new) | Concurrent API, rollback, upgrade and reconciliation checks |
| `tests/phase2_browser_helpers.cjs` (new) | Actual inline JS helper tests and script syntax checks |
| `README.md`, `API.md`, `PHASE2_REVIEW.md`, `PHASE2_MIGRATION_REHEARSAL.md`, `PHASE2_APPLICATION_ROLLOUT.md` | Upgrade, API, rehearsal, rollout and testing evidence |
| `.gitignore` | Exclude private backups and the temporary maintenance marker |

Earlier Phase 1 changes remain in the worktree. The pre-existing `php/login.html`/`php/index.html` edits and deletions of `ocr_service.py` and `php/api/ocr_receipt.php` have not been reverted. Local Phase 1 notes under ignored `docs/` record the user's manual-test result.

## Database changes and compatibility

The approved local rollout is complete, and its temporary maintenance marker has been removed. No test records were created in the application database.

The upgrade adds `items.stock_version`, `stock_movements`, `inventory_operations` and `code_counters`. It does not drop or rewrite existing requests, POs, stock issues, users or usage records. Opening rows preserve stock and department balances at cutover; they are not invented historical demand.

The new code requires this upgrade. Do not point it at an unupgraded database for normal use. It rejects protected operations when the required schema is absent; deploying files and schema must be coordinated.

Before applying to an existing installation:

1. Back up the database and receipt files and verify the restore procedure. Rehearse the upgrade on an actual database copy, including all previously applied migrations.
2. Pause inventory/procurement writers and scheduled integrations. All participating business tables must use InnoDB.
3. Run the preflight checks in the migration. Also investigate fractional equipment balances, mismatched item-key collations or existing invalid business records. Do not silently clamp quantities or fabricate corrections.
4. Apply `sql/migration_stock_integrity.sql` once, stopping on the first error. Do not import `schema.sql` into an existing database and do not use a client option that ignores errors.
5. Verify row counts, stock totals, department totals and movement baselines; deploy the matching PHP/frontend files and refresh browser assets before restoring writers.

MySQL/MariaDB DDL is not an all-or-nothing application transaction. If upgrade DDL fails partway, keep writes paused and inspect the state. After new stock activity has been recorded, do not roll back only the PHP files: old code would bypass required history/revisions. Use a coordinated recovery plan, preserving post-cutover activity. No downgrade script or destructive automatic repair is included.

## Automated verification

```powershell
python tests/phase1_security.py --php C:/xampp/php/php.exe --mysql-bin C:/xampp/mysql/bin --temp-parent C:/Users/Dion/AppData/Local/Temp --phase2
node tests/phase2_browser_helpers.cjs
```

The API suite covers 176 checks: the 135 existing regression checks and 41 Phase 2 checks. A new MariaDB data directory is verified before any write SQL. Concurrency tests use separate PHP processes, separate login sessions and synchronized requests, with delays confined to disposable test triggers. Both fresh-install and upgrade fixtures are exercised. Processes stop and temporary data is removed afterward.

Covered cases include competing issuance, duplicate replay, fulfillment/cancellation, repeated reversal, receiving/cancellation, receipt rejection/replacement, duplicate PO creation, stale stock edits and month close, equipment returns/retirements, both equipment receiving destinations, quantity overflow, code allocation after 999, permission-scope changes, and forced history failures. Movement totals reconcile to current storage and department balances.

All PHP API files passed syntax validation. The isolated preview login/dashboard/assets/authentication smoke check and orderly shutdown also passed.

JS checks cover both existing pages: duplicate clicks, network retries, page reload after an uncertain result, unreadable responses, new-operation identifiers, conflict handling, plain HTTP crypto compatibility and inline script syntax. They are not a substitute for manual browser testing.

## Actual-data backup and migration rehearsal

See [PHASE2_MIGRATION_REHEARSAL.md](PHASE2_MIGRATION_REHEARSAL.md). The latest verified pre-rollout backup includes 21 original tables, 1,270 records and 54 receipt files. A single new login-attempt record after the earlier backup was included in the refreshed backup and rehearsal before application. The isolated restore/migration preserved every original row and column, reconciled opening balances, passed existing API/report reads and test-only stock/procurement workflows, and confirmed that the original database records and schema remain unchanged. Backups are Git-ignored.

The reusable rehearsal helper is `tests/phase2_migration_rehearsal.py`; it uses the installed compatible MySQL binaries, read-only source sessions and a separately verified target data directory. It must never be used as an application-database upgrade command.

## Manual browser testing without changing the application database

In your own PowerShell window, from the project directory, run:

```powershell
python tests/phase1_security.py --php C:/xampp/php/php.exe --mysql-bin C:/xampp/mysql/bin --temp-parent C:/Users/Dion/AppData/Local/Temp --phase2 --preview
```

After the checks, open the printed `MANUAL TEST URL` in Chrome. It uses a random local port and a disposable demo database. Finance is stubbed; Prophet/OCR services are not started and their preview URLs do not target your live services. Keep that PowerShell window open; Ctrl+C stops the preview and discards its test data. Do not assume the application's usual port 8000 is this isolated preview.

Demo credentials in this isolated preview: `juan / staff123` (Staff), `maria / manager123` (Manager), `admin / admin123` (Super Admin), `fleet_dept / staff123` (department). These are existing seeded demo accounts, not newly added production credentials.

Use a normal Chrome window and an Incognito window for independent login sessions. Two tabs sharing one PHP session may serialize at the PHP session-file lock and do not prove cross-user concurrency safety. API concurrency is verified by the automated multi-process suite.

| Manual check | Expected |
|---|---|
| Invalid login, all roles, refresh, logout and protected pages | Existing permissions and session behavior remain correct |
| Open inventory, existing requests, POs, issue history, equipment activity and reports | Existing records load normally |
| Issue a small quantity, then fulfill a separate stock request | Each successful action deducts its quantity once and appears in existing history |
| Attempt fulfillment/cancellation on the same pending request from independent sessions | One transition wins; the other shows a conflict |
| Open an inventory edit in one session; issue stock in another; save the old edit | Old edit is rejected and does not overwrite the issuance |
| Reopen the edit after refreshing inventory | Current revision saves normally |
| Open month close; change stock elsewhere; submit the old close | Conflict; current stock remains intact |
| Reverse a test issue twice | Only the first reversal restores stock |
| Create a PR, approve, create its PO, attach proof and receive | Existing Finance-stub/proof flow works; full quantity is added once |
| Receive the same PO from independent sessions | One receipt; one stock increase |
| Reject and replace a receipt, then receive | Corrected proof clears rejection and existing receiving still works |
| Receive equipment into storage and separately into a department | Existing destinations and equipment activity remain correct |
| Return good/damaged equipment and write off broken equipment | Storage/department totals change correctly; no duplicate units |
| Staff/department attempts direct stock edits, reversal, receiving or retirement beyond their permissions | Existing authorization is enforced |
| Review reports and monthly usage after the above actions | Existing reports still load and agree with successful actions |

Record Pass/Fail/Not tested. For a failure, include the role, actions, starting balance, expected/actual result and a screenshot. The user subsequently reported that Phase 2 looks good and accepted it.

## Remaining boundaries

- Historical month close still sets current stock according to the existing accounting flow. Separating historical corrections from live stock and reconciling forecasting/reporting usage is Phase 3.
- Multi-item requests, reservations, fairness overrides, MFA, bidding and supplier agreements are later phases.
- The actual-data copy migration rehearsal has passed. Live Finance, production proxy/cookie configuration and remote department clients still require environment-specific validation.
- The existing `sql/generate_seed.py` predates several current modules; regenerating `schema.sql` with it would remove current structures. It was not executed or rewritten in this phase.
- An abrupt operating-system/process crash can leave an unreferenced uploaded file. Ordinary upload/database failures are cleaned up; the filesystem and MySQL cannot share one atomic commit.
- Operation identifiers must be retained for uncertain retries. Clearing browser storage, closing a private session or changing the request represents a new client operation; inspect activity before resubmitting an uncertain action in those cases.
- Existing unrelated frontend whitespace warnings may remain in the worktree; they are not part of the stock behavior changes. The scoped Phase 2 backend/schema/API diff check passed.
- One earlier failed run left a temporary fixture folder. Automatic approval review blocked its separate cleanup because sandbox approval is disabled; it was left untouched. Subsequent test and preview runs cleaned up their own temporary databases successfully.

Suggested commit message after user testing and explicit approval:

`fix(inventory): serialize stock movements and prevent duplicate receiving`

No commit or push is authorized by this report.
