<?php
// Alpine Hut Finder (cached) — like hut_search_v2.php but reads availability
// from local reservation_cache/ files instead of calling the API live.
// Run update_cache.php first to populate the cache.
// Requires: PHP 7.4+, curl extension, data/alpine_huts_full.csv readable

setlocale(LC_ALL, 'C');

define('CSV_PATH',       __DIR__ . '/data/alpine_huts_full.csv');
define('CACHE_DIR',      __DIR__ . '/reservation_cache');
define('NOMINATIM_URL',  'https://nominatim.openstreetmap.org/search');
define('USER_AGENT',     'alpine-hut-search/1.0');
define('MAX_DATE_RANGE', 14);    // maximum days in date range

// ---------------------------------------------------------------------------
// Geocoding
// ---------------------------------------------------------------------------
function geocode(string $location): array {
    $url = NOMINATIM_URL . '?' . http_build_query(array(
        'q'      => $location,
        'format' => 'json',
        'limit'  => 1,
    ));
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ));
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
    return array(
        'lat'          => (float)$data[0]['lat'],
        'lon'          => (float)$data[0]['lon'],
        'display_name' => $data[0]['display_name'],
    );
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

    $huts = array();
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

        $reservation_id = isset($col['reservation_id'], $row[$col['reservation_id']])
                          ? trim($row[$col['reservation_id']]) : '';

        $huts[] = array(
            'official_name'        => $name,
            'operating_club'       => isset($col['operating_club'], $row[$col['operating_club']])
                                      ? trim($row[$col['operating_club']]) : '',
            'latitude'             => $lat,
            'longitude'            => $lon,
            'elevation_m'          => $elev,
            'official_website_url' => isset($col['official_website_url'], $row[$col['official_website_url']])
                                      ? trim($row[$col['official_website_url']]) : '',
            'distance_km'          => round($dist, 1),
            'reservation_id'       => $reservation_id,
            'availability'         => array(),
        );
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
// Availability — read from local cache files
// ---------------------------------------------------------------------------

/**
 * Look up availability for a single hut from the local cache.
 * Returns array keyed by 'YYYY-MM-DD' => ['freeBeds', 'hutStatus', 'totalSleepingPlaces']
 * Returns [] if cache file missing or unreadable.
 */
function fetch_availability(string $reservation_id, array $date_range): array {
    $file = CACHE_DIR . '/' . $reservation_id;
    if (!file_exists($file)) {
        return array();
    }

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        return array();
    }

    $date_set = array_flip($date_range);
    $result   = array();
    foreach ($data as $entry) {
        $entry_date = substr($entry['date'] ?? '', 0, 10);
        if (isset($date_set[$entry_date])) {
            $result[$entry_date] = array(
                'freeBeds'            => $entry['freeBeds'] ?? null,
                'hutStatus'           => $entry['hutStatus'] ?? null,
                'totalSleepingPlaces' => $entry['totalSleepingPlaces'] ?? null,
            );
        }
    }
    return $result;
}

/**
 * Fill availability for all huts from cache — instant, no pauses needed.
 */
function fetch_all_availability(array &$huts, array $date_range): void {
    foreach ($huts as &$hut) {
        if ($hut['reservation_id'] === '') continue;
        $hut['availability'] = fetch_availability($hut['reservation_id'], $date_range);
    }
    unset($hut);
}

