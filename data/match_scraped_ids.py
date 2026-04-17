#!/usr/bin/env python3
"""
Match unassigned reservation IDs from hut_reservation_scraped.csv into
alpine_huts_full.csv.

Only processes scraped entries whose ID is not already present in the full CSV.
For each unmatched scraped entry, looks for a corresponding OSM row in the
full CSV (one without a reservation_id) using distance + signals.

High-confidence (auto-merged): distance < 10 m + same_name_stem, OR
                                distance < 500 m + >= 2 signals, OR
                                bad/missing coords + >= 2 signals
Everything else within 2 km with >= 1 signal goes to match_scraped_review.csv.

Signals: same_name_stem, same_phone, same_web.
"""

import csv
import math
import re

FULL_CSV    = "alpine_huts_full.csv"
SCRAPED_CSV = "hut_reservation_scraped.csv"
REVIEW_CSV  = "match_scraped_review.csv"

# ── helpers ──────────────────────────────────────────────────────────────────

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
                " utoe", " cai", " aacb", " aac"):
        s = s.replace(tok, "")
    return s.strip()


def norm_phone(p):
    return re.sub(r"[\s\-\(\)\/]", "", p)


def norm_url(u):
    u = u.lower().rstrip("/")
    u = re.sub(r"^https?://", "", u)
    u = re.sub(r"^www\.", "", u)
    return u


def safe_coords(lat_s, lon_s):
    """Return (lat, lon) or None if missing / outside Alps bounding box."""
    try:
        lat, lon = float(lat_s), float(lon_s)
    except (ValueError, TypeError):
        return None
    if not (40 <= lat <= 52) or not (3 <= lon <= 20):
        return None
    return lat, lon


def signals(r_full, r_scraped):
    found = []
    stem_f = name_stem(r_full.get("official_name", ""))
    stem_s = name_stem(r_scraped.get("name", ""))
    if stem_f and stem_s and stem_f == stem_s:
        found.append("same_name_stem")

    ph_f = norm_phone(r_full.get("phone_number", ""))
    ph_s = norm_phone(r_scraped.get("phone", ""))
    if ph_f and ph_s and ph_f == ph_s:
        found.append("same_phone")

    web_f = norm_url(r_full.get("official_website_url", ""))
    web_s = norm_url(r_scraped.get("website_url", ""))
    if web_f and web_s and web_f == web_s:
        found.append("same_web")

    return found


def is_high_confidence(dist_km, sigs):
    if dist_km < 0.01 and "same_name_stem" in sigs:
        return True
    if dist_km < 0.5 and len(sigs) >= 2:
        return True
    if dist_km == float("inf") and len(sigs) >= 2:
        return True
    return False


# ── load ─────────────────────────────────────────────────────────────────────

with open(FULL_CSV, newline="", encoding="utf-8") as f:
    reader = csv.DictReader(f)
    fieldnames = reader.fieldnames
    full_rows = list(reader)

with open(SCRAPED_CSV, newline="", encoding="utf-8") as f:
    scraped_rows = list(csv.DictReader(f))

existing_ids = {r["reservation_id"].strip() for r in full_rows if r["reservation_id"].strip()}

# Scraped entries to process: not NOT_FOUND and ID not already in full CSV
to_match = [r for r in scraped_rows
            if r["name"] != "NOT_FOUND" and r["id"].strip() not in existing_ids]

# OSM rows in full CSV that have no reservation_id yet
candidates_full = [(i, r) for i, r in enumerate(full_rows)
                   if not r["reservation_id"].strip()]

print(f"Full CSV rows:              {len(full_rows)}")
print(f"Scraped entries total:      {len(scraped_rows)}")
print(f"Already matched IDs:        {len(existing_ids)}")
print(f"Scraped entries to match:   {len(to_match)}")
print(f"Full CSV rows without res_id: {len(candidates_full)}")

# ── match each scraped entry against full CSV rows ────────────────────────────

INF = float("inf")
pairs = []   # (dist, full_idx, scraped_row, sigs)

for r_s in to_match:
    coords_s = safe_coords(r_s.get("latitude"), r_s.get("longitude"))
    best = None

    for f_idx, r_f in candidates_full:
        coords_f = safe_coords(r_f.get("latitude"), r_f.get("longitude"))
        sigs = signals(r_f, r_s)
        if not sigs:
            continue

        if coords_s and coords_f:
            dist = haversine(coords_f[0], coords_f[1], coords_s[0], coords_s[1])
            if dist > 2.0:
                continue
        else:
            dist = INF   # can't compute distance; rely on signals alone

        if best is None or dist < best[0]:
            best = (dist, f_idx, r_s, sigs)

    if best is not None:
        pairs.append(best)

pairs.sort(key=lambda x: x[0])
print(f"\nCandidate pairs found: {len(pairs)}")

# ── split high-confidence vs review (each full row matched at most once) ──────

used_full    = set()
used_scraped = set()
auto_merges  = []
review_pairs = []

for dist, f_idx, r_s, sigs in pairs:
    hc = is_high_confidence(dist, sigs)
    if hc and f_idx not in used_full and r_s["id"] not in used_scraped:
        auto_merges.append((dist, f_idx, r_s, sigs))
        used_full.add(f_idx)
        used_scraped.add(r_s["id"])
    else:
        review_pairs.append((dist, f_idx, r_s, sigs))

print(f"Auto-merge (high confidence): {len(auto_merges)}")
print(f"For manual review:            {len(review_pairs)}")

# ── apply auto-merges ─────────────────────────────────────────────────────────

for dist, f_idx, r_s, sigs in auto_merges:
    full_rows[f_idx]["reservation_id"] = r_s["id"]
    dist_str = "? (bad coords)" if dist == INF else f"{dist*1000:.0f} m"
    print(f"  MERGED [{full_rows[f_idx]['official_name']}] ← id={r_s['id']}"
          f"  ({dist_str}, signals={sigs})")

with open(FULL_CSV, "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    writer.writerows(full_rows)

print(f"\nSaved {FULL_CSV} ({len(full_rows)} rows)")

# ── write review CSV ──────────────────────────────────────────────────────────

review_fieldnames = [
    "confidence", "distance_m", "signals",
    "full_name",    "full_idx",  "full_lat",  "full_lon",
    "full_phone",   "full_website",
    "scraped_name", "scraped_id",
    "scraped_lat",  "scraped_lon",
    "scraped_phone","scraped_website",
    "action",
]

with open(REVIEW_CSV, "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=review_fieldnames)
    writer.writeheader()
    for dist, f_idx, r_s, sigs in review_pairs:
        r_f = full_rows[f_idx]
        writer.writerow({
            "confidence":    "high" if is_high_confidence(dist, sigs) else "low",
            "distance_m":    "?" if dist == INF else f"{dist*1000:.0f}",
            "signals":       "|".join(sigs),
            "full_name":     r_f["official_name"],
            "full_idx":      f_idx,
            "full_lat":      r_f["latitude"],
            "full_lon":      r_f["longitude"],
            "full_phone":    r_f["phone_number"],
            "full_website":  r_f["official_website_url"],
            "scraped_name":  r_s["name"],
            "scraped_id":    r_s["id"],
            "scraped_lat":   r_s["latitude"],
            "scraped_lon":   r_s["longitude"],
            "scraped_phone": r_s["phone"],
            "scraped_website": r_s["website_url"],
            "action":        "",
        })

print(f"Wrote {REVIEW_CSV} ({len(review_pairs)} pairs for manual review)")
