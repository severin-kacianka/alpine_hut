# Alpine Hut Weather Planner — Claude context

## Project purpose

Tools for finding and ranking alpine huts by weather forecast and bed availability. Useful for trip planning: given a location (or lat/lon) and radius, find huts with the best forecast and available beds. Available as a web UI (PHP) and a CLI (Python).

## Key files

```
hut_search.php               Web UI (elevation-sorted table + cached bed availability + OSM map)
update_cache.php             CLI/web: bulk-download reservation availability to reservation_cache/
hut_search.py                CLI main tool (geocode + filter + weather + rank)
hut_weather.py               CLI rank huts from a CSV by weather (no location filter)
filter_huts.py               CLI create distance-filtered CSVs without weather
data/get_huts.py             Scrape hut data from SAC API + OpenStreetMap Overpass API
data/scrape_hut_reservation.py  Scrape hut metadata from hut-reservation.org (Playwright)
data/alpine_huts_full.csv   ~4,160 huts across the Alps (merged with reservation data)
data/hut_reservation_scraped.csv  Hut metadata from hut-reservation.org (id, name, coords, …)
reservation_cache/           Per-hut availability JSON files (one file per reservation_id)
reservation_cache/update_log-1.txt  Most recent update_cache.php run log (3 rotated logs kept)
results/                     Output CSVs and CLI weather cache files (gitignored)
data/<location>/             Distance-filtered CSVs from filter_huts.py
```

## Architecture

All three CLI Python scripts are **standalone** — no shared modules. Scoring and fetching logic is duplicated between `hut_search.py` and `hut_weather.py`. If making changes to scoring or fetching behaviour, apply them to both files.

`hut_search.php` is a standalone single file. It reads availability from local `reservation_cache/<id>` files instead of calling the API. Use `update_cache.php` to populate the cache first. It embeds an OSM map (Leaflet.js via CDN) below the results table; markers are color-coded by availability (green/yellow/red/default blue). The search location is marked with a crosshair icon. The location field accepts either a place name (geocoded via Nominatim) or a raw `lat,lon` pair (skips geocoding).

`update_cache.php` iterates a range of reservation IDs and downloads the raw JSON response for each into `reservation_cache/`. Files fresher than 12 h are skipped. Rate-limiting: 0.5 s between requests, 5 s pause every 10 successful fetches, 5 s pause after any HTTP 403. Logs are written to `reservation_cache/update_log-1.txt` (rotating, 3 files kept).

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

## APIs

- **Open-Meteo** (`http://api.open-meteo.com/v1/forecast`) — free, no key, supports batch lat/lon requests (comma-separated). Returns JSON array in same order as input. Rate-limited: `BATCH_PAUSE_SEC = 1.5` between batches avoids 429s.
- **Nominatim** (`https://nominatim.openstreetmap.org/search`) — free OSM geocoding. Requires `User-Agent: alpine-hut-search/1.0` header. Used by `hut_search.php` (skipped if user enters raw lat/lon) and the Python CLI scripts.
- **hut-reservation.org availability API** (`https://www.hut-reservation.org/api/v1/reservation/getHutAvailability?hutId=<id>&step=WIZARD`) — free, no key. Returns a JSON array of daily availability objects (`date`, `freeBeds`, `hutStatus`, `totalSleepingPlaces`). Used by `update_cache.php`. Rate-limiting: 0.5 s between calls + 5 s every 10 successful fetches + 5 s after any HTTP 403.
- **SAC API** (`https://huts.web.sac-cas.ch/api/1/huts?language=en`) — free, no key. Returns JSON array of SAC hut objects. Used by `data/get_huts.py` to build the hut database.
- **OpenStreetMap Overpass API** (`https://overpass-api.de/api/interpreter`) — free, no key. Queried with a POST body for nodes/ways tagged `tourism=alpine_hut` within the Alps area. Used by `data/get_huts.py`.
- **hut-reservation.org booking pages** (`https://www.hut-reservation.org/reservation/book-hut/<id>/wizard`) — scraped by `data/scrape_hut_reservation.py` using Playwright (headless Chromium). Extracts name, coordinates, elevation, phone, beds, website per hut ID. Backs up existing CSV before each run; resumes from last known state.

## Configuration constants (top of each script)

| Constant | Purpose |
|---|---|
| `DAY_WEIGHTS` | Per-day weight for the 5-day average |
| `SCORE_WEIGHTS` | Per-component weight within a day's score |
| `BATCH_SIZE` | Huts per Open-Meteo batch request (default 17) |
| `BATCH_PAUSE_SEC` | Sleep between batches to avoid 429 (default 1.5s) |
| `CACHE_MAX_AGE_HOURS` | How long to reuse cached forecasts (default 6h) |
| `CACHE_TTL` | Reservation cache TTL in seconds (default 12h) |

## CSV schema

**Input** (`data/alpine_huts_full.csv`):
`official_name, operating_club, hut_id, latitude, longitude, elevation_m, email, phone_number, official_website_url, capacity_beds, source_url, reservation_id`

The `reservation_id` column (last) contains the numeric ID used by hut-reservation.org. It is populated for ~300 huts; the rest have an empty string. When merging new scraped data, the scraped values take precedence over existing rows (matched by exact name, then by coordinates within 0.5 km).

About 1,200 rows have `elevation_m = NaN`. These are included in results unless `--min-elevation` is set, in which case they are excluded.

**Reservation scrape** (`data/hut_reservation_scraped.csv`):
`id, name, warden, phone, beds, elevation_m, latitude, longitude, website_url`

Rows with `name = NOT_FOUND` indicate IDs that do not exist on hut-reservation.org. These are skipped by `update_cache.php`.

**Output** (`results/*_weather.csv`):
`rank, official_name, operating_club, distance_km, elevation_m, latitude, longitude, weather_score, <MM-DD>_weathercode, <MM-DD>_temp_max, <MM-DD>_precip_mm, <MM-DD>_wind_kmh, <MM-DD>_day_score` × 5 days

## Common tasks

**Add a new filter flag to hut_search.py:**
Add the `argparse` argument, add the parameter to `filter_huts()`, apply the filter after the distance filter on the `nearby` DataFrame, and include the value in the output `stem` string for unique cache/CSV filenames.

**Change scoring behaviour:**
Edit `score_precipitation`, `score_wind`, `score_weathercode`, or `score_temperature` — these are pure functions. The same functions exist in `hut_search.py` and `hut_weather.py`; update both.

**Run on the full dataset:**
```bash
python hut_search.py "Innsbruck" 100   # ~760 huts, takes ~2 min due to rate-limit pauses
```

**Force fresh forecast data (CLI):**
Delete the relevant cache file in `results/` and re-run.

**Refresh reservation cache:**
```bash
php update_cache.php 1 800
```
Logs are written to `reservation_cache/update_log-1.txt`.

**Refresh hut database:**
```bash
python data/get_huts.py          # fetch from SAC API + Overpass
python data/scrape_hut_reservation.py   # scrape hut-reservation.org (needs Playwright)
```

## Dependencies

**Python CLI:** `requests`, `pandas` — both present in `.venv`. Python 3.10+.
`data/scrape_hut_reservation.py` additionally requires `playwright` + Chromium:
```bash
.venv/bin/pip install playwright
.venv/bin/python -m playwright install chromium
```

**PHP web UI:** PHP 8.0+, `curl` extension. No Composer packages. The `reservation_cache/` directory must be writable by the web server user.

No test suite exists yet.
