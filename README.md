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
http://localhost:8000/index.html
```

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
