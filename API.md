# Department API integration

Phase 5 development addendum: this copy implements approved multi-item stock requests.
POST stock_requests.php accepts department (derived for department accounts), mandatory purpose,
optional expected_collection, and items [{item_key,requested_qty,notes?}].
PUT ?id requires request_revision and X-Idempotency-Key; actions are edit, approve, issue,
cancel/close, release. Decisions reference child id and approved_qty; reductions/rejections
require reason, protected-stock use requires override_reason. Issuance uses items [{id,qty}].
All responses include items with requested/approved/issued/released/remaining quantities.
Flat item/qty columns are legacy evidence, never multi-item totals.

Normal direct stock_issues POST is manager/admin expedited issuance through the same request
service; staff must use an approved request. Linked void requires reason, request_revision and
reservation_disposition release/reissue. Closed requests cannot reissue through void.

Inventory PUT can set protected_qty (null means unconfigured), with stock_version and
policy_reason. Corrections/write-offs may carry reservation_releases
[{id,qty,request_revision,reason}]; all selected releases and correction commit atomically.
GET stock_requests.php?outstanding=1 lists open commitments within existing role scope.
Manager report: reports.php?type=stock_requests&format=csv|html.
This is isolated development; do not deploy/apply to the normal system before manual acceptance,
the legacy exception decision and approved coordinated rollout.

Departments use the **same request flows** as the central app’s “Request from stock” and “Request equipment” buttons — pick catalog items for storage requests, describe new equipment for purchase requests. No full inventory table access.

Central staff use `index.html`. Department accounts use `department-api.html` or their own UI against these endpoints.

---

## Authentication

Session-based JSON API (cookie `PHPSESSID`). Use `credentials: 'include'` in browser fetch.

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| `POST` | `/api/auth.php` | `{ "username", "password" }` | `{ "status": "ok", "user": { username, name, role, department? }, "csrf_token" }` |
| `GET` | `/api/auth.php` | — | `{ "authenticated": true/false, "user"?, "csrf_token"? }` |
| `DELETE` | `/api/auth.php` | — | `{ "status": "ok" }` — send `X-CSRF-Token` if logged in |

After login, every `POST` / `PUT` / `DELETE` (except the login POST itself) must include header `X-CSRF-Token` matching `csrf_token` from `GET /api/auth.php`. The public vendor quote form does not send a token.

**Demo department accounts** (password `staff123`):

| Username | Department |
|----------|------------|
| `fleet_dept` | Fleet & Transportation management |
| `tour_ops_dept` | Tour Operations |

Open `http://localhost:8000/department-api.html` after signing in.

---

## Requestable items (picker list)

Same pool as central **Request from stock** — active items with quantity in storage.

`GET /api/stock_requests.php?requestable=1`

Optional: `&type=consumable` or `&type=equipment`

```json
[
  {
    "item_key": "ballpointpens",
    "label": "Ballpoint pens",
    "display_label": "Ballpoint pens",
    "unit": "pcs",
    "item_type": "consumable",
    "max_qty": 25
  }
]
```

- `max_qty` — how many can be requested (same cap as central UI)
- No locations, min/max thresholds, or admin fields

---

## Stock requests

### List (scoped to department)

`GET /api/stock_requests.php` — optional `?status=Pending`

### Create (department)

Same shape as central, **without** `department` in body (taken from login):

```json
{
  "item_key": "ballpointpens",
  "qty": 5,
  "notes": "optional"
}
```

### Create (central staff)

```json
{
  "department": "Tour Operations",
  "item_key": "ballpointpens",
  "qty": 5,
  "notes": "optional"
}
```

### Cancel

`PUT /api/stock_requests.php?id=<id>` — `{ "action": "cancel" }`

Fulfill is central staff only (main app).

---

## Purchase requests (Request equipment)

For **new** equipment to buy — same as central **Request equipment** button.

### List (scoped)

`GET /api/purchase_requests.php`

