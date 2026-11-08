#!/usr/bin/env python3
"""
Check hut-reservation.org for data not yet in data/hut_reservation_scraped.csv.

Usage:
    python3 data/check_hut_reservation.py                  # probe NOT_FOUND/ERROR/missing IDs + new IDs
    python3 data/check_hut_reservation.py --all            # also re-check known huts (GONE / CHANGED)
    python3 data/check_hut_reservation.py --all --apply    # write NEW/REVIVED/CHANGED rows into the CSV
    python3 data/check_hut_reservation.py --from 1 --to 900

Uses the JSON API (reservation/hutInfo/<id>) instead of Playwright; stdlib only.
Read-only unless --apply (backs up the CSV first; GONE rows are reported, never modified).
Exit code: 0 = nothing new, 1 = findings, 2 = request errors only.
"""

import argparse
import csv
import json
import os
import re
import shutil
import ssl
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime

API_URL      = "https://www.hut-reservation.org/api/v1/reservation/hutInfo/{}"
DEFAULT_CSV  = os.path.join(os.path.dirname(os.path.abspath(__file__)), "hut_reservation_scraped.csv")
USER_AGENT   = "alpine-hut-search/1.0"
EXTRA_IDS    = 50     # probe this many IDs past the highest known ID
PAUSE_403    = 5.0    # seconds to wait after HTTP 403/429 before one retry
HTTP_TIMEOUT = 20
MAX_ERR_RUN  = 5      # abort after this many consecutive request errors

# Python 3.13+ enables VERIFY_X509_STRICT, which rejects the site's CA chain; keep normal verification
SSL_CTX = ssl.create_default_context()
SSL_CTX.verify_flags &= ~getattr(ssl, "VERIFY_X509_STRICT", 0)


#  manually verified coordinates for IDs whose hut-reservation.org coordinates are wrong (2026-11-08)
COORD_OVERRIDES = {
    23:  ("46.587038", "8.282682"),    # Bächlitalhütte SAC: API 66.4688/15.9878; SAC API + OSM
    405: ("46.922457", "9.142993"),    # Skihütte Elm: API has LV95 2'729'805/1'198'227 as 2.729805/1.198227
}

FIELDNAMES = [ "id", "name", "warden", "phone", "beds",
               "elevation_m", "latitude", "longitude", "website_url" ]
NUMERIC    = { "elevation_m", "latitude", "longitude" }


def load_csv(path: str) -> dict:
    """Return {id: row} for all rows in the CSV."""
    if not os.path.exists(path):
        sys.exit(f"CSV not found: {path}")
    with open(path, newline="", encoding="utf-8") as f:
        return { int(row["id"]): row for row in csv.DictReader(f) }


def is_complete(row: dict) -> bool:
    name = row.get("name", "")
    return bool(name and row.get("latitude") and not name.startswith(("NOT_FOUND", "ERROR")))


