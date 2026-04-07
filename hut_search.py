"""
Alpine Hut Search
Find huts near a location and rank them by 5-day weather forecast.

Usage:
    python hut_search.py <location> <distance_km> [--source CSV]

    e.g.  python hut_search.py "Innsbruck" 50
          python hut_search.py "Chamonix, France" 25
          python hut_search.py "8001" 100 --source data/alpine_huts_full.csv

Output:
    Pretty-printed ranked table on stdout.
    CSV saved to results/<location>_<distance>km_weather.csv

Tune DAY_WEIGHTS to emphasize specific days, e.g.:
    [1, 1, 1, 1, 1]  — equal weight across all 5 days
    [1, 2, 3, 4, 5]  — weight later days more heavily
    [5, 4, 3, 2, 1]  — weight nearer days more heavily
"""

import argparse
import json
import math
import os
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

import pandas as pd
import requests

# ── Configuration ─────────────────────────────────────────────────────────────
DEFAULT_SOURCE      = "data/alpine_huts_full.csv"
RESULTS_DIR         = "results"
FORECAST_DAYS       = 5
DAY_WEIGHTS         = [1, 1, 1, 1, 1]   # change to e.g. [1,2,3,4,5] to weight future days more
CACHE_MAX_AGE_HOURS = 6
API_BASE_URL        = "https://api.open-meteo.com/v1/forecast"
API_TIMEOUT_SEC     = 30
MAX_WORKERS         = 1
BATCH_SIZE          = 17
BATCH_PAUSE_SEC     = 1.5   # pause between Open-Meteo batch requests to avoid 429s
NOMINATIM_URL       = "https://nominatim.openstreetmap.org/search"
NOMINATIM_AGENT     = "alpine-hut-search/1.0"

SCORE_WEIGHTS = {
    "precipitation": 0.35,
    "wind":          0.25,
    "weathercode":   0.25,
    "temperature":   0.15,
}

DAILY_VARIABLES = [
    "weathercode",
    "temperature_2m_max",
    "temperature_2m_min",
    "precipitation_sum",
    "windspeed_10m_max",
    "precipitation_probability_max",
]

WMO_DESCRIPTIONS = {
    0:  "Clear",        1:  "Mainly clear",   2:  "Partly cloudy",
    3:  "Overcast",     45: "Fog",             48: "Rime fog",
    51: "Lt drizzle",   53: "Drizzle",         55: "Hvy drizzle",
    56: "Frzg drizzle", 57: "Hvy frzg drzl",  61: "Lt rain",
    63: "Rain",         65: "Hvy rain",        66: "Frzg rain",
    67: "Hvy frzg rain",71: "Lt snow",         73: "Snow",
    75: "Hvy snow",     77: "Snow grains",     80: "Lt showers",
    81: "Showers",      82: "Hvy showers",     85: "Snow showers",
    86: "Hvy snow shwrs",95: "Thunderstorm",   96: "Tstorm+hail",
    99: "Tstorm+hvy hail",
}


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
    R = 6371.0
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    dphi    = math.radians(lat2 - lat1)
    dlambda = math.radians(lon2 - lon1)
    a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return R * 2 * math.asin(math.sqrt(a))


def filter_huts(source_csv: str, lat: float, lon: float, radius_km: float) -> list[dict]:
    """Load CSV, filter by radius, return sorted list of HutRecord dicts."""
    df = pd.read_csv(source_csv)
    df = df[df["official_name"].notna() & df["latitude"].notna() & df["longitude"].notna()].copy()

    df["distance_km"] = df.apply(
        lambda row: haversine_km(lat, lon, row["latitude"], row["longitude"]), axis=1
    )
    nearby = df[df["distance_km"] <= radius_km].sort_values("distance_km").reset_index(drop=True)

    records = []
    for _, row in nearby.iterrows():
        elev = row.get("elevation_m")
        records.append({
            "official_name":  str(row["official_name"]),
            "operating_club": str(row["operating_club"]) if pd.notna(row.get("operating_club")) else None,
            "hut_id":         row.get("hut_id"),
            "latitude":       float(row["latitude"]),
            "longitude":      float(row["longitude"]),
            "elevation_m":    float(elev) if pd.notna(elev) else None,
            "distance_km":    round(float(row["distance_km"]), 1),
            "forecast":       None,
            "daily_scores":   [],
            "hut_score":      None,
            "error":          None,
        })
    return records


