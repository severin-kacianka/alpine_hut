<?php
// Alpine Hut Finder — list huts by elevation, no weather API calls
// Requires: PHP 8.0+, curl extension, data/alpine_huts_full.csv readable

setlocale(LC_ALL, 'C');

define('CSV_PATH',      __DIR__ . '/data/alpine_huts_full.csv');
define('NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search');
define('USER_AGENT',    'alpine-hut-search/1.0');

// ---------------------------------------------------------------------------
// Geocoding
// ---------------------------------------------------------------------------
function geocode(string $location): array {
    $url = NOMINATIM_URL . '?' . http_build_query([
        'q'      => $location,
        'format' => 'json',
        'limit'  => 1,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Geocoding request failed: $err");
    }
    $data = json_decode($body, true);
    if (empty($data)) {
        throw new RuntimeException("No results found for location: " . htmlspecialchars($location, ENT_QUOTES, 'UTF-8'));
    }
    return [
        'lat'          => (float)$data[0]['lat'],
        'lon'          => (float)$data[0]['lon'],
        'display_name' => $data[0]['display_name'],
    ];
}

// ---------------------------------------------------------------------------
// Distance
// ---------------------------------------------------------------------------
function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R    = 6371.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlam = deg2rad($lon2 - $lon1);
    $a    = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlam / 2) ** 2;
    return $R * 2 * asin(sqrt($a));
}

// ---------------------------------------------------------------------------
// CSV loading + filtering
// ---------------------------------------------------------------------------
function load_and_filter_huts(
    string $csv_path,
    float  $center_lat,
    float  $center_lon,
    float  $radius_km,
    ?float $min_elevation
): array {
    $fh = fopen($csv_path, 'r');
    if ($fh === false) {
        throw new RuntimeException("Cannot open CSV: $csv_path");
    }

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException("CSV is empty");
    }
    $col = array_flip($header);

    $huts = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!isset($col['official_name'], $col['latitude'], $col['longitude'])) continue;
        $name = isset($row[$col['official_name']]) ? trim($row[$col['official_name']]) : '';
        $lat  = isset($row[$col['latitude']])  ? trim($row[$col['latitude']])  : '';
        $lon  = isset($row[$col['longitude']]) ? trim($row[$col['longitude']]) : '';
        if ($name === '' || $lat === '' || $lon === '') continue;

        $lat = (float)$lat;
        $lon = (float)$lon;

        $dist = haversine_km($center_lat, $center_lon, $lat, $lon);
        if ($dist > $radius_km) continue;

        $elev_raw = isset($col['elevation_m'], $row[$col['elevation_m']])
                    ? trim($row[$col['elevation_m']]) : '';
        $elev = ($elev_raw !== '' && $elev_raw !== 'nan' && $elev_raw !== 'NaN')
                ? (float)$elev_raw : null;

        if ($min_elevation !== null) {
            if ($elev === null || $elev < $min_elevation) continue;
        }

        $huts[] = [
            'official_name'        => $name,
            'operating_club'       => isset($col['operating_club'], $row[$col['operating_club']])
                                      ? trim($row[$col['operating_club']]) : '',
            'latitude'             => $lat,
            'longitude'            => $lon,
            'elevation_m'          => $elev,
            'official_website_url' => isset($col['official_website_url'], $row[$col['official_website_url']])
                                      ? trim($row[$col['official_website_url']]) : '',
            'distance_km'          => round($dist, 1),
        ];
    }
    fclose($fh);

    // Sort: highest elevation first, nulls at the bottom
    usort($huts, function($a, $b) {
        if ($a['elevation_m'] === null && $b['elevation_m'] === null) return 0;
        if ($a['elevation_m'] === null) return 1;
        if ($b['elevation_m'] === null) return -1;
        return $b['elevation_m'] <=> $a['elevation_m'];
    });

    return $huts;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function render_form(?string $location, ?float $distance, ?float $min_elevation): void {
    $loc  = h($location ?? '');
    $dist = $distance !== null ? (int)$distance : 50;
    $elev = $min_elevation !== null ? (int)$min_elevation : '';
    echo <<<HTML
    <form class="search-form" method="get" action="">
      <h2>Search</h2>
      <div class="form-row">
        <div class="form-group">
          <label for="location">Location</label>
          <input type="text" id="location" name="location" value="$loc"
                 placeholder="e.g. Innsbruck, Chamonix" required style="width:220px">
        </div>
        <div class="form-group">
          <label for="distance">Radius (km)</label>
          <input type="number" id="distance" name="distance" value="$dist"
                 min="1" max="500" step="1" style="width:90px">
        </div>
        <div class="form-group">
          <label for="min_elevation">Min elevation (m)</label>
          <input type="number" id="min_elevation" name="min_elevation" value="$elev"
                 min="0" max="5000" step="100" placeholder="optional" style="width:130px">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <button type="submit">Search</button>
        </div>
      </div>
    </form>
    HTML;
}

function render_error(string $message): void {
    $m = h($message);
    echo "<div class=\"error-box\">$m</div>\n";
}

