# Phase 6 emergency administrator MFA recovery

Prepared 31 October 2026. Implementation was explicitly approved after the file-by-file proposal. Department-account rollout and hosting readiness are deferred by the user. Implementation and targeted/cumulative automated verification are finished. User manual review/acceptance is pending; this does not close Phase 6.

## Change and operating boundary

Added `scripts/recover_admin_mfa.php`, an operator CLI tool outside the public root. It defaults to a read-only readiness check. Execution requires the configured database name, repeated target username, operator name, incident reason, explicit all-administrator-lockout attestation, mandatory MFA, the existing maintenance marker and a new private output directory. The target must be an existing active named Super Admin with a previously enrolled authenticator decryptable using the current key. A revoked previous factor is allowed for reissuing an expired/lost reset handover.

The tool reuses the existing account lock order, MFA reset service, grant tables, login setup and audit service. One transaction revokes only the target's old factor, recovery codes, outstanding grants and challenges, increments the security version, requires MFA, issues a hashed 15-minute reset grant and adds both `mfa.grant` and `mfa.emergency_recovery` audit records. The private raw authorization is written and flushed before commit and is never printed to stdout/stderr or audit. Its file alone is not proof of commit.

`php/api/config.php` permits maintenance access only when PHP's actual SAPI is CLI, the tool's internal flag is true, the tool exists and the directly invoked script resolves to that exact installation's recovery-tool path. Web requests and arbitrary CLI scripts remain subject to maintenance. No new HTTP endpoint or login screen is introduced. Maintenance is never automatically disabled by this tool.

No account creation, password change, role change, schema migration, environment/key change, normal-account reset, normal maintenance activation, commit or push was performed for this implementation. Other business features and the first-admin bootstrap were not changed.

## Files

- Added: `scripts/recover_admin_mfa.php`, `tests/phase6_emergency_recovery.py`, this review.
- Modified: `php/api/config.php`, `tests/phase6b_mfa.py`, `README.md`.
- Deleted: none.
- Runtime database writes during verification: disposable fixtures only.
- `scripts/` remains Git-ignored. A future separately approved commit/package must deliberately include the recovery tool. Private output and evidence must be excluded.

Private implementation evidence is under `backups/emergency-mfa-recovery-20261031-01/`: preserved pre-change files, test logs, diagnostic directories, metadata-only network logs, normal-system security/audit fingerprints and the actual default read-only check. Never publish the evidence directory or handovers.

## Verification record

PHP syntax checks passed for the tool and modified configuration. Python syntax parsing passed for both changed test modules. The tool's `--help` output was executed successfully without database access.

The actual default read-only check for normal `owner` passed: target eligible, enforcement enabled, maintenance disabled. Fingerprints of users, authenticators, recovery codes, enrollment grants, challenges, policy and audit were identical immediately before/after that check. No normal account was reset.

The first cumulative attempt passed 478 checks, including the existing regressions and initial emergency safeguards, then stopped on a syntax error while creating the new test's conditional audit-failure trigger. The test's MariaDB client delimiter handling was corrected. This is a failed attempt, not a completed suite.

The second cumulative attempt stopped after 122 passed checks: receiving returned success, but its subsequent existing stock query returned an empty value and Python could not convert it to a number. It did not reach emergency recovery. This intermittent baseline result remains unexplained; no unrelated purchasing/application fix was applied.

The final cumulative run (`run-03.log`) exited 0 with **506 checks passed**, including **47 emergency-recovery assertions**, and an empty error log. It recorded **881 loopback Python connections and zero non-loopback attempts**; the fixture's PHP outbound restrictions remained enabled. All disposable fixture directories were cleaned up. This is the passing run; the two earlier unsuccessful attempts remain retained separately.

The passing emergency tests cover read-only preservation, execution confirmations, target restrictions, policy/key checks, private-output restrictions, web/other-CLI denial, audit/output-failure rollback, target-only revocation, preservation of other administrators and business tables, grant expiry/supersession/consumption, concurrent resets, password-only denial, fresh enrollment/TOTP login, replay protection and one-time recovery codes. PHP syntax and Python parsing were checked again after the implementation stabilized. The tool's default read-only check was also exercised against the normal installation as described above.

## Manual regression guide

Run the preview from the repository in your own PowerShell terminal:

```powershell
& 'C:\Python314\python.exe' tests/phase1_security.py --phase6b --preview --temp-parent C:/Users/Dion/AppData/Local/Temp
```

After tests pass, use the printed **isolated** URL and private `manual-browser-handover/handover.json` path. The disposable account is `emergency_admin` with password `MfaPassword123`. Open the JSON file privately and copy the complete **64-character `grant` value**, without surrounding quotation marks, into Enrollment authorization. The temporary folder identifier and file path are not the authorization. It expires after 15 minutes. Keep the terminal alive while testing. Restart any old preview after applying the preview-handoff fix below. The preview policy is restored to its original preparation setting after automated checks; the recovered account itself remains MFA-required. The automatic emergency execution tests run with global enforcement enabled. The preview handover tests the browser enrollment/login path, not a new real-account reset.

## Manual-preview handoff correction

User manual testing exposed a preview preparation defect: four deliberately failed MFA attempts remained on `emergency_admin`; a subsequent invalid authorization attempt reached the five-attempt account limit. Read-only inspection of that user's isolated fixture confirmed five recent failures and an unexpired grant. The screenshot's submitted authorization matched the temporary directory identifier rather than the JSON file's `grant` value.

