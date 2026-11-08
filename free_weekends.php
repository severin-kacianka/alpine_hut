<?php
// free_weekends.php — For every weekend of the coming year, list huts within a radius that have
// at least N free beds on the Friday and/or Saturday night, grouped by how well they fit.
// Reads reservation_cache/<id> for availability, populated by update_cache.php.

setlocale(LC_ALL, 'C');

define('CSV_PATH',      __DIR__ . '/data/alpine_huts_full.csv');
define('CACHE_DIR',     __DIR__ . '/reservation_cache');
define('CACHE_TTL',     86400 / 2);   // 12 h in seconds, matches update_cache.php
define('BOOKING_BASE',  'https://www.hut-reservation.org/reservation/book-hut/');
define('GEO_CACHE',     __DIR__ . '/reservation_cache/geocode_cache.json');
define('NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search');
define('USER_AGENT',    'alpine-hut-search/1.0');
define('HORIZON_DAYS',  365);         // look this many days ahead for weekends

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function format_age(int $age_s): string {
    if ($age_s < 3600)  return (int)($age_s / 60) . 'm ago';
    if ($age_s < 86400) return round($age_s / 3600, 1) . 'h ago';
    return round($age_s / 86400, 1) . 'd ago';
}

// ---------------------------------------------------------------------------
// Geocoding + distance (copied from where2go.php)
// ---------------------------------------------------------------------------
function parse_latlon(string $input): ?array {
    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[,\s]\s*(-?\d+(?:\.\d+)?)\s*$/', $input, $m)) {
        $lat = (float)$m[1];
        $lon = (float)$m[2];
        if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
            return ['lat' => $lat, 'lon' => $lon, 'display_name' => $input];
        }
    }
    return null;
}

function geocode(string $location): array {
    $key   = strtolower(trim($location));
    $cache = array();
    if (file_exists(GEO_CACHE)) {
        $cache = json_decode(file_get_contents(GEO_CACHE), true) ?: array();
    }
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $url = NOMINATIM_URL . '?' . http_build_query(['q' => $location, 'format' => 'json', 'limit' => 1]);
    $ch  = curl_init($url);
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
        throw new RuntimeException("No results found for location: " . $location);
    }
    $result = ['lat' => (float)$data[0]['lat'], 'lon' => (float)$data[0]['lon'], 'display_name' => $data[0]['display_name']];
    $cache[$key] = $result;
    $tmp = GEO_CACHE . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($tmp, GEO_CACHE);
    return $result;
}

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R    = 6371.0;
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlam = deg2rad($lon2 - $lon1);
    $a    = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlam / 2) ** 2;
    return $R * 2 * asin(sqrt($a));
}

// ---------------------------------------------------------------------------
// Data loading
// ---------------------------------------------------------------------------

