# Phase 4: categories and filtering - applied and verified

The user approved Phase 4 and accepted the isolated browser checks. **Implementation and automated API/regression verification passed: 287 checks, including Phases 1-3.** On 2026-10-25 at 16:37 Asia/Manila, the authorized normal-database migration was applied and verified after a fresh native backup/rehearsal. All 1,329 original records and 54 receipt files were preserved, and storage/department ledgers reconcile. Final normal-browser confirmation remains. No application dependencies, credentials or installed/global configuration were changed. No commit or push was performed. Earlier pending/blocked statements below describe the historical investigation; this status and PHASE4_APPLICATION_ROLLOUT.md supersede them.

## Implemented behavior

- One optional category on each existing inventory item; existing type, equipment grouping, locations and Finance expense classification remain independent.
- Root categories plus one subcategory level. The initial taxonomy contains 15 categories. Existing records remain Uncategorized; names are not guessed into classifications.
- Manager/Super Admin category creation, renaming, moving, archiving and reactivation. Staff can read/filter. Department users receive category metadata only on their authorized requestable list; public vendors receive it only on the existing quotable catalog.
- Server-side text/ID validation, root/sibling uniqueness including null parents, parent validity, two-level hierarchy, active-parent rules and child-before-parent archival. No category deletion endpoint.
- Category revision checks and serialized category administration. Item assignment uses its own category_version; omitted fields preserve assignments and explicit null clears them. Existing assignments to archived categories remain usable, while new assignments to them are rejected.
- Required audit writes inside the same transaction as category or assignment changes.
- Parent/child/Uncategorized filters compose with existing catalog type/item filters on inventory, purchase requests, POs and documents. Equipment variants are filtered before their visible group totals are calculated.
- Existing item edit form includes category assignment. Inventory rows show current classification. Stock request pickers, department requests and public vendor quotes have category filters. Vendor filtering hides existing inputs so prices/selections are retained.
- Inventory CSV/print reports include current category and an optional category filter. No historical classification snapshots are implied. Forecast selectors share category metadata; forecasting eligibility/calculation and usage records are unchanged.

## Changed files for this phase

| File | Change |
|---|---|
| `sql/migration_inventory_categories.sql` (new) | Add inventory_categories, taxonomy seeds, nullable items.category_id, items.category_version, foreign keys and indexes |
| `sql/schema.sql` | Same additive SQL appended for fresh installs; category table included in existing fresh-install reset list |
| `php/api/category_service.php` (new) | Shared availability, batched metadata, validation, revisions, assignments and filtering |
| `php/api/inventory_categories.php` (new) | Private taxonomy reads; authorized transactional administration |
| `php/api/config.php` | Load category helper; enrich requestable catalog and retirement history; apply optional requestable filter |
| `php/api/stock_requests.php`, `stock_issues.php`, `purchase_requests.php`, `purchase_orders.php`, `equipment_movements.php` | Current category metadata on already-authorized history rows, including inactive items; mutation/status workflows unchanged |
| `php/api/inventory.php` | Expose/filter metadata; transactional assignment and audit using existing create/edit endpoints |
| `php/api/items.php` | Metadata/filter for existing forecast selector, with existing history behavior retained |
| `php/api/vendor_applications.php` | Metadata/filter for existing public quotable list; no vendor workflow replacement |
| `php/api/reports.php` | Current-category inventory columns and filter |
| `php/index.html` | Existing catalog filters, item assignment, category administration modal, request picker and inventory export filter |
| `php/department-api.html` | Category filter on the same eligible requestable items |
| `php/vendor-apply.html` | Category filter preserving entered quote values |
| `tests/phase4_categories.py` (new) | Passing API, concurrency, rollback, inactive-history, compatibility and upgrade checks |
| `scripts/start_phase4_preview.cmd` (new) | Scoped PowerShell telemetry opt-out and a user-owned disposable preview launcher |
| `tests/phase4_chrome.cjs` (new) | Real Chrome functional checks against a marked isolated preview only; external CDN assets blocked |
| `tests/phase4_browser.cjs` (new) | Execute actual filter/render helpers and parse page scripts |
| `tests/phase1_security.py` | Add --phase4 to the existing isolated test runner, including earlier phase regressions |
| `tests/phase3_usage.py` | Replace the obsolete assumption that Phase 3 SQL must be last in schema.sql with an exact SQL inclusion check |
| `tests/phase2_migration_rehearsal.py` | Extend existing isolated backup/rehearsal operator with explicit --phase4; existing default behavior retained |
| `API.md`, `README.md`, `PHASE4_PLAN.md`, `PHASE4_REVIEW.md` | Contracts, operational status and manual checklist |
| `PHASE4_MIGRATION_REHEARSAL.md` | Generated native MySQL rehearsal results |

