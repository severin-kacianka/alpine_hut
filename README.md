# Alpine Hut Weather Planner

Find alpine huts near a location and rank them by forecast weather quality. Uses free, no-signup APIs throughout.

## Scripts

| Script | Purpose |
|---|---|
| `hut_search.py` | Main tool: geocode a location, filter huts by distance/elevation, rank by weather |
| `hut_weather.py` | Rank huts from any CSV by weather (no location filter) |
| `filter_huts.py` | Create distance-filtered CSVs (10/25/50/100 km) without weather data |
| `data/get_huts.py` | Data collection: fetch hut data from SAC API and OpenStreetMap |

## Quick start

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

The main entry point. Combines geocoding, distance filtering, weather fetching, and ranking.

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
# Rank all huts in a file
python hut_weather.py data/sample_huts.csv

# Combine with filter_huts.py output
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

**`data/alpine_huts_full.csv`** — ~4,100 alpine huts across the Alps, combining:
- Swiss Alpine Club (SAC) official data
- OpenStreetMap (via Overpass API)

Columns: `official_name`, `operating_club`, `hut_id`, `latitude`, `longitude`, `elevation_m`, `email`, `phone_number`, `official_website_url`, `capacity_beds`, `source_url`

Re-fetch fresh data:
```bash
python data/get_huts.py
```

## APIs used

| API | Used for | Key required |
|---|---|---|
| [Open-Meteo](https://open-meteo.com) | 5-day weather forecasts | No |
| [Nominatim / OSM](https://nominatim.org) | Geocoding place names to lat/lon | No |
| SAC API / Overpass | Hut data collection (`get_huts.py`) | No |

Forecast data is cached for 6 hours in `results/*_cache.json` to avoid redundant API calls. Delete a cache file to force a fresh fetch.

## Dependencies

```bash
pip install requests pandas
```

Python 3.10+ required (uses `float | None` union syntax).
