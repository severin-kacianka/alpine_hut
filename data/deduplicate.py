#!/usr/bin/env python3
"""
Remove duplicate rows in alpine_huts_full.csv caused by the same hut appearing
in both the SAC API and OSM datasets and now sharing a reservation_id.

Strategy for SAC+OSM pairs:
  - Keep the OSM row (better coords, phone, reservation_id)
  - Overwrite its official_name with the SAC row's name (more canonical)
  - Fill empty OSM fields (email, capacity_beds, operating_club) from SAC row
  - Delete the SAC row

Strategy for OSM+OSM pairs:
  - If the two rows have different emails AND different clubs, they are likely
    genuinely different huts sharing a reservation_id — skip with a warning.
  - Otherwise keep the row with more non-empty fields, delete the other.
"""

import csv
from collections import defaultdict

INPUT_CSV = "alpine_huts_full.csv"

# Fields filled from SAC into OSM only when OSM field is empty
FILL_FROM_SAC = ["email", "capacity_beds", "operating_club"]

with open(INPUT_CSV, newline="", encoding="utf-8") as f:
    reader = csv.DictReader(f)
    fieldnames = reader.fieldnames
    rows = list(reader)

# Group indices by reservation_id
by_resid = defaultdict(list)
for i, r in enumerate(rows):
    if r["reservation_id"].strip():
        by_resid[r["reservation_id"]].append(i)

dups = {rid: idxs for rid, idxs in by_resid.items() if len(idxs) > 1}
print(f"Rows total: {len(rows)}")
print(f"Duplicate reservation_id groups: {len(dups)}")

to_delete = set()
merged = 0
skipped = 0

for rid, idxs in sorted(dups.items(), key=lambda x: int(x[0])):
    if len(idxs) != 2:
        print(f"  WARNING res_id={rid}: {len(idxs)} rows (not 2) — skipping")
        skipped += 1
        continue

    ra, rb = rows[idxs[0]], rows[idxs[1]]
    ia, ib = idxs[0], idxs[1]

    is_sac_a = "sac-cas" in ra.get("source_url", "")
    is_sac_b = "sac-cas" in rb.get("source_url", "")

    if is_sac_a and not is_sac_b:
        sac_idx, osm_idx = ia, ib
    elif is_sac_b and not is_sac_a:
        sac_idx, osm_idx = ib, ia
    else:
        # OSM+OSM: check if genuinely different huts
        email_a = ra.get("email", "").strip()
        email_b = rb.get("email", "").strip()
        club_a  = ra.get("operating_club", "").strip()
        club_b  = rb.get("operating_club", "").strip()
        if email_a and email_b and email_a != email_b and club_a and club_b and club_a != club_b:
            print(f"  SKIP    res_id={rid}: OSM+OSM with different emails/clubs "
                  f"([{ra['official_name']}] vs [{rb['official_name']}]) — likely different huts")
            skipped += 1
            continue
        # Keep the more complete row
        score_a = sum(1 for v in ra.values() if v.strip())
        score_b = sum(1 for v in rb.values() if v.strip())
        if score_b > score_a:
            osm_idx, sac_idx = ib, ia   # "sac" here just means "the one to delete"
        else:
            osm_idx, sac_idx = ia, ib
        print(f"  OSM+OSM res_id={rid}: keeping [{rows[osm_idx]['official_name']}], "
              f"removing [{rows[sac_idx]['official_name']}]")
        to_delete.add(sac_idx)
        merged += 1
        continue

    r_sac = rows[sac_idx]
    r_osm = rows[osm_idx]

    # Use SAC's official_name (always)
    old_name = r_osm["official_name"]
    r_osm["official_name"] = r_sac["official_name"]

    # Fill empty fields from SAC
    for field in FILL_FROM_SAC:
        if not r_osm.get(field, "").strip() and r_sac.get(field, "").strip():
            r_osm[field] = r_sac[field]

    name_note = f" (renamed from [{old_name}])" if old_name != r_sac["official_name"] else ""
    print(f"  MERGE   res_id={rid}: keeping OSM [{r_osm['official_name']}]{name_note}, "
          f"removing SAC [{r_sac['official_name']}]")
    to_delete.add(sac_idx)
    merged += 1

rows_out = [r for i, r in enumerate(rows) if i not in to_delete]

with open(INPUT_CSV, "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    writer.writerows(rows_out)

print(f"\nDone. Merged/deduped: {merged}. Skipped: {skipped}. "
      f"Removed {len(to_delete)} rows. Total rows now: {len(rows_out)}.")
