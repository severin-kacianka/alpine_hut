# Alpine Hut Weather Planner — Claude context

## Project purpose

Tools for finding and ranking alpine huts by weather forecast. Useful for trip planning: given a location and radius, find huts with the best forecast over the next 5 days. Available as a CLI (Python) and a web UI (PHP).

## Key files

```
index.php              Web UI (search form + ranked HTML table with weather scores)
hut_search_v2.php      Web UI (search form + elevation-sorted table + bed availability)
hut_availability.php   CLI/web: query hut-reservation.org availability for a single hut
hut_search.py          CLI main tool (geocode + filter + weather + rank)
hut_weather.py         CLI rank huts from a CSV by weather (no location filter)
filter_huts.py         CLI create distance-filtered CSVs without weather
data/get_huts.py       Scrape hut data from SAC API + OpenStreetMap
data/alpine_huts_full.csv   ~4,160 huts across the Alps (merged with reservation data)
data/weather_cache.json     Per-hut forecast cache used by index.php (24h TTL)
results/               Output CSVs and CLI weather cache files (gitignored)
data/<location>/       Distance-filtered CSVs from filter_huts.py
```

## Architecture

All three CLI scripts are **standalone** — no shared modules. Scoring and fetching logic is duplicated between `hut_search.py` and `hut_weather.py`. If making changes to scoring or fetching behaviour, apply them to both files.

`index.php` is also fully standalone (single file). It replicates the same scoring logic as the Python scripts. If scoring behaviour changes, update all three: `hut_search.py`, `hut_weather.py`, and `index.php`.

`hut_search_v2.php` is a separate standalone file — no shared code with `index.php`. It does not do weather scoring; instead it optionally calls the hut-reservation.org availability API for huts that have a `reservation_id` in the CSV.

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
**CLI** (`hut_search.py`): cache stores **raw forecasts only** (not scores), so changing `DAY_WEIGHTS` re-scores automatically. Cache file: `results/<location>_<distance>km[_<elev>m]_cache.json`, keyed per search, 6 h TTL.

**Web UI** (`index.php`): per-hut cache in `data/weather_cache.json`, keyed by `"lat_lon"` (4 decimal places), 24 h TTL. Scores are always recalculated from cached raw forecasts.

## APIs

- **Open-Meteo** (`http://api.open-meteo.com/v1/forecast`) — free, no key, supports batch lat/lon requests (comma-separated). Returns JSON array in same order as input. Rate-limited: `BATCH_PAUSE_SEC = 1.5` between batches avoids 429s. Note: `index.php` uses HTTP (not HTTPS) to avoid SSL issues on servers without outbound 443.
- **Nominatim** (`https://nominatim.openstreetmap.org/search`) — free OSM geocoding. Requires `User-Agent: alpine-hut-search/1.0` header.
- **hut-reservation.org** (`https://www.hut-reservation.org/api/v1/reservation/getHutAvailability`) — free, no key. Returns a JSON array of daily availability objects (`date`, `freeBeds`, `hutStatus`, `totalSleepingPlaces`). Used by `hut_search_v2.php` and `hut_availability.php`. Rate-limited: 0.5 s pause between calls in `hut_search_v2.php`.

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
`official_name, operating_club, hut_id, latitude, longitude, elevation_m, email, phone_number, official_website_url, capacity_beds, source_url, reservation_id`

The `reservation_id` column (last) contains the numeric ID used by hut-reservation.org. It is populated for ~300 huts; the rest have an empty string. When merging new scraped data, the scraped values take precedence over existing rows (matched by exact name, then by coordinates within 0.5 km).

About 1,200 rows have `elevation_m = NaN`. These are included in results unless `--min-elevation` is set, in which case they are excluded.

**Output** (`results/*_weather.csv`):
`rank, official_name, operating_club, distance_km, elevation_m, latitude, longitude, weather_score, <MM-DD>_weathercode, <MM-DD>_temp_max, <MM-DD>_precip_mm, <MM-DD>_wind_kmh, <MM-DD>_day_score` × 5 days

## Common tasks

**Add a new filter flag to hut_search.py:**
Add the `argparse` argument, add the parameter to `filter_huts()`, apply the filter after the distance filter on the `nearby` DataFrame, and include the value in the output `stem` string for unique cache/CSV filenames.

**Change scoring behaviour:**
Edit `score_precipitation`, `score_wind`, `score_weathercode`, or `score_temperature` — these are pure functions. The same functions exist in `hut_search.py`, `hut_weather.py`, and `index.php`; update all three.

**Run on the full dataset:**
```bash
python hut_search.py "Innsbruck" 100   # ~760 huts, takes ~2 min due to rate-limit pauses
```

**Force fresh forecast data (CLI):**
Delete the relevant cache file in `results/` and re-run.

**Force fresh forecast data (web UI):**
Delete `data/weather_cache.json` and reload the page.

## Dependencies

**Python CLI:** `requests`, `pandas` — both present in `.venv`. Python 3.10+.

**PHP web UI:** PHP 8.0+, `curl` extension. No Composer packages. The `data/` directory must be writable by the web server user.

No test suite exists yet.
