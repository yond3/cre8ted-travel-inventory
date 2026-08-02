"""
Wayfarer Travel & Tours — AI Demand Forecasting backend
--------------------------------------------------------
Serves Prophet-generated forecasts for office supply items over a small
JSON API. Historical usage now lives in a local SQLite database
(inventory.db, created automatically next to this file) instead of being
hardcoded in Python — so feeding Prophet new data is just an API call,
never a code edit.

On first run, the database is seeded with 24 months of synthetic history
(the same demo data from before) so the prototype still works out of the
box. From then on, add each new month's real usage with:

    POST /api/items/<item_key>/usage   { "month": "2026-07-01", "usage": 5 }

or, if you'd rather let the server compute usage from stock counts instead
of supplying it directly:

    POST /api/items/<item_key>/close-month
    { "month": "2026-07-01", "opening_qty": 8, "received_qty": 2, "closing_qty": 5 }

Run it:
    pip install -r requirements.txt
    python app.py
Then open the prototype HTML in a browser — it calls http://localhost:5050
"""

import warnings
warnings.filterwarnings("ignore")

import sqlite3
from pathlib import Path

from flask import Flask, jsonify, request
from flask_cors import CORS
import pandas as pd
from prophet import Prophet

app = Flask(__name__)
CORS(app)  # allow the HTML prototype (opened as a local file) to call this API

DB_PATH = Path(__file__).parent / "inventory.db"

# ---------------------------------------------------------------------------
# Known peak vs. low travel season, by calendar month (1 = peak, 0 = low).
# Rough Philippines travel pattern: Dec/Jan holidays and Mar-May summer +
# Holy Week are peak; rainy season and shoulder months are lower. This is
# domain knowledge from the client's business, not something learned from
# the office-supply data itself — fed in as a Prophet regressor rather than
# waiting for years of history to statistically rediscover it.
# ---------------------------------------------------------------------------
PEAK_SEASON_BY_MONTH = {
    1: 1, 2: 0, 3: 1, 4: 1, 5: 1, 6: 0,
    7: 0, 8: 0, 9: 0, 10: 0, 11: 0, 12: 1,
}

# Seed data used only the very first time the database is created — after
# that, everything comes from inventory.db and this constant is never
# touched again.
_SEED_MONTHS = [d.strftime("%Y-%m-%d") for d in pd.date_range("2024-07-01", periods=24, freq="MS")]
_SEED_ITEMS = {
    "bondpaper": {
        "label": "Bond paper", "unit": "reams", "current": 8, "min": 4,
        "values": [4, 5, 4, 3, 5, 7, 6, 5, 7, 7, 7, 5, 4, 4, 4, 5, 5, 7, 7, 5, 7, 7, 8, 6],
    },
    "printerink": {
        "label": "Printer ink", "unit": "units", "current": 2, "min": 3,
        "values": [2, 2, 2, 1, 3, 4, 3, 3, 4, 3, 4, 2, 2, 2, 3, 2, 3, 3, 4, 2, 4, 4, 4, 3],
    },
    "ballpointpens": {
        "label": "Ballpoint pens", "unit": "pcs", "current": 25, "min": 10,
        "values": [8, 10, 10, 7, 8, 10, 12, 8, 12, 9, 13, 8, 9, 8, 8, 9, 10, 11, 11, 10, 10, 10, 12, 8],
    },
    "businesscards": {
        "label": "Business cards", "unit": "pcs", "current": 100, "min": 50,
        "values": [15, 17, 17, 17, 19, 19, 20, 12, 21, 24, 21, 17, 11, 16, 14, 20, 18, 24, 22, 17, 23, 21, 21, 16],
    },
    "cleaningsupplies": {
        "label": "Cleaning supplies", "unit": "sets", "current": 5, "min": 3,
        "values": [4, 1, 4, 3, 4, 4, 5, 3, 3, 4, 4, 3, 3, 4, 4, 3, 3, 2, 5, 3, 4, 4, 5, 4],
    },
}


# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------
def get_db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    """Create tables on first run, and seed them with demo history so the
    prototype keeps working immediately — but only if the database doesn't
    already exist, so real data added later is never overwritten."""
    is_new = not DB_PATH.exists()
    conn = get_db()
    conn.execute("""CREATE TABLE IF NOT EXISTS items (
        item_key TEXT PRIMARY KEY, label TEXT, unit TEXT,
        current_qty REAL, min_qty REAL)""")
    conn.execute("""CREATE TABLE IF NOT EXISTS usage_log (
        item_key TEXT, month TEXT, usage REAL,
        PRIMARY KEY (item_key, month))""")

    if is_new:
        for key, item in _SEED_ITEMS.items():
            conn.execute(
                "INSERT INTO items (item_key, label, unit, current_qty, min_qty) VALUES (?,?,?,?,?)",
                (key, item["label"], item["unit"], item["current"], item["min"]),
            )
            for month, usage in zip(_SEED_MONTHS, item["values"]):
                conn.execute(
                    "INSERT INTO usage_log (item_key, month, usage) VALUES (?,?,?)",
                    (key, month, usage),
                )
        conn.commit()
        print(f"Created {DB_PATH.name} and seeded {len(_SEED_ITEMS)} items with 24 months of demo history.")
    conn.close()


def get_item(item_key):
    conn = get_db()
    row = conn.execute("SELECT * FROM items WHERE item_key = ?", (item_key,)).fetchone()
    conn.close()
    return dict(row) if row else None


def get_usage_history(item_key):
    """Returns (months, values) ordered oldest to newest, straight from
    usage_log — this is what actually feeds Prophet now."""
    conn = get_db()
    rows = conn.execute(
        "SELECT month, usage FROM usage_log WHERE item_key = ? ORDER BY month ASC", (item_key,)
    ).fetchall()
    conn.close()
    return [r["month"] for r in rows], [r["usage"] for r in rows]


# ---------------------------------------------------------------------------
# Forecasting
# ---------------------------------------------------------------------------
def run_prophet(months, values, periods=1):
    """Fit Prophet on monthly counts and forecast `periods` months ahead.

    Native yearly_seasonality is switched off even with 24+ months of
    history (2 years is Prophet's own recommended minimum). Tested both
    ways on this data: native seasonality produced sensible magnitudes at
    that length, but its confidence interval was suspiciously near-zero
    width, a sign it's still overfitting at the bare minimum. The known
    peak/low travel season calendar is fed in as a regressor instead —
    same seasonal effect, but better-calibrated uncertainty and easier to
    explain, since it reflects business knowledge rather than a fitted
    curve. It'll only get more reliable as more real history accumulates."""
    df = pd.DataFrame({"ds": pd.to_datetime(months), "y": values})
    df["peak_season"] = df["ds"].dt.month.map(PEAK_SEASON_BY_MONTH)

    model = Prophet(yearly_seasonality=False, weekly_seasonality=False, daily_seasonality=False)
    model.add_regressor("peak_season")
    model.fit(df)

    future = model.make_future_dataframe(periods=periods, freq="MS")
    future["peak_season"] = future["ds"].dt.month.map(PEAK_SEASON_BY_MONTH)
    return model.predict(future)


def recommend_action(current, min_stock, predicted):
    """Simple, explainable reorder rule sitting on top of the Prophet output —
    this is deliberately not a black box for the client or the panel."""
    if current <= min_stock:
        return "Reorder now", "high"
    if predicted and predicted > 0:
        days_of_stock = round((current / predicted) * 30)
        if days_of_stock <= 14:
            return f"Reorder within {days_of_stock} days", "medium"
    return "No action needed yet", "low"


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------
@app.route("/api/forecast/<item_key>")
def get_forecast(item_key):
    item = get_item(item_key)
    if not item:
        return jsonify({"error": f"unknown item '{item_key}'"}), 404

    months, values = get_usage_history(item_key)
    if len(values) < 2:
        return jsonify({"error": "not enough usage history yet (need at least 2 months)"}), 400

    forecast = run_prophet(months, values)
    last = forecast.iloc[-1]
    predicted = round(float(last["yhat"]), 1)
    lower = round(float(last["yhat_lower"]), 1)
    upper = round(float(last["yhat_upper"]), 1)

    action, urgency = recommend_action(item["current_qty"], item["min_qty"], predicted)
    month_labels = [pd.to_datetime(d).strftime("%b '%y") for d in forecast["ds"][:-1]]
    month_labels.append(pd.to_datetime(forecast["ds"].iloc[-1]).strftime("%b '%y") + " (predicted)")

    return jsonify({
        "item": item_key,
        "label": item["label"],
        "unit": item["unit"],
        "current": item["current_qty"],
        "min": item["min_qty"],
        "months": month_labels,
        "actual": values,
        "predicted": predicted,
        "interval_lower": lower,
        "interval_upper": upper,
        "action": action,
        "urgency": urgency,
        "data_points_used": len(values),
    })


