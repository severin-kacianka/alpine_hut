import requests
import pandas as pd

# --- SAC (official API) ---
def get_sac():
    url = "https://huts.web.sac-cas.ch/api/1/huts?language=en"
    data = requests.get(url).json()

    rows = []
    for h in data:
        if not str(h.get("owner","")).startswith("SAC"):
            continue

        rows.append({
            "official_name": h.get("name"),
            "operating_club": "SAC",
            "hut_id": h.get("poi_id"),
            "latitude": h.get("coordinates",{}).get("latitude"),
            "longitude": h.get("coordinates",{}).get("longitude"),
            "elevation_m": h.get("altitude"),
            "email": h.get("contact",{}).get("email"),
            "phone_number": h.get("contact",{}).get("phone"),
            "official_website_url": h.get("contact",{}).get("website"),
            "capacity_beds": h.get("capacity"),
            "source_url": h.get("sac_reference",{}).get("url"),
        })

    return pd.DataFrame(rows)


# --- DAV / ÖAV (fallback via OSM — high coverage) ---
def get_osm_alpine_huts():
    query = """
    [out:json][timeout:60];
    area["name"="Alps"]->.searchArea;
    (
      node["tourism"="alpine_hut"](area.searchArea);
      way["tourism"="alpine_hut"](area.searchArea);
    );
    out center;
    """

    url = "https://overpass-api.de/api/interpreter"
    data = requests.post(url, data=query).json()

    rows = []
    for el in data["elements"]:
        tags = el.get("tags", {})

        lat = el.get("lat") or el.get("center", {}).get("lat")
        lon = el.get("lon") or el.get("center", {}).get("lon")

        rows.append({
            "official_name": tags.get("name"),
            "operating_club": tags.get("operator"),
            "hut_id": el.get("id"),
            "latitude": lat,
            "longitude": lon,
            "elevation_m": tags.get("ele"),
            "email": tags.get("email"),
            "phone_number": tags.get("phone"),
            "official_website_url": tags.get("website"),
            "capacity_beds": tags.get("capacity"),
            "source_url": "OSM",
        })

    return pd.DataFrame(rows)


# --- RUN ---
sac = get_sac()
osm = get_osm_alpine_huts()

df = pd.concat([sac, osm], ignore_index=True)

# simple dedup
df = df.drop_duplicates(subset=["official_name", "latitude", "longitude"])

df.to_csv("alpine_huts_full.csv", index=False)
print("Saved alpine_huts_full.csv with", len(df), "rows")
