<?php
// where2go.php — Find huts with enough free beds, ranked by 7-day weather score.
// Reads reservation_cache/<id> for availability and forecasts/<id> for weather.

setlocale(LC_ALL, 'C');

define('CSV_PATH',      __DIR__ . '/data/alpine_huts_full.csv');
define('CACHE_DIR',     __DIR__ . '/reservation_cache');
define('FORECAST_DIR',  __DIR__ . '/forecasts');
define('BOOKING_BASE',  'https://www.hut-reservation.org/reservation/book-hut/');
define('GEO_CACHE',     __DIR__ . '/reservation_cache/geocode_cache.json');
define('NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search');
define('USER_AGENT',    'alpine-hut-search/1.0');

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
// Scoring — sunshine (40%) + precipitation (40%) + wind (20%).
// sunshine_duration: seconds/day, max ~43200 s (12 h).
// ---------------------------------------------------------------------------
function score_day(int $i, array $daily): float {
    $get = function(string $field) use ($i, $daily): ?float {
        $vals = $daily[$field] ?? [];
        return isset($vals[$i]) && $vals[$i] !== null ? (float)$vals[$i] : null;
    };

    $sun_s  = $get('sunshine_duration');  // seconds, 0–43200
    $precip = $get('precipitation_sum');  // mm
    $wind   = $get('windspeed_10m_max');  // km/h

    // 0 s → 0 pts, 43200 s → 100 pts, linear
    $sun_score    = $sun_s  !== null ? max(0.0, min(100.0, $sun_s / 432.0))           : 50.0;
    // 0 mm → 100 pts, ≥20 mm → 0 pts, linear
    $precip_score = $precip !== null ? max(0.0, 100.0 - ($precip / 20.0) * 100.0)    : 50.0;
    // ≤20 km/h → 100 pts, ≥80 km/h → 0 pts, linear
    $wind_score   = $wind   !== null ? max(0.0, min(100.0, (80.0 - $wind) / 60.0 * 100.0)) : 50.0;

    return 0.4 * $sun_score + 0.4 * $precip_score + 0.2 * $wind_score;
}

// Equal weights across all 7 days.
function score_forecast(array $daily): float {
    $days = count($daily['time'] ?? []);
    if ($days === 0) return 0.0;
    $total = 0.0;
    for ($i = 0; $i < $days; $i++) {
        $total += score_day($i, $daily);
    }
    return $total / $days;
}

// ---------------------------------------------------------------------------
// Geocoding + distance (copied from hut_search.php)
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
        throw new RuntimeException("No results found for location: " . htmlspecialchars($location, ENT_QUOTES, 'UTF-8'));
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

/** Load huts that have a reservation_id from the CSV. */
function load_huts_with_reservation(): array {
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
        ];
    }
    fclose($fh);
    return $huts;
}

/**
 * Return availability info for the next $days days from today, or null if no
 * cache data exists at all.
 *
 * Returned array keys:
 *   min_free   — minimum free beds seen across open days (0 if any day is FULL)
 *   max_free   — maximum free beds seen (useful for "too full" display)
 *   is_open    — true if at least one day is not CLOSED
 *   cache_age  — seconds since the cache file was written
 */
function min_free_beds(string $rid, int $days): ?array {
    $file = CACHE_DIR . '/' . $rid;
    if (!file_exists($file)) return null;

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return null;

    $today     = date('Y-m-d');
    $cutoff    = date('Y-m-d', strtotime("+{$days} days"));
    $cache_age = time() - filemtime($file);

    $min_beds = PHP_INT_MAX;
    $max_beds = 0;
    $found    = false;
    $is_open  = false;
    $by_day   = [];   // date => ['free' => int|null, 'status' => string]

    foreach ($data as $entry) {
        $d = substr($entry['date'] ?? '', 0, 10);
        if ($d < $today || $d > $cutoff) continue;

        $status = $entry['hutStatus'] ?? '';
        $free   = $entry['freeBeds']  ?? null;

        $by_day[$d] = ['free' => $free, 'status' => $status];

        if ($status === 'CLOSED') continue;
        $is_open = true;

        if ($status === 'FULL' || $free === 0) {
            $found    = true;
            $min_beds = 0;
            continue;
        }

        if ($free !== null) {
            $found    = true;
            $min_beds = min($min_beds, (int)$free);
            $max_beds = max($max_beds, (int)$free);
        }
    }

    if (!$found && !$is_open) return null;

    return [
        'min_free'  => $found ? (int)$min_beds : 0,
        'max_free'  => $max_beds,
        'is_open'   => $is_open,
        'cache_age' => $cache_age,
        'by_day'    => $by_day,
    ];
}

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------
$min_beds_input = isset($_GET['min_beds']) && $_GET['min_beds'] !== ''
                ? max(1, (int)$_GET['min_beds']) : null;