The separately approved fix changes only `tests/phase6_emergency_recovery.py`, `README.md` and this report. After all negative/security tests finish, preview preparation rechecks database name, physical database directory, web directory and fixture marker. It clears only the synthetic account's attempt records, verifies security records and other accounts' attempts are preserved, and checks shared-IP rate budgets without resetting them. It then issues a fresh handover through the existing operator tool, proves enrollment can start, cancels that pending challenge without consuming the grant, and checks that the grant has at least 14 minutes left and the account has zero failed verification attempts. Console/docs explicitly identify the full JSON `grant` value. No production rate limit, application PHP, schema or normal account is changed.

Fix verification: Python syntax parsing passed. The first attempt stopped after 137 passing checks on the existing Phase 2 assertion `concurrent fulfillment produces exactly one deduction`, before preview preparation. Its cause remains unresolved; no inventory/application changes were made. The repeat with actual `--preview-hold` preparation passed **510 checks**, including all four new preview-readiness assertions, with an empty error log and **888 loopback Python connections, zero non-loopback attempts**. It proved that only the synthetic account's attempts were cleared, other security records and accounts' attempts were preserved, shared IP limits retained headroom for the checklist, the fresh grant started enrollment without a rate-limit error and cancellation left it unused with zero failed account verifications. A final read-only check confirmed the preview's grant was still unused and the account had zero failures. Evidence is in `backups/emergency-preview-handoff-fix-20261031-01/`. The new preview is retained temporarily for user manual review; stopping its own runner/control removes its disposable database. The earlier 506-check result above predates this correction. Browser rendering/user acceptance remains pending.

| Test | Account/role and page | Actions | Expected result and existing behavior to retain |
|---|---|---|---|
| Normal administrator access | Normal `admin`, then `owner`; normal Login | Sign in privately in fresh sessions with password and authenticator. Open Inventory and Manage Users. | Both retain Super Admin access; password alone does not open protected pages. Their existing authenticators still work. |
| Pending recovery remains blocked | Disposable `emergency_admin` / Super Admin; isolated Login | Attempt normal password login before enrolling; try opening isolated `index.html` directly. Cancel and return to Login. | Protected inventory/user data remains inaccessible. No authenticated session is created by the operator grant or password alone. |
| Standard recovery enrollment | Disposable `emergency_admin`; isolated Login | Choose **Set up or replace an authenticator**. Enter the disposable password and private reset authorization. Scan QR, enter code and save the new recovery codes privately. | Ten recovery codes appear after confirmation. Setup requires a fresh login; it does not automatically open the dashboard. Reusing the authorization for setup fails. |
| Fresh login and recovery-code rules | Same disposable account; isolated Login | Sign in using the next authenticator code. Log out; sign in with one complete new recovery-code line. Log out and try that same recovery code again, then a fresh authenticator code. | Authenticator login works, a recovery code works once, reuse is rejected and a fresh valid authenticator still works. Logout removes access. |
| Audit and connected workflows | Recovered disposable Super Admin; isolated dashboard | Open Audit Log, filter username `operator_emergency` and view the recovery details. Then open existing Inventory, Stock Requests and Purchase Orders. | Recovery records show operator/reason/grant metadata without raw authorization, QR key or recovery codes. Existing records/pages remain available. |
| Existing role boundaries | Normal `juan` / Staff and `maria` / Manager; normal Login/dashboard | Complete each person's own MFA login. Open their normal inventory/request pages and check whether account administration is available. | Existing Staff/Manager workflows remain usable; neither gains Super Admin user-management access. |

Report pass/fail for these checks. If something fails, investigate and propose its fix for separate approval. Explicitly waive any unperformed manual checks if desired. Implementation is not phase acceptance; no subsequent phase, commit, push or deployment is authorized by test success alone.

## Limitations and incident handling

- Operator authority and account ownership require external verification. Possession of server/database access is a privileged trust boundary; the software cannot verify the truth of the lockout attestation.
- Windows parent-directory ACLs must restrict access to private outputs. POSIX chmod modes are not Windows ACL isolation. The path guard rejects the installation's public PHP root, including canonical ancestor resolution; the operator must also exclude any other web-served roots/aliases on the host.
- The maintenance marker stops new API requests, not existing requests, scheduled writers, direct database access or operators removing maintenance during recovery. Pause/drain those writers first.
- A crash before commit can leave an unusable private file. A commit connection failure can have an uncertain outcome. Keep maintenance, inspect the matching live grant and emergency audit, and reconcile before retrying. Only the latest committed reset grant remains usable. Do not disable MFA as a recovery shortcut.
- Lost passwords, lost encryption keys, corrupted encrypted factors, unavailable database/storage, host-specific deployment and live Finance/forecast availability are outside this feature. This implementation uses local PHP CLI; a host without CLI needs a separately reviewed operator method.
- Dedicated Chrome automation and user manual checks have not been run for this change. Real-HTTP API tests exercise existing enrollment/login endpoints; they do not establish the browser rendering result.
- The previously observed close/issue and physical-correction intermittent failures, plus this run's empty receiving query, have no established root cause. No claim that this recovery change fixes those failures is made.
