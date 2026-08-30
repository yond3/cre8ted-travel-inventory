# Department API integration

Departments use the **same request flows** as the central app’s “Request from stock” and “Request equipment” buttons — pick catalog items for storage requests, describe new equipment for purchase requests. No full inventory table access.

Central staff use `index.html`. Department accounts use `department-api.html` or their own UI against these endpoints.

---

## Authentication

Session-based JSON API (cookie `PHPSESSID`). Use `credentials: 'include'` in browser fetch.

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| `POST` | `/api/auth.php` | `{ "username", "password" }` | `{ "status": "ok", "user": { username, name, role, department? } }` |
| `GET` | `/api/auth.php` | — | `{ "authenticated": true/false, "user"? }` |
| `DELETE` | `/api/auth.php` | — | `{ "status": "ok" }` |

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