# ── Weather fetching ──────────────────────────────────────────────────────────

def _is_cache_valid(path: str, max_age_hours: float) -> bool:
    if not os.path.exists(path):
        return False
    return (time.time() - os.path.getmtime(path)) < max_age_hours * 3600


def save_cache(hut_records: list[dict], path: str) -> None:
    slim = [
        {k: h[k] for k in ("hut_id", "official_name", "latitude", "longitude",
                            "elevation_m", "operating_club", "distance_km", "forecast", "error")}
        for h in hut_records
    ]
    with open(path, "w", encoding="utf-8") as f:
        json.dump({"generated_at": pd.Timestamp.now().isoformat(), "huts": slim}, f, indent=2)


def load_cache(path: str) -> list[dict]:
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    return [{**h, "daily_scores": [], "hut_score": None} for h in data["huts"]]


def fetch_batch(hut_chunk: list[dict]) -> list[dict | None]:
    lats = ",".join(str(h["latitude"]) for h in hut_chunk)
    lons = ",".join(str(h["longitude"]) for h in hut_chunk)
    params = {
        "latitude":      lats,
        "longitude":     lons,
        "daily":         ",".join(DAILY_VARIABLES),
        "forecast_days": FORECAST_DAYS,
        "timezone":      "auto",
    }
    if all(h["elevation_m"] is not None for h in hut_chunk):
        params["elevation"] = ",".join(str(h["elevation_m"]) for h in hut_chunk)
    try:
        resp = requests.get(API_BASE_URL, params=params, timeout=API_TIMEOUT_SEC)
        resp.raise_for_status()
        results = resp.json()
        if isinstance(results, dict):
            results = [results]
        return [r.get("daily") for r in results]
    except Exception as exc:
        names = ", ".join(h["official_name"] for h in hut_chunk)
        print(f"  WARNING: batch fetch failed ({names}): {exc}")
        return [None] * len(hut_chunk)


def fetch_all_forecasts(hut_records: list[dict]) -> list[dict]:
    chunks = [hut_records[i:i + BATCH_SIZE] for i in range(0, len(hut_records), BATCH_SIZE)]
    print(f"Fetching forecasts for {len(hut_records)} huts in {len(chunks)} batch(es)...")
    futures_map = {}
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        for idx, chunk in enumerate(chunks):
            futures_map[executor.submit(fetch_batch, chunk)] = idx
            if idx < len(chunks) - 1:
                time.sleep(BATCH_PAUSE_SEC)
        for future in as_completed(futures_map):
            chunk = chunks[futures_map[future]]
            for hut, forecast in zip(chunk, future.result()):
                if forecast is None:
                    hut["error"] = "API fetch failed"
                else:
                    hut["forecast"] = forecast
    return hut_records


def load_or_fetch_forecasts(hut_records: list[dict], cache_path: str) -> list[dict]:
    if _is_cache_valid(cache_path, CACHE_MAX_AGE_HOURS):
        print(f"Using cached forecasts ({cache_path})")
        return load_cache(cache_path)
    hut_records = fetch_all_forecasts(hut_records)
    save_cache(hut_records, cache_path)
    return hut_records


# ── Scoring ───────────────────────────────────────────────────────────────────

def _lerp(x, x0, x1, y0, y1):
    t = max(0.0, min(1.0, (x - x0) / (x1 - x0))) if x1 != x0 else 0.0
    return y0 + t * (y1 - y0)

def score_precipitation(mm):
    if mm is None or (isinstance(mm, float) and math.isnan(mm)): return 50.0
    mm = float(mm)
    if mm <= 0:  return 100.0
    if mm <= 5:  return _lerp(mm, 0, 5, 100, 50)
    if mm <= 20: return _lerp(mm, 5, 20, 50, 0)
    return 0.0

def score_wind(kmh):
    if kmh is None or (isinstance(kmh, float) and math.isnan(kmh)): return 50.0
    kmh = float(kmh)
    if kmh <= 20: return 100.0
    if kmh <= 80: return _lerp(kmh, 20, 80, 100, 0)
    return 0.0

