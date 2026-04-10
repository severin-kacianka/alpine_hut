# Alpine Hut Weather Planner

Find alpine huts near a location and check bed availability. Uses free, no-signup APIs throughout.

## Tools

| Tool | Purpose |
|---|---|
| `hut_search.php` | Web UI: elevation-sorted table + cached bed availability + OSM map |
| `update_cache.php` | CLI/web: bulk-download reservation availability into `reservation_cache/` |
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

## Data

### alpine_huts_full.csv

~4,160 alpine huts across the Alps, built by merging:
- **SAC API** — official Swiss Alpine Club huts
- **OpenStreetMap Overpass API** — `tourism=alpine_hut` nodes and ways within the Alps
- **hut-reservation.org scrape** — adds `reservation_id` for ~300 huts

Columns: `official_name`, `operating_club`, `hut_id`, `latitude`, `longitude`, `elevation_m`, `email`, `phone_number`, `official_website_url`, `capacity_beds`, `source_url`, `reservation_id`

About 1,200 rows have `elevation_m = NaN`.

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
| Nominatim | `https://nominatim.openstreetmap.org/search` | `hut_search.php` | No |
| hut-reservation.org availability | `https://www.hut-reservation.org/api/v1/reservation/getHutAvailability` | `update_cache.php` | No |
| hut-reservation.org booking pages | `https://www.hut-reservation.org/reservation/book-hut/<id>/wizard` | `data/scrape_hut_reservation.py` | No |
| SAC API | `https://huts.web.sac-cas.ch/api/1/huts` | `data/get_huts.py` | No |
| Overpass API | `https://overpass-api.de/api/interpreter` | `data/get_huts.py` | No |

**Nominatim** requires a `User-Agent: alpine-hut-search/1.0` header. Results are cached in `reservation_cache/geocode_cache.json` by the web UI. Geocoding is skipped when the user enters coordinates directly.

**hut-reservation.org availability** returns a JSON array of `{ date, freeBeds, hutStatus, totalSleepingPlaces }` objects for roughly 90 days ahead. `update_cache.php` writes the raw response to `reservation_cache/<id>`.

**hut-reservation.org scraper** (`scrape_hut_reservation.py`) uses Playwright to render JavaScript-heavy booking pages and parses the resulting DOM for hut metadata.

**SAC API + Overpass API** are called once by `data/get_huts.py` to build the hut database and are not used at runtime.

## Dependencies

**PHP web UI:** PHP 8.0+, `curl` extension. No Composer packages.

**Data collection scripts:**
```bash
pip install requests pandas
```
Python 3.10+ required.

**Hut-reservation scraper** (additional):
```bash
.venv/bin/pip install playwright
.venv/bin/python -m playwright install chromium
```
