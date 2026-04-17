#!/usr/bin/env python3
"""
Find OSM rows in alpine_huts_full.csv that are duplicates of rows already
carrying a reservation_id, and copy the reservation_id across.

Row types:
  "osm"     – has hut_id, no reservation_id   (3 820 rows)
  "merged"  – has hut_id AND reservation_id   (302 rows, already complete)
  "scraped" – has reservation_id, no hut_id   (42 rows, came from scraper)

We look for OSM rows that are duplicates of either a merged or scraped row.
When a high-confidence match is found:
  • The reservation_id is copied to the OSM row.
  • The scraped / duplicate row is removed (if it was a scraped-only row with
    no hut_id – it becomes redundant).  Merged rows are never removed.

High-confidence criteria (auto-merged):
  • distance < 10 m  AND  same_name_stem, OR
  • distance < 500 m AND  ≥ 2 matching signals

Everything else within 2 km with ≥ 1 signal goes to review_matches.csv.
"""

import csv
import math
import re

INPUT_CSV  = "alpine_huts_full.csv"
REVIEW_CSV = "review_matches.csv"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def haversine(lat1, lon1, lat2, lon2):
    R = 6371
    dlat = math.radians(lat2 - lat1)
    dlon = math.radians(lon2 - lon1)
    a = (math.sin(dlat / 2) ** 2
         + math.cos(math.radians(lat1)) * math.cos(math.radians(lat2))
         * math.sin(dlon / 2) ** 2)
    return R * 2 * math.asin(math.sqrt(a))


def name_stem(name):
    s = name.lower()
    for tok in (" sac", " aacz", " cas", " dav", " oav", " alpenverein",
                " utoe", " cai"):
        s = s.replace(tok, "")
    return s.strip()


def norm_phone(p):
    return re.sub(r"[\s\-\(\)\/]", "", p)


def norm_url(u):
    u = u.lower().rstrip("/")
    u = re.sub(r"^https?://", "", u)
    u = re.sub(r"^www\.", "", u)
    return u


def match_signals(r_osm, r_donor):
    found = []
    stem_o = name_stem(r_osm["official_name"])
    stem_d = name_stem(r_donor["official_name"])
    if stem_o and stem_d and stem_o == stem_d:
        found.append("same_name_stem")

    # phone: compare both phone columns of the donor (it has phone_number)
    ph_o = norm_phone(r_osm.get("phone_number", ""))
    ph_d = norm_phone(r_donor.get("phone_number", ""))
    if ph_o and ph_d and ph_o == ph_d:
        found.append("same_phone")

    web_o = norm_url(r_osm.get("official_website_url", ""))
    web_d = norm_url(r_donor.get("official_website_url", ""))
    if web_o and web_d and web_o == web_d:
        found.append("same_web")

    return found


def is_high_confidence(dist_km, sigs):
    if dist_km < 0.01 and "same_name_stem" in sigs:
        return True                          # <10 m + same name stem
    if dist_km < 0.5 and len(sigs) >= 2:
        return True                          # <500 m + two signals
    if dist_km == float("inf") and len(sigs) >= 2:
        return True                          # bad coords but strong signals
    return False


def safe_coords(row):
    """Return (lat, lon) floats or None if missing / obviously out of range.
    The Alps span roughly lat 43–49, lon 5–17.  We use wider bounds to allow
    for edge cases (Saxon Switzerland etc.) but reject clear typos like lon=78.
    """
    try:
        lat = float(row["latitude"])
        lon = float(row["longitude"])
    except (ValueError, KeyError):
        return None
    if not (40 <= lat <= 52) or not (3 <= lon <= 20):
        return None
    return lat, lon


# ---------------------------------------------------------------------------
# Load
# ---------------------------------------------------------------------------

with open(INPUT_CSV, newline="", encoding="utf-8") as f:
    reader = csv.DictReader(f)
    fieldnames = reader.fieldnames
    rows = list(reader)

osm_rows     = [(i, r) for i, r in enumerate(rows)
                if r["hut_id"].strip() and not r["reservation_id"].strip()]
merged_rows  = [(i, r) for i, r in enumerate(rows)
                if r["hut_id"].strip() and r["reservation_id"].strip()]
scraped_rows = [(i, r) for i, r in enumerate(rows)
                if r["reservation_id"].strip() and not r["hut_id"].strip()]

donor_rows = merged_rows + scraped_rows   # both can donate a reservation_id

print(f"Rows total:    {len(rows)}")
print(f"OSM (hut_id, no res_id):       {len(osm_rows)}")
print(f"Merged (both IDs, complete):   {len(merged_rows)}")
print(f"Scraped (res_id only):         {len(scraped_rows)}")

# ---------------------------------------------------------------------------
# Find candidate pairs: each OSM row paired with its best donor within 2 km.
# Scraped donors with bad/missing coordinates are matched on signals only
# (no distance check) — auto-merged only if they have ≥ 2 signals.
# ---------------------------------------------------------------------------

INF = float("inf")
candidates = []   # (dist_km, osm_idx, donor_idx, sigs, donor_is_scraped)

# Donors with invalid coordinates (signals-only matching)
no_coord_scraped = [(i, r) for i, r in scraped_rows if safe_coords(r) is None]

