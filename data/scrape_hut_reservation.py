#!/usr/bin/env python3
"""
Scrape hut metadata from hut-reservation.org.

Usage:
    .venv/bin/python data/scrape_hut_reservation.py

Prerequisites (one-time):
    .venv/bin/pip install playwright
    .venv/bin/python -m playwright install chromium

Resume behaviour:
    Re-running will skip IDs that already have valid data in the CSV.
    Only NOT_FOUND, ERROR rows, and missing IDs are re-fetched.
    A timestamped backup of the existing CSV is created before each run.
"""

import csv
import os
import re
import shutil
import sys
import time
from datetime import datetime

try:
    from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout
except ModuleNotFoundError:
    sys.exit(
        "playwright is not installed. Run:\n"
        "  .venv/bin/pip install playwright\n"
        "  .venv/bin/python -m playwright install chromium"
    )

BASE_URL         = "https://www.hut-reservation.org/reservation/book-hut/{}/wizard"
OUTPUT_CSV       = "data/hut_reservation_scraped.csv"
ID_START         = 1      # used only for CSV range / existing-data loading
ID_FETCH_FROM    = 440    # first ID to actually fetch this run
ID_END           = 770
PAGE_TIMEOUT_OK  = 15_000  # ms — wait for h1.hutTitle
NAV_TIMEOUT      = 30_000  # ms — page navigation timeout
DELAY_SEC        = 2.0     # pause between pages

FIELDNAMES = ["id", "name", "warden", "phone", "beds",
              "elevation_m", "latitude", "longitude", "website_url"]


def load_existing(path: str) -> dict:
    """Return {id: row} for ALL rows already in the CSV (good and NOT_FOUND)."""
    if not os.path.exists(path):
        return {}
    rows = {}
    with open(path, newline="", encoding="utf-8") as f:
        for row in csv.DictReader(f):
            rows[int(row["id"])] = row
    return rows


def backup(path: str):
    if not os.path.exists(path):
        return
    ts  = datetime.now().strftime("%Y%m%d_%H%M%S")
    dst = path.replace(".csv", f"_{ts}.bak.csv")
    shutil.copy2(path, dst)
    print(f"Backed up existing data to {dst}", flush=True)


def _after_label(text: str, *labels: str) -> str:
    """Return the first non-empty line after the first matching label."""
    for label in labels:
        idx = text.find(label)
        if idx == -1:
            continue
        for line in text[idx + len(label):].splitlines():
            val = line.strip()
            if val:
                return val
    return ""


def scrape_hut(page, hut_id: int) -> dict:
    row = {k: "" for k in FIELDNAMES}
    row["id"] = hut_id

    try:
        page.goto(BASE_URL.format(hut_id), timeout=NAV_TIMEOUT)
        page.wait_for_selector("h1.hutTitle", timeout=PAGE_TIMEOUT_OK)
    except (PlaywrightTimeout, Exception):
        row["name"] = "NOT_FOUND"
        return row

    text = page.locator("body").inner_text()

    # Name is in <h1 class="hutTitle">
    try:
        row["name"] = page.locator("h1.hutTitle").first.inner_text(timeout=2_000).strip()
    except Exception:
        pass

    # Labels vary by language — EN / DE / FR / IT
    row["warden"] = _after_label(text,
        "Hut warden(s):",            # EN
        "Hüttenwarte:",              # DE
        "Gardien/ne de la cabane:",  # FR
        "Direttore(i) del Rifugio:", # IT
    )
    row["phone"] = _after_label(text,
        "Hut phone number:",         # EN
        "Hüttentelefonnummer:",      # DE
        "Téléphone de la cabane:",   # FR
        "Telefono del Rifugio:",     # IT
    )
    row["beds"] = _after_label(text,
        "Total sleeping places:",    # EN
        "Schlafplätze total:",       # DE
        "Nombre de couchettes:",     # FR
        "Totale Posti Letto:",       # IT
    )

    # "Altitude meters:" before "Altitude:" to avoid partial match
    elevation_raw = _after_label(text,
        "Altitude meters:",          # EN
        "Höhe ü. Meer:",             # DE
        "Altitude:",                 # FR
        "Metri di altitudine:",      # IT
    )
    row["elevation_m"] = re.sub(r"\s*m\.?$", "", elevation_raw).strip()

    # Coordinates: EN/FR use ", ", DE/IT use "/"
    coords = _after_label(text,
        "Coordinates:",              # EN
        "Koordinaten:",              # DE
        "Coordonnées:",              # FR
        "Coordinate:",               # IT
    )
    parts = re.split(r"[,/]", coords)
    if len(parts) == 2:
        row["latitude"]  = parts[0].strip()
        row["longitude"] = parts[1].strip()

    # Website URL — link text differs by language
    try:
        a = page.locator("a[href]", has_text=re.compile(
            r"Website|Webseite|Site internet|Sito web", re.IGNORECASE
        )).first
        href = a.get_attribute("href", timeout=2_000)
        if href:
            row["website_url"] = href.strip()
    except Exception:
        pass

    return row


def main():
    existing = load_existing(OUTPUT_CSV)
    backup(OUTPUT_CSV)

    # Only fetch IDs from ID_FETCH_FROM onward that aren't already complete
    def is_complete(row):
        name = row.get("name", "")
        lat  = row.get("latitude", "")
        return bool(name and lat and not name.startswith("NOT_FOUND") and not name.startswith("ERROR"))

    todo = [i for i in range(ID_FETCH_FROM, ID_END + 1) if not is_complete(existing.get(i, {}))]
    print(f"{len(existing)} rows in existing CSV, fetching {len(todo)} IDs ({ID_FETCH_FROM}–{ID_END}).", flush=True)

    all_rows = dict(existing)  # preserve 1–439 data unchanged

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        page    = browser.new_page()
        page.set_extra_http_headers({"User-Agent": "alpine-hut-scraper/1.0"})

        for i, hut_id in enumerate(todo, 1):
            row = scrape_hut(page, hut_id)
            all_rows[hut_id] = row
            label = row["name"] or "(no name extracted)"
            print(f"[{i:3d}/{len(todo)}  id={hut_id}] {label}", flush=True)

            # Write full merged CSV after every row (crash-safe)
            with open(OUTPUT_CSV, "w", newline="", encoding="utf-8") as f:
                writer = csv.DictWriter(f, fieldnames=FIELDNAMES)
                writer.writeheader()
                for rid in range(ID_START, ID_END + 1):
                    if rid in all_rows:
                        writer.writerow(all_rows[rid])

            time.sleep(DELAY_SEC)

        browser.close()

    print(f"\nDone. {OUTPUT_CSV} contains {len(all_rows)} rows.", flush=True)


if __name__ == "__main__":
    main()