def fetch(hut_id: int):
    """Return (status, data): status is "OK", "NOT_FOUND", or "ERROR"; data is dict or error text."""
    req = urllib.request.Request(API_URL.format(hut_id), headers={ "User-Agent": USER_AGENT })
    for attempt in (1, 2):
        try:
            with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT, context=SSL_CTX) as res:
                return "OK", json.loads(res.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            if e.code in (403, 429) and attempt == 1:
                time.sleep(PAUSE_403)
                continue

            # API answers 400 "Hut Id not found" for unknown IDs
            body = e.read().decode("utf-8", "replace")
            if e.code in (400, 404) and "not found" in body.lower():
                return "NOT_FOUND", None
            return "ERROR", f"HTTP {e.code}"
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as e:
            return "ERROR", str(e)
    return "ERROR", "retry exhausted"


def to_row(hut_id: int, info: dict) -> dict:
    """Map a hutInfo response onto the scraped-CSV schema."""
    coords = re.split(r"[,/]", info.get("coordinates") or "")
    lat, lon = (coords[0].strip(), coords[1].strip()) if len(coords) == 2 else ("", "")
    lat, lon = COORD_OVERRIDES.get(hut_id, (lat, lon))
    return {
        "id":          str(hut_id),
        "name":        (info.get("hutName")       or "").strip(),
        "warden":      (info.get("hutWarden")     or "").strip(),
        "phone":       (info.get("phone")         or "").strip(),
        "beds":        str(info.get("totalBedsInfo") or "").strip(),
        "elevation_m": re.sub(r"\s*m\.?$", "", (info.get("altitude") or "").strip()),
        "latitude":    lat,
        "longitude":   lon,
        "website_url": (info.get("hutWebsite")    or "").strip(),
    }


def same(field: str, old: str, new: str) -> bool:
    old, new = (old or "").strip(), (new or "").strip()
    if field in NUMERIC:
        try:
            return abs(float(old) - float(new)) < 1e-4
        except ValueError:
            pass
    return old == new


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--csv",   default=DEFAULT_CSV, help="scraped CSV to compare against")
    ap.add_argument("--from",  dest="id_from", type=int, default=1, help="first ID (default 1)")
    ap.add_argument("--to",    dest="id_to",   type=int, default=None,
                    help=f"last ID (default: highest CSV ID + {EXTRA_IDS})")
    ap.add_argument("--all",   action="store_true", help="also re-check complete huts for GONE/CHANGED")
    ap.add_argument("--delay", type=float, default=1.0, help="seconds between requests (default 1.0)")
    ap.add_argument("--apply", action="store_true", help="write NEW/REVIVED/CHANGED rows into the CSV (with backup)")
    args = ap.parse_args()

    existing = load_csv(args.csv)
    id_to    = args.id_to if args.id_to is not None else max(existing, default=0) + EXTRA_IDS
    todo     = [ i for i in range(args.id_from, id_to + 1)
                 if args.all or not is_complete(existing.get(i, {})) ]
    print(f"{len(existing)} rows in CSV; probing {len(todo)} IDs in {args.id_from}–{id_to}"
          f"{' (incl. known huts)' if args.all else ''}.", flush=True)

    findings = { "NEW": [], "REVIVED": [], "GONE": [], "CHANGED": [] }
    updates  = {}
    errors   = []
    err_run  = 0
    for n, hut_id in enumerate(todo, 1):
        if n > 1:
            time.sleep(args.delay)
        status, data = fetch(hut_id)
        old          = existing.get(hut_id)
        known        = old is not None and is_complete(old)
        if status == "ERROR":
            errors.append((hut_id, data))
            print(f"[{n:4d}/{len(todo)}] id={hut_id:<4d} ERROR {data}", flush=True)
            err_run += 1
            if err_run >= MAX_ERR_RUN:
                print(f"Aborting: {MAX_ERR_RUN} consecutive errors.", flush=True)
                break
            continue
        err_run = 0
        if status == "OK":
            row = to_row(hut_id, data)
            if old is None:
                updates[hut_id] = row
                findings["NEW"].append((hut_id, row["name"]))
                print(f"[{n:4d}/{len(todo)}] id={hut_id:<4d} NEW      {row['name']}", flush=True)
            elif not known:
                updates[hut_id] = row
                findings["REVIVED"].append((hut_id, row["name"]))
                print(f"[{n:4d}/{len(todo)}] id={hut_id:<4d} REVIVED  {row['name']} (was {old['name']})", flush=True)
            else:
                diffs = [ f"{f}: {old.get(f, '')!r} → {row[f]!r}"
                          for f in FIELDNAMES[1:] if not same(f, old.get(f, ""), row[f]) ]
                if diffs:
                    updates[hut_id] = row
                    findings["CHANGED"].append((hut_id, f"{row['name']}: " + "; ".join(diffs)))
                    print(f"[{n:4d}/{len(todo)}] id={hut_id:<4d} CHANGED  {row['name']}", flush=True)
        elif known:
            findings["GONE"].append((hut_id, old["name"]))
            print(f"[{n:4d}/{len(todo)}] id={hut_id:<4d} GONE     {old['name']}", flush=True)

    print("\nSummary")
    for kind, items in findings.items():
        print(f"  {kind:<8} {len(items)}")
        for hut_id, text in items:
            print(f"    {hut_id:>4}  {text}")
    if errors:
        print(f"  ERRORS   {len(errors)}: " + ", ".join(str(i) for i, _ in errors))

    found = any(findings.values())
    if updates and args.apply:
        ts  = datetime.now().strftime("%Y%m%d_%H%M%S")
        bak = args.csv.replace(".csv", f"_{ts}.bak.csv")
        shutil.copy2(args.csv, bak)
        merged = { **existing, **updates }
        tmp    = args.csv + ".tmp"
        with open(tmp, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=FIELDNAMES, extrasaction="ignore")
            writer.writeheader()
            for rid in sorted(merged):
                writer.writerow(merged[rid])
        os.replace(tmp, args.csv)
        print(f"\nApplied {len(updates)} row(s) to {args.csv} (backup: {bak}).")
    elif updates:
        print(f"\nRe-run with --apply to write {len(updates)} NEW/REVIVED/CHANGED row(s) into the CSV.")
    if findings["GONE"]:
        print("GONE rows are left untouched; remove them manually if they stay gone.")
    sys.exit(1 if found else (2 if errors else 0))


if __name__ == "__main__":
    main()