$location_input = isset($_GET['location']) ? trim($_GET['location']) : '';
$radius_km      = isset($_GET['radius']) && $_GET['radius'] !== ''
                ? max(1.0, (float)$_GET['radius']) : 50.0;
$use_location   = ($location_input !== '');

// All 7 available dates (today + 6)
$all_dates = [];
for ($i = 0; $i < 7; $i++) {
    $all_dates[] = date('Y-m-d', strtotime("+{$i} days"));
}

// Selected dates: from checkboxes when form was submitted, else all dates
$submitted = isset($_GET['min_beds']);   // form has been submitted at least once
if ($submitted && isset($_GET['days']) && is_array($_GET['days'])) {
    $date_list = array_values(array_intersect($all_dates, $_GET['days']));
} else {
    $date_list = $all_dates;   // default: all 7
}
if (empty($date_list)) {
    $date_list = $all_dates;   // guard: never filter on zero days
}

$results   = [];
$too_full  = [];
$error     = null;
$stats     = ['total' => 0, 'with_forecast' => 0, 'passed_beds' => 0];

if ($min_beds_input !== null) {
    try {
        // Geocode location if provided
        $center_lat  = null;
        $center_lon  = null;
        $center_name = '';
        if ($use_location) {
            $geo = parse_latlon($location_input) ?? geocode($location_input);
            $center_lat  = $geo['lat'];
            $center_lon  = $geo['lon'];
            $center_name = $geo['display_name'];
        }

        $huts = load_huts_with_reservation();
        $stats['total'] = count($huts);

        foreach ($huts as &$hut) {
            $rid = $hut['reservation_id'];

            // Distance filter
            if ($use_location) {
                $dist = haversine_km($center_lat, $center_lon, $hut['latitude'], $hut['longitude']);
                if ($dist > $radius_km) continue;
                $hut['distance_km'] = round($dist, 1);
            } else {
                $hut['distance_km'] = null;
            }

            // Availability check against selected dates only
            $avail = min_free_beds($rid, 7);   // always load full 7-day window
            if ($avail === null) continue;

            // Compute min_free restricted to $date_list
            $sel_min = PHP_INT_MAX;
            $sel_found = false;
            $sel_open  = false;
            foreach ($date_list as $date) {
                $day = $avail['by_day'][$date] ?? null;
                if ($day === null) continue;
                $status = $day['status'] ?? '';
                $free   = $day['free']   ?? null;
                if ($status === 'CLOSED') continue;
                $sel_open = true;
                if ($status === 'FULL' || $free === 0) { $sel_found = true; $sel_min = 0; continue; }
                if ($free !== null) { $sel_found = true; $sel_min = min($sel_min, (int)$free); }
            }
            $sel_min_free = $sel_found ? (int)$sel_min : 0;

            if ($sel_min_free < $min_beds_input) {
                // Open but not enough beds — collect for second table
                if ($sel_open) {
                    $hut['avail'] = $avail;
                    $too_full[]   = $hut;
                }
                continue;
            }
            $stats['passed_beds']++;

            // Load forecast
            $ffile = FORECAST_DIR . '/' . $rid;
            if (!file_exists($ffile)) continue;
            $fdata = json_decode(file_get_contents($ffile), true);
            if (!isset($fdata['daily'])) continue;
            $stats['with_forecast']++;

            $daily = $fdata['daily'];
            $score = score_forecast($daily);

            $hut['avail']     = $avail;
            $hut['daily']     = $daily;
            $hut['score']     = $score;
            $results[]        = $hut;
        }
        unset($hut);

        // Sort best weather first
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        // Sort too_full by max free beds descending (most beds first)
        usort($too_full, fn($a, $b) => $b['avail']['max_free'] <=> $a['avail']['max_free']);

    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/layout.php';
render_page_head(
    'Where to Go — Alpine Hut Weather Ranker',
    'Where to Go',
    'Find huts with available beds on selected days, ranked by weather forecast quality',
    'where2go'
);
?>
<style>
    .results-table { border-collapse: collapse; width: 100%; font-size: .82rem;
                     background: #fff; border-radius: 6px; overflow: hidden; }
    .results-table th { background: var(--hdr-bg); color: var(--hdr-fg);
                        padding: 6px 8px; text-align: center; white-space: nowrap; }
    .results-table th.day-header { background: var(--sub-bg); }
    .results-table th.sub-hdr    { background: #6c757d; font-size: .75rem; font-weight: 500; }
    .results-table td { border: 1px solid var(--border); padding: 5px 8px; vertical-align: middle; }
    .results-table tr:nth-child(even) td { background: #f8f9fa; }
    .results-table tr:hover td { background: #e9f0ff; }
    .results-table td.rank      { text-align: right; font-weight: 700; color: #6c757d; width: 3rem; }
    .results-table td.hut-name  { font-weight: 600; }
    .results-table td.hut-name a { color: #0d6efd; text-decoration: none; }
    .results-table td.hut-name a:hover { text-decoration: underline; }
    .results-table td.num       { text-align: right; white-space: nowrap; }
    .results-table td.elev      { font-weight: 600; color: #495057; }
    .results-table td.score     { text-align: right; font-weight: 700; }
    .results-table td.cache-age { text-align: right; color: #adb5bd; font-size: .75rem; white-space: nowrap; }
    .results-table td.reservation { text-align: center; white-space: nowrap; }
    .results-table td.reservation a { color: #0d6efd; text-decoration: none; font-size: .8rem;
                                       font-weight: 600; }
    .results-table td.reservation a:hover { text-decoration: underline; }
    .results-table td.day-cell  { text-align: center; font-size: .75rem; white-space: nowrap;
                                   min-width: 6rem; }

    /* Score gradient colours */
    .score-great  { color: #155724; }
    .score-good   { color: #0a58ca; }
    .score-ok     { color: #856404; }
    .score-poor   { color: #721c24; }

    /* Day cell weather colours */
    .wx-great  { background: #d4edda !important; color: #155724; font-weight: 700; }
    .wx-good   { background: #cfe2ff !important; color: #084298; }
    .wx-ok     { background: #fff3cd !important; color: #856404; }
    .wx-poor   { background: #f8d7da !important; color: #721c24; font-weight: 700; }

    .avail-open   { background: var(--green-bg)  !important; color: var(--green);  font-weight: 700; }
    .avail-full   { background: var(--yellow-bg) !important; color: var(--yellow); font-weight: 700; }
    .avail-closed { background: var(--red-bg)    !important; color: var(--red);    font-weight: 700; }

    .day-picker       { margin-top: .9rem; }
    .day-picker-label { font-size: .8rem; font-weight: 600; color: #495057; margin-bottom: .35rem; }
    .toggle-btn       { margin-left: .4rem; padding: 1px 8px; font-size: .75rem; background: #e9ecef;
                        border: 1px solid #ced4da; border-radius: 3px; cursor: pointer; }
    .toggle-btn:hover { background: #dee2e6; }
    .day-checks       { display: flex; flex-wrap: wrap; gap: .4rem .9rem; margin-top: .3rem; }
    .day-check-item   { font-size: .85rem; display: flex; align-items: center; gap: .25rem;
                        cursor: pointer; white-space: nowrap; }
    .day-check-item input { cursor: pointer; }
</style>

<?php
// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
$beds_val   = $min_beds_input !== null ? $min_beds_input : '';
$radius_val = $radius_km;
echo '<div class="search-form">';
echo '<h2>Search</h2>';
echo '<form method="get" action="">';
echo '<div class="form-row">';
echo '<div class="form-group">';
echo '<label for="location">Location <span style="font-weight:400;color:#6c757d">(optional)</span></label>';
echo '<input type="text" id="location" name="location" value="' . h($location_input) . '" placeholder="e.g. Innsbruck or 47.26,11.39">';
echo '</div>';
echo '<div class="form-group">';
echo '<label for="radius">Radius (km)</label>';
echo '<input type="number" id="radius" name="radius" min="1" step="1" value="' . h((string)$radius_val) . '">';
echo '</div>';
echo '<div class="form-group">';
echo '<label for="min_beds">Minimum free beds</label>';
echo '<input type="number" id="min_beds" name="min_beds" min="1" step="1" value="' . h((string)$beds_val) . '" placeholder="e.g. 4">';
echo '</div>';
echo '<div class="form-group">';
echo '<button type="submit">Search</button>';
echo '</div>';
echo '</div>';

// Day checkboxes
echo '<div class="day-picker">';
echo '<div class="day-picker-label">Days to consider: '
   . '<button type="button" class="toggle-btn" onclick="setAllDays(true)">All</button>'
   . '<button type="button" class="toggle-btn" onclick="setAllDays(false)">None</button>'
   . '</div>';
echo '<div class="day-checks">';
foreach ($all_dates as $d) {
    $checked = in_array($d, $date_list) ? ' checked' : '';
    $label   = date('D d.m', strtotime($d));
    echo '<label class="day-check-item">'
       . '<input type="checkbox" name="days[]" value="' . h($d) . '"' . $checked . '> '
       . h($label)
       . '</label>';
}
echo '</div></div>';

echo '<p class="hint">Availability is read from local cache. </p>';
echo '</form>';
echo '</div>';

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------
if ($error !== null) {
    echo '<div class="error-box">' . h($error) . '</div>';
} elseif ($min_beds_input !== null) {
    if (empty($results)) {
        echo '<div class="warn-box">No huts found with at least ' . $min_beds_input . ' free bed(s) and a weather forecast available. Try a lower number or refresh the caches.</div>';
    } else {
        $n = count($results);
        echo "<div class=\"result-summary\">";
        $nd = count($date_list);
        echo "Showing <strong>$n</strong> hut(s) with &ge; <strong>{$min_beds_input}</strong> free bed(s) on all <strong>$nd</strong> selected day(s)";
        if ($use_location) {
            echo " within <strong>" . (int)$radius_km . " km</strong> of <em>" . h($center_name) . "</em>";
        }
        echo ", ranked by average weather score.";
        echo " <span style=\"color:#6c757d;font-size:.85em\">(of {$stats['total']} huts with reservation IDs)</span>";
        echo "</div>\n";

        // Day labels from the canonical date list
        $day_labels = [];
        foreach ($date_list as $d) {
            $day_labels[] = date('D d.m', strtotime($d));
        }
        $num_days = count($day_labels);

        echo '<div style="overflow-x:auto">';
        echo '<table class="results-table">';
        echo '<thead>';

        // First header row
        echo '<tr>';
        echo '<th rowspan="3">#</th>';
        echo '<th rowspan="3">Hut</th>';
        echo '<th rowspan="3">Club</th>';
        echo '<th rowspan="3">Elevation</th>';
        if ($use_location) echo '<th rowspan="3">Distance</th>';
        echo '<th rowspan="3">Avg score</th>';
        echo '<th rowspan="3">Book</th>';
        foreach ($day_labels as $lbl) {
            echo '<th colspan="1" class="day-header">' . h($lbl) . '</th>';
        }
        echo '<th rowspan="3" class="sub-hdr" style="min-width:5rem">Avail cached</th>';
        echo '</tr>';

        // Second header row — weather sub-label
        echo '<tr>';
        for ($d = 0; $d < $num_days; $d++) {
            echo '<th class="sub-hdr">sun / precip / wind</th>';
        }
        echo '</tr>';

        // Third header row — beds sub-label
        echo '<tr>';
        for ($d = 0; $d < $num_days; $d++) {
            echo '<th class="sub-hdr">free beds</th>';
        }
        echo '</tr>';

        echo '</thead>';
        echo '<tbody>';

        foreach ($results as $rank0 => $hut) {
            $rank   = $rank0 + 1;
            $name   = h($hut['official_name']);
            $club   = h($hut['operating_club']);
            $url    = $hut['official_website_url'];
            $rid    = $hut['reservation_id'];
            $score  = $hut['score'];
            $daily  = $hut['daily'];
            $by_day = $hut['avail']['by_day'] ?? [];

            $elev_s = $hut['elevation_m'] !== null
                    ? number_format((int)$hut['elevation_m']) . ' m' : '?';

            $name_td = $url !== ''
                     ? '<a href="' . h($url) . '" target="_blank">' . $name . '</a>'
                     : $name;

            $book_td = '<a href="' . h(BOOKING_BASE . $rid . '/wizard') . '" target="_blank">Book now</a>';

            if ($score >= 75)      $score_cls = 'score-great';
            elseif ($score >= 55)  $score_cls = 'score-good';
            elseif ($score >= 35)  $score_cls = 'score-ok';
            else                   $score_cls = 'score-poor';

            $dist_s = $hut['distance_km'] !== null ? $hut['distance_km'] . ' km' : '—';

            // Weather row
            echo "<tr>";
            echo "<td class=\"rank\" rowspan=\"2\">$rank</td>";
            echo "<td class=\"hut-name\" rowspan=\"2\">$name_td</td>";
            echo "<td rowspan=\"2\">" . h($club) . "</td>";
            echo "<td class=\"num elev\" rowspan=\"2\">$elev_s</td>";
            if ($use_location) echo "<td class=\"num\" rowspan=\"2\">$dist_s</td>";
            echo "<td class=\"num score $score_cls\" rowspan=\"2\">" . number_format($score, 1) . "</td>";
            echo "<td class=\"reservation\" rowspan=\"2\">$book_td</td>";

            for ($i = 0; $i < $num_days; $i++) {
                $sun_s  = isset($daily['sunshine_duration'][$i]) ? (float)$daily['sunshine_duration'][$i] : null;
                $precip = isset($daily['precipitation_sum'][$i]) ? (float)$daily['precipitation_sum'][$i] : null;
                $wind   = isset($daily['windspeed_10m_max'][$i]) ? (float)$daily['windspeed_10m_max'][$i] : null;
                $ds     = score_day($i, $daily);

                if ($ds >= 75)      $wx_cls = 'wx-great';
                elseif ($ds >= 55)  $wx_cls = 'wx-good';
                elseif ($ds >= 35)  $wx_cls = 'wx-ok';
                else                $wx_cls = 'wx-poor';

                $sun_h  = $sun_s  !== null ? number_format($sun_s / 3600, 1) . 'h☀' : '?';
                $prec_s = $precip !== null ? number_format($precip, 1) . 'mm'        : '?';
                $wind_s = $wind   !== null ? number_format($wind, 0) . 'km/h'        : '?';

                echo "<td class=\"day-cell $wx_cls\">";
                echo h($sun_h) . ' ' . h($prec_s) . '<br>' . h($wind_s);
                echo "</td>";
            }

            $age_s  = $hut['avail']['cache_age'] ?? null;
            $age_td = $age_s !== null ? format_age((int)$age_s) : '?';
            echo "<td class=\"cache-age\" rowspan=\"2\">$age_td</td>";
            echo "</tr>\n";

            // Beds row
            echo "<tr>";
            foreach ($date_list as $date) {
                $day    = $by_day[$date] ?? null;
                $free   = $day['free']   ?? null;
                $status = $day['status'] ?? '';

                if ($status === 'CLOSED') {
                    echo '<td class="num avail-closed" style="font-size:.75rem">CLOSED</td>';
                } elseif ($status === 'FULL' || $free === 0) {
                    echo '<td class="num avail-full">0</td>';
                } elseif ($free !== null) {
                    $beds_cls = $free >= $min_beds_input ? 'avail-open' : 'avail-full';
                    echo "<td class=\"num $beds_cls\">$free</td>";
                } else {
                    echo '<td class="num" style="color:#adb5bd">—</td>';
                }
            }
            echo "</tr>\n";
        }

        echo '</tbody></table></div>';
    }

    // -------------------------------------------------------------------------
    // Second table: open huts without enough free beds
    // -------------------------------------------------------------------------
    if (!empty($too_full)) {
        $n2 = count($too_full);
        echo "<div class=\"result-summary\" style=\"margin-top:1.5rem\">";
        echo "<strong>$n2</strong> hut(s) are open but have fewer than <strong>{$min_beds_input}</strong> free bed(s):";
        echo "</div>\n";

        $day_labels2 = [];
        foreach ($date_list as $d) {
            $day_labels2[] = date('D d.m', strtotime($d));
        }

        echo '<div style="overflow-x:auto">';
        echo '<table class="results-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th rowspan="2">#</th>';
        echo '<th rowspan="2">Hut</th>';
        echo '<th rowspan="2">Club</th>';
        echo '<th rowspan="2">Elevation</th>';
        if ($use_location) echo '<th rowspan="2">Distance</th>';
        echo '<th rowspan="2">Book</th>';
        foreach ($day_labels2 as $lbl) {
            echo '<th class="day-header">' . h($lbl) . '</th>';
        }
        echo '<th rowspan="2" class="sub-hdr" style="min-width:5rem">Avail cached</th>';
        echo '</tr>';
        echo '<tr>';
        foreach ($day_labels2 as $lbl) {
            echo '<th class="sub-hdr">free beds</th>';
        }
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($too_full as $rank0 => $hut) {
            $rank   = $rank0 + 1;
            $name   = h($hut['official_name']);
            $club   = h($hut['operating_club']);
            $url    = $hut['official_website_url'];
            $rid    = $hut['reservation_id'];
            $age_s  = $hut['avail']['cache_age'] ?? null;
            $by_day = $hut['avail']['by_day'] ?? [];

            $elev_s = $hut['elevation_m'] !== null
                    ? number_format((int)$hut['elevation_m']) . ' m' : '?';

            $name_td = $url !== ''
                     ? '<a href="' . h($url) . '" target="_blank">' . $name . '</a>'
                     : $name;

            $book_td = '<a href="' . h(BOOKING_BASE . $rid . '/wizard') . '" target="_blank">Book now</a>';
            $age_td  = $age_s !== null ? format_age((int)$age_s) : '?';

            $dist_s2 = $hut['distance_km'] !== null ? $hut['distance_km'] . ' km' : '—';

            echo "<tr>";
            echo "<td class=\"rank\">$rank</td>";
            echo "<td class=\"hut-name\">$name_td</td>";
            echo "<td>" . h($club) . "</td>";
            echo "<td class=\"num elev\">$elev_s</td>";
            if ($use_location) echo "<td class=\"num\">$dist_s2</td>";
            echo "<td class=\"reservation\">$book_td</td>";

            foreach ($date_list as $date) {
                $day    = $by_day[$date] ?? null;
                $free   = $day['free']   ?? null;
                $status = $day['status'] ?? '';

                if ($status === 'CLOSED') {
                    echo '<td class="num avail-closed" style="font-size:.75rem">CLOSED</td>';
                } elseif ($status === 'FULL' || $free === 0) {
                    echo '<td class="num avail-full">0</td>';
                } elseif ($free !== null) {
                    echo "<td class=\"num avail-full\">$free</td>";
                } else {
                    echo '<td class="num" style="color:#adb5bd">—</td>';
                }
            }

            echo "<td class=\"cache-age\">$age_td</td>";
            echo "</tr>\n";
        }

        echo '</tbody></table></div>';
    }
}
?>

<script>
function setAllDays(checked) {
    document.querySelectorAll('input[name="days[]"]').forEach(function(cb) {
        cb.checked = checked;
    });
}
</script>

<?php render_page_foot([
    'Availability: <a href="https://www.hut-reservation.org" target="_blank">hut-reservation.org</a>',
    'Weather: <a href="https://open-meteo.com" target="_blank">Open-Meteo</a>',
]); ?>