### Create (department)

```json
{
  "requested_label": "HP LaserJet printer",
  "qty": 1,
  "request_type": "equipment",
  "reason": "new_need",
  "notes": "optional"
}
```

Department is set automatically. Approve/PO flows are central only.

---

## Blocked for department accounts (403)

Full inventory, issue/fulfill, POs, suppliers, admin endpoints, etc.

---

## Migration

Existing DBs:

```powershell
Get-Content ".\sql\migration_stock_requests_free_text.sql" | mysql -u root cre8ted_inventory
```

(Free-text columns remain for legacy rows; new department requests use `item_key`.)

---

## Same-origin note

Session cookies require the department UI to run on the **same host** as the API, or use a server-side proxy. Cross-domain Bearer tokens are not in v1.


## Phase 2: safe retries and stale stock forms

Authorization and `X-CSRF-Token` remain mandatory. An operation identifier does not grant access.

- `POST stock_issues.php`, `POST equipment_returns.php` and `POST inventory_retirements.php` now require header `X-Idempotency-Key`: a unique 16-100 character identifier made of letters, digits, `_` or `-`. Keep it for every retry of the same operation, including after a lost response. A new intentional operation needs a new identifier.
- Stock-request and purchase-request creation honor the same header when supplied. Older integrations can still create these requests without it, but do not receive creation-retry protection until updated.
- The same authorized actor, route and payload with the same key returns the original response and does not repeat the stock effect. JSON property order is ignored; changed values, routes or permission scope with an existing key return HTTP 409. Failed transactions do not consume their keys.
- Fulfillment/cancellation, issue reversal, PO creation/receiving/cancellation and receipt review serialize their existing record/status transitions. A repeated or losing transition normally returns HTTP 409 rather than applying it twice.
- `PUT inventory.php?item=KEY` when including `current_qty`, `POST stock.php?item=KEY` when including `current` require `stock_version` in the JSON body. Read it from the inventory item response when opening the form. Month close uses the Phase 3 monthly tokens below. Missing revision: HTTP 428. Stale revision: HTTP 409. Refresh and review the new values; do not automatically attach a fresh revision to an old form submission.
- A lock timeout/deadlock returns HTTP 409 with a retry message; other transactional failures roll back. Avoid blind write retries with new operation identifiers.
- The bundled central and department pages generate the header and retain uncertain retry identifiers in session storage. If storage is unavailable, protection is limited to retries within the current page. External clients must persist their own identifiers.

For example, after opening an item with revision 7:

```json
{ "current": 12, "stock_version": 7, "reason": "Verified current shelf count" }
```

Existing roles, statuses, single-item stock requests and full PO receiving remain in place. The matching Phase 2 database migration must be applied before these PHP/frontend files are deployed. See [PHASE2_REVIEW.md](PHASE2_REVIEW.md).

## Phase 3: historical usage and month closing

Requires `sql/migration_usage_consistency.sql` after Phase 2. Monthly endpoints return an explicit upgrade-required 503 until it is installed. The approved local application rollout is complete; see PHASE3_APPLICATION_ROLLOUT.md. Do not reapply it.

- Closing or correcting a historical month never changes `items.current_qty`, `stock_version`, deployments or stock movements. Use the existing inventory/stock adjustment for a physical correction today.
- Only completed calendar months can be written as final usage. The database calendar defines the boundary, matching receiving and demand summaries.
- `GET close_month.php?item=KEY&month=YYYY-MM` returns `usage_version` and `context_version`. Capture both when opening the form. Send both unchanged on POST with `month`, `opening_qty`, `received_qty`, `closing_qty` and optional `reason`.
- Unknown opening quantities are null, not today's stock. Saved receipt overrides are preserved on reopening; `po_received_qty` separately shows the PO total. Corrections, replacement of existing manual usage and overrides of suggested figures require a reason (maximum 200 characters).
- Missing tokens return 428; a changed monthly revision or changed supporting context returns 409. Reload and review rather than automatically attaching fresh tokens to old values.
- Closing above opening plus receipts is rejected with 400. Negative computed usage is never clamped to zero.
- A successful close returns `computed_usage`, `usage_version`, `current_qty`, `stock_changed: false` and `warnings`. The former `new_current_qty` response is removed because no current stock update occurs. `next_month_opening` is a suggestion only; later saved months are never rewritten automatically.
- `GET usage.php?item=KEY` retains its array of `month`/`usage` records and adds `usage_version`, `usage_source`, `has_month_close` and `usage_conflict`. Missing months are not inserted as zero.
- `POST usage.php?item=KEY` requires `month`, `usage`, `usage_version` and a reason when overwriting an existing value. A genuinely unrecorded month starts with revision `0`. A month with a saved close must be corrected through month closing, not through this independent endpoint.
- Both monthly write endpoints honor optional `X-Idempotency-Key` with the existing actor/route/payload rules. The bundled UI supplies it. Required audit history, monthly records and successful replay responses commit together.
- Current stock changes through `stock.php` or inventory PUT require a nonempty `reason` when the existing quantity changes, in addition to the existing stock revision. Their audit write participates in the same transaction. Metadata-only edits do not require a stock adjustment reason.
- The month-close report shows both close usage and recorded usage, legacy/manual/month-close source, and inconsistencies. Existing discrepancies are exposed rather than repaired automatically. Full audit history remains governed by the existing Super Admin permissions.

The Prophet model/input columns are unchanged in this phase. Dashboard averages now use recorded observations within the last six completed calendar months and expose coverage; missing observations remain unknown. Existing procurement signals in the dashboard score remain unchanged.
# Phase 4 category API - verified and applied to the normal database

- `GET inventory_categories.php`: Staff and above, excluding department accounts. Returns roots/children including archived categories (`id`, `name`, `parent_id`, `active`, `revision`). Before migration it returns `[]`.
- `POST inventory_categories.php`: Manager/Super Admin; `{name, parent_id?: null|positive_id, active?: boolean}`. Creates a category, not an inventory item.
- `PUT inventory_categories.php?id=N`: Manager/Super Admin; `{revision, name?, parent_id?, active?}`. Missing revision returns 428, stale revision 409. Root plus optional child only; active children must be archived/moved before parent archival. No DELETE endpoint.
- Existing `inventory.php` POST accepts optional `category_id`; PUT accepts `category_id` plus `category_version` whenever assignment is supplied. Omitted assignment preserves it; explicit null clears it. Invalid/inactive new assignments return 400; missing assignment revision 428; stale revision 409. Existing active/inactive item permissions remain unchanged.
- Already-authorized stock request/issue, purchase request/PO, equipment movement and retirement history rows also expose current category metadata, including for inactive items. This does not broaden list permissions or create historical category snapshots.
- Existing inventory/item/requestable/quotable catalog reads return category IDs, parent IDs, names and `category_label`; existing inventory edits return category_version. Labels represent current classification. `item_type`, equipment grouping and stock rules remain authoritative for their original purposes.
- `?category=ID` includes direct/child assignments; `?category=uncategorized` includes null assignments. Supported by inventory list, forecast item selector, stock requestable GET, public vendor quotable GET and inventory report. Existing eligible-item rules still apply.
- Inventory CSV/print reports add **Current category**. Historical classification is not stored or inferred. Finance expense categories are separate.
- Existing origin/CSRF/session checks apply to all new writes. Department/public clients receive metadata only through their existing permitted catalog endpoints. Category/assignment mutations have required transactional audits. Category changes do not create stock movements or usage records.
- Apply `sql/migration_inventory_categories.sql` only through the approved rollout after verification/acceptance. Until then category mutation returns 503 and normal item workflows remain usable. Full verification status: `PHASE4_REVIEW.md`.



