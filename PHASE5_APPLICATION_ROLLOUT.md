# Phase 5 application rollout

**APPLIED AND VERIFIED**

Approved by the user. No commit or push performed.

- Finished (Asia/Manila): 2026-10-26, 18:42:16
- Normal local URL: http://127.0.0.1:8000/login.html
- Backup: phase5-development/backups/phase5_before_20261026_183412_d5e29f
- Structural migration SHA-256: 8197698630ee97644537e6d198a3f2178f476af9b57acd6dff8de48c1d3a94a7
- Historical correction SHA-256: 9f56862c63a289ba437c3bbeea6131a685a02808de2ac63b7875f6b91561007a
- Maintenance: lifted after verification.

## Verification

- Original files, database, receipt hashes, quiet writers and both rehearsed migrations verified.
- Schema/line linkage verified; only four approved request statuses changed, four audits added, original evidence and stock preserved.
- Matching code/frontend files deployed with exact predeployment backups and verified release hashes.
- All 36 PHP API files linted and shared/inline JavaScript syntax and unique IDs passed.
- Normal HTTP health/pages/assets respond; protected reads and anonymous POST writes return 401; internal dispatcher returns 404.
- All database rows/schema unchanged by final HTTP checks; receipts unchanged; no unrelated baseline files changed.

The disposable regression suite passed 326 checks before rollout. The fresh native restore rehearsal passed correction rollback/retry/stale-state tests, publication failure recovery evidence and connected request/procurement/receiving/monthly workflows. Original 1,344 records were retained; the four approved status corrections and four new audit entries are deliberate. All 54 receipts were preserved. No fixtures/accounts were added to the normal database.

## Changed files

- php/api/config.php
- php/api/stock_service.php
- php/api/stock_requests.php
- php/api/stock_issues.php
- php/api/inventory.php
- php/api/stock.php
- php/api/reports.php
- php/api/stock_request_service.php
- php/api/stock_requests_v5.php
- php/stock-request-ui.js
- php/index.html
- php/department-api.html
- sql/schema.sql
- sql/migration_stock_request_items.sql
- sql/migration_close_reversed_requests.sql
- sql/apply_stock_integrity.py
- sql/phase5_rollout.py
- API.md
- tests/phase1_security.py
- tests/phase2_migration_rehearsal.py
- tests/phase5_requests.py
- tests/phase5_chrome.cjs
- tests/phase5_csv.cjs
- tests/phase5_ui_syntax.cjs
- tests/phase5_migration_preflight.py
- tests/phase5_historical_correction.py
- tests/phase5_operator.py
- scripts/start_local_system.cmd

## Manual acceptance

1. Open the normal URL and hard-refresh (Ctrl+Shift+R); use your existing normal accounts, not disposable preview credentials.
2. Check old requests, including 18/26/27/31: Closed, original Fulfilled evidence and voided issues retained, no open reservation or issue action.
3. Verify multi-item Stock Request/purpose and Manager review. Perform real approval/issuance only when operationally intended; confirm approval reserves and issuance deducts once.
4. Verify partial issuance, closure/release, protected override reason, Self-approved label and request report.
5. Verify inventory/categories, existing vendor/PR/PO/receiving screens, receipt links, month-close/autofill and reports.
6. Log out and confirm role boundaries.

The local server may stop with the tool session. Double-click scripts/start_local_system.cmd to run it yourself at the same URL; leave its window open. This normal launcher preserves your data across restarts.

## Limits and next step

Live external Finance/forecasting services were not exercised by the isolated suite. The transaction gate serializes stock writers; large-history query performance needs profiling if volume grows. Final normal-browser acceptance remains with the user. Do not begin Phase 6 implementation until Phase 5 acceptance and separate Phase 6 plan approval.

Suggested commit after separate approval: feat(inventory): add approved multi-item stock requests and reservations
