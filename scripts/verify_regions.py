"""Smoke-check both region DBs. Credentials from env or db_config.php next to repo root."""
import os
import re
import sys
from pathlib import Path

try:
    import psycopg2
except ImportError:
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "psycopg2-binary", "-q"])
    import psycopg2

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / "db_config.php"


def load_regions():
    """Parse simple PHP return-array config without executing PHP."""
    if not CONFIG.is_file():
        print(f"Missing {CONFIG.name}. Copy db_config.example.php → db_config.php")
        sys.exit(1)
    text = CONFIG.read_text(encoding="utf-8")
    regions = {}
    for key in ("ci", "cm"):
        block = re.search(rf"'{key}'\s*=>\s*array\s*\((.*?)\)\s*,", text, re.S)
        if not block:
            continue
        body = block.group(1)
        fields = dict(re.findall(r"'(\w+)'\s*=>\s*'([^']*)'", body))
        if not fields.get("host"):
            continue
        ssl = fields.get("sslmode") or ""
        dsn = (
            f"host={fields['host']} port={fields.get('port', '5432')} "
            f"dbname={fields['dbname']} user={fields['user']} "
            f"password={fields['password']} connect_timeout={fields.get('timeout', '8')}"
        )
        if ssl:
            dsn += f" sslmode={ssl}"
        regions[key] = {"label": fields.get("label", key), "dsn": dsn}
    # Env override (optional)
    for key in list(regions):
        env_dsn = os.environ.get(f"WMS_DSN_{key.upper()}")
        if env_dsn:
            regions[key]["dsn"] = env_dsn
    return regions


NAME_MAP = {"inventory": "库存", "transactions": "存取记录"}

regions = load_regions()
ok = True
for key, cfg in regions.items():
    print(f"=== {key} {cfg['label']} ===")
    try:
        c = psycopg2.connect(cfg["dsn"])
        cur = c.cursor()
        cur.execute(
            "SELECT table_name FROM information_schema.tables "
            "WHERE table_schema='public' AND table_type='BASE TABLE'"
        )
        tables = [r[0] for r in cur.fetchall()]
        mapped = {}
        for actual in tables:
            low = actual.lower()
            if low in NAME_MAP:
                mapped[NAME_MAP[low]] = actual
        print("  mapped:", mapped)
        for display, actual in mapped.items():
            cur.execute(f'SELECT COUNT(*) FROM public."{actual}"')
            print(f"  {display} ({actual}): {cur.fetchone()[0]} rows")
        c.close()
        if "库存" not in mapped or "存取记录" not in mapped:
            print("  FAIL: missing Inventory/transactions mapping")
            ok = False
        else:
            print("  OK")
    except Exception as e:
        print("  FAIL:", e)
        ok = False

sys.exit(0 if ok else 1)