/** Load huts with a reservation_id within $radius_km of the centre, nearest first. */
function load_huts_in_radius(float $center_lat, float $center_lon, float $radius_km): array {
    $fh = fopen(CSV_PATH, 'r');
    if ($fh === false) {
        throw new RuntimeException('Cannot open CSV: ' . CSV_PATH);
    }
    $header = fgetcsv($fh);
    if ($header === false) { fclose($fh); throw new RuntimeException('CSV is empty'); }
    $col = array_flip($header);

    $huts = [];
    while (($row = fgetcsv($fh)) !== false) {
        $rid = isset($col['reservation_id'], $row[$col['reservation_id']])
             ? trim($row[$col['reservation_id']]) : '';
        if ($rid === '') continue;

        $name = trim($row[$col['official_name']] ?? '');
        $lat  = trim($row[$col['latitude']]      ?? '');
        $lon  = trim($row[$col['longitude']]     ?? '');
        if ($name === '' || $lat === '' || $lon === '') continue;

        $dist = haversine_km($center_lat, $center_lon, (float)$lat, (float)$lon);
        if ($dist > $radius_km) continue;

        $elev_raw = trim($row[$col['elevation_m']] ?? '');
        $elev     = ($elev_raw !== '' && strtolower($elev_raw) !== 'nan') ? (float)$elev_raw : null;

        $huts[] = [
            'official_name'        => $name,
            'operating_club'       => trim($row[$col['operating_club']]       ?? ''),
            'latitude'             => (float)$lat,
            'longitude'            => (float)$lon,
            'elevation_m'          => $elev,
            'official_website_url' => trim($row[$col['official_website_url']] ?? ''),
            'reservation_id'       => $rid,
            'distance_km'          => round($dist, 1),
        ];
    }
    fclose($fh);

    usort($huts, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    return $huts;
}

/**
 * Read a hut's cached availability as date => ['free', 'total', 'status'] plus the cache age.
 * Returns null if no usable cache file exists.
 */
function load_nights(string $rid): ?array {
    $file = CACHE_DIR . '/' . $rid;
    if (!file_exists($file)) return null;

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return null;

    $nights = [];
    foreach ($data as $entry) {
        $d = substr($entry['date'] ?? '', 0, 10);
        if ($d === '') continue;
        $nights[$d] = [
            'free'   => isset($entry['freeBeds'])            ? (int)$entry['freeBeds']            : null,
            'total'  => isset($entry['totalSleepingPlaces']) ? (int)$entry['totalSleepingPlaces'] : null,
            'status' => $entry['hutStatus'] ?? '',
        ];
    }
    return ['cache_age' => time() - filemtime($file), 'nights' => $nights];
}

/** Classify one night: 'ok' (>= n free), 'almost' (exactly n-1 free, n > 1), 'no', or null (no data). */
function classify_night(?array $night, int $n): ?string {
    if ($night === null) return null;
    if ($night['status'] === 'CLOSED' || $night['free'] === null) return 'no';
    if ($night['free'] >= $n) return 'ok';
    if ($n > 1 && $night['free'] === $n - 1) return 'almost';
    return 'no';
}

/** List the Friday dates of all weekends from now until HORIZON_DAYS ahead. */
function upcoming_fridays(): array {
    $dow = (int)date('N');   // 1=Mon .. 7=Sun

    // On a Saturday the current weekend still has its Saturday night ahead
    $cur  = new DateTime($dow === 6 ? 'yesterday' : ($dow === 5 ? 'today' : 'next friday'));
    $last = new DateTime('+' . HORIZON_DAYS . ' days');
    $fridays = [];
    while ($cur <= $last) {
        $fridays[] = $cur->format('Y-m-d');
        $cur->modify('+7 days');
    }
    return $fridays;
}

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------
$location_input = isset($_GET['location']) ? trim($_GET['location']) : '';
$radius_km      = isset($_GET['distance']) && $_GET['distance'] !== '' ? (float)$_GET['distance'] : 20.0;
$people         = isset($_GET['people'])   && $_GET['people']   !== '' ? max(1, (int)$_GET['people']) : 2;
$submitted      = $location_input !== '';

$error     = null;
$huts      = [];
$weekends  = [];   // fri => ['sat' => date, 'groups' => ['both' => [...], 'one' => [...], 'almost' => [...]]]
$geo       = null;

if ($submitted) {
    if ($radius_km < 1 || $radius_km > 500) {
        $error = 'Radius must be between 1 and 500 km.';
    } else {
        try {
            $geo  = parse_latlon($location_input) ?? geocode($location_input);
            $huts = load_huts_in_radius($geo['lat'], $geo['lon'], $radius_km);
            if (empty($huts)) {
                $error = 'No huts with a reservation ID found within ' . (int)$radius_km . ' km of "' . $location_input . '".';
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

if ($submitted && $error === null) {
    foreach ($huts as &$hut) {
        $hut['cache'] = load_nights($hut['reservation_id']);
    }
    unset($hut);

    foreach (upcoming_fridays() as $fri) {
        $sat      = date('Y-m-d', strtotime($fri . ' +1 day'));
        $groups   = ['both' => [], 'sat' => [], 'fri' => [], 'almost' => []];
        $has_data = false;

        foreach ($huts as $hut) {
            if ($hut['cache'] === null) continue;
            $fri_night = $hut['cache']['nights'][$fri] ?? null;
            $sat_night = $hut['cache']['nights'][$sat] ?? null;

            // Past Friday nights (Saturday "today") count as missing data for that night
            if ($fri < date('Y-m-d')) $fri_night = null;
            if ($fri_night === null && $sat_night === null) continue;
            $has_data = true;

            $c_fri = classify_night($fri_night, $people);
            $c_sat = classify_night($sat_night, $people);
            $entry = ['hut' => $hut, 'fri' => $fri_night, 'sat' => $sat_night, 'c_fri' => $c_fri, 'c_sat' => $c_sat];

            if ($c_fri === 'ok' && $c_sat === 'ok')         $groups['both'][]   = $entry;
            elseif ($c_sat === 'ok')                         $groups['sat'][]    = $entry;
            elseif ($c_fri === 'ok')                         $groups['fri'][]    = $entry;
            elseif ($c_fri === 'almost' || $c_sat === 'almost') $groups['almost'][] = $entry;
        }

        // Weekends beyond every hut's cached horizon are left out entirely
        if (!$has_data) continue;
        $weekends[$fri] = [
            'sat'    => $sat,
            'groups' => [
                'both'   => $groups['both'],
                'one'    => array_merge($groups['sat'], $groups['fri']),
                'almost' => $groups['almost'],
            ],
        ];
    }
}

// ---------------------------------------------------------------------------
// Rendering helpers
// ---------------------------------------------------------------------------

/** Render one night cell: "free / total", coloured by classification. */
function render_night_cell(?array $night, ?string $cls): string {
    if ($night === null) return '<td class="night avail-unknown">?</td>';
    if ($night['status'] === 'CLOSED') return '<td class="night avail-short">CLOSED</td>';

    $txt = $night['free'] !== null ? (string)$night['free'] : '?';
    if ($night['free'] !== null && $night['total'] !== null) $txt .= ' / ' . $night['total'];
    if ($night['status'] === 'UNSERVICED') $txt .= ' <span class="unserviced" title="Hut unserviced (winter room)">unserv.</span>';

    $css = $cls === 'ok' ? 'avail-open' : ($cls === 'almost' ? 'avail-almost' : 'avail-short');
    return '<td class="night ' . $css . '">' . $txt . '</td>';
}

/** Render one section: a table with a separator row per weekend, followed by that weekend's huts. */
function render_section(string $key, string $title, string $intro, array $weekends): void {
    echo '<h2 class="section-title">' . h($title) . '</h2>';
    echo '<p class="section-intro">' . h($intro) . '</p>';

    $total = 0;
    foreach ($weekends as $wk) $total += count($wk['groups'][$key]);
    if ($total === 0) {
        echo '<div class="warn-box">No huts in this group on any weekend.</div>';
        return;
    }

    echo '<div style="overflow-x:auto">';
    echo '<table class="results-table"><thead><tr>'
       . '<th>#</th><th>Hut</th><th>Club</th><th>Distance</th><th>Elevation</th>'
       . '<th>Fri night</th><th>Sat night</th><th>Map</th><th>Book</th><th>Cached</th>'
       . '</tr></thead><tbody>';

    foreach ($weekends as $fri => $wk) {
        $rows = $wk['groups'][$key];
        if (empty($rows)) continue;

        $sun   = date('Y-m-d', strtotime($fri . ' +2 days'));
        $label = date('D d.m', strtotime($fri)) . ' – ' . date('D d.m.Y', strtotime($sun))
               . ' · ' . count($rows) . ' hut' . (count($rows) === 1 ? '' : 's');
        echo '<tr class="weekend-row" id="' . h($key . '-' . $fri) . '"><th colspan="10">'
           . h($label) . ' <a class="to-top" href="#calendar">↑ calendar</a></th></tr>';

        foreach ($rows as $i => $e) {
            $hut       = $e['hut'];
            $name      = h($hut['official_name']);
            $url       = $hut['official_website_url'];
            $name_td   = $url !== '' ? '<a href="' . h($url) . '" target="_blank">' . $name . '</a>' : $name;
            $elev_s    = $hut['elevation_m'] !== null ? number_format((int)$hut['elevation_m']) . ' m' : '?';
            $lat       = $hut['latitude'];
            $lon       = $hut['longitude'];
            $map_td    = '<a href="' . h('https://www.google.com/maps?q=' . $lat . ',' . $lon) . '" target="_blank">G-Maps</a>'
                       . ' <a href="' . h('https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '&zoom=15') . '" target="_blank">OSM</a>'
                       . ' <a href="' . h('geo:' . $lat . ',' . $lon) . '">GEO</a>';
            $book_td   = '<a href="' . h(BOOKING_BASE . $hut['reservation_id'] . '/wizard') . '" target="_blank">Reservation</a>';
            $age       = (int)$hut['cache']['cache_age'];
            $age_td    = h(format_age($age)) . ($age > CACHE_TTL ? ' <span class="stale">(stale)</span>' : '');

            echo '<tr>';
            echo '<td class="rank">' . ($i + 1) . '</td>';
            echo '<td class="hut-name">' . $name_td . '</td>';
            echo '<td>' . h($hut['operating_club']) . '</td>';
            echo '<td class="num">' . h($hut['distance_km'] . ' km') . '</td>';
            echo '<td class="num elev">' . h($elev_s) . '</td>';
            echo render_night_cell($e['fri'], $e['c_fri']);
            echo render_night_cell($e['sat'], $e['c_sat']);
            echo '<td class="map-links">' . $map_td . '</td>';
            echo '<td class="reservation">' . $book_td . '</td>';
            echo '<td class="cache-age">' . $age_td . '</td>';
            echo "</tr>\n";
        }
    }
    echo '</tbody></table></div>';
}

/** Render the weekend calendar: one row per month, one chip per weekend linking to section 1. */
function render_calendar(array $weekends): void {
    $months = [];
    foreach ($weekends as $fri => $wk) {
        $months[date('M Y', strtotime($fri))][$fri] = $wk;
    }

    echo '<div class="calendar" id="calendar">';
    echo '<div class="calendar-title">Weekends with both nights free &mdash; click to jump</div>';
    foreach ($months as $month => $wks) {
        echo '<div class="cal-month"><span class="cal-label">' . h($month) . '</span>';
        foreach ($wks as $fri => $wk) {
            $n_both   = count($wk['groups']['both']);
            $tooltip  = 'both: ' . $n_both . ' · one night: ' . count($wk['groups']['one'])
                      . ' · almost: ' . count($wk['groups']['almost']);
            $chip_txt = h(date('D d.m', strtotime($fri))) . ' <b>' . $n_both . '</b>';
            if ($n_both > 0) {
                echo '<a class="cal-chip cal-free" href="#both-' . h($fri) . '" title="' . h($tooltip) . '">' . $chip_txt . '</a>';
            } else {
                echo '<span class="cal-chip cal-none" title="' . h($tooltip) . '">' . $chip_txt . '</span>';
            }
        }
        echo '</div>';
    }
    echo '</div>';
}

require_once __DIR__ . '/layout.php';
render_page_head(
    'Free Weekends — Alpine Hut Weather Planner',
    'Free Weekends',
    'Find huts near a location with enough free beds on Friday and Saturday nights, weekend by weekend',
    'free_weekends'
);
?>
<style>
    .results-table { border-collapse: collapse; width: 100%; font-size: .82rem;
                     background: #fff; border-radius: 6px; overflow: hidden; }
    .results-table th { background: var(--hdr-bg); color: var(--hdr-fg);
                        padding: 6px 8px; text-align: center; white-space: nowrap; }
    .results-table td { border: 1px solid var(--border); padding: 5px 8px; vertical-align: middle; }
    .results-table tr:nth-child(even) td { background: #f8f9fa; }
    .results-table tr:hover td { background: #e9f0ff; }
    .results-table td.rank      { text-align: right; font-weight: 700; color: #6c757d; width: 3rem; }
    .results-table td.hut-name  { font-weight: 600; }
    .results-table td.hut-name a { color: #0d6efd; text-decoration: none; }
    .results-table td.hut-name a:hover { text-decoration: underline; }
    .results-table td.num       { text-align: right; white-space: nowrap; }
    .results-table td.elev      { font-weight: 600; color: #495057; }
    .results-table td.night     { text-align: center; white-space: nowrap; min-width: 6rem; }
    .results-table td.cache-age { text-align: right; color: #adb5bd; font-size: .75rem; white-space: nowrap; }
    .results-table td.map-links { text-align: center; white-space: nowrap; }
    .results-table td.map-links a { color: #0d6efd; text-decoration: none; font-size: .8rem; }
    .results-table td.map-links a:hover { text-decoration: underline; }
    .results-table td.reservation { text-align: center; white-space: nowrap; }
    .results-table td.reservation a { color: #0d6efd; text-decoration: none; font-size: .8rem; }
    .results-table td.reservation a:hover { text-decoration: underline; }
    .results-table td.avail-unknown { color: #adb5bd; font-style: italic; }
    .results-table tr.weekend-row th { background: var(--sub-bg); text-align: left; scroll-margin-top: 1rem; }
    .to-top { float: right; color: #ced4da; font-size: .75rem; font-weight: 400; text-decoration: none; }
    .to-top:hover { color: #fff; }

    .avail-open   { background: var(--green-bg)  !important; color: var(--green);  font-weight: 700; }
    .avail-almost { background: #ffe5cc          !important; color: #8a4500;      font-weight: 700; }
    .avail-short  { background: var(--red-bg)    !important; color: var(--red);    font-weight: 700; }
    .unserviced   { font-size: .68rem; font-weight: 400; color: #6c757d; }
    .stale        { color: var(--yellow); font-weight: 600; }

    .section-title { font-size: 1.1rem; margin: 1.5rem 0 .2rem; }
    .section-intro { font-size: .82rem; color: #6c757d; margin: 0 0 .6rem; }

    .calendar       { background: #fff; border: 1px solid var(--border); border-radius: 6px;
                      padding: .75rem 1rem; margin-bottom: 1rem; }
    .calendar-title { font-size: .85rem; font-weight: 600; color: #495057; margin-bottom: .5rem; }
    .cal-month      { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; margin-bottom: .35rem; }
    .cal-label      { width: 5.5rem; font-size: .8rem; font-weight: 600; color: #495057; }
    .cal-chip       { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: .78rem;
                      white-space: nowrap; text-decoration: none; }
    .cal-free       { background: var(--green-bg); color: var(--green); border: 1px solid #b7dfc2; }
    .cal-free:hover { background: #c3e6cb; }
    .cal-none       { background: #e9ecef; color: #adb5bd; border: 1px solid #dee2e6; }
</style>

<?php
// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
echo '<div class="search-form">';
echo '<h2>Search</h2>';
echo '<form method="get" action="">';
echo '<div class="form-row">';
echo '<div class="form-group">';
echo '<label for="location">Location or Lat/Lon</label>';
echo '<input type="text" id="location" name="location" value="' . h($location_input) . '" placeholder="e.g. Innsbruck or 47.26,11.39" required>';
echo '</div>';
echo '<div class="form-group">';
echo '<label for="distance">Radius (km)</label>';
echo '<input type="number" id="distance" name="distance" min="1" max="500" step="1" value="' . h((string)(int)$radius_km) . '">';
echo '</div>';
echo '<div class="form-group">';
echo '<label for="people">Free beds needed</label>';
echo '<input type="number" id="people" name="people" min="1" step="1" value="' . h((string)$people) . '">';
echo '</div>';
echo '<div class="form-group">';
echo '<button type="submit">Search</button>';
echo '</div>';
echo '</div>';
echo '<p class="hint">Checks the Friday and Saturday nights of every weekend in the next year that has cached data. '
   . 'Availability is read from local cache.</p>';
echo '</form>';
echo '</div>';

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------
if ($error !== null) {
    echo '<div class="error-box">' . h($error) . '</div>';
} elseif ($submitted) {
    $no_cache = count(array_filter($huts, fn($hut) => $hut['cache'] === null));
    echo '<div class="result-summary">';
    echo '<strong>' . count($huts) . '</strong> hut(s) with a reservation ID within <strong>' . (int)$radius_km
       . ' km</strong> of <em>' . h($geo['display_name']) . '</em>, needing <strong>' . $people
       . '</strong> free bed(s) &mdash; <strong>' . count($weekends) . '</strong> weekend(s) with cached data';
    if ($no_cache > 0) {
        echo ' <span style="color:#856404">(' . $no_cache . ' hut(s) without cached availability; run <code>update_cache.php</code>)</span>';
    }
    echo '</div>';

    if (empty($weekends)) {
        echo '<div class="warn-box">No cached availability for any upcoming weekend. Run <code>update_cache.php</code> first.</div>';
    } else {
        render_calendar($weekends);
        render_section('both', 'Both nights free',
            "At least $people free bed(s) on Friday and Saturday night.", $weekends);
        render_section('one', 'One night free',
            "At least $people free bed(s) on only one night: Saturday-only huts first, then Friday-only.", $weekends);
        if ($people > 1) {
            render_section('almost', 'Almost',
                'Neither night has enough beds, but at least one night is only 1 bed short (' . ($people - 1) . ' free).', $weekends);
        }
    }
}
?>

<?php render_page_foot([
    'Geocoding: <a href="https://nominatim.openstreetmap.org" target="_blank">Nominatim</a>',
    'Availability: <a href="https://www.hut-reservation.org" target="_blank">hut-reservation.org</a>',
]); ?>