// ---------------------------------------------------------------------------
// Date range helpers
// ---------------------------------------------------------------------------
function build_date_range(string $start, string $end): array {
    $dates = array();
    $cur   = new DateTime($start);
    $last  = new DateTime($end);
    while ($cur <= $last) {
        $dates[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }
    return $dates;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
function render_form(
    ?string $location,
    ?float  $distance,
    ?float  $min_elevation,
    string  $start_date,
    string  $end_date,
    int     $min_places
): void {
    $loc    = h($location ?? '');
    $dist   = $distance !== null ? (int)$distance : 10;
    $elev   = $min_elevation !== null ? (int)$min_elevation : '';
    $start  = h($start_date);
    $end    = h($end_date);
    $places = $min_places;
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
        <div class="form-group">
          <label for="start_date">Start date</label>
          <input type="date" id="start_date" name="start_date" value="$start" style="width:145px">
        </div>
        <div class="form-group">
          <label for="end_date">End date</label>
          <input type="date" id="end_date" name="end_date" value="$end" style="width:145px">
        </div>
        <div class="form-group">
          <label for="places">Min free places</label>
          <input type="number" id="places" name="places" value="$places"
                 min="1" max="999" step="1" style="width:90px">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <button type="submit">Search</button>
        </div>
      </div>
      <p class="hint">Leave dates empty to skip availability lookup. Max date range: 14 days.</p>
    </form>
    <script>
    document.getElementById('start_date').addEventListener('change', function() {
        var end = document.getElementById('end_date');
        if (!end.value || end.value <= this.value) {
            var parts = this.value.split('-');
            var next = new Date(+parts[0], +parts[1] - 1, +parts[2] + 1);
            var mm = String(next.getMonth() + 1).padStart(2, '0');
            var dd = String(next.getDate()).padStart(2, '0');
            end.value = next.getFullYear() + '-' + mm + '-' + dd;
        }
    });
    </script>
    HTML;
}

// ---------------------------------------------------------------------------
// Results table
// ---------------------------------------------------------------------------
function render_results(
    array   $huts,
    string  $display_name,
    float   $distance_km,
    ?float  $min_elevation,
    array   $date_range,
    int     $min_places
): void {
    $total      = count($huts);
    $dn         = h($display_name);
    $dist       = (int)$distance_km;
    $elev_s     = $min_elevation !== null ? ', min elevation ' . (int)$min_elevation . ' m' : '';
    $with_avail = count($date_range) > 0;

    echo "<div class=\"result-summary\">";
    echo "Found <strong>$total</strong> huts within {$dist} km of <em>$dn</em>$elev_s, sorted by elevation";
    if ($with_avail) {
        $n = count($date_range);
        echo " &mdash; showing availability for <strong>$n</strong> day(s) (from cache)";
    }
    echo "</div>\n";

    $day_labels = array();
    foreach ($date_range as $d) {
        $day_labels[] = date('D d.m', strtotime($d));
    }

    echo '<div style="overflow-x:auto">';
    echo '<table class="results-table">';
    echo '<thead>';

    // First header row
    echo '<tr>';
    echo '<th rowspan="2">#</th>';
    echo '<th rowspan="2" class="sortable" data-col="1" data-type="text" onclick="sortTable(this)">Hut <span class="sort-arrow sort-idle">&#8645;</span></th>';
    echo '<th rowspan="2">Club</th>';
    echo '<th rowspan="2" class="sortable" data-col="3" data-type="num" onclick="sortTable(this)">Distance <span class="sort-arrow">&#9660;</span></th>';
    echo '<th rowspan="2" class="sortable" data-col="4" data-type="num" onclick="sortTable(this)">Elevation <span class="sort-arrow sort-idle">&#8645;</span></th>';
    echo '<th rowspan="2">Map</th>';
    echo '<th rowspan="2">Book</th>';
    if ($with_avail) {
        foreach ($day_labels as $lbl) {
            echo '<th colspan="1" class="day-header">' . h($lbl) . '</th>';
        }
    }
    echo '</tr>';

    // Second header row (sub-columns per day)
    echo '<tr>';
    if ($with_avail) {
        for ($d = 0; $d < count($day_labels); $d++) {
            echo '<th class="sub-hdr">Free beds</th>';
        }
    }
    echo '</tr>';

    echo '</thead>';
    echo '<tbody>';

    foreach ($huts as $i => $hut) {
        $rank    = $i + 1;
        $name    = h($hut['official_name']);
        $club    = h($hut['operating_club'] ?? '');
        $url     = $hut['official_website_url'] ?? '';
        $dist_s  = $hut['distance_km'] . ' km';
        $elev_s2 = $hut['elevation_m'] !== null ? number_format((int)$hut['elevation_m']) . ' m' : '?';
        $name_td = $url !== ''
                   ? '<a href="' . h($url) . '" target="_blank">' . $name . '</a>'
                   : $name;

        $res_id = $hut['reservation_id'];
        $res_td = $res_id !== ''
                  ? '<a href="https://www.hut-reservation.org/reservation/book-hut/' . h($res_id) . '/wizard" target="_blank">Reservation</a>'
                  : '';

        $lat       = $hut['latitude'];
        $lon       = $hut['longitude'];
        $gmaps_url = 'https://www.google.com/maps?q=' . $lat . ',' . $lon;
        $osm_url   = 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '&zoom=15';
        $geo_url   = 'geo:' . $lat . ',' . $lon;
        $map_td    = '<a href="' . h($gmaps_url) . '" target="_blank">G-Maps</a>'
                   . ' <a href="' . h($osm_url) . '" target="_blank">OSM</a>'
                   . ' <a href="' . h($geo_url) . '">GEO</a>';

        echo "<tr>";
        echo "<td class=\"rank\">$rank</td>";
        echo "<td class=\"hut-name\">$name_td</td>";
        echo "<td>$club</td>";
        echo "<td class=\"num\">$dist_s</td>";
        echo "<td class=\"num elev\">$elev_s2</td>";
        echo "<td class=\"map-links\">$map_td</td>";
        echo "<td class=\"reservation\">$res_td</td>";

        if ($with_avail) {
            $avail   = $hut['availability'];
            $has_res = $hut['reservation_id'] !== '';

            foreach ($date_range as $date) {
                if (!$has_res) {
                    echo '<td class="avail-none">—</td>';
                    continue;
                }

                if (!isset($avail[$date])) {
                    echo '<td class="avail-unknown">?</td>';
                    continue;
                }

                $day     = $avail[$date];
                $free    = $day['freeBeds'];
                $total_b = $day['totalSleepingPlaces'];
                $status  = $day['hutStatus'] ?? '';

                $beds_s = $free !== null ? (string)$free : '?';
                if ($total_b !== null && $free !== null) {
                    $beds_s .= ' / ' . $total_b;
                }

                $is_open = ($status === 'OPEN' || $status === 'SERVICED');
                if ($free !== null && $free >= $min_places && $is_open) {
                    $beds_cls = 'avail-open';
                } elseif ($free === 0 || $status === 'FULL' || ($free !== null && $free < $min_places)) {
                    $beds_cls = 'avail-full';
                } else {
                    $beds_cls = '';
                }

                if ($status === 'CLOSED') {
                    echo '<td class="num avail-closed">CLOSED</td>';
                } else {
                    echo "<td class=\"num $beds_cls\">$beds_s</td>";
                }
            }
        }

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
  <title>Alpine Hut Finder (Cached)</title>
  <style>
    :root {
      --border:  #dee2e6;
      --hdr-bg:  #343a40; --hdr-fg: #fff;
      --sub-bg:  #495057;
      --green:   #155724; --green-bg:  #d4edda;
      --red:     #721c24; --red-bg:    #f8d7da;
      --yellow:  #856404; --yellow-bg: #fff3cd;
    }
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
    input[type=text], input[type=number], input[type=date] {
      padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: .95rem; }
    button[type=submit] { padding: 8px 22px; background: #0d6efd; color: #fff; border: none;
                          border-radius: 4px; cursor: pointer; font-size: .95rem; font-weight: 600; }
    button[type=submit]:hover { background: #0b5ed7; }
    .hint { margin: .6rem 0 0; font-size: .78rem; color: #6c757d; }

    .error-box { background: var(--red-bg); color: var(--red); border: 1px solid #f5c6cb;
                 border-radius: 6px; padding: .75rem 1rem; margin-bottom: 1rem; }
    .warn-box  { background: var(--yellow-bg); color: var(--yellow); border: 1px solid #ffeeba;
                 border-radius: 6px; padding: .75rem 1rem; margin-bottom: 1rem; }

    .result-summary { background: #fff; border: 1px solid var(--border); border-radius: 6px;
                      padding: .6rem 1rem; margin-bottom: .75rem; font-size: .9rem; }

    .results-table { border-collapse: collapse; width: 100%; font-size: .82rem;
                     background: #fff; border-radius: 6px; overflow: hidden; }
    .results-table th { background: var(--hdr-bg); color: var(--hdr-fg);
                        padding: 6px 8px; text-align: center; white-space: nowrap; }
    .results-table th.day-header { background: var(--sub-bg); }
    .results-table th.sub-hdr    { background: #6c757d; font-size: .75rem; font-weight: 500; }
    .results-table th.sortable   { cursor: pointer; user-select: none; }
    .results-table th.sortable:hover { background: #23272b; }
    .sort-arrow      { font-size: .7rem; opacity: .7; }
    .sort-arrow.sort-idle { opacity: .35; }
    .results-table td { border: 1px solid var(--border); padding: 5px 8px; vertical-align: middle; }
    .results-table tr:nth-child(even) td { background: #f8f9fa; }
    .results-table tr:hover td { background: #e9f0ff; }
    .results-table td.rank     { text-align: right; font-weight: 700; color: #6c757d; width: 3rem; }
    .results-table td.hut-name { font-weight: 600; }
    .results-table td.hut-name a { color: #0d6efd; text-decoration: none; }
    .results-table td.hut-name a:hover { text-decoration: underline; }
    .results-table td.num      { text-align: right; white-space: nowrap; }
    .results-table td.elev     { font-weight: 600; color: #495057; }
    .results-table td.avail-status  { text-align: center; font-size: .78rem; white-space: nowrap; }
    .results-table td.avail-none    { text-align: center; color: #adb5bd; }
    .results-table td.avail-unknown { text-align: center; color: #adb5bd; font-style: italic; }
    .results-table td.map-links    { text-align: center; white-space: nowrap; }
    .results-table td.map-links a  { color: #0d6efd; text-decoration: none; font-size: .8rem; }
    .results-table td.map-links a:hover { text-decoration: underline; }
    .results-table td.reservation  { text-align: center; white-space: nowrap; }
    .results-table td.reservation a { color: #0d6efd; text-decoration: none; font-size: .8rem; }
    .results-table td.reservation a:hover { text-decoration: underline; }

    .avail-open   { background: var(--green-bg)  !important; color: var(--green);  font-weight: 700; }
    .avail-closed { background: var(--red-bg)    !important; color: var(--red);    font-weight: 700; }
    .avail-full   { background: var(--yellow-bg) !important; color: var(--yellow); font-weight: 700; }

    footer { margin-top: 1.5rem; text-align: center; font-size: .78rem; color: #6c757d; }
  </style>
</head>
<body>
<header>
  <h1>Alpine Hut Finder</h1>
  <p>List huts near a location, sorted by elevation — availability served from local cache</p>
</header>

<?php

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------
$location      = isset($_GET['location'])      ? trim($_GET['location'])      : null;
$distance_km   = isset($_GET['distance'])      && $_GET['distance']      !== '' ? (float)$_GET['distance']      : 10.0;
$min_elevation = isset($_GET['min_elevation']) && $_GET['min_elevation'] !== '' ? (float)$_GET['min_elevation'] : null;
$start_input   = isset($_GET['start_date'])    ? trim($_GET['start_date'])    : '';
$end_input     = isset($_GET['end_date'])      ? trim($_GET['end_date'])      : '';
$min_places    = isset($_GET['places'])        && $_GET['places'] !== '' ? max(1, (int)$_GET['places']) : 1;

// Validate and build date range
$date_range  = array();
$date_errors = array();

if ($start_input !== '' || $end_input !== '') {
    $start_dt = DateTime::createFromFormat('Y-m-d', $start_input);
    $end_dt   = DateTime::createFromFormat('Y-m-d', $end_input);

    if (!$start_dt || $start_dt->format('Y-m-d') !== $start_input) {
        $date_errors[] = "Invalid start date.";
    }
    if (!$end_dt || $end_dt->format('Y-m-d') !== $end_input) {
        $date_errors[] = "Invalid end date.";
    }
    if (empty($date_errors)) {
        if ($end_dt < $start_dt) {
            $date_errors[] = "End date must be on or after start date.";
        } else {
            $diff_days = (int)$start_dt->diff($end_dt)->days + 1;
            if ($diff_days > MAX_DATE_RANGE) {
                $date_errors[] = "Date range is too large ($diff_days days). Maximum is " . MAX_DATE_RANGE . " days.";
            } else {
                $date_range = build_date_range($start_input, $end_input);
            }
        }
    }
}

render_form($location, $distance_km, $min_elevation, $start_input, $end_input, $min_places);

foreach ($date_errors as $de) {
    echo '<div class="warn-box">' . h($de) . '</div>';
}

if ($location !== null && $location !== '') {

    if ($distance_km <= 0 || $distance_km > 500) {
        echo '<div class="error-box">Distance must be between 1 and 500 km.</div>';
    } elseif (empty($date_errors)) {

        try {
            $geo  = geocode($location);
            $huts = load_and_filter_huts(CSV_PATH, $geo['lat'], $geo['lon'], $distance_km, $min_elevation);

            if (empty($huts)) {
                $elev_hint = $min_elevation !== null ? " above {$min_elevation} m elevation" : '';
                echo '<div class="error-box">No huts found within ' . (int)$distance_km . ' km of &ldquo;'
                    . h($location) . "&rdquo;$elev_hint.</div>";
            } else {
                if (!empty($date_range)) {
                    fetch_all_availability($huts, $date_range);
                }
                render_results($huts, $geo['display_name'], $distance_km, $min_elevation, $date_range, $min_places);
            }
        } catch (RuntimeException $e) {
            echo '<div class="error-box">' . h($e->getMessage()) . '</div>';
        }
    }
}
?>

<script>
var _sortCol = 3, _sortAsc = true;

function sortTable(th) {
    var col  = parseInt(th.dataset.col);
    var type = th.dataset.type;

    if (_sortCol === col) {
        _sortAsc = !_sortAsc;
    } else {
        _sortCol = col;
        _sortAsc = true;
    }

    // Update arrows
    document.querySelectorAll('th.sortable').forEach(function(el) {
        var arrow = el.querySelector('.sort-arrow');
        arrow.innerHTML = '&#8645;';
        arrow.classList.add('sort-idle');
    });
    var active = th.querySelector('.sort-arrow');
    active.innerHTML = _sortAsc ? '&#9660;' : '&#9650;';
    active.classList.remove('sort-idle');

    var tbody = document.querySelector('.results-table tbody');
    var rows  = Array.prototype.slice.call(tbody.querySelectorAll('tr'));

    rows.sort(function(a, b) {
        var av = a.cells[col] ? a.cells[col].textContent.trim() : '';
        var bv = b.cells[col] ? b.cells[col].textContent.trim() : '';

        if (type === 'num') {
            // Extract leading number, treat '?' / missing as -Infinity so they sort last
            var an = parseFloat(av.replace(/[^\d.\-]/g, ''));
            var bn = parseFloat(bv.replace(/[^\d.\-]/g, ''));
            an = isNaN(an) ? -Infinity : an;
            bn = isNaN(bn) ? -Infinity : bn;
            return _sortAsc ? an - bn : bn - an;
        } else {
            return _sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
        }
    });

    rows.forEach(function(r) { tbody.appendChild(r); });

    // Re-number rank column
    rows.forEach(function(r, i) {
        if (r.cells[0]) r.cells[0].textContent = i + 1;
    });
}
</script>

<footer>
  Data: <a href="https://www.sac-cas.ch" target="_blank">SAC</a> &amp;
  <a href="https://www.openstreetmap.org" target="_blank">OpenStreetMap</a> &mdash;
  Geocoding: <a href="https://nominatim.openstreetmap.org" target="_blank">Nominatim</a> &mdash;
  Availability: <a href="https://www.hut-reservation.org" target="_blank">hut-reservation.org</a>
</footer>
</body>
</html>
