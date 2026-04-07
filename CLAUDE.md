# Alpine Hut Weather Planner — Claude context

## Project purpose

CLI tools for finding and ranking alpine huts by weather forecast. Useful for trip planning: given a location and radius, find huts with the best forecast over the next 5 days.

## Key files

```
hut_search.py          Main tool (geocode + filter + weather + rank)
hut_weather.py         Rank huts from a CSV by weather (no location filter)
filter_huts.py         Create distance-filtered CSVs without weather
data/get_huts.py       Scrape hut data from SAC API + OpenStreetMap
data/alpine_huts_full.csv   ~4,100 huts across the Alps
results/               Output CSVs and weather cache files (gitignored)
data/<location>/       Distance-filtered CSVs from filter_huts.py
```

## Architecture

All three CLI scripts are **standalone** — no shared modules. Scoring and fetching logic is duplicated between `hut_search.py` and `hut_weather.py`. If making changes to scoring or fetching behaviour, apply them to both files.

### Data flow (hut_search.py)
1. Geocode location → lat/lon (Nominatim)
2. Filter CSV by haversine distance (and optionally elevation)
3. Fetch 5-day daily forecasts from Open-Meteo in batches of 17
4. Cache raw forecasts to `results/<stem>_cache.json` (6h TTL)
5. Score each hut: weighted average of daily scores
6. Sort descending by score, print table, save CSV

### Scoring (0–100 scale, higher = better hiking weather)
Each day is scored from four components (weights in `SCORE_WEIGHTS`):
- **Precipitation** (35%): 0mm→100, linear to 20mm→0
- **Wind** (25%): ≤20 km/h→100, linear to ≥80 km/h→0
- **WMO weather code** (25%): clear→100, thunderstorm→5
- **Temperature max** (15%): peak at 5–25°C, 0 below freezing or above 35°C

Final hut score = weighted average of 5 daily scores using `DAY_WEIGHTS`.

### Caching
Cache stores **raw forecasts only** (not scores), so changing `DAY_WEIGHTS` re-scores automatically without needing to delete the cache. Cache is keyed by `<location>_<distance>km[_<elev>m]`.

## APIs

- **Open-Meteo** (`https://api.open-meteo.com/v1/forecast`) — free, no key, supports batch lat/lon requests (comma-separated). Returns JSON array in same order as input. Rate-limited: `BATCH_PAUSE_SEC = 1.5` between batches avoids 429s.
- **Nominatim** (`https://nominatim.openstreetmap.org/search`) — free OSM geocoding. Requires `User-Agent` header.

## Configuration constants (top of each script)

| Constant | Purpose |
|---|---|
| `DAY_WEIGHTS` | Per-day weight for the 5-day average |
| `SCORE_WEIGHTS` | Per-component weight within a day's score |
| `BATCH_SIZE` | Huts per Open-Meteo batch request (default 17) |
| `BATCH_PAUSE_SEC` | Sleep between batches to avoid 429 (default 1.5s) |
| `CACHE_MAX_AGE_HOURS` | How long to reuse cached forecasts (default 6h) |

## CSV schema

**Input** (`data/alpine_huts_full.csv`):
`official_name, operating_club, hut_id, latitude, longitude, elevation_m, email, phone_number, official_website_url, capacity_beds, source_url`

About 1,200 rows have `elevation_m = NaN`. These are included in results unless `--min-elevation` is set, in which case they are excluded.

**Output** (`results/*_weather.csv`):
`rank, official_name, operating_club, distance_km, elevation_m, latitude, longitude, weather_score, <MM-DD>_weathercode, <MM-DD>_temp_max, <MM-DD>_precip_mm, <MM-DD>_wind_kmh, <MM-DD>_day_score` × 5 days

## Common tasks

**Add a new filter flag to hut_search.py:**
Add the `argparse` argument, add the parameter to `filter_huts()`, apply the filter after the distance filter on the `nearby` DataFrame, and include the value in the output `stem` string for unique cache/CSV filenames.

**Change scoring behaviour:**
Edit `score_precipitation`, `score_wind`, `score_weathercode`, or `score_temperature` — these are pure functions. The same functions exist in both `hut_search.py` and `hut_weather.py`; update both.

**Run on the full dataset:**
```bash
python hut_search.py "Innsbruck" 100   # ~760 huts, takes ~2 min due to rate-limit pauses
```

**Force fresh forecast data:**
Delete the relevant cache file in `results/` and re-run.

## Dependencies

`requests`, `pandas` — both present in `.venv`. Python 3.10+.
No test suite exists yet.