Earlier phase changes and the user's pre-existing changes remain uncommitted. This list is the Phase 4 scope, not a claim that every working-tree diff belongs to this phase.

## Verification results

- **287 API/regression checks passed** on the final code, including all earlier phase checks, new category validation/authorization, stale edits, concurrent category creation/assignment, audit-failure rollback, old payload/schema compatibility, and fresh/upgrade schema paths.
- Python recorded **513 loopback connections, zero non-loopback attempts**. The private PHP runtime restrictions were verified before the suite and remained in effect.
- The suite passed again while creating the labeled browser preview.
- All PHP API files pass syntax checks; the Python test modules compile.
- Phase 2 retry/duplicate-submission and Phase 3 monthly browser-helper checks pass.
- Phase 4 browser-helper checks pass: category/type/item composition, active/inactive items, history fallback, Uncategorized/free-text matching, actual equipment-renderer rollup inputs and inline script parsing.
- Modified page HTML element IDs are unique.
- Final native MySQL actual-data rehearsal **PASSED**, preserving every original row/column and receipt file. Classified-item requests/fulfillment/reversal, procurement/full receiving, reports and historical close ran on the restored copy. Source schema, records and server identity remained unchanged.
- The early telemetry-related execution block no longer prevents verification: the full suite completed with the documented PowerShell telemetry opt-out set before starting the test shell. Investigation and limits are recorded below; no unknown external transfer was authorized and no approval rules were disabled.
- **Real headless Chrome functional checks PASSED** for Manager category creation/item assignment/filtering with unchanged quantity, Staff filtering/hidden administration, Department requestable-item filtering, and Vendor selection/price preservation. No unexpected page JavaScript errors were reported. External CDN assets were blocked for these checks; visual styling remains a manual acceptance check. User acceptance and normal-database rollout remain pending.

## Migration and compatibility

The migration creates one taxonomy and adds category_id/category_version to existing items. It does not rewrite item keys, stock, usage, requests, transactions, POs or receipts. Existing items remain unclassified, and older edit payloads omit/preserve category values. New assignment changes require category_version.

The final source backup/rehearsal is `backups/phase4_before_20261025_012526_b85031`, containing 24 tables, 1,329 original records and 54 receipt files. The source records, schema and server identity were unchanged after rehearsal. The backup is Git-ignored and contains private data; do not publish it.

Until normal migration, catalog reads return Uncategorized and the taxonomy read returns an empty list. Category writes fail with a clear 503 message. Category administration is hidden when no taxonomy is available. Normal migration needs a fresh matching backup, writer pause, the rehearsed additive DDL, preserved-row/ledger checks and user acceptance first. The existing rollout operator has NOT been extended to apply Phase 4 yet.

Do not import schema.sql into an existing database. Prefer a reviewed roll-forward correction after a partial DDL failure; do not drop categories or restore over newer business transactions automatically.

## Manual checklist — after automated verification, in an isolated Phase 4 preview

