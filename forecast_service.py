"""
Wayfarer Travel & Tours — AI Demand Forecasting microservice
-------------------------------------------------------------
This is the Prophet-only piece of the stack. Everything else (inventory,
purchase requests/orders, supplier directory, document tracking, stock
updates) now lives in PHP talking directly to MySQL — this service's only
job is to read usage history from the same MySQL database and hand back a
Prophet forecast, so the ML stays in Python where the library lives.

    PHP  ->  GET /api/forecast/<item_key>  ->  this service  ->  MySQL (read-only)

Not meant to be exposed to the internet directly: bind to 127.0.0.1 and let
PHP (api/forecast.php) proxy requests to it.

Run it:
    venv\\Scripts\\activate
    pip install -r requirements.txt
    python forecast_service.py
"""

import warnings
warnings.filterwarnings("ignore")

import os

import pymysql
import pymysql.cursors
from flask import Flask, jsonify
from flask_cors import CORS
import pandas as pd
from prophet import Prophet

app = Flask(__name__)
CORS(app)  # only PHP (localhost) is expected to call this, but keep it permissive for local dev

DB_CONFIG = dict(
    host=os.environ.get("DB_HOST", "127.0.0.1"),
    user=os.environ.get("DB_USER", "root"),
    password=os.environ.get("DB_PASSWORD", ""),
    database=os.environ.get("DB_NAME", "wayfarer_inventory"),
    cursorclass=pymysql.cursors.DictCursor,
)

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


def get_db():
    return pymysql.connect(**DB_CONFIG)


def get_item(item_key):
    conn = get_db()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT * FROM items WHERE item_key = %s", (item_key,))
            return cur.fetchone()
    finally:
        conn.close()


def get_usage_history(item_key):
    """Returns (months, values) ordered oldest to newest — this is what
    feeds Prophet. Usage is written by PHP (via /api/usage.php and
    /api/close-month.php); this service only ever reads it."""
    conn = get_db()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT month, usage_qty FROM usage_log WHERE item_key = %s ORDER BY month ASC",
                (item_key,),
            )
            rows = cur.fetchall()
    finally:
        conn.close()
    months = [r["month"].strftime("%Y-%m-%d") for r in rows]
    values = [float(r["usage_qty"]) for r in rows]
    return months, values


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
    if item.get("item_type") == "equipment" or item.get("min_qty") is None:
        return jsonify({
            "error": f"'{item_key}' is equipment, not a consumable — no reorder threshold to forecast against"
        }), 400

    months, values = get_usage_history(item_key)
    if len(values) < 2:
        return jsonify({"error": "not enough usage history yet (need at least 2 months)"}), 400

    forecast = run_prophet(months, values)
    last = forecast.iloc[-1]
    predicted = round(float(last["yhat"]), 1)
    lower = round(float(last["yhat_lower"]), 1)
    upper = round(float(last["yhat_upper"]), 1)

    current_qty = float(item["current_qty"])
    min_qty = float(item["min_qty"])
    action, urgency = recommend_action(current_qty, min_qty, predicted)
    month_labels = [pd.to_datetime(d).strftime("%b '%y") for d in forecast["ds"][:-1]]
    month_labels.append(pd.to_datetime(forecast["ds"].iloc[-1]).strftime("%b '%y") + " (predicted)")

    return jsonify({
        "item": item_key,
        "label": item["label"],
        "unit": item["unit"],
        "current": current_qty,
        "min": min_qty,
        "months": month_labels,
        "actual": values,
        "predicted": predicted,
        "interval_lower": lower,
        "interval_upper": upper,
        "action": action,
        "urgency": urgency,
        "data_points_used": len(values),
    })


@app.route("/api/health")
def health():
    try:
        conn = get_db()
        conn.close()
        db_ok = True
    except Exception as e:
        db_ok = False
    return jsonify({"status": "ok", "database": "connected" if db_ok else "unreachable"})


if __name__ == "__main__":
    # 127.0.0.1 only — PHP (api/forecast.php) is the public-facing proxy.
    app.run(debug=True, port=5050, host="127.0.0.1")
