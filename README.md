# Cre8ted Travel — Inventory & Procurement System

Office inventory, purchase requests/orders, supplier directory, tour discount vouchers, and AI demand forecasting for Cre8ted Travel.

**Stack:** PHP (API + UI) · MySQL · Python (Prophet forecast microservice)

### Quick start (group handoff)

```powershell
git clone https://github.com/yond3/cre8ted-travel-inventory.git
cd cre8ted-travel-inventory
git checkout cursor/rbac-procurement-and-po-fixes
Get-Content ".\sql\schema.sql" | mysql -u root -p
cd php
php -S localhost:8000
```

Open `http://localhost:8000/login.html` — demo logins are in [section 6](#6-open-in-browser). Add the Python forecast service (section 4) only if you need the **AI Demand Forecast** page.

---

## What you need installed

| Tool | Purpose |
|------|---------|
| **PHP 8+** | Web app + API (`php/` folder) |
| **MySQL 8+** | Database (MariaDB also works) |
| **Python 3.10+** | Forecast service only |

Windows: [XAMPP](https://www.apachefriends.org/) is fine for PHP. Use its PHP path or add `php` to your PATH. You only need **one** MySQL server running (do not run two MySQL services on the same port).

---

## 1. Clone the repo

Use the **`cursor/rbac-procurement-and-po-fixes`** branch — it has the latest procurement, receipt review, and Finance stub work. `main` is older.

```powershell
git clone https://github.com/yond3/cre8ted-travel-inventory.git
cd cre8ted-travel-inventory
git checkout cursor/rbac-procurement-and-po-fixes
```

---

## 2. Create the database

From the project root (PowerShell):

```powershell
Get-Content ".\sql\schema.sql" | mysql -u root -p
```

This creates `wayfarer_inventory` and loads demo seed data (items, suppliers, sample requests, vouchers, etc.).

If MySQL is not on your PATH, use the full path, for example:

```powershell
Get-Content ".\sql\schema.sql" | & "C:\xampp\mysql\bin\mysql.exe" -u root -p
```

**Already have a database from before?** `schema.sql` is the full current schema (receipts, Finance integration, receipt reject/reupload, vendor applications, etc.). Rebuilding from scratch **drops all data**. To upgrade an existing `wayfarer_inventory` database instead, run each migration below **once**, in order, skipping any you already applied:

```powershell
Get-Content ".\sql\migration_vendor_applications.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_stock_issues.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_month_closes.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_supplier_active.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_items_locations_active.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_po_receipts.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_po_finance_status.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_po_receipt_waiver.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_po_receipt_rejection.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_po_lost_receipt_report.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_stock_requests.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_items_assigned_department.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_inventory_retirements.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_vendor_contact_details.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_login_attempts.sql" | mysql -u root wayfarer_inventory
Get-Content ".\sql\migration_equipment_groups.sql" | mysql -u root wayfarer_inventory
```

If a migration fails with “duplicate column” or “table already exists”, that file was already applied — skip it and continue.

---

## 3. Configure database login (if needed)

Default settings assume local MySQL with user `root` and **empty password**.

Edit these if your machine is different:

- `php/api/config.php` — `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`
- Forecast service uses the same values via environment variables (optional):

```powershell
$env:DB_HOST = "127.0.0.1"
$env:DB_USER = "root"
$env:DB_PASSWORD = "your_password"
$env:DB_NAME = "wayfarer_inventory"
```

---

## 4. Python forecast service (optional but needed for AI Forecast page)

Requires **Python 3.10+**. If `pip install prophet` fails (common on very new Python such as 3.14), use **3.11 or 3.12** for the venv instead.

```powershell
python -m venv venv
.\venv\Scripts\Activate.ps1
pip install -r requirements.txt
python forecast_service.py
```

Leave this terminal open. The service runs on `http://127.0.0.1:5050`.

If script activation is blocked:

```powershell
Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
```

---

## 5. Python OCR service (optional — suggests receipt amount / OR number)

When someone uploads a purchase-order receipt, the form calls this service to
suggest the amount and OR number by reading the image, so staff can just
double-check a suggestion instead of typing everything by hand. It never
blocks the upload — if it's not running, the form just falls back to manual
entry.

This one needs the **Tesseract OCR** binary installed separately (it's not a
pip package — `pytesseract` just calls out to it):

- Windows: install from the [UB-Mannheim Tesseract build](https://github.com/UB-Mannheim/tesseract/wiki) (default install path is picked up automatically)
- macOS: `brew install tesseract`
- Linux: `sudo apt install tesseract-ocr`

Then, in the same venv as the forecast service:

```powershell
.\venv\Scripts\Activate.ps1
pip install -r requirements.txt
python ocr_service.py
```

Leave this terminal open too. The service runs on `http://127.0.0.1:5051`.
Only JPG/PNG/WEBP receipts are scanned automatically — PDFs are always
entered manually.

---

## 6. Start the PHP app

Open a **second** terminal:

```powershell
cd php
php -S localhost:8000
```

If `php` is not recognized:

```powershell
cd php
& "C:\xampp\php\php.exe" -S localhost:8000
```

---

## 7. Open in browser

```
http://localhost:8000/login.html
```

Sign in with one of the demo accounts:

| Username | Password | Role | Can do |
|----------|----------|------|--------|
| `juan` | `staff123` | Staff | View everything, create purchase requests, **request stock from inventory**, use vouchers, issue stock to a department, fulfill department stock requests, upload a purchase order receipt |
| `maria` | `manager123` | Manager | Staff + approve/reject requests, create POs, resend a PO to Financial Management, record lost receipt on an order, **mark orders received**, edit stock, close month, restock vouchers, approve vendor quotes, void a stock issue |
| `admin` | `admin123` | Super Admin | Manager + mark items/suppliers/locations inactive, edit voucher quantities, cancel POs |

The vendor quote form (`vendor-apply.html`) stays **public** — no login required, by design.

---

## Department stock requests

Departments can ask for items from shelf **before** inventory staff hand them out:

1. Any staff member clicks **Request from stock** on Office Storage & Inventory (or **Ask** on a consumable row), picks one of the six official departments, item, and quantity.
2. The request appears on the **Stock requests** tab with status **Pending** — stock is **not** deducted yet.
3. Inventory staff click **Fulfill** — this opens the same **Issue stock** form, pre-filled from the request. Submitting records the checkout in the issue log, reduces stock, and marks the request **Fulfilled**.
4. The requester (or any staff member) can **Cancel** a pending request.

Official departments (used on stock requests and the issue log): Human resource management, Financial management, Fleet & Transportation management, Facilities & Administration management, Tour Operations, Back-office.

API: `GET/POST /api/stock_requests.php`, `PUT /api/stock_requests.php?id=<id> { action: 'fulfill' | 'cancel' }`.

---

## RBAC — how it works and where to swap it later

Authentication is session-based PHP, defined entirely in `php/api/config.php`:

- `AUTH_USERS` — the demo account list (username → password hash, name, role). **This is the only place with hardcoded credentials**, and passwords are stored as `password_hash()` output, never plaintext.
- `authenticate_user()` / `get_session_user()` — look up and read the logged-in user.
- `require_auth()`, `require_role()`, `require_staff_or_above()`, `require_manager_or_above()`, `require_super_admin()` — guards called at the top of each API endpoint.

**When the lead programmer's central/super-admin login is ready:** replace `authenticate_user()` (and how `login_user()`/`get_session_user()` store the user) to read from his auth system instead of the `AUTH_USERS` array — for example, verifying his token/session and mapping his user to `{ username, name, role }`. Every `require_role()` call across the API files keeps working unchanged, since they only depend on that shape.

---

## Login security

Three protections, all in `php/api/config.php` + `php/api/auth.php`:

1. **Password hashing** — `AUTH_USERS` stores bcrypt hashes (`password_hash()`), and `authenticate_user()` checks them with `password_verify()`. Plaintext passwords are never stored or compared. The three demo passwords shown in the table above still work the same way from the login page — only how they're checked server-side changed.
2. **Session timeouts** — enforced by `enforce_session_timeout()`, called on every API request right after the session starts:
   - **Idle timeout: 10 minutes.** No API calls for 10 minutes → next request is treated as logged out.
   - **Absolute timeout: 1 hour.** Session is force-ended 1 hour after login, even if active the whole time.
   - The session ID is also regenerated on every successful login (`session_regenerate_id(true)`), and the session cookie is `HttpOnly` + `SameSite=Lax` (and `Secure` automatically once served over HTTPS).
   - No frontend polling needed — `apiGet`/`apiSend` in `index.html` already redirect to `login.html` on any `401`, which is exactly what an expired session returns on the next request.
3. **Login rate limiting** — every attempt (success or failure) is logged to the `login_attempts` table (`sql/migration_login_attempts.sql`). After **5 failed attempts for the same username, or 15 for the same IP, within 10 minutes**, further attempts are rejected with `429` and a generic message (`"too many failed login attempts — try again in N minutes"`) for **15 minutes** — checked *before* the password is verified, and the same generic `"invalid username or password"` is returned either way so failed logins never reveal whether the username or password was wrong.

---

## Purchase order receipts

Once a purchase order is **Placed** and **funded** (see Financial Management below), it can't be marked **Received** until a receipt is attached:

1. Staff or manager clicks **Upload receipt** on the order — attaches a JPG/PNG/PDF (max 5 MB), the amount on the receipt, and optionally an OR/receipt number and notes.
2. The file is saved under `php/uploads/receipts/` (git-ignored) and served only through `GET /api/receipts.php?po_id=<id>`, which requires login.
3. Once uploaded, the order shows **Waiting for manager to verify & receive** to staff. **Mark received is manager-and-above only** — a manager checks the receipt against the PO before confirming; stock only increases at that step.

**Reject receipt (manager only):** if the receipt is wrong — blurry, wrong amount, wrong supplier, etc. — a manager clicks **Reject receipt** and leaves a required note (min. 10 characters) explaining the problem. This:
- Blocks **Mark received** until a corrected receipt is uploaded.
- Shows staff a **Rejected — view note** state instead of the normal receipt view, with the manager's note and a **Reupload receipt** button.
- Lets staff upload a replacement the same way as the first upload; the new file replaces the old one on disk, clears the rejection, and re-sends the expense to Financial Management. The order then goes back to **Waiting for manager to verify & receive**.

A receipt can only be replaced this way — a second upload is rejected by the API unless the current receipt was rejected first. This keeps the loop explicit: **upload → manager review → reject with note (if needed) → reupload → receive**, instead of a silent, unaudited replace.

**Lost receipt (manager only):** if proof of purchase truly cannot be attached, a manager can click **Lost receipt** on a funded order, enter the actual amount spent and a note (min. 10 characters). This is forwarded to Financial Management instead of a file, and **Mark received** becomes available the same way. Staff cannot use this — only managers and above.

**Report lost receipt (staff):** if staff lost the receipt, they click **Report lost receipt** on a funded order, enter the amount spent and an explanation (min. 10 characters). This sends a report to the manager — it does **not** go to Finance or unlock **Mark received** until approved. The manager clicks **Review lost receipt**, then either:
- **Approve** — manager writes the official **Note for Finance** (staff explanation is shown for context only, pre-filled as a starting point they can edit). Same outcome as manager **Lost receipt** once approved.
- **Reject report** — staff see the manager's note and can upload a receipt if they find one, or submit a new report.

If staff find the receipt while a report is still pending, they can still **Upload receipt** — the file upload clears the pending report.

**Why manager-only receive:** upload and receive used to both be staff actions, so a wrong receipt upload followed immediately by Mark received could lock in bad Finance data and inventory with no second check. Requiring a manager to receive adds a verification step between "proof attached" and "stock/Finance finalized," without blocking staff from doing the legwork of buying and uploading. Reject-with-note turns that check into an actual feedback loop instead of a silent rubber stamp.

---

## Financial Management integration

Every purchase order now flows through a budget-disbursement / expense-recording state machine (`purchase_orders.finance_status`), separate from the procurement `status` column:

```
not_sent → pending_disbursement → funded → (receipt uploaded) → expense_pending → expense_recorded
                    ↘ disbursement_rejected
```

**The flow:**

1. **PO created** → SCIM automatically sends a disbursement request to Finance (`finance_send_disbursement()` in `php/api/finance_client.php`). The order can't have a receipt uploaded until Finance funds it.
2. **Finance funds it** → `finance_status` becomes `funded`. **Upload receipt** now appears (this is a hard gate — see `receipts.php`).
3. **Receipt uploaded** → SCIM automatically forwards it to Finance as an expense/AP record (`finance_send_expense()`). `finance_status` moves to `expense_pending`, then `expense_recorded` once Finance confirms.
4. If Finance rejects the disbursement, a manager sees a **Resend to Finance** button on the order (`PUT purchase_orders.php?id=<id> { action: 'resend_finance' }`).

Every outbound call and inbound response is written to `finance_integration_log` (payload, HTTP status, response body) for audit/debugging.

### Stub mode (default) — no real Finance API needed

`FINANCE_MODE` in `php/api/config.php` is `'stub'` by default: disbursement requests auto-approve and expense submissions auto-record **immediately, in-process** — no network call is made, but everything is still logged to `finance_integration_log` exactly as it would be for a real call. This means the whole PO → funded → receipt → expense-recorded flow is demoable end-to-end today, with no dependency on the Finance team's endpoints being ready.

### Testing without a real Finance API

Two layers, from easiest to most realistic:

1. **Stub mode (already on)** — just use the app. Create a PO and watch `finance_status` go straight to `funded`; upload a receipt and watch it go to `expense_recorded`. Check `finance_integration_log` in the database to see every "call" that was made.
2. **Contract check** — `GET /api/finance_integration.php` returns the full integration contract: exact outbound URLs/payloads SCIM will send, and the inbound webhook shape Finance needs to call back with. Share this endpoint's response with the Finance team so they can build against the real shape before URLs are finalized.

### Going live

Once Finance shares real (or staging) URLs, flip these in `php/api/config.php` — no other code changes needed:

```php
const FINANCE_MODE = 'live';
const FINANCE_API_BASE_URL = 'https://finance.example.com/api'; // real base URL
const FINANCE_API_KEY = '...';                                  // real API key
const FINANCE_WEBHOOK_SECRET = '...';                            // shared secret, given to Finance
const APP_BASE_URL = 'https://scim.example.com';                 // SCIM's public URL, for receipt links
```

In live mode, SCIM POSTs to `{FINANCE_API_BASE_URL}/disbursement-requests` and `/expenses`, and Finance calls back `POST /api/finance_integration.php` (header `X-Finance-Secret`) with `disbursement.approved`, `disbursement.rejected`, or `expense.recorded` events to move `finance_status` forward. See `GET /api/finance_integration.php` for exact payload shapes.

A Finance-side hiccup (network error, timeout) never blocks creating a PO or uploading a receipt — it's logged and a manager can retry via **Resend to Finance**.

---

## Daily run checklist

You need **3 things** running:

1. **MySQL** — database service
2. **PHP server** — `php -S localhost:8000` inside `php/`
3. **Forecast service** — `python forecast_service.py` (only for the AI Forecast page)

Inventory, requests, orders, vouchers, and documents work without the forecast service.

---

## Project layout

```
php/                    Main app (index.html + api/)
sql/                    schema.sql + small migration scripts
archive/                Old Flask/SQLite prototype (not used)
forecast_service.py     Prophet forecast microservice
requirements.txt        Python dependencies
venv/                   Created locally (not in git)
```

**Legacy files** live in `archive/` — old Flask app, SQLite DB, and original HTML prototype. Safe to ignore for day-to-day use.

---

## Troubleshooting

**MySQL won't start in XAMPP**  
Another MySQL may already be using port 3306. Stop the duplicate service or use the system MySQL you already have.

**Forecast page says unavailable**  
Start `forecast_service.py` and confirm `php/api/config.php` points to `http://127.0.0.1:5050`.

**Page refresh returns to Dashboard**  
Use the sidebar links, or open a direct hash URL such as `index.html#inventory`.

**PowerShell and `<` for SQL**  
PowerShell does not support `mysql < file.sql`. Use:

```powershell
Get-Content ".\sql\schema.sql" | mysql -u root -p
```

---

## License

Private / internal use for Cre8ted Travel unless otherwise specified.
