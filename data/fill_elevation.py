#!/usr/bin/env python3
"""
Fill missing elevation_m values in alpine_huts_full.csv using the
Open-Meteo elevation API. Only NaN/empty rows are updated.
"""
import csv
import time
import requests

INPUT_CSV  = "alpine_huts_full.csv"
BATCH_SIZE = 100
PAUSE_SEC  = 2.0
API_URL    = "https://api.open-meteo.com/v1/elevation"

with open(INPUT_CSV, newline="", encoding="utf-8") as f:
    reader = csv.DictReader(f)
    fieldnames = reader.fieldnames
    rows = list(reader)

missing = [
    (i, row)
    for i, row in enumerate(rows)
    if row["elevation_m"].strip() == "" or row["elevation_m"].strip().lower() == "nan"
]
print(f"Found {len(missing)} rows with missing elevation out of {len(rows)} total.")

filled = 0
errors = 0
total_batches = (len(missing) + BATCH_SIZE - 1) // BATCH_SIZE

for batch_num, batch_start in enumerate(range(0, len(missing), BATCH_SIZE), 1):
    batch = missing[batch_start : batch_start + BATCH_SIZE]
    lats = ",".join(r["latitude"]  for _, r in batch)
    lons = ",".join(r["longitude"] for _, r in batch)
    print(f"Batch {batch_num}/{total_batches} — {len(batch)} huts ...", end=" ", flush=True)
    try:
        resp = requests.get(API_URL, params={"latitude": lats, "longitude": lons}, timeout=30)
        resp.raise_for_status()
        elevations = resp.json().get("elevation", [])
    except requests.exceptions.HTTPError as e:
        if resp.status_code == 429:
            print(f"429 rate-limited — waiting 60s before retry")
            time.sleep(60)
            try:
                resp = requests.get(API_URL, params={"latitude": lats, "longitude": lons}, timeout=30)
                resp.raise_for_status()
                elevations = resp.json().get("elevation", [])
            except Exception as e2:
                print(f"ERROR on retry: {e2} — skipping batch")
                errors += len(batch)
                time.sleep(PAUSE_SEC)
                continue
        else:
            print(f"ERROR: {e} — skipping batch")
            errors += len(batch)
            time.sleep(PAUSE_SEC)
            continue
    except Exception as e:
        print(f"ERROR: {e} — skipping batch")
        errors += len(batch)
        time.sleep(PAUSE_SEC)
        continue
    if len(elevations) != len(batch):
        print(f"WARNING: expected {len(batch)}, got {len(elevations)} — skipping batch")
        errors += len(batch)
        time.sleep(PAUSE_SEC)
        continue
    for (orig_idx, _), elev in zip(batch, elevations):
        rows[orig_idx]["elevation_m"] = str(round(elev, 1))
        filled += 1
    print(f"OK (+{len(batch)} filled)")
    time.sleep(PAUSE_SEC)

with open(INPUT_CSV, "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    writer.writerows(rows)

print(f"\nDone. Filled {filled} rows. Errors: {errors}. Saved {INPUT_CSV}.")