def score_weathercode(code):
    if code is None: return 50.0
    code = int(code)
    if code <= 2:  return 100.0
    if code == 3:  return 80.0
    if code <= 48: return 60.0
    if code <= 57: return 40.0
    if code <= 67: return 20.0
    if code <= 82: return 10.0
    return 5.0

def score_temperature(tmax):
    if tmax is None or (isinstance(tmax, float) and math.isnan(tmax)): return 50.0
    tmax = float(tmax)
    if tmax < 0:   return 0.0
    if tmax < 5:   return _lerp(tmax, 0, 5, 0, 50)
    if tmax <= 20: return _lerp(tmax, 5, 20, 50, 100)
    if tmax <= 25: return 100.0
    if tmax <= 35: return _lerp(tmax, 25, 35, 100, 50)
    return 0.0

def score_day(day_index: int, forecast: dict) -> float:
    def _get(field):
        vals = forecast.get(field)
        if not vals or day_index >= len(vals): return None
        v = vals[day_index]
        return None if (isinstance(v, float) and math.isnan(v)) else v
    return (
        SCORE_WEIGHTS["precipitation"] * score_precipitation(_get("precipitation_sum")) +
        SCORE_WEIGHTS["wind"]          * score_wind(_get("windspeed_10m_max")) +
        SCORE_WEIGHTS["weathercode"]   * score_weathercode(_get("weathercode")) +
        SCORE_WEIGHTS["temperature"]   * score_temperature(_get("temperature_2m_max"))
    )

def score_all_huts(hut_records: list[dict]) -> list[dict]:
    total_w = sum(DAY_WEIGHTS)
    normalized = [w / total_w for w in DAY_WEIGHTS]
    for hut in hut_records:
        if hut["forecast"] is None:
            continue
        daily = [score_day(i, hut["forecast"]) for i in range(FORECAST_DAYS)]
        hut["daily_scores"] = daily
        hut["hut_score"] = sum(s * w for s, w in zip(daily, normalized))
    scored   = sorted([h for h in hut_records if h["hut_score"] is not None],
                      key=lambda h: h["hut_score"], reverse=True)
    unscored = [h for h in hut_records if h["hut_score"] is None]
    return scored + unscored


# ── Output ────────────────────────────────────────────────────────────────────

def _day_summary(day_index: int, forecast: dict) -> str:
    codes = forecast.get("weathercode", [])
    temps = forecast.get("temperature_2m_max", [])
    code  = codes[day_index] if day_index < len(codes) else None
    tmax  = temps[day_index] if day_index < len(temps) else None
    label = WMO_DESCRIPTIONS.get(code, f"WMO{code}") if code is not None else "?"
    temp_str = f"{tmax:>3.0f}°" if tmax is not None else "  ?°"
    return f"{label:<14}{temp_str}"


def print_table(hut_records: list[dict], location_name: str, radius_km: float) -> None:
    day_headers = []
    for h in hut_records:
        if h.get("forecast") and h["forecast"].get("time"):
            day_headers = [t[5:] for t in h["forecast"]["time"][:FORECAST_DAYS]]
            break
    while len(day_headers) < FORECAST_DAYS:
        day_headers.append(f"Day{len(day_headers)+1}")

    W_RANK  = 4
    W_NAME  = 30
    W_DIST  = 7
    W_ELEV  = 6
    W_SCORE = 6
    W_DAY   = 18

    total_width = W_RANK + 2 + W_NAME + W_DIST + W_ELEV + W_SCORE + 2 + W_DAY * FORECAST_DAYS + (FORECAST_DAYS - 1) * 2
    sep    = "─" * total_width
    header = (
        f"{'#':>{W_RANK}}  "
        f"{'Hut':<{W_NAME}}"
        f"{'Dist':>{W_DIST}}"
        f"{'Elev':>{W_ELEV}}"
        f"{'Score':>{W_SCORE}}  "
        + "  ".join(f"{d:<{W_DAY}}" for d in day_headers)
    )

    weights_str = str(DAY_WEIGHTS)
    print(f"\nHuts within {radius_km:.0f} km of {location_name}  |  day weights: {weights_str}")
    print(sep)
    print(header)
    print(sep)

    ranked   = [h for h in hut_records if h["hut_score"] is not None]
    unscored = [h for h in hut_records if h["hut_score"] is None]

    for rank, hut in enumerate(ranked, 1):
        name  = hut["official_name"][:W_NAME]
        dist  = f"{hut['distance_km']:.1f}km"
        elev  = f"{hut['elevation_m']:.0f}m" if hut["elevation_m"] is not None else "   ?m"
        score = f"{hut['hut_score']:>5.1f}"
        days  = "  ".join(_day_summary(i, hut["forecast"]) for i in range(FORECAST_DAYS))
        print(f"{rank:>{W_RANK}}  {name:<{W_NAME}}{dist:>{W_DIST}}{elev:>{W_ELEV}}{score:>{W_SCORE}}  {days}")

    for hut in unscored:
        name = hut["official_name"][:W_NAME]
        dist = f"{hut['distance_km']:.1f}km"
        print(f"  --  {name:<{W_NAME}}{dist:>{W_DIST}}  FETCH ERROR: {hut.get('error', 'unknown')}")

    print(sep)
    print(f"  {len(ranked)} huts ranked  |  {len(unscored)} failed")


