<?php
// free_weekends.php — Weekend calendar of huts with at least N free beds on the Friday and/or
// Saturday night; picking a weekend shows its huts grouped by how well they fit. Covers all huts
// by default, or only huts within a radius of a location. Reads reservation_cache/<id>.

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

/**
 * Load huts as ['res' => huts with a reservation_id, 'no_res' => huts without one]. With a $center
 * (['lat', 'lon']) only huts within $radius_km are kept, nearest first, and 'no_res' is filled;
 * without one all reservation huts are returned, sorted by name, and 'no_res' stays empty.
 */
function load_huts(?array $center, float $radius_km): array {
    $fh = fopen(CSV_PATH, 'r');
    if ($fh === false) {
        throw new RuntimeException('Cannot open CSV: ' . CSV_PATH);
    }
    $header = fgetcsv($fh);
    if ($header === false) { fclose($fh); throw new RuntimeException('CSV is empty'); }
    $col = array_flip($header);

    $field = function(array $row, string $key) use ($col): string {
        $v = trim($row[$col[$key] ?? -1] ?? '');
        return strtolower($v) === 'nan' ? '' : $v;
    };

    $huts   = [];
    $no_res = [];
    while (($row = fgetcsv($fh)) !== false) {
        $rid = isset($col['reservation_id'], $row[$col['reservation_id']])
             ? trim($row[$col['reservation_id']]) : '';
        if ($rid === '' && $center === null) continue;

        $name = trim($row[$col['official_name']] ?? '');
        $lat  = trim($row[$col['latitude']]      ?? '');
        $lon  = trim($row[$col['longitude']]     ?? '');
        if ($name === '' || $lat === '' || $lon === '') continue;

        $dist = null;
        if ($center !== null) {
            $dist = haversine_km($center['lat'], $center['lon'], (float)$lat, (float)$lon);
            if ($dist > $radius_km) continue;
        }

        $elev_raw = trim($row[$col['elevation_m']] ?? '');
        $elev     = ($elev_raw !== '' && strtolower($elev_raw) !== 'nan') ? (float)$elev_raw : null;

        $hut = [
            'official_name'        => $name,
            'operating_club'       => trim($row[$col['operating_club']]       ?? ''),
            'latitude'             => (float)$lat,
            'longitude'            => (float)$lon,
            'elevation_m'          => $elev,
            'official_website_url' => trim($row[$col['official_website_url']] ?? ''),
            'reservation_id'       => $rid,
            'distance_km'          => $dist !== null ? round($dist, 1) : null,
            'phone_number'         => $field($row, 'phone_number'),
            'email'                => $field($row, 'email'),
            'capacity_beds'        => $field($row, 'capacity_beds'),
        ];
        if ($rid === '')
            $no_res[] = $hut;
        else
            $huts[] = $hut;
    }
    fclose($fh);

    $cmp = $center !== null
         ? fn($a, $b) => $a['distance_km'] <=> $b['distance_km']
         : fn($a, $b) => strcasecmp($a['official_name'], $b['official_name']);
    usort($huts,   $cmp);
    usort($no_res, $cmp);
    return ['res' => $huts, 'no_res' => $no_res];
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

// Only the "All huts" submit button sends "all_huts"; before the first search all huts are shown
$submitted      = isset($_GET['submitted']);
$all_huts       = !$submitted || isset($_GET['all_huts']);
$location_input = isset($_GET['location']) ? trim($_GET['location']) : '';
$radius_km      = isset($_GET['distance']) && $_GET['distance'] !== '' ? (float)$_GET['distance'] : 20.0;
$people         = isset($_GET['people'])   && $_GET['people']   !== '' ? max(1, (int)$_GET['people']) : 2;
$weekend_input  = isset($_GET['weekend'])  ? trim($_GET['weekend']) : '';

$error     = null;
$huts      = [];
$no_res    = [];   // huts in the radius without a reservation_id (radius mode only)
$weekends  = [];   // fri => ['sat' => date, 'groups' => ['both' => [...], 'one' => [...], 'almost' => [...]]]
$geo       = null;
$selected  = null;

if ($submitted) {
    try {
        if ($all_huts) {
            $huts = load_huts(null, 0.0)['res'];
        } elseif ($location_input === '') {
            $error = 'Enter a location or click "All huts".';
        } elseif ($radius_km < 1 || $radius_km > 500) {
            $error = 'Radius must be between 1 and 500 km.';
        } else {
            $geo  = parse_latlon($location_input) ?? geocode($location_input);
            $loaded = load_huts($geo, $radius_km);
            $huts   = $loaded['res'];
            $no_res = $loaded['no_res'];
            if (empty($huts) && empty($no_res)) {
                $error = 'No huts found within ' . (int)$radius_km . ' km of "' . $location_input . '".';
            }
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
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

    // Selected weekend: the requested one if known, else the first with both nights free, else the first
    if (isset($weekends[$weekend_input])) {
        $selected = $weekend_input;
    } else {
        foreach ($weekends as $fri => $wk) {
            if (!empty($wk['groups']['both'])) { $selected = $fri; break; }
        }
        $selected ??= array_key_first($weekends);
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

/**
 * Render a client-side sortable <th> (see sortTable()). $first is the direction of the first click;
 * $active marks the column the server already sorted by (ascending).
 */
function sort_th(string $label, string $type, string $first, bool $active = false): string {
    $arrow = $active ? '<span class="sort-arrow">&#9660;</span>' : '<span class="sort-arrow sort-idle">&#8645;</span>';
    return '<th class="sortable" data-type="' . $type . '" data-first="' . $first . '"' . ($active ? ' data-dir="asc"' : '')
         . ' onclick="sortTable(this)" title="Sort by ' . strtolower($label) . '">' . $label . ' ' . $arrow . '</th>';
}

/** Render one group of the selected weekend as a table. */
function render_section(string $title, string $intro, array $rows, bool $show_dist): void {
    echo '<h3 class="section-title">' . h($title) . ' <span class="section-count">(' . count($rows) . ')</span></h3>';
    echo '<p class="section-intro">' . h($intro) . '</p>';

    if (empty($rows)) {
        echo '<div class="warn-box">No huts in this group on this weekend.</div>';
        return;
    }

    echo '<div style="overflow-x:auto">';
    echo '<table class="results-table"><thead><tr>'
       . '<th>#</th>' . sort_th('Hut', 'text', 'asc', !$show_dist) . '<th>Club</th>'
       . ($show_dist ? sort_th('Distance', 'num', 'asc', true) : '') . sort_th('Elevation', 'num', 'desc')
       . '<th>Fri night</th><th>Sat night</th><th>Map</th><th>Book</th><th>Cached</th>'
       . '</tr></thead><tbody>';

    foreach ($rows as $i => $e) {
        $hut     = $e['hut'];
        $name    = h($hut['official_name']);
        $url     = $hut['official_website_url'];
        $name_td = $url !== '' ? '<a href="' . h($url) . '" target="_blank">' . $name . '</a>' : $name;
        $elev_s  = $hut['elevation_m'] !== null ? number_format((int)$hut['elevation_m']) . ' m' : '?';
        $lat     = $hut['latitude'];
        $lon     = $hut['longitude'];
        $map_td  = '<a href="' . h('https://www.google.com/maps?q=' . $lat . ',' . $lon) . '" target="_blank">G-Maps</a>'
                 . ' <a href="' . h('https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '&zoom=15') . '" target="_blank">OSM</a>'
                 . ' <a href="' . h('geo:' . $lat . ',' . $lon) . '">GEO</a>';
        $book_td = '<a href="' . h(BOOKING_BASE . $hut['reservation_id'] . '/wizard') . '" target="_blank">Reservation</a>';
        $age     = (int)$hut['cache']['cache_age'];
        $age_td  = h(format_age($age)) . ($age > CACHE_TTL ? ' <span class="stale">(stale)</span>' : '');

        echo '<tr>';
        echo '<td class="rank">' . ($i + 1) . '</td>';
        echo '<td class="hut-name">' . $name_td . '</td>';
        echo '<td>' . h($hut['operating_club']) . '</td>';
        if ($show_dist) echo '<td class="num">' . h($hut['distance_km'] . ' km') . '</td>';
        echo '<td class="num elev">' . h($elev_s) . '</td>';
        echo render_night_cell($e['fri'], $e['c_fri']);
        echo render_night_cell($e['sat'], $e['c_sat']);
        echo '<td class="map-links">' . $map_td . '</td>';
        echo '<td class="reservation">' . $book_td . '</td>';
        echo '<td class="cache-age">' . $age_td . '</td>';
        echo "</tr>\n";
    }
    echo '</tbody></table></div>';
}

/** Render the huts without a reservation_id: contact data only, no availability. */
function render_no_reservation(array $huts): void {
    echo '<h3 class="section-title">Not using hut-reservation <span class="section-count">(' . count($huts) . ')</span></h3>';
    echo '<p class="section-intro">No online availability; contact the hut directly.</p>';

    echo '<div style="overflow-x:auto">';
    echo '<table class="results-table"><thead><tr>'
       . '<th>#</th>' . sort_th('Hut', 'text', 'asc') . '<th>Club</th>'
       . sort_th('Distance', 'num', 'asc', true) . sort_th('Elevation', 'num', 'desc')
       . '<th>Beds</th><th>Phone</th><th>Email</th><th>Map</th>'
       . '</tr></thead><tbody>';

    $dash = '<td class="muted">—</td>';
    foreach ($huts as $i => $hut) {
        $name    = h($hut['official_name']);
        $url     = $hut['official_website_url'];
        $name_td = $url !== '' ? '<a href="' . h($url) . '" target="_blank">' . $name . '</a>' : $name;
        $elev_s  = $hut['elevation_m'] !== null ? number_format((int)$hut['elevation_m']) . ' m' : '?';
        $beds    = $hut['capacity_beds'] !== '' ? (string)(int)(float)$hut['capacity_beds'] : '';
        $phone   = $hut['phone_number'];
        $email   = $hut['email'];
        $lat     = $hut['latitude'];
        $lon     = $hut['longitude'];
        $map_td  = '<a href="' . h('https://www.google.com/maps?q=' . $lat . ',' . $lon) . '" target="_blank">G-Maps</a>'
                 . ' <a href="' . h('https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '&zoom=15') . '" target="_blank">OSM</a>'
                 . ' <a href="' . h('geo:' . $lat . ',' . $lon) . '">GEO</a>';

        echo '<tr>';
        echo '<td class="rank">' . ($i + 1) . '</td>';
        echo '<td class="hut-name">' . $name_td . '</td>';
        echo '<td>' . h($hut['operating_club']) . '</td>';
        echo '<td class="num">' . h($hut['distance_km'] . ' km') . '</td>';
        echo '<td class="num elev">' . h($elev_s) . '</td>';
        echo $beds  !== '' ? '<td class="num">' . h($beds) . '</td>' : $dash;
        echo $phone !== '' ? '<td class="contact"><a href="' . h('tel:' . preg_replace('/[^\d+]/', '', $phone)) . '">' . h($phone) . '</a></td>' : $dash;
        echo $email !== '' ? '<td class="contact"><a href="' . h('mailto:' . $email) . '">' . h($email) . '</a></td>' : $dash;
        echo '<td class="map-links">' . $map_td . '</td>';
        echo "</tr>\n";
    }
    echo '</tbody></table></div>';
}

/** Render an OSM map of the huts with both nights free (green pins), plus the search centre in radius mode. */
function render_both_map(array $rows, ?array $center): void {
    if (empty($rows)) return;

    $huts = [];
    foreach ($rows as $e) {
        $hut    = $e['hut'];
        $huts[] = [
            'lat'  => $hut['latitude'],
            'lon'  => $hut['longitude'],
            'name' => h($hut['official_name']),
            'club' => h($hut['operating_club']),
            'elev' => $hut['elevation_m'] !== null ? number_format((int)$hut['elevation_m']) . ' m' : null,
            'dist' => $hut['distance_km'],
            'fri'  => $e['fri']['free'] ?? '?',
            'sat'  => $e['sat']['free'] ?? '?',
            'book' => h(BOOKING_BASE . $hut['reservation_id'] . '/wizard'),
        ];
    }
    $huts_json   = json_encode($huts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $center_json = json_encode($center !== null
        ? ['lat' => $center['lat'], 'lon' => $center['lon'], 'name' => h($center['display_name'] ?? '')]
        : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    echo '<div id="hut-map"></div>';
    echo <<<JS
    <script>
    (function() {
        var CENTER = $center_json;
        var HUTS   = $huts_json;

        var map = L.map("hut-map");
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "&copy; <a href=\"https://www.openstreetmap.org/copyright\">OpenStreetMap</a> contributors",
            maxZoom: 18,
        }).addTo(map);

        /*  green SVG pin, same shape as on hut_search.php  */
        var pinSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="25" height="41" viewBox="0 0 25 41">'
                   + '<path d="M12.5 0C5.6 0 0 5.6 0 12.5c0 9.4 12.5 28.5 12.5 28.5S25 21.9 25 12.5C25 5.6 19.4 0 12.5 0z" fill="#28a745" stroke="#fff" stroke-width="1.5"/>'
                   + '<circle cx="12.5" cy="12.5" r="5" fill="rgba(255,255,255,0.7)"/>'
                   + '</svg>';
        var pinIcon = L.divIcon({ html: pinSvg, className: "", iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [0, -38] });

        var points = [];
        if (CENTER) {
            var starSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="45" height="45" viewBox="0 0 30 30">'
                        + '<circle cx="15" cy="15" r="10" fill="none" stroke="#dc3545" stroke-width="2.5"/>'
                        + '<circle cx="15" cy="15" r="2.5" fill="#dc3545"/>'
                        + '<line x1="15" y1="0" x2="15" y2="8" stroke="#dc3545" stroke-width="2.5" stroke-linecap="round"/>'
                        + '<line x1="15" y1="22" x2="15" y2="30" stroke="#dc3545" stroke-width="2.5" stroke-linecap="round"/>'
                        + '<line x1="0" y1="15" x2="8" y2="15" stroke="#dc3545" stroke-width="2.5" stroke-linecap="round"/>'
                        + '<line x1="22" y1="15" x2="30" y2="15" stroke="#dc3545" stroke-width="2.5" stroke-linecap="round"/>'
                        + '</svg>';
            var centerIcon = L.divIcon({ html: starSvg, className: "", iconSize: [45, 45], iconAnchor: [22, 22], popupAnchor: [0, -22] });
            L.marker([ CENTER.lat, CENTER.lon ], { icon: centerIcon }).addTo(map)
                .bindPopup('<div class="map-popup"><b>' + CENTER.name + '</b><br><em>Search center</em></div>');
            points.push([ CENTER.lat, CENTER.lon ]);
        }

        HUTS.forEach((h) => {
            var info  = [ h.club, h.elev, h.dist != null ? h.dist + " km away" : null ].filter((s) => s).join(" &bull; ");
            var popup = '<div class="map-popup">'
                      + '<b><a href="' + h.book + '" target="_blank">' + h.name + '</a></b><br>'
                      + (info ? info + '<br>' : '')
                      + 'Fri ' + h.fri + ' / Sat ' + h.sat + ' free<br>'
                      + '<a href="' + h.book + '" target="_blank">Reservation</a>'
                      + '</div>';
            L.marker([ h.lat, h.lon ], { icon: pinIcon }).addTo(map).bindPopup(popup);
            points.push([ h.lat, h.lon ]);
        });

        map.fitBounds(points, { padding: [ 30, 30 ], maxZoom: 12 });
    })();
    </script>
    JS;
}

/** Render the weekend calendar: one row per month, one chip per weekend selecting that weekend. */
function render_calendar(array $weekends, string $selected, array $query): void {
    $months = [];
    foreach ($weekends as $fri => $wk) {
        $months[date('M Y', strtotime($fri))][$fri] = $wk;
    }

    echo '<div class="calendar">';
    echo '<div class="calendar-title">Weekends &mdash; number = huts with both nights free; click to show</div>';
    foreach ($months as $month => $wks) {
        echo '<div class="cal-month"><span class="cal-label">' . h($month) . '</span>';
        foreach ($wks as $fri => $wk) {
            $n_both  = count($wk['groups']['both']);
            $tooltip = 'both: ' . $n_both . ' · one night: ' . count($wk['groups']['one'])
                     . ' · almost: ' . count($wk['groups']['almost']);
            $cls     = 'cal-chip ' . ($n_both > 0 ? 'cal-free' : 'cal-none') . ($fri === $selected ? ' cal-selected' : '');
            $href    = '?' . http_build_query($query + ['weekend' => $fri]);
            echo '<a class="' . $cls . '" href="' . h($href) . '" title="' . h($tooltip) . '">'
               . h(date('D d.m', strtotime($fri))) . ' <b>' . $n_both . '</b></a>';
        }
        echo '</div>';
    }
    echo '</div>';
}

require_once __DIR__ . '/layout.php';
$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">' . "\n"
            . '  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>';
render_page_head(
    'Free Weekends — Alpine Hut Weather Planner',
    'Free Weekends',
    'Find huts with enough free beds on Friday and Saturday nights, weekend by weekend',
    'free_weekends',
    $extra_head
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
    .results-table td.muted     { color: #adb5bd; text-align: center; }
    .results-table th.sortable  { cursor: pointer; user-select: none; }
    .results-table th.sortable:hover { background: #23272b; }
    .sort-arrow           { font-size: .7rem; opacity: .7; }
    .sort-arrow.sort-idle { opacity: .35; }
    .results-table td.contact   { white-space: nowrap; }
    .results-table td.contact a { color: #0d6efd; text-decoration: none; }
    .results-table td.contact a:hover { text-decoration: underline; }

    .avail-open   { background: var(--green-bg)  !important; color: var(--green);  font-weight: 700; }
    .avail-almost { background: #ffe5cc          !important; color: #8a4500;      font-weight: 700; }
    .avail-short  { background: var(--red-bg)    !important; color: var(--red);    font-weight: 700; }
    .unserviced   { font-size: .68rem; font-weight: 400; color: #6c757d; }
    .stale        { color: var(--yellow); font-weight: 600; }

    .weekend-title { font-size: 1.15rem; margin: 1.25rem 0 .2rem; }
    .section-title { font-size: 1rem; margin: 1.1rem 0 .2rem; }
    .section-count { font-weight: 400; color: #6c757d; }
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
    .cal-none       { background: #e9ecef; color: #6c757d; border: 1px solid #dee2e6; }
    .cal-none:hover { background: #dee2e6; }
    .cal-selected   { outline: 2px solid var(--hdr-bg); outline-offset: 1px; font-weight: 700; }

    #hut-map { height: 500px; margin: .6rem 0 1rem; border-radius: 8px;
               border: 1px solid var(--border); }
    .map-popup b { font-size: .9rem; }
    .map-popup a { color: #0d6efd; }
</style>

<?php
// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------

echo '<div class="search-form">';
echo '<h2>Search</h2>';
echo '<form method="get" action="" id="search-form">';
echo '<input type="hidden" name="submitted" value="1">';

// Row 1: group size + all huts
echo '<div class="form-row">';
echo '<div class="form-group">';
echo '<label for="people">Free beds needed</label>';
echo '<input type="number" id="people" name="people" min="1" step="1" value="' . h((string)$people) . '">';
echo '</div>';
echo '<div class="form-group">';
echo '<button type="submit" name="all_huts" value="1" title="Ignore location/radius and list all huts">All huts</button>';
echo '</div>';
echo '</div>';

// Row 2: location + radius search
echo '<div class="form-row" style="margin-top:.75rem">';
echo '<div class="form-group">';
echo '<label for="location">Location or Lat/Lon</label>';
echo '<input type="text" id="location" name="location" value="' . h($location_input) . '" placeholder="e.g. Innsbruck or 47.26,11.39">';
echo '</div>';
echo '<div class="form-group">';
echo '<label for="distance">Radius (km)</label>';
echo '<input type="number" id="distance" name="distance" min="1" max="500" step="1" value="' . h((string)(int)$radius_km) . '">';
echo '</div>';
echo '<div class="form-group">';
echo '<button type="submit" id="radius-btn" title="Search huts within the radius around the location">Radius</button>';
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
    if ($all_huts)
        echo '<strong>' . count($huts) . '</strong> hut(s) with a reservation ID (all locations)';
    else
        echo '<strong>' . count($huts) . '</strong> hut(s) with a reservation ID within <strong>' . (int)$radius_km
           . ' km</strong> of <em>' . h($geo['display_name']) . '</em>';
    echo ', needing <strong>' . $people . '</strong> free bed(s) &mdash; <strong>' . count($weekends)
       . '</strong> weekend(s) with cached data';
    if ($no_cache > 0) {
        echo ' <span style="color:#856404">(' . $no_cache . ' hut(s) without cached availability; run <code>update_cache.php</code>)</span>';
    }
    if (!empty($no_res)) {
        echo ' &mdash; + <strong>' . count($no_res) . '</strong> hut(s) not using hut-reservation (listed at the bottom)';
    }
    echo '</div>';

    if (empty($huts)) {
        echo '<div class="warn-box">No huts using hut-reservation within this radius.</div>';
    } elseif (empty($weekends)) {
        echo '<div class="warn-box">No cached availability for any upcoming weekend. Run <code>update_cache.php</code> first.</div>';
    } else {

        // Chip links repeat the current search so that only the weekend changes
        $query = ['submitted' => 1, 'people' => $people];
        if ($all_huts)
            $query['all_huts'] = 1;
        else
            $query += ['location' => $location_input, 'distance' => (int)$radius_km];
        render_calendar($weekends, $selected, $query);

        $wk  = $weekends[$selected];
        $sun = date('Y-m-d', strtotime($selected . ' +2 days'));
        echo '<h2 class="weekend-title">' . h(date('D d.m', strtotime($selected)) . ' – ' . date('D d.m.Y', strtotime($sun))) . '</h2>';
        render_both_map($wk['groups']['both'], $all_huts ? null : $geo);
        render_section('Both nights free',
            "At least $people free bed(s) on Friday and Saturday night.", $wk['groups']['both'], !$all_huts);
        render_section('One night free',
            "At least $people free bed(s) on only one night: Saturday-only huts first, then Friday-only.", $wk['groups']['one'], !$all_huts);
        if ($people > 1) {
            render_section('Almost',
                'Neither night has enough beds, but at least one night is only 1 bed short (' . ($people - 1) . ' free).', $wk['groups']['almost'], !$all_huts);
        }
    }

    // Huts without online booking are independent of the selected weekend, so they always come last
    if (!$all_huts && !empty($no_res)) {
        render_no_reservation($no_res);
    }
}
?>

<script>
/*  Enter in Location/Radius runs the radius search (the default button would be "All huts", first in the form)  */
document.getElementById("search-form").addEventListener("keydown", (e) => {
    if (e.key === "Enter" && (e.target.id === "location" || e.target.id === "distance")) {
        e.preventDefault();
        document.getElementById("radius-btn").click();
    }
});

/*  sort the clicked table by this column: first click uses data-first, then toggles; unknown values always last  */
function sortTable(th) {
    var tbody = th.closest("table").tBodies[0];
    var col   = th.cellIndex;
    var num   = th.dataset.type === "num";
    var dir   = th.dataset.dir ? (th.dataset.dir === "asc" ? "desc" : "asc") : th.dataset.first;

    th.closest("tr").querySelectorAll("th.sortable").forEach((el) => {
        var arrow = el.querySelector(".sort-arrow");
        arrow.innerHTML = "&#8645;";
        arrow.classList.add("sort-idle");
        delete el.dataset.dir;
    });
    th.dataset.dir = dir;
    var arrow = th.querySelector(".sort-arrow");
    arrow.innerHTML = dir === "desc" ? "&#9650;" : "&#9660;";
    arrow.classList.remove("sort-idle");

    var key = (row) => {
        var s = row.cells[col].textContent.trim();
        if (!num)
            return s;
        var v = parseFloat(s.replace(/[^\d.]/g, ""));
        return isNaN(v) ? null : v;
    };
    var sign = dir === "desc" ? -1 : 1;
    var rows = Array.prototype.slice.call(tbody.rows);
    rows.sort((a, b) => {
        var av = key(a), bv = key(b);
        if (av === null || bv === null)
            return (av === null) - (bv === null);
        return sign * (num ? av - bv : av.localeCompare(bv, "de", { sensitivity: "base" }));
    });
    rows.forEach((r, i) => {
        r.cells[0].textContent = i + 1;
        tbody.appendChild(r);
    });
}
</script>

<?php render_page_foot([
    'Geocoding: <a href="https://nominatim.openstreetmap.org" target="_blank">Nominatim</a>',
    'Availability: <a href="https://www.hut-reservation.org" target="_blank">hut-reservation.org</a>',
]); ?>
