"""
Cre8ted Travel — Receipt OCR microservice
---------------------------------------------------
Second Python sidecar next to forecast_service.py, same "PHP calls a local
Python service over HTTP" pattern. This one reads an uploaded receipt image,
runs Tesseract OCR on it, and tries to pull out the total amount and the
official-receipt/invoice number so the purchase-order receipt form can
autofill them. The user always sees the raw fields and can correct them
before submitting — this is a suggestion, not an authority.

    PHP (receipt upload form) -> POST /api/ocr/receipt -> this service
                                                        -> Tesseract (local)

Not meant to be exposed to the internet: bind to 127.0.0.1 and let PHP
(api/ocr_receipt.php) proxy requests to it, same as the forecast service.

Requires the Tesseract OCR binary to be installed separately (it's not a
pip package — pytesseract just calls out to it):
    Windows: https://github.com/UB-Mannheim/tesseract/wiki
    macOS:   brew install tesseract
    Linux:   apt install tesseract-ocr

Run it:
    venv\\Scripts\\activate
    pip install -r requirements.txt
    python ocr_service.py
"""

import os
import re

from flask import Flask, jsonify, request
from flask_cors import CORS
from PIL import Image, ImageOps
import pytesseract

app = Flask(__name__)
CORS(app)

# Only needed on Windows, where the Tesseract binary usually isn't on PATH.
# Set TESSERACT_CMD if it was installed somewhere else.
_default_win_path = r"C:\Program Files\Tesseract-OCR\tesseract.exe"
tesseract_cmd = os.environ.get("TESSERACT_CMD") or (
    _default_win_path if os.path.isfile(_default_win_path) else None
)
if tesseract_cmd:
    pytesseract.pytesseract.tesseract_cmd = tesseract_cmd

MAX_UPLOAD_BYTES = 5 * 1024 * 1024
ALLOWED_CONTENT_TYPES = {"image/jpeg", "image/png", "image/webp"}

# ---------------------------------------------------------------------------
# Text parsing — receipts are messy OCR text, so these are best-effort
# heuristics tuned for PH retail/office-supply receipts, not a guarantee.
# ---------------------------------------------------------------------------
AMOUNT_LINE_KEYWORDS = [
    "grand total", "total amount due", "amount due", "total due",
    "total sales", "total amount", "total",
]

AMOUNT_TOKEN_RE = re.compile(r"(?<![\d.])(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})|\d+\.\d{2})(?![\d])")

OR_NUMBER_LINE_RE = re.compile(
    r"(?:official\s*receipt|o\.?\s*r\.?|receipt|invoice|s\.?\s*i\.?|sales\s*invoice)\s*"
    r"(?:no\.?|number|#)?\s*[:\-#]?\s*([A-Z0-9][A-Z0-9\-]{3,20})",
    re.IGNORECASE,
)


def _parse_amount_token(token: str):
    cleaned = token.replace(",", "")
    try:
        return round(float(cleaned), 2)
    except ValueError:
        return None


def extract_amount(text: str):
    lines = [l.strip() for l in text.splitlines() if l.strip()]
    lower_lines = [l.lower() for l in lines]

    # Pass 1: look for a keyword line (grand total, amount due, ...), in
    # priority order, and pull the first money-looking token from it (or
    # the next line, since totals are often split across two OCR lines).
    for keyword in AMOUNT_LINE_KEYWORDS:
        for i, lower in enumerate(lower_lines):
            if keyword in lower:
                for candidate_line in (lines[i], lines[i + 1] if i + 1 < len(lines) else ""):
                    tokens = AMOUNT_TOKEN_RE.findall(candidate_line)
                    if tokens:
                        amt = _parse_amount_token(tokens[-1])
                        if amt is not None and amt > 0:
                            return amt

    # Pass 2: no labeled total found — fall back to the largest money-looking
    # number anywhere on the receipt (usually the total, since line items
    # are smaller than the sum of them).
    all_tokens = AMOUNT_TOKEN_RE.findall(text)
    amounts = [a for a in (_parse_amount_token(t) for t in all_tokens) if a and a > 0]
    return max(amounts) if amounts else None


def extract_receipt_number(text: str):
    match = OR_NUMBER_LINE_RE.search(text)
    if match:
        return match.group(1).strip().upper()
    return None


def preprocess_image(img: Image.Image) -> Image.Image:
    """Light preprocessing to help Tesseract on phone-photo receipts —
    grayscale + autocontrast, and upscale small images. Deliberately not
    using OpenCV to keep this service's dependency footprint small."""
    img = img.convert("L")
    img = ImageOps.autocontrast(img)
    if img.width < 1200:
        scale = 1200 / img.width
        img = img.resize((int(img.width * scale), int(img.height * scale)), Image.LANCZOS)
    return img


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------
@app.route("/api/ocr/receipt", methods=["POST"])
def ocr_receipt():
    file = request.files.get("receipt")
    if not file or file.filename == "":
        return jsonify({"ok": False, "error": "no file uploaded"}), 400

    content = file.read()
    if len(content) > MAX_UPLOAD_BYTES:
        return jsonify({"ok": False, "error": "file too large"}), 400

    try:
        img = Image.open(__import__("io").BytesIO(content))
        img.load()
    except Exception:
        return jsonify({
            "ok": False,
            "error": "could not read this as an image — PDF receipts aren't scanned automatically yet, enter the details manually",
        }), 200

    try:
        processed = preprocess_image(img)
        raw_text = pytesseract.image_to_string(processed)
    except pytesseract.TesseractNotFoundError:
        return jsonify({
            "ok": False,
            "error": "OCR engine (Tesseract) is not installed on the server — enter the amount and OR number manually",
        }), 200
    except Exception as e:
        return jsonify({"ok": False, "error": f"OCR failed: {e}"}), 200

    amount = extract_amount(raw_text)
    receipt_number = extract_receipt_number(raw_text)

    return jsonify({
        "ok": True,
        "amount": amount,
        "receipt_number": receipt_number,
        "raw_text": raw_text.strip()[:2000],
    })


@app.route("/api/health")
def health():
    try:
        version = str(pytesseract.get_tesseract_version())
        tesseract_ok = True
    except Exception:
        version = None
        tesseract_ok = False
    return jsonify({"status": "ok", "tesseract": "available" if tesseract_ok else "not found", "version": version})


if __name__ == "__main__":
    # 127.0.0.1 only — PHP (api/ocr_receipt.php) is the public-facing proxy.
    app.run(debug=True, port=5051, host="127.0.0.1")