1. Manager: review the seeded items, quantities, equipment groups and placements. The disposable preview contains regression fixtures and a Chrome-created sample category/assignment; unclassified items show Uncategorized. Your actual original records were verified separately in the restored-copy rehearsal and will start Uncategorized after normal migration.
2. Open Manage categories. Create a root and child, rename the child, and move it to another root. Reject duplicate sibling/root names, third levels and self-parenting.
3. Edit an existing consumable and an equipment variant, assign categories and save. Verify quantities, deployment totals and usage history remain unchanged.
4. Combine parent, child and Uncategorized filters with search, type, item and inactive filters. Clear filters. Check equipment totals count only visible variants.
5. Open the same category or item in two sessions; save one classification edit, then attempt the stale edit. Verify the stale save is rejected and the first value remains.
6. Archive an assigned child. Its items remain visible/requestable. New assignments to the archived child fail. Archiving a parent with active children fails; archive/move children first.
7. Staff: category filtering works; category administration and direct category API writes are forbidden. Existing stock request creation and fulfillment/reversal still work with categorized items.
8. Department account: filter the requestable picker, submit and cancel a request; verify no broader catalog/admin access is gained.
9. Public vendor quote page: select an item and enter a price; switch category filters and back. The selection/price must remain. Submit and approve a test quote using the existing vendor workflow.
10. Create/approve a purchase request for a categorized item; create its existing single-item PO, record proof and receive it once. Confirm stock increases once and repeated receiving fails.
11. Export inventory CSV and printable report with a category selected. Confirm current classification and report scope. Open existing reports and documents.
12. Close/correct a completed historical month. Verify received-this-month autofill still works and live stock is unchanged. Confirm forecasting selector/history behavior is retained.
13. Verify audit history for category and assignment changes; test login/logout and protected pages for all existing roles.

User manual results: not yet available. Run `scripts/start_phase4_preview.cmd` for a fresh labeled disposable preview and use the checklist above. Full Chrome visual styling and responsive layouts still require manual acceptance.

## Follow-up investigation — 2026-10-25 (Asia/Manila)

The user authorized investigation of the reported connection. Inspection found no project telemetry client, Python site-packages startup hooks in the inspected installation, PHP auto-prepend/append script, or configured PHP telemetry extension. This does not establish the source of the connection reported by approval review. A filtered DNS-cache inspection returned no matching telemetry names; this is not proof that no such connection occurred.

The test runner now verifies a private temporary PHP configuration, inherited by its test/concurrency processes, disabling outbound cURL, URL streams, direct socket functions, mail and process launches. Python rejects non-loopback DNS requests as well as socket connections. The host's installed PHP configuration and managed proxy/security variables are preserved. Local PDO/database access remains enabled for the isolated database. These restrictions do not affect normal application processes.

A run with the verified private runtime progressed through the earlier stock/monthly concurrency and ledger checks. Automatic approval review nevertheless rejected completion of the command, again citing unverified external HTTPS telemetry. No further runtime retries or indirect execution were attempted after that rejection. The review did not supply a destination hostname or payload that could be verified. Full API verification remains blocked; the new category checks have not completed under the stronger restrictions. The later cURL-init hardening is syntax-checked but has not been exercised by a completed runtime run.

Unaffected checks were rerun successfully: all PHP API syntax checks, Phase 2/3/4 browser-helper tests, Python compilation and unique HTML element IDs on the three modified pages. Code review also corrected Unicode-whitespace-only category names to be rejected as blank; its API regression case is written but still awaits a completed run. No normal database migration, commit or push occurred.

Proposed commit message only after verification and explicit user approval: `feat(inventory): add hierarchical categories and shared catalog filters`

No commit or push is authorized by this document.

## Resolution and final integration correction

The execution shell was identified as bundled PowerShell 7.6.5 with the Application Insights assembly present. Microsoft documents PowerShell startup telemetry and requires `POWERSHELL_TELEMETRY_OPTOUT=1` **before** PowerShell startup (https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.core/about/about_telemetry?view=powershell-7.6). A child-shell probe verified that opt-out before execution. The same full suite then completed successfully, with the existing security restrictions intact. No global environment, installed PowerShell/PHP configuration, proxy/security guard, approval rule or application credential was changed. This establishes a successful restricted execution path; it does not independently reconstruct the payload or destination of the earlier reported connection. The empty local Microsoft telemetry queue did not provide that evidence.