def save_csv(hut_records: list[dict], out_path: str) -> None:
    """Write results to CSV with rank, weather score, and per-day summaries."""
    rows = []
    rank = 1
    for hut in hut_records:
        row = {
            "rank":          rank if hut["hut_score"] is not None else None,
            "official_name": hut["official_name"],
            "operating_club": hut.get("operating_club"),
            "distance_km":   hut["distance_km"],
            "elevation_m":   hut["elevation_m"],
            "latitude":      hut["latitude"],
            "longitude":     hut["longitude"],
            "weather_score": round(hut["hut_score"], 1) if hut["hut_score"] is not None else None,
        }
        if hut["forecast"] and hut["forecast"].get("time"):
            for i, date in enumerate(hut["forecast"]["time"][:FORECAST_DAYS]):
                prefix = date[5:]  # MM-DD
                fc = hut["forecast"]
                def _val(field):
                    v = fc.get(field, [])
                    return v[i] if i < len(v) else None
                row[f"{prefix}_weathercode"]  = _val("weathercode")
                row[f"{prefix}_temp_max"]     = _val("temperature_2m_max")
                row[f"{prefix}_precip_mm"]    = _val("precipitation_sum")
                row[f"{prefix}_wind_kmh"]     = _val("windspeed_10m_max")
                row[f"{prefix}_day_score"]    = round(hut["daily_scores"][i], 1) if i < len(hut.get("daily_scores", [])) else None
        else:
            row["error"] = hut.get("error", "fetch failed")
        rows.append(row)
        if hut["hut_score"] is not None:
            rank += 1

    pd.DataFrame(rows).to_csv(out_path, index=False)
    print(f"\nSaved → {out_path}")


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description="Find alpine huts near a location and rank by weather."
    )
    parser.add_argument("location", help="Place name or postcode (e.g. 'Innsbruck')")
    parser.add_argument("distance", type=float, help="Search radius in km (e.g. 50)")
    parser.add_argument(
        "--source", default=DEFAULT_SOURCE,
        help=f"Huts CSV to search (default: {DEFAULT_SOURCE})"
    )
    args = parser.parse_args()

    # Geocode
    lat, lon, display_name = geocode(args.location)
    print(f"Location: {display_name}")
    print(f"Coords:   {lat:.5f}, {lon:.5f}")

    # Filter huts
    huts = filter_huts(args.source, lat, lon, args.distance)
    if not huts:
        sys.exit(f"No huts found within {args.distance} km of {args.location!r}.")
    print(f"Found {len(huts)} huts within {args.distance:.0f} km")

    # Cache path: results/<slug>_<distance>km_cache.json
    os.makedirs(RESULTS_DIR, exist_ok=True)
    slug       = re.sub(r"[^\w]+", "_", args.location).strip("_").lower()
    stem       = f"{slug}_{args.distance:.0f}km"
    cache_path = os.path.join(RESULTS_DIR, f"{stem}_cache.json")
    out_csv    = os.path.join(RESULTS_DIR, f"{stem}_weather.csv")

    # Fetch forecasts
    huts = load_or_fetch_forecasts(huts, cache_path)

    # Score and rank
    huts = score_all_huts(huts)

    # Output
    print_table(huts, display_name.split(",")[0], args.distance)
    save_csv(huts, out_csv)


if __name__ == "__main__":
    main()