## Phase 6B authenticator authentication (isolated review)

Existing auth.php remains authoritative. POST username/password returns either status=ok (explicit pre-enrollment preparation only) or status=challenge with challenge.purpose and csrf_token. Enrolled/MFA-required users never receive a full session from password alone. Subsequent auth.php POST actions require X-CSRF-Token: verify with code or recovery_code; cancel to consume the challenge and destroy pending state; enroll_begin with username, password and a user-bound authorization to begin setup. enroll_begin returns secret/otpauth URI once; confirmation returns ten recovery codes once and requires fresh login. GET returns authenticated=false for pending users, never elevated roles.

GET mfa.php exposes own enrollment status; Super Admin additionally sees readiness metadata. POST requires current authenticated account/CSRF, current password and recent MFA (or fresh code): grant for a verified named employee with user_id, purpose initial/reset and reason; replace for oneself; recovery_codes to regenerate and revoke old sessions/material. Password reset never removes MFA. Deactivation revokes all factors/codes/challenges/grants; reactivation requires authorized re-enrollment.

MFA globals: MFA_ENCRYPTION_KEY is a separately backed-up random 32-byte Base64 key outside the web root; MFA_SCHEMA_REQUIRED=1 is mandatory at enforced rollout; MFA_ALLOW_LOCAL_HTTP=1 is loopback-development-only (default HTTPS); MFA_ISSUER identifies the authenticator entry; MFA_CHALLENGES_PER_USER/MFA_CHALLENGES_PER_IP limit credential-success starts in five minutes (10/60 defaults). Five failed verification attempts are additionally limited across challenges for 15 minutes.

The additive SQL migrations must be applied in order: migration_account_security.sql, migration_authenticator_mfa.sql. auth_policy.enforce_mfa defaults to zero for coordinated preparation; do not enable it until readiness is verified and rollout approved. Never import schema.sql into an existing database. Composer runtime vendor must be installed from composer.lock outside the public PHP root; security screens/QR scripts are local. No secrets belong in URLs, logs, source control, external QR services, or browser local storage. Operator bootstrap is CLI-only, requires an existing named active Super Admin and verified handover, and closes once an enrolled administrator exists or enforcement starts.

## Phase 6 HTTP hosting compatibility (isolated review)

MFA_ALLOW_HTTP=0 remains the default. For HTTP deployment set both MFA_ALLOW_HTTP=1 and MFA_HTTP_ORIGIN=http://your-exact-host.example (include non-default port). The origin has no path, query, credentials, wildcard or list; it is compared with the normalized request origin derived from Host. This is configuration scoping, not encryption or authorization. HTTPS remains accepted independently, and existing loopback-only MFA_ALLOW_LOCAL_HTTP is unchanged.

GET auth.php?transport=1 returns {"transport_allowed":true} with no-store if allowed, or 403 otherwise. No database access, user data, key or configured origin is disclosed. The UI disables controls until this succeeds. Existing server guards, CSRF, MFA, sessions and roles remain authoritative. HTTP compatibility does not enable enforcement, create users or authorize deployment. Preparation and manual testing: PHASE6_HTTP_REVIEW.md.

## Phase 6C named-account readiness (isolated)

users.php accepts only named for new account kinds or classification changes. Historical unclassified/shared values remain readable; updates can omit account_kind to preserve history. Deactivation does not rewrite identity. Reactivation of a legacy non-named account requires verified named classification; replace formerly shared identities rather than reassigning their historical activity. Department role and scope remain unchanged. No automatic account migration is performed.

Offline scripts/export_first_admin.php generates a private SQL-import bundle using existing grant/audit tables. Host requires database import and stored-routine permissions but no CLI. Import uses ordered locks, current-version binding, first-admin/preparation guards, expiry, duplicate detection, required audit and rollback. It creates no account/session or public bootstrap endpoint. PHASE6C_REVIEW.md contains prerequisites, handover and remaining deployment gates.
