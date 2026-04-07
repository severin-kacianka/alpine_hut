"""
Alpine Hut Distance Filter
Geocodes a location and creates filtered CSVs of huts within 10/25/50/100 km.

Usage:
    python filter_huts.py <location> <huts.csv>

    e.g.  python filter_huts.py "Innsbruck" data/alpine_huts_full.csv
          python filter_huts.py "8001" data/alpine_huts_full.csv
          python filter_huts.py "Chamonix, France" data/alpine_huts_full.csv

Output:
    Creates a directory named after the location (spaces→underscores) and writes:
        <location>_10km.csv   huts within  10 km
        <location>_25km.csv   huts within  25 km
        <location>_50km.csv   huts within  50 km
        <location>_100km.csv  huts within 100 km
"""

import argparse
import math
import os
import re
import sys

import pandas as pd
import requests

NOMINATIM_URL   = "https://nominatim.openstreetmap.org/search"
NOMINATIM_AGENT = "alpine-hut-filter/1.0"
RADII_KM        = [10, 25, 50, 100]


# ── Geocoding ─────────────────────────────────────────────────────────────────

def geocode(location: str) -> tuple[float, float, str]:
    """Return (lat, lon, display_name) for a location string via Nominatim."""
    params = {"q": location, "format": "json", "limit": 1}
    headers = {"User-Agent": NOMINATIM_AGENT}
    try:
        resp = requests.get(NOMINATIM_URL, params=params, headers=headers, timeout=10)
        resp.raise_for_status()
        results = resp.json()
    except requests.RequestException as exc:
        sys.exit(f"Geocoding request failed: {exc}")

    if not results:
        sys.exit(f"No results found for location: {location!r}")

    r = results[0]
    return float(r["lat"]), float(r["lon"]), r["display_name"]


# ── Distance ──────────────────────────────────────────────────────────────────

def haversine_km(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Great-circle distance in km between two points."""
    R = 6371.0
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    dphi  = math.radians(lat2 - lat1)
    dlambda = math.radians(lon2 - lon1)
    a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return R * 2 * math.asin(math.sqrt(a))


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description="Filter alpine huts by distance from a location."
    )
    parser.add_argument("location", help="Place name or postcode (e.g. 'Innsbruck' or '6020')")
    parser.add_argument("csv", help="Path to huts CSV (e.g. data/alpine_huts_full.csv)")
    args = parser.parse_args()

    # Geocode
    lat, lon, display_name = geocode(args.location)
    print(f"Location: {display_name}")
    print(f"Coords:   {lat:.5f}, {lon:.5f}")

    # Load huts
    df = pd.read_csv(args.csv)
    df = df[df["latitude"].notna() & df["longitude"].notna()].copy()
    print(f"Loaded {len(df)} huts from {args.csv}")

    # Compute distances
    df["distance_km"] = df.apply(
        lambda row: haversine_km(lat, lon, row["latitude"], row["longitude"]),
        axis=1,
    )
    df.sort_values("distance_km", inplace=True)

    # Output directory named after the location
    safe_name = re.sub(r"[^\w]+", "_", args.location).strip("_").lower()
    out_dir = os.path.join(os.path.dirname(args.csv) or ".", safe_name)
    os.makedirs(out_dir, exist_ok=True)

    # Write one CSV per radius
    for radius in RADII_KM:
        subset = df[df["distance_km"] <= radius]
        out_path = os.path.join(out_dir, f"{safe_name}_{radius}km.csv")
        subset.to_csv(out_path, index=False)
        print(f"  {radius:>4} km: {len(subset):>4} huts → {out_path}")


if __name__ == "__main__":
    main()