@app.route("/api/items")
def list_items():
    """See everything currently on record, without opening the database file."""
    conn = get_db()
    rows = conn.execute("SELECT * FROM items").fetchall()
    result = []
    for r in rows:
        months, values = get_usage_history(r["item_key"])
        result.append({**dict(r), "months_of_history": len(values)})
    conn.close()
    return jsonify(result)


@app.route("/api/items/<item_key>/usage", methods=["GET", "POST"])
def item_usage(item_key):
    if not get_item(item_key):
        return jsonify({"error": f"unknown item '{item_key}'"}), 404

    if request.method == "GET":
        months, values = get_usage_history(item_key)
        return jsonify([{"month": m, "usage": v} for m, v in zip(months, values)])

    # POST — add or overwrite one month's usage number directly.
    body = request.get_json(force=True)
    month = body.get("month")
    usage = body.get("usage")
    if month is None or usage is None:
        return jsonify({"error": "body must include 'month' (YYYY-MM-DD) and 'usage' (number)"}), 400

    conn = get_db()
    conn.execute(
        "INSERT INTO usage_log (item_key, month, usage) VALUES (?,?,?) "
        "ON CONFLICT(item_key, month) DO UPDATE SET usage = excluded.usage",
        (item_key, month, usage),
    )
    conn.commit()
    conn.close()
    return jsonify({"status": "ok", "item": item_key, "month": month, "usage": usage})


@app.route("/api/items/<item_key>/close-month", methods=["POST"])
def close_month(item_key):
    """Alternative to /usage — instead of supplying the usage number
    directly, supply opening/received/closing stock counts and let the
    server compute usage = opening + received - closing. This mirrors how
    a real monthly close would work off the Inventory and Purchase Order
    tables, without needing a person to manually count 'how much did we
    use' themselves."""
    if not get_item(item_key):
        return jsonify({"error": f"unknown item '{item_key}'"}), 404

    body = request.get_json(force=True)
    month = body.get("month")
    opening = body.get("opening_qty")
    received = body.get("received_qty")
    closing = body.get("closing_qty")
    if None in (month, opening, received, closing):
        return jsonify({"error": "body must include month, opening_qty, received_qty, closing_qty"}), 400

    usage = max(0, opening + received - closing)

    conn = get_db()
    conn.execute(
        "INSERT INTO usage_log (item_key, month, usage) VALUES (?,?,?) "
        "ON CONFLICT(item_key, month) DO UPDATE SET usage = excluded.usage",
        (item_key, month, usage),
    )
    # the closing count becomes the new current stock on hand
    conn.execute("UPDATE items SET current_qty = ? WHERE item_key = ?", (closing, item_key))
    conn.commit()
    conn.close()
    return jsonify({"status": "ok", "item": item_key, "month": month, "computed_usage": usage, "new_current_qty": closing})


@app.route("/api/items/<item_key>/stock", methods=["POST"])
def update_stock(item_key):
    """Update current stock / minimum threshold directly, without touching code."""
    if not get_item(item_key):
        return jsonify({"error": f"unknown item '{item_key}'"}), 404
    body = request.get_json(force=True)
    conn = get_db()
    if "current" in body:
        conn.execute("UPDATE items SET current_qty = ? WHERE item_key = ?", (body["current"], item_key))
    if "min" in body:
        conn.execute("UPDATE items SET min_qty = ? WHERE item_key = ?", (body["min"], item_key))
    conn.commit()
    conn.close()
    return jsonify({"status": "ok", "item": item_key, **body})


@app.route("/api/health")
def health():
    return jsonify({"status": "ok"})


init_db()

if __name__ == "__main__":
    app.run(debug=True, port=5050)