"""
Alpine Hut Weather Ranker
Fetches 5-day forecasts for a sample of alpine huts and ranks them by weather quality.

Usage:
    python hut_weather.py

Tune DAY_WEIGHTS to emphasize specific days, e.g.:
    [1, 1, 1, 1, 1]  — equal weight across all 5 days
    [1, 2, 3, 4, 5]  — weight later days more heavily
    [5, 4, 3, 2, 1]  — weight nearer days more heavily
"""

import json
import math
import os
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

import pandas as pd
import requests

# ── Configuration ─────────────────────────────────────────────────────────────
SAMPLE_SIZE         = 50
RANDOM_SEED         = 42
FORECAST_DAYS       = 5
DAY_WEIGHTS         = [1, 1, 1, 1, 1]   # change to e.g. [1,2,3,4,5] to weight future days more
CACHE_FILE          = "data/weather_cache.json"
CACHE_MAX_AGE_HOURS = 6
SAMPLE_FILE         = "data/sample_huts.csv"
SOURCE_CSV          = "data/alpine_huts_full.csv"
API_BASE_URL        = "https://api.open-meteo.com/v1/forecast"
API_TIMEOUT_SEC     = 30
MAX_WORKERS         = 3
BATCH_SIZE          = 17   # 50 huts → 3 batches of ~17

# Component weights within a single day's score (must sum to 1.0)
SCORE_WEIGHTS = {
    "precipitation": 0.35,  # rain/snow most directly ruins a hike
    "wind":          0.25,  # critical at alpine elevations
    "weathercode":   0.25,  # captures visibility and severe weather
    "temperature":   0.15,  # alpine hikers dress for cold; less decisive
}

DAILY_VARIABLES = [
    "weathercode",
    "temperature_2m_max",
    "temperature_2m_min",
    "precipitation_sum",
    "windspeed_10m_max",
    "precipitation_probability_max",
]

# WMO weather interpretation codes → short display label
WMO_DESCRIPTIONS = {
    0:  "Clear",
    1:  "Mainly clear",
    2:  "Partly cloudy",
    3:  "Overcast",
    45: "Fog",
    48: "Rime fog",
    51: "Lt drizzle",
    53: "Drizzle",
    55: "Hvy drizzle",
    56: "Frzg drizzle",
    57: "Hvy frzg drzl",
    61: "Lt rain",
    63: "Rain",
    65: "Hvy rain",
    66: "Frzg rain",
    67: "Hvy frzg rain",
    71: "Lt snow",
    73: "Snow",
    75: "Hvy snow",
    77: "Snow grains",
    80: "Lt showers",
    81: "Showers",
    82: "Hvy showers",
    85: "Snow showers",
    86: "Hvy snow shwrs",
    95: "Thunderstorm",
    96: "Tstorm+hail",
    99: "Tstorm+hvy hail",
}


# ── Step 0: Sampling ───────────────────────────────────────────────────────────

def load_and_sample(csv_path: str, n: int, seed: int, output_path: str) -> list[dict]:
    """Load the huts CSV, sample n huts, save sample, return as list of HutRecord dicts."""
    df = pd.read_csv(csv_path)
    df = df[df["official_name"].notna()]
    sample = df.sample(n=n, random_state=seed).reset_index(drop=True)
    sample.to_csv(output_path, index=False)
    print(f"Sampled {len(sample)} huts → {output_path}")

    records = []
    for _, row in sample.iterrows():
        elev = row.get("elevation_m")
        records.append({
            "official_name":  str(row["official_name"]),
            "operating_club": str(row.get("operating_club", "")) if pd.notna(row.get("operating_club")) else None,
            "hut_id":         row.get("hut_id"),
            "latitude":       float(row["latitude"]),
            "longitude":      float(row["longitude"]),
            "elevation_m":    float(elev) if pd.notna(elev) else None,
            "forecast":       None,
            "daily_scores":   [],
            "hut_score":      None,
            "error":          None,
        })
    return records


# ── Step 1: Fetching with cache ────────────────────────────────────────────────

def _is_cache_valid(path: str, max_age_hours: float) -> bool:
    if not os.path.exists(path):
        return False
    age_seconds = time.time() - os.path.getmtime(path)
    return age_seconds < max_age_hours * 3600


def save_cache(hut_records: list[dict], path: str) -> None:
    """Store raw forecast data only (not scores) so DAY_WEIGHTS changes re-score correctly."""
    slim = [
        {
            "hut_id":        h["hut_id"],
            "official_name": h["official_name"],
            "latitude":      h["latitude"],
            "longitude":     h["longitude"],
            "elevation_m":   h["elevation_m"],
            "operating_club": h["operating_club"],
            "forecast":      h["forecast"],
            "error":         h["error"],
        }
        for h in hut_records
    ]
    with open(path, "w", encoding="utf-8") as f:
        json.dump({"generated_at": pd.Timestamp.now().isoformat(), "huts": slim}, f, indent=2)
    print(f"Cached forecasts → {path}")


def load_cache(path: str) -> list[dict]:
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    records = []
    for h in data["huts"]:
        records.append({
            **h,
            "daily_scores": [],
            "hut_score":    None,
        })
    return records


def fetch_batch(hut_chunk: list[dict]) -> list[dict | None]:
    """Fetch forecasts for a batch of huts in a single Open-Meteo request."""
    lats = ",".join(str(h["latitude"]) for h in hut_chunk)
    lons = ",".join(str(h["longitude"]) for h in hut_chunk)

    params = {
        "latitude":     lats,
        "longitude":    lons,
        "daily":        ",".join(DAILY_VARIABLES),
        "forecast_days": FORECAST_DAYS,
        "timezone":     "auto",
    }
    # Only include elevation if all huts in this chunk have it — avoids mixing present/absent
    if all(h["elevation_m"] is not None for h in hut_chunk):
        params["elevation"] = ",".join(str(h["elevation_m"]) for h in hut_chunk)

    try:
        resp = requests.get(API_BASE_URL, params=params, timeout=API_TIMEOUT_SEC)
        resp.raise_for_status()
        results = resp.json()
        # Single-hut response is a dict; batch response is a list
        if isinstance(results, dict):
            results = [results]
        forecasts = []
        for r in results:
            forecasts.append(r.get("daily", None))
        return forecasts
    except Exception as exc:
        names = ", ".join(h["official_name"] for h in hut_chunk)
        print(f"  WARNING: batch fetch failed ({names}): {exc}")
        return [None] * len(hut_chunk)


def fetch_all_forecasts(hut_records: list[dict]) -> list[dict]:
    """Split huts into batches and fetch concurrently."""
    chunks = [hut_records[i:i + BATCH_SIZE] for i in range(0, len(hut_records), BATCH_SIZE)]
    print(f"Fetching forecasts for {len(hut_records)} huts in {len(chunks)} batches...")

    # Map chunk index → future so we can match results back to records
    futures_map = {}
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        for idx, chunk in enumerate(chunks):
            futures_map[executor.submit(fetch_batch, chunk)] = idx

        for future in as_completed(futures_map):
            chunk_idx = futures_map[future]
            chunk = chunks[chunk_idx]
            forecasts = future.result()  # already handles exceptions internally
            for hut, forecast in zip(chunk, forecasts):
                if forecast is None:
                    hut["error"] = "API fetch failed"
                else:
                    hut["forecast"] = forecast

    return hut_records


def load_or_fetch_forecasts(hut_records: list[dict], cache_path: str, max_age_hours: float) -> list[dict]:
    if _is_cache_valid(cache_path, max_age_hours):
        print(f"Using cached forecasts from {cache_path}")
        return load_cache(cache_path)

    hut_records = fetch_all_forecasts(hut_records)
    save_cache(hut_records, cache_path)
    return hut_records


# ── Step 2/3: Scoring ──────────────────────────────────────────────────────────

def _lerp(x: float, x0: float, x1: float, y0: float, y1: float) -> float:
    """Linear interpolation clamped to [min(y0,y1), max(y0,y1)]."""
    if x1 == x0:
        return y0
    t = (x - x0) / (x1 - x0)
    t = max(0.0, min(1.0, t))
    return y0 + t * (y1 - y0)


def score_precipitation(mm) -> float:
    """0mm=100, 5mm=50, ≥20mm=0."""
    if mm is None or (isinstance(mm, float) and math.isnan(mm)):
        return 50.0
    mm = float(mm)
    if mm <= 0:
        return 100.0
    if mm <= 5:
        return _lerp(mm, 0, 5, 100, 50)
    if mm <= 20:
        return _lerp(mm, 5, 20, 50, 0)
    return 0.0


def score_wind(kmh) -> float:
    """≤20 km/h=100, ≥80 km/h=0."""
    if kmh is None or (isinstance(kmh, float) and math.isnan(kmh)):
        return 50.0
    kmh = float(kmh)
    if kmh <= 20:
        return 100.0
    if kmh <= 80:
        return _lerp(kmh, 20, 80, 100, 0)
    return 0.0


def score_weathercode(code) -> float:
    """WMO codes: lower = better weather."""
    if code is None:
        return 50.0
    code = int(code)
    if code <= 2:
        return 100.0
    if code == 3:
        return 80.0
    if code <= 48:   # fog and unassigned codes — conservative
        return 60.0
    if code <= 57:   # drizzle
        return 40.0
    if code <= 67:   # rain, freezing rain
        return 20.0
    if code <= 82:   # snow, showers
        return 10.0
    return 5.0       # thunderstorm, heavy snow showers (83+)


def score_temperature(tmax) -> float:
    """Peak score in 5–25°C range; <0°C and >35°C → 0."""
    if tmax is None or (isinstance(tmax, float) and math.isnan(tmax)):
        return 50.0
    tmax = float(tmax)
    if tmax < 0:
        return 0.0
    if tmax < 5:
        return _lerp(tmax, 0, 5, 0, 50)
    if tmax <= 20:
        return _lerp(tmax, 5, 20, 50, 100)
    if tmax <= 25:
        return 100.0
    if tmax <= 35:
        return _lerp(tmax, 25, 35, 100, 50)
    return 0.0


def score_day(day_index: int, forecast: dict) -> float:
    """Compute a 0–100 score for a single forecast day."""
    def _get(field):
        vals = forecast.get(field)
        if vals is None or day_index >= len(vals):
            return None
        v = vals[day_index]
        return None if (isinstance(v, float) and math.isnan(v)) else v

    prec   = score_precipitation(_get("precipitation_sum"))
    wind   = score_wind(_get("windspeed_10m_max"))
    wcode  = score_weathercode(_get("weathercode"))
    temp   = score_temperature(_get("temperature_2m_max"))

    return (
        SCORE_WEIGHTS["precipitation"] * prec +
        SCORE_WEIGHTS["wind"]          * wind +
        SCORE_WEIGHTS["weathercode"]   * wcode +
        SCORE_WEIGHTS["temperature"]   * temp
    )


def compute_hut_score(hut: dict, day_weights: list[float]) -> float | None:
    if hut["forecast"] is None:
        return None
    daily_scores = [score_day(i, hut["forecast"]) for i in range(FORECAST_DAYS)]
    hut["daily_scores"] = daily_scores

    total_w = sum(day_weights)
    normalized = [w / total_w for w in day_weights]
    return sum(s * w for s, w in zip(daily_scores, normalized))


def score_all_huts(hut_records: list[dict], day_weights: list[float]) -> list[dict]:
    for hut in hut_records:
        hut["hut_score"] = compute_hut_score(hut, day_weights)

    scored   = [h for h in hut_records if h["hut_score"] is not None]
    unscored = [h for h in hut_records if h["hut_score"] is None]
    scored.sort(key=lambda h: h["hut_score"], reverse=True)
    return scored + unscored


# ── Step 4: Output ─────────────────────────────────────────────────────────────

def weather_summary_for_day(day_index: int, forecast: dict) -> str:
    """Return a compact label like 'Clear    12°' for one day."""
    code = None
    tmax = None
    codes = forecast.get("weathercode")
    if codes and day_index < len(codes):
        code = codes[day_index]
    temps = forecast.get("temperature_2m_max")
    if temps and day_index < len(temps):
        tmax = temps[day_index]

    label = WMO_DESCRIPTIONS.get(code, f"WMO{code}") if code is not None else "?"
    temp_str = f"{tmax:>3.0f}°" if tmax is not None else "  ?°"
    return f"{label:<14}{temp_str}"


def print_ranked_table(hut_records: list[dict]) -> None:
    # Build date headers from the first hut that has forecast data
    day_headers = []
    for h in hut_records:
        if h["forecast"] and h["forecast"].get("time"):
            for t in h["forecast"]["time"][:FORECAST_DAYS]:
                day_headers.append(t[5:])  # MM-DD
            break
    while len(day_headers) < FORECAST_DAYS:
        day_headers.append(f"Day{len(day_headers)+1}")

    col_name  = 32
    col_elev  = 6
    col_score = 6
    col_day   = 18  # label(14) + temp(4)

    sep = "-" * (6 + col_name + col_elev + col_score + col_day * FORECAST_DAYS + 4)
    header = (
        f"{'#':>4}  "
        f"{'Hut':<{col_name}}"
        f"{'Elev':>{col_elev}}"
        f"{'Score':>{col_score}}  "
        + "  ".join(f"{d:<{col_day}}" for d in day_headers)
    )
    print()
    print(header)
    print(sep)

    ranked = [h for h in hut_records if h["hut_score"] is not None]
    failed = [h for h in hut_records if h["hut_score"] is None]

    for rank, hut in enumerate(ranked, 1):
        name  = hut["official_name"][:col_name]
        elev  = f"{hut['elevation_m']:.0f}m" if hut["elevation_m"] is not None else "   ?m"
        score = f"{hut['hut_score']:>5.1f}"
        days  = "  ".join(
            weather_summary_for_day(i, hut["forecast"])
            for i in range(FORECAST_DAYS)
        )
        print(f"{rank:>4}  {name:<{col_name}}{elev:>{col_elev}}{score:>{col_score}}  {days}")

    for hut in failed:
        name = hut["official_name"][:col_name]
        print(f"  --  {name:<{col_name}}  FETCH ERROR: {hut.get('error', 'unknown')}")

    print(sep)


def print_summary_stats(hut_records: list[dict]) -> None:
    scored = [h for h in hut_records if h["hut_score"] is not None]
    failed = [h for h in hut_records if h["hut_score"] is None]

    print(f"\nResults: {len(scored)} huts scored, {len(failed)} failed")
    if scored:
        best  = scored[0]
        worst = scored[-1]
        print(f"  Best:  {best['official_name']}  ({best['hut_score']:.1f})")
        print(f"  Worst: {worst['official_name']}  ({worst['hut_score']:.1f})")
    weights_str = " ".join(str(w) for w in DAY_WEIGHTS)
    print(f"  Day weights used: [{weights_str}]  (edit DAY_WEIGHTS at top of script to change)")


# ── Main ───────────────────────────────────────────────────────────────────────

def main() -> None:
    huts = load_and_sample(SOURCE_CSV, SAMPLE_SIZE, RANDOM_SEED, SAMPLE_FILE)
    huts = load_or_fetch_forecasts(huts, CACHE_FILE, CACHE_MAX_AGE_HOURS)
    huts = score_all_huts(huts, DAY_WEIGHTS)
    print_ranked_table(huts)
    print_summary_stats(huts)


if __name__ == "__main__":
    main()
