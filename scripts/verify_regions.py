"""Smoke-check both region DBs (schema compatibility). No PHP required."""
import sys

try:
    import psycopg2
except ImportError:
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "psycopg2-binary", "-q"])
    import psycopg2

REGIONS = {
    "ci": {
        "label": "科特迪瓦",
        "dsn": (
            "host=pgm-gw8ffg06e16gfgcwho.pgsql.germany.rds.aliyuncs.com "
            "dbname=postgres user=Honsen_Admin password=!66778899HONSEN "
            "connect_timeout=8"
        ),
    },
    "cm": {
        "label": "喀麦隆",
        "dsn": (
            "host=ep-soft-grass-abytsqkh.eu-west-2.aws.neon.tech "
            "dbname=neondb user=neondb_owner password=npg_HAMNDb6U9IzX "
            "sslmode=require connect_timeout=8"
        ),
    },
}

NAME_MAP = {"inventory": "库存", "transactions": "存取记录"}

ok = True
for key, cfg in REGIONS.items():
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