Final integration review found that inactive items, absent from Staff's active inventory list, needed category metadata on their already-authorized history rows. The existing history formatters now obtain current classification through one batched lookup from the same items table. Staff list permissions stay unchanged. Central UI metadata resolution falls back to those history rows. Legacy free-text stock requests match All/Uncategorized rather than every selected category. Tests cover the correction; no new stock/request/PO workflow or database table was added.

Final full-suite log: `C:/Users/Dion/AppData/Local/Temp/phase4-final-regression.log`. Labeled preview log: `C:/Users/Dion/AppData/Local/Temp/phase4-browser-preview.log`. Both finish with 287 passing checks and 513 loopback connections. Logs are local diagnostics, not application data or an instruction to commit them.

The real Chrome check also exposed a pre-existing form mismatch: consumable quantities support two decimal places server-side, but the item's HTML number inputs had default whole-number steps. The form now uses 0.01 steps for consumable quantity/thresholds and switches the quantity step to 1 for equipment. This allows classification of existing fractional-stock items without changing their quantities. The actual item-type toggle and browser form validity are covered by checks.

Only the disposable preview's forecast endpoint is replaced with an authenticated, structured 503 explanation because outbound service access is intentionally disabled there. The normal `php/api/forecast.php`, Prophet pipeline and normal service configuration are unchanged. This preview is intended for inventory category and connected regression review, not live external-service validation. Finance remains in the existing stub mode.

## Browser preview and final handoff

Live preview at handoff: http://127.0.0.1:50062/login.html . It is a disposable demo/test database, visibly marked ISOLATED TEST PREVIEW. Existing normal application records are not shown or modified by this preview. Regression fixtures and the browser-created sample category are expected.

Manager: `maria / manager123`; Staff: `juan / staff123`; Super Admin: `admin / admin123`; Department: `fleet_dept / staff123`. These are seed/demo credentials only.

If the managed preview stops, double-click `scripts/start_phase4_preview.cmd` on your PC and leave its window open; use the new local URL it prints after checks finish. Enter or Ctrl+C in that window stops the disposable preview. The launcher sets the telemetry opt-out only in its own environment; it does not modify installed/global configuration.

Chrome functional test: `node tests/phase4_chrome.cjs <marked-preview-url>` uses the already-present Puppeteer and installed Chrome; it first verifies the disposable-runner marker and rejects normal/unmarked application targets. Do not run it against the normal system. No dependency installation or package/lockfile change was needed.

Technical acceptance and user preview acceptance are complete. The subsequent normal rollout is recorded in PHASE4_APPLICATION_ROLLOUT.md. Separate commit approval remains outstanding. Phase 5 implementation still requires its own plan review and approval.

## Authorized normal rollout - 2026-10-25

- Extended the existing sql/apply_stock_integrity.py operator with explicit --phase4, phase/hash matching, prerequisite and unapplied-schema checks, pre/post stock reconciliation, category constraints/index verification and protected-endpoint smoke checks. Existing Phase 2/3 selection remains intact; simultaneous phase flags are rejected.
- Fresh backup/rehearsal: backups/phase4_before_20261025_163620_313b9d. Native restore and connected workflow checks passed before normal application.
- Maintenance gate verified; database quiet and source fingerprints matched backup. Only migration_inventory_categories.sql was applied. Original rows/columns and all receipts remain unchanged; no demo records were inserted into the normal database.
- All 15 categories exist. Existing items start Uncategorized at category_version zero. Maintenance is off; normal health/login/auth and protected API responses passed.
- Normal local browser review: http://127.0.0.1:8000/login.html using existing accounts. Check original inventory/history, Manage categories, filters and unchanged quantities. This is the normal database; regression fixtures belong only in the disposable preview.
- User-reported isolated manual result: Phase 4 seems good. The slash-based category path remains a minor display ambiguity; no hierarchy or data defect was found. No unrelated display change was included in rollout.
- Live external Finance/forecasting integrations were not exercised by the isolated regression tests. No commit or push occurred.
