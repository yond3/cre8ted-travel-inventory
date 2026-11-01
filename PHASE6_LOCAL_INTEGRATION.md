# Phase 6 local integration and final regression verification

**LOCALLY INTEGRATED AND REGRESSION VERIFIED — 28 October 2026**

Phase 6 account security/MFA changes are integrated into the normal local source and database. No hosting deployment, global MFA enforcement, commit or push was performed. Current system: http://127.0.0.1:8000/login.html. Existing normal accounts/passwords remain in use; fixture credentials and authenticator entries do not transfer.

## Final evidence

| Verification | Result |
|---|---|
| Complete merged-source disposable API suite | 458 checks passed; command exited 0; error log empty |
| Network diagnostic audit | 834 loopback Python connections; no non-loopback attempts; only installed PHP/MariaDB executables allowed |
| PHP outbound policy during API tests | HTTP/URL streams, direct sockets, mail and subprocess execution disabled |
| Real Chrome MFA | Authorized enrollment, local QR, recovery display, password-only denial, TOTP login, factor replacement and old/current recovery behavior passed |
| Real Chrome accounts | Named-account creation, Department password change/re-login and permission boundaries passed |
| Real Chrome legacy handling | Legacy edits without reclassification, disabled shared choice, deactivation preserving identity, and rejected reactivation without named ownership passed |
| Real Chrome categories | Manager/Staff/Department/vendor category behavior passed |
| Real Chrome stock requests | Multiple items, Manager self-approval, Staff partial issuance, Department submission and protected-stock form passed |
| Real Chrome recovery mock | Incomplete code guidance, inactive field validation and full-code submission passed |
| Real Chrome current normal login | CSS returned as text/css and applied; max-width 480px; challenge hidden; controls enabled; setup toggle works; no JavaScript errors |
| CSV export regression | Formula neutralization passed through the disposable API |
| Asset and script checks | 17 local asset references; inline/shared JS; request retry/idempotency helpers; monthly/category form handlers; five HTTP UI behaviors; test syntax passed |
| Original data | Original business/user records and original audit rows preserved; all 54 receipt files match backup hashes |
| Local service | Normal port 8000 running; maintenance inactive; only this run's disposable fixture stopped and removed |

The 458-check suite includes staged Phases 1–6 regressions: authentication/authorization/CSRF, stock ledger and concurrency, receiving retries and rollback, historical month-close consistency, categories, multi-item requests/reservations/issuance, account/session revocation, MFA/OTP/recovery, HTTP origin policy, first-admin provisioning and required security audit rollback. Browser stock/category/account checks ran against the fully migrated disposable system. This is local regression evidence, not a claim that future features or external integrations have been implemented.

## Corrections made

The local merge omitted php/assets/security.css. It was restored from the tested candidate. tests/phase6_assets.py and the integration operator's file/health manifest now cover it. The normal login's rendered CSS was verified in real Chrome, so an HTTP 200 alone is not being treated as sufficient.

An earlier local configuration check exposed a Windows is_readable() false negative despite successful file access. php/api/env.php now checks the actual file-read result, preserving missing-file handling and process-variable precedence. Encryption and the complete regression run pass with this fix.

Earlier full runs were interrupted by automatic approval review over an unverified telemetry connection. The final diagnostic run completed without that interruption. Its application-level trace contains only loopback destinations, logs no request bodies/credentials/secrets, and allows only installed PHP/MariaDB executables. The origin of the earlier external-connection report was not established; do not claim it was an application telemetry feature or remove host-managed security/proxy settings to work around it.

## Test harness changes

- tests/phase1_security.py: optional --network-log metadata and subprocess allowlist; existing loopback audit and private PHP restrictions retained. Windows audit command-line parsing corrected.
- tests/local_browser_runtime.cjs: existing installed Chrome with disposable profile/temp directories; external DNS blocked and page requests intercepted by callers. In this restricted Windows execution environment the nested browser sandbox failed to start, so only this synthetic local-test launcher uses --no-sandbox. No installed browser setting or application security policy was changed; do not use it for public browsing.
- tests/phase4_chrome.cjs, phase5_chrome.cjs, phase6a_chrome.cjs, phase6b_chrome.cjs, phase6b_recovery_ui.cjs: reuse the local test launcher without removing workflow assertions.
- tests/phase6c_chrome.cjs: new browser regression for legacy account editing/deactivation/reactivation; writes require the marked disposable fixture.
- tests/phase6_merged_login.cjs: read-only normal login styling/readiness regression.
- tests/phase6_assets.py: verifies referenced local assets exist; no CDN fetches.
- phase6-development/tests/phase6_local_integration.py: required stylesheet/tests included in publication manifest and stylesheet health check.

Matching test changes are retained in the isolated candidate. No dependencies were installed or updated. No extra business schema changes were made during verification.

## Data and operating state

The normal database received only the previously approved additive account/MFA migrations. Global auth_policy.enforce_mfa stays 0. Real users were not automatically enrolled, renamed, reclassified or deactivated. Existing unenrolled users can sign in during preparation; enrolled users require their factor. Current private local MFA configuration/key is preserved. Do not regenerate that key as a routine recovery action.

Mutation, concurrency, browser and CSV fixtures used a separately verified temporary database. The normal login browser check submitted no real credentials or business writes. Earlier normal smoke logins/report reads added normal login/report audit entries; all pre-existing audit entries remain intact. Final normal-data checks were read-only.

Backup and detailed evidence:

`phase6-development/backups/phase6_before_20261028_171340_57ed0a`

- database.sql and manifest.json: verified pre-integration backup and fingerprints.
- preintegration/: original application/configuration files.
- verified-full-regression.log / .err.log: complete API result and empty error log.
- verified-network.jsonl: destinations/executable metadata only.
- browser-mfa, browser-categories, browser-accounts, browser-requests, browser-legacy .log/.err.log: all passed, final error logs empty.
- final_regression_result.json: final integrity/coverage summary.
- local_integration_attempt1.json and integration_report_before_final_verification.md: earlier failure/blocked evidence retained for history.

Never publish backups, environment files, test fixtures, keys or handovers under the public PHP root.

## Limits and manual acceptance

Functional dashboard browser tests block/stub external CDN assets; they do not establish pixel-perfect dashboard styling with live CDNs. The actual normal login's local stylesheet was loaded and rendered successfully. Live Finance/forecast services and hosting compatibility were not exercised; they remain separately scoped. There is no claim that every possible bug is eliminated.

Open http://127.0.0.1:8000/login.html and hard-refresh with Ctrl+Shift+R. Confirm login/account-security appearance and your normal navigation. Existing real-account enrollment, global enforcement and hosting remain separate from merging and verifying the code. No next feature phase started.

Suggested eventual commit, only after user approval: feat(auth): integrate TOTP MFA and named account security
