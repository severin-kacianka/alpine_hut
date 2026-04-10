# Alpine Hut Weather Planner

Find alpine huts near a location and rank them by forecast weather quality and bed availability. Uses free, no-signup APIs throughout.

Available as a **web UI** (PHP) and a **CLI** (Python).

## Tools

| Tool | Purpose |
|---|---|
| `hut_search.php` | Web UI: elevation-sorted table + cached bed availability + OSM map |
| `update_cache.php` | CLI/web: bulk-download reservation availability into `reservation_cache/` |
| `hut_search.py` | CLI: geocode a location, filter huts by distance/elevation, rank by weather |
| `hut_weather.py` | CLI: rank huts from any CSV by weather (no location filter) |
| `filter_huts.py` | CLI: create distance-filtered CSVs (10/25/50/100 km) without weather data |
| `data/get_huts.py` | Data collection: fetch hut data from SAC API and OpenStreetMap Overpass |
| `data/scrape_hut_reservation.py` | Data collection: scrape hut metadata from hut-reservation.org |

## Web UI — hut_search.php

Drop `hut_search.php` alongside the `data/` and `reservation_cache/` directories on any PHP 8.0+ web server.

Features:
- Search by location name **or** raw `lat,lon` coordinates (skips geocoding)
- Filter by radius and optional minimum elevation
- Results sorted by elevation (highest first), sortable by name/distance/elevation
- Optional date range (up to 14 days): shows cached bed availability per hut per day
- Colour-coded availability cells: green = open & beds free, yellow = full, red = closed
- Interactive OSM map (Leaflet.js) below the table:
  - Crosshair icon marks the search center
  - Pin markers colour-coded by availability (green/yellow/red/blue)
  - Click a marker or table row to open the hut popup
- Direct booking link and map links (Google Maps, OSM, GEO) per hut
- Requires outbound HTTPS to `nominatim.openstreetmap.org` (port 443) for place name searches

### update_cache.php — Bulk availability downloader

Pre-downloads availability data for a range of hut IDs from hut-reservation.org into `reservation_cache/<id>` files (raw JSON, 12 h TTL).

```bash
# CLI: fetch IDs 1 through 800
php update_cache.php 1 800

# Web: update_cache.php?start_id=1&end_id=800
```

Output per ID: `OK`, `SKIP` (still fresh), `NF` (not found in CSV), or `FAIL` with reason.

Rate-limiting: 0.5 s between requests, 5 s pause every 10 successful fetches, 5 s pause after any HTTP 403.

Logs are written to `reservation_cache/update_log-1.txt`. Three rotated log files are kept (`update_log-1.txt` through `update_log-3.txt`), with timestamps on every line.

## CLI quick start

```bash
# Activate the virtual environment
source .venv/bin/activate

# Find huts within 50 km of Innsbruck, ranked by weather
python hut_search.py "Innsbruck" 50

# Only show huts above 1500m
python hut_search.py "Innsbruck" 50 --min-elevation 1500

# Works with place names, postcodes, regions
python hut_search.py "Chamonix, France" 25
python hut_search.py "6020" 40
```

## hut_search.py

The main CLI entry point. Combines geocoding, distance filtering, weather fetching, and ranking.

```
python hut_search.py <location> <distance_km> [options]

Arguments:
  location        Place name or postcode (quoted if it contains spaces)
  distance_km     Search radius in kilometres

Options:
  --min-elevation M   Exclude huts below M metres (huts with unknown elevation
                      are also excluded when this flag is set)
  --source CSV        Huts database to search (default: data/alpine_huts_full.csv)
```

**Output:**
- Ranked table printed to stdout
- CSV saved to `results/<location>_<distance>km[_<elev>m]_weather.csv`

**Example output:**
```
Huts within 25 km of Innsbruck  |  min elevation: 1800m  |  day weights: [1, 1, 1, 1, 1]
──────────────────────────────────────────────────────────────────────────────────
   #  Hut                              Dist  Elev Score  04-07    04-08    ...
──────────────────────────────────────────────────────────────────────────────────
   1  Poltnalm                       19.1km 1860m  91.6  Overcast 11°  ...
   2  Stöcklalm                      19.5km 1882m  91.5  Overcast 11°  ...
```

## hut_weather.py

Ranks all huts in a given CSV by weather. Useful for working with pre-filtered CSV files.

```
python hut_weather.py <input.csv>
```

```bash
python hut_weather.py data/innsbruck/innsbruck_50km.csv
```

## filter_huts.py

Creates four distance-filtered CSVs (10/25/50/100 km) from the full database.

```
python filter_huts.py <location> <huts.csv>
```

```bash
python filter_huts.py "Innsbruck" data/alpine_huts_full.csv
# → data/innsbruck/innsbruck_10km.csv
# → data/innsbruck/innsbruck_25km.csv
# → data/innsbruck/innsbruck_50km.csv
# → data/innsbruck/innsbruck_100km.csv
```

Each output CSV includes all original columns plus `distance_km`, sorted nearest-first.

## Tuning the weather score

Open `hut_search.py` or `hut_weather.py` and edit the constants at the top of the file.