for o_idx, r_o in osm_rows:
    coords_o = safe_coords(r_o)
    if coords_o is None:
        continue
    lat_o, lon_o = coords_o

    best = None

    # Normal distance-based matching against all donors with valid coords
    for d_idx, r_d in donor_rows:
        coords_d = safe_coords(r_d)
        if coords_d is None:
            continue
        lat_d, lon_d = coords_d
        dist = haversine(lat_o, lon_o, lat_d, lon_d)
        if dist > 2.0:
            continue
        sigs = match_signals(r_o, r_d)
        if not sigs:
            continue
        if best is None or dist < best[0]:
            is_scraped = not r_d["hut_id"].strip()
            best = (dist, o_idx, d_idx, sigs, is_scraped)

    # Signal-only matching for scraped donors with bad coordinates
    for d_idx, r_d in no_coord_scraped:
        sigs = match_signals(r_o, r_d)
        if not sigs:
            continue
        # Use INF as distance so these sort after all coord-based matches
        if best is None or (len(sigs) >= 2 and best[0] == INF):
            best = (INF, o_idx, d_idx, sigs, True)

    if best is not None:
        candidates.append(best)

candidates.sort()
print(f"\nCandidate pairs (≤ 2 km, ≥ 1 signal): {len(candidates)}")

# ---------------------------------------------------------------------------
# Split high-confidence (auto-merge) vs review
# One OSM row can only be matched once; one scraped donor can only be used once
# (merged donors can be reused — they stay in the CSV either way)
# ---------------------------------------------------------------------------

used_osm     = set()
used_scraped = set()   # only scraped donors are tracked for exclusion

auto_merges  = []
review_pairs = []

for dist, o_idx, d_idx, sigs, is_scraped in candidates:
    already_used = (o_idx in used_osm) or (is_scraped and d_idx in used_scraped)
    hc = is_high_confidence(dist, sigs)

    if hc and not already_used:
        auto_merges.append((dist, o_idx, d_idx, sigs, is_scraped))
        used_osm.add(o_idx)
        if is_scraped:
            used_scraped.add(d_idx)
    else:
        review_pairs.append((dist, o_idx, d_idx, sigs, is_scraped))

print(f"Auto-merge (high confidence): {len(auto_merges)}")
print(f"For manual review:            {len(review_pairs)}")

# ---------------------------------------------------------------------------
# Apply auto-merges
# ---------------------------------------------------------------------------

scraped_indices_to_remove = set()

for dist, o_idx, d_idx, sigs, is_scraped in auto_merges:
    r_o = rows[o_idx]
    r_d = rows[d_idx]
    r_o["reservation_id"] = r_d["reservation_id"]
    if is_scraped:
        scraped_indices_to_remove.add(d_idx)
    donor_label = "scraped" if is_scraped else "merged"
    dist_str = "? m (bad coords)" if dist == float("inf") else f"{dist*1000:.0f} m"
    print(f"  MERGED  [{r_o['official_name']}]  ← res_id={r_d['reservation_id']}"
          f"  (from [{r_d['official_name']}], {dist_str}, "
          f"signals={sigs}, donor={donor_label})")

rows_out = [r for i, r in enumerate(rows) if i not in scraped_indices_to_remove]

with open(INPUT_CSV, "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    writer.writerows(rows_out)

print(f"\nSaved {INPUT_CSV}: {len(rows_out)} rows "
      f"(removed {len(scraped_indices_to_remove)} scraped duplicates)")

# ---------------------------------------------------------------------------
# Write review CSV
# ---------------------------------------------------------------------------

review_fieldnames = [
    "confidence", "distance_m", "signals", "donor_type",
    "osm_name",     "osm_row",     "osm_lat",     "osm_lon",
    "osm_phone",    "osm_website",
    "donor_name",   "donor_row",   "donor_res_id",
    "donor_lat",    "donor_lon",
    "donor_phone",  "donor_website",
    "action",   # reviewer fills: MERGE / SKIP / DELETE_DONOR
]

with open(REVIEW_CSV, "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=review_fieldnames)
    writer.writeheader()
    for dist, o_idx, d_idx, sigs, is_scraped in review_pairs:
        r_o = rows[o_idx]
        r_d = rows[d_idx]
        writer.writerow({
            "confidence":   "high" if is_high_confidence(dist, sigs) else "low",
            "distance_m":   "?" if dist == float("inf") else f"{dist*1000:.0f}",
            "signals":      "|".join(sigs),
            "donor_type":   "scraped" if is_scraped else "merged",
            "osm_name":     r_o["official_name"],
            "osm_row":      o_idx,
            "osm_lat":      r_o["latitude"],
            "osm_lon":      r_o["longitude"],
            "osm_phone":    r_o["phone_number"],
            "osm_website":  r_o["official_website_url"],
            "donor_name":   r_d["official_name"],
            "donor_row":    d_idx,
            "donor_res_id": r_d["reservation_id"],
            "donor_lat":    r_d["latitude"],
            "donor_lon":    r_d["longitude"],
            "donor_phone":  r_d["phone_number"],
            "donor_website":r_d["official_website_url"],
            "action":       "",
        })

print(f"Wrote {REVIEW_CSV}: {len(review_pairs)} pairs for manual review")
