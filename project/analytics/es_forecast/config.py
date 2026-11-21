"""Settings for the forecasting job (plan decisions D3, D7–D11).

The Supabase URL and key are NOT stored here: they are read from the PHP
app's own config/secrets.php through the PHP CLI, so there is one place
for secrets and nothing sensitive is written into Python files or logs.
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
from pathlib import Path

ANALYTICS_DIR = Path(__file__).resolve().parents[1]
PROJECT_DIR = ANALYTICS_DIR.parent
SECRETS_FILE = PROJECT_DIR / "config" / "secrets.php"
SAMPLE_DIR = ANALYTICS_DIR / "sample_data"

TZ = "Asia/Manila"
SEASON = 7                       # weekly pattern (daily data)
MIN_OBS = {"seasonal_naive": 14, "arima": 56, "sarima": 56, "mlr": 56}
FOLDS = 4                        # rolling-origin folds
FOLD_HORIZON = 7                 # days forecast per fold
HORIZON = 14                     # days forecast after the last observed date
ACTUAL_DAYS = 90                 # actual history stored per run for the charts
INV_MIN_USAGE_DAYS = 5           # items used on fewer days are not modelled
INV_AVG_WINDOW = 28              # fallback average-use window (open days)
MODEL_ORDER = ["seasonal_naive", "mlr", "arima", "sarima"]   # simplest first (tie-break)
METRICS = ("appointments", "sales", "inventory")


def _php_binary() -> str:
    for cand in (os.environ.get("ES_PHP"), shutil.which("php"), r"C:\xampp\php\php.exe"):
        if cand and Path(cand).exists():
            return cand
    raise RuntimeError("PHP CLI not found (set ES_PHP to php.exe)")


def load_credentials() -> tuple[str, str]:
    """(supabase_url, supabase_key) from config/secrets.php via the PHP CLI."""
    code = (
        "$s = require " + json.dumps(str(SECRETS_FILE)) + ";"
        "echo json_encode(['url' => $s['supabase_url'] ?? '', 'key' => $s['supabase_key'] ?? '']);"
    )
    out = subprocess.run([_php_binary(), "-r", code], capture_output=True, text=True, timeout=30)
    try:
        data = json.loads(out.stdout or "{}")
    except json.JSONDecodeError:
        data = {}
    url, key = (data.get("url") or "").rstrip("/"), data.get("key") or ""
    if not url or not key:
        raise RuntimeError("Supabase credentials not found")
    return url, key
