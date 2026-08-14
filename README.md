# Cre8ted Travel — Inventory & Procurement System

Office inventory, purchase requests/orders, supplier directory, tour discount vouchers, and AI demand forecasting for Cre8ted Travel.

**Stack:** PHP (API + UI) · MySQL · Python (Prophet forecast microservice)

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

```powershell
git clone https://github.com/YOUR_USERNAME/YOUR_REPO.git
cd YOUR_REPO
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

**Already have a database from before?** `schema.sql` now includes `stock_issues` (Issue log), but rebuilding from scratch drops all data. To add just the new table to an existing database instead, run:

```powershell
Get-Content ".\sql\migration_stock_issues.sql" | mysql -u root wayfarer_inventory
```

(Also run `migration_month_closes.sql` the same way if you haven't already — Close month needs it too.)

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

## 5. Start the PHP app

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

## 6. Open in browser

```
http://localhost:8000/login.html
```

Sign in with one of the demo accounts:

| Username | Password | Role | Can do |
|----------|----------|------|--------|
| `juan` | `staff123` | Staff | View everything, create purchase requests, use vouchers, issue stock to a department, mark orders received |
| `maria` | `manager123` | Manager | Staff + approve/reject requests, create POs, edit stock, close month, restock vouchers, approve vendor quotes, void a stock issue |
| `admin` | `admin123` | Super Admin | Manager + mark items/suppliers/locations inactive, edit voucher quantities, cancel POs |

The vendor quote form (`vendor-apply.html`) stays **public** — no login required, by design.

---

## RBAC — how it works and where to swap it later

Authentication is session-based PHP, defined entirely in `php/api/config.php`:

- `AUTH_USERS` — the demo account list (username → password, name, role). **This is the only place with hardcoded credentials.**
- `authenticate_user()` / `get_session_user()` — look up and read the logged-in user.
- `require_auth()`, `require_role()`, `require_staff_or_above()`, `require_manager_or_above()`, `require_super_admin()` — guards called at the top of each API endpoint.

**When the lead programmer's central/super-admin login is ready:** replace `authenticate_user()` (and how `login_user()`/`get_session_user()` store the user) to read from his auth system instead of the `AUTH_USERS` array — for example, verifying his token/session and mapping his user to `{ username, name, role }`. Every `require_role()` call across the API files keeps working unchanged, since they only depend on that shape.

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