function render_results(array $huts, string $display_name, float $distance_km, ?float $min_elevation): void {
    $total = count($huts);
    $dn    = h($display_name);
    $dist  = (int)$distance_km;
    $elev_s = $min_elevation !== null ? ', min elevation ' . (int)$min_elevation . ' m' : '';

    echo "<div class=\"result-summary\">";
    echo "Found <strong>$total</strong> huts within {$dist} km of <em>$dn</em>$elev_s, sorted by elevation";
    echo "</div>\n";

    echo '<div style="overflow-x:auto">';
    echo '<table class="results-table">';
    echo '<thead><tr>';
    echo '<th>#</th><th>Hut</th><th>Club</th><th>Distance</th><th>Elevation</th><th>Website</th>';
    echo '</tr></thead><tbody>';

    foreach ($huts as $i => $hut) {
        $rank    = $i + 1;
        $name    = h($hut['official_name']);
        $club    = h($hut['operating_club'] ?? '');
        $url     = $hut['official_website_url'] ?? '';
        $dist_s  = $hut['distance_km'] . ' km';
        $elev_s2 = $hut['elevation_m'] !== null ? number_format((int)$hut['elevation_m']) . ' m' : '?';
        $link    = $url !== ''
                   ? '<a href="' . h($url) . '" target="_blank" title="' . h($url) . '">&#x1F517;</a>'
                   : '';

        echo "<tr>";
        echo "<td class=\"rank\">$rank</td>";
        echo "<td class=\"hut-name\">$name</td>";
        echo "<td>$club</td>";
        echo "<td class=\"num\">$dist_s</td>";
        echo "<td class=\"num elev\">$elev_s2</td>";
        echo "<td class=\"center\">$link</td>";
        echo "</tr>\n";
    }

    echo '</tbody></table></div>';
}

// ---------------------------------------------------------------------------
// HTML page
// ---------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Alpine Hut Finder</title>
  <style>
    :root { --border: #dee2e6; --hdr-bg: #343a40; --hdr-fg: #fff; }
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, sans-serif; margin: 0; padding: 1rem;
           background: #f0f2f5; color: #212529; }
    header { background: var(--hdr-bg); color: var(--hdr-fg); padding: .75rem 1.25rem;
             border-radius: 6px; margin-bottom: 1rem; }
    header h1 { margin: 0; font-size: 1.4rem; }
    header p  { margin: .2rem 0 0; font-size: .85rem; opacity: .75; }

    .search-form { background: #fff; border: 1px solid var(--border); border-radius: 8px;
                   padding: 1.25rem; margin-bottom: 1rem; }
    .search-form h2 { margin: 0 0 .75rem; font-size: 1rem; }
    .form-row { display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; }
    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .form-group label { font-size: .8rem; font-weight: 600; color: #495057; }
    input[type=text], input[type=number] {
      padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: .95rem; }
    button[type=submit] { padding: 8px 22px; background: #0d6efd; color: #fff; border: none;
                          border-radius: 4px; cursor: pointer; font-size: .95rem; font-weight: 600; }
    button[type=submit]:hover { background: #0b5ed7; }

    .error-box { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;
                 border-radius: 6px; padding: .75rem 1rem; margin-bottom: 1rem; }

    .result-summary { background: #fff; border: 1px solid var(--border); border-radius: 6px;
                      padding: .6rem 1rem; margin-bottom: .75rem; font-size: .9rem; }

    .results-table { border-collapse: collapse; width: 100%; font-size: .85rem;
                     background: #fff; border-radius: 6px; overflow: hidden; }
    .results-table th { background: var(--hdr-bg); color: var(--hdr-fg);
                        padding: 7px 10px; text-align: left; white-space: nowrap; }
    .results-table td { border: 1px solid var(--border); padding: 6px 10px; vertical-align: middle; }
    .results-table tr:nth-child(even) td { background: #f8f9fa; }
    .results-table tr:hover td { background: #e9f0ff; }
    .results-table td.rank { text-align: right; color: #6c757d; font-weight: 700; width: 3rem; }
    .results-table td.hut-name { font-weight: 600; }
    .results-table td.num { text-align: right; white-space: nowrap; }
    .results-table td.elev { font-weight: 600; color: #495057; }
    .results-table td.center { text-align: center; font-size: 1rem; }

    footer { margin-top: 1.5rem; text-align: center; font-size: .78rem; color: #6c757d; }
  </style>
</head>
<body>
<header>
  <h1>Alpine Hut Finder</h1>
  <p>List huts near a location, sorted by elevation &mdash; <a href="index.php" style="color:#adb5bd">switch to weather view</a></p>
</header>

<?php
$location      = isset($_GET['location']) ? trim($_GET['location']) : null;
$distance_km   = isset($_GET['distance']) && $_GET['distance'] !== '' ? (float)$_GET['distance'] : 50.0;
$min_elevation = isset($_GET['min_elevation']) && $_GET['min_elevation'] !== '' ? (float)$_GET['min_elevation'] : null;

render_form($location, $distance_km, $min_elevation);

if ($location !== null && $location !== '') {
    if ($distance_km <= 0 || $distance_km > 500) {
        render_error("Distance must be between 1 and 500 km.");
    } else {
        try {
            $geo  = geocode($location);
            $huts = load_and_filter_huts(CSV_PATH, $geo['lat'], $geo['lon'], $distance_km, $min_elevation);

            if (empty($huts)) {
                $elev_hint = $min_elevation !== null ? " above {$min_elevation} m elevation" : '';
                render_error("No huts found within {$distance_km} km of &ldquo;" . h($location) . "&rdquo;$elev_hint.");
            } else {
                render_results($huts, $geo['display_name'], $distance_km, $min_elevation);
            }
        } catch (RuntimeException $e) {
            render_error($e->getMessage());
        }
    }
}
?>

<footer>
  Data: <a href="https://www.sac-cas.ch" target="_blank">SAC</a> &amp;
  <a href="https://www.openstreetmap.org" target="_blank">OpenStreetMap</a> &mdash;
  Geocoding: <a href="https://nominatim.openstreetmap.org" target="_blank">Nominatim</a>
</footer>
</body>
</html>