**`DAY_WEIGHTS`** — controls how much each of the 5 forecast days contributes to the final score:

```python
DAY_WEIGHTS = [1, 1, 1, 1, 1]   # equal — default
DAY_WEIGHTS = [1, 2, 3, 4, 5]   # weight weekend / later days more
DAY_WEIGHTS = [5, 4, 3, 2, 1]   # trust near-term forecast more
DAY_WEIGHTS = [0, 0, 1, 1, 1]   # ignore today and tomorrow
```

**`SCORE_WEIGHTS`** — controls how each weather variable contributes to a day's score:

```python
SCORE_WEIGHTS = {
    "precipitation": 0.35,   # rain/snow (most important)
    "wind":          0.25,   # wind speed
    "weathercode":   0.25,   # WMO weather condition code
    "temperature":   0.15,   # max temperature (peak score: 5–25°C)
}
```

**Scoring thresholds:**

| Variable | Best (100) | Worst (0) |
|---|---|---|
| Precipitation | 0 mm | ≥ 20 mm |
| Wind | ≤ 20 km/h | ≥ 80 km/h |
| Weather code | Clear (0–2) | Thunderstorm (95+) |
| Temperature | 5–25°C | < 0°C or > 35°C |

## Data

### alpine_huts_full.csv

~4,160 alpine huts across the Alps, built by merging:
- **SAC API** — official Swiss Alpine Club huts
- **OpenStreetMap Overpass API** — `tourism=alpine_hut` nodes and ways within the Alps
- **hut-reservation.org scrape** — adds `reservation_id` for ~300 huts

Columns: `official_name`, `operating_club`, `hut_id`, `latitude`, `longitude`, `elevation_m`, `email`, `phone_number`, `official_website_url`, `capacity_beds`, `source_url`, `reservation_id`

The `reservation_id` column is populated for ~300 huts. When merging new scraped data, scraped values take precedence (matched by name, then by coordinates within 0.5 km). About 1,200 rows have `elevation_m = NaN`.

### Rebuilding the hut database

**Step 1 — fetch hut list from SAC + OSM:**
```bash
python data/get_huts.py
# → data/alpine_huts_full.csv
```

Calls the SAC API (`https://huts.web.sac-cas.ch/api/1/huts?language=en`) and the Overpass API (`https://overpass-api.de/api/interpreter`) for all `tourism=alpine_hut` elements in the Alps. Merges and deduplicates by name + coordinates.

**Step 2 — scrape reservation IDs and metadata from hut-reservation.org:**
```bash
.venv/bin/pip install playwright
.venv/bin/python -m playwright install chromium
python data/scrape_hut_reservation.py
# → data/hut_reservation_scraped.csv
```

Uses Playwright (headless Chromium) to load each booking page at `https://www.hut-reservation.org/reservation/book-hut/<id>/wizard` and extract: name, warden, phone, beds, elevation, coordinates, website. IDs that return no hut page are recorded as `NOT_FOUND`. Re-running resumes from the last known state; a timestamped backup of the existing CSV is created before each run.

Configure `ID_FETCH_FROM` and `ID_END` at the top of the script to control which ID range to scrape.

## APIs used

| API | Endpoint | Used by | Key required |
|---|---|---|---|
| Open-Meteo | `http://api.open-meteo.com/v1/forecast` | `hut_search.py`, `hut_weather.py` | No |
| Nominatim | `https://nominatim.openstreetmap.org/search` | `hut_search.php`, `hut_search.py`, `hut_weather.py`, `filter_huts.py` | No |
| hut-reservation.org availability | `https://www.hut-reservation.org/api/v1/reservation/getHutAvailability` | `update_cache.php` | No |
| hut-reservation.org booking pages | `https://www.hut-reservation.org/reservation/book-hut/<id>/wizard` | `data/scrape_hut_reservation.py` | No |
| SAC API | `https://huts.web.sac-cas.ch/api/1/huts` | `data/get_huts.py` | No |
| Overpass API | `https://overpass-api.de/api/interpreter` | `data/get_huts.py` | No |

**Open-Meteo** supports batched requests (up to 17 lat/lon pairs per call). CLI scripts pause 1.5 s between batches. Forecast data is cached 6 h in `results/*_cache.json`.

**Nominatim** requires a `User-Agent: alpine-hut-search/1.0` header. Results are cached in `reservation_cache/geocode_cache.json` by the web UI.

**hut-reservation.org availability** returns a JSON array of `{ date, freeBeds, hutStatus, totalSleepingPlaces }` objects for roughly 90 days ahead. `update_cache.php` writes the raw response to `reservation_cache/<id>`.

**hut-reservation.org scraper** (`scrape_hut_reservation.py`) uses Playwright to render JavaScript-heavy booking pages and parses the resulting DOM for hut metadata.

## Dependencies

**PHP web UI:** PHP 8.0+, `curl` extension. No Composer packages.

**Python CLI:**
```bash
pip install requests pandas
```
Python 3.10+ required.

**Hut-reservation scraper** (additional):
```bash
.venv/bin/pip install playwright
.venv/bin/python -m playwright install chromium
```
