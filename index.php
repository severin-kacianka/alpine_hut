<?php
// Alpine Hut Weather Planner — PHP web UI
// Requires: PHP 8.0+, curl extension, data/alpine_huts_full.csv readable,
//           data/ directory writable (for weather_cache.json)

setlocale(LC_ALL, 'C');

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define('CSV_PATH',       __DIR__ . '/data/alpine_huts_full.csv');
define('CACHE_PATH',     __DIR__ . '/data/weather_cache.json');
define('FORECAST_DAYS',  5);
define('BATCH_SIZE',     17);
define('BATCH_PAUSE',    1.5);   // seconds between Open-Meteo batch requests
define('CACHE_TTL',      86400); // 24 hours in seconds
define('NOMINATIM_URL',  'https://nominatim.openstreetmap.org/search');
define('OPENMETEO_URL',  'http://api.open-meteo.com/v1/forecast');
define('USER_AGENT',     'alpine-hut-search/1.0');

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

    // Build column index map from header row
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException("CSV is empty");
    }
    $col = array_flip($header);

    $huts = [];
    while (($row = fgetcsv($fh)) !== false) {
        // Skip malformed rows
        if (!isset($col['official_name'], $col['latitude'], $col['longitude'])) continue;
        $name = isset($row[$col['official_name']]) ? trim($row[$col['official_name']]) : '';
        $lat  = isset($row[$col['latitude']])  ? trim($row[$col['latitude']])  : '';
        $lon  = isset($row[$col['longitude']]) ? trim($row[$col['longitude']]) : '';
        if ($name === '' || $lat === '' || $lon === '') continue;

        $lat = (float)$lat;
        $lon = (float)$lon;

        $dist = haversine_km($center_lat, $center_lon, $lat, $lon);
        if ($dist > $radius_km) continue;

        $elev_raw  = isset($col['elevation_m']) && isset($row[$col['elevation_m']])
                     ? trim($row[$col['elevation_m']]) : '';
        $elev = ($elev_raw !== '' && $elev_raw !== 'nan' && $elev_raw !== 'NaN')
                ? (float)$elev_raw : null;

        if ($min_elevation !== null) {
            if ($elev === null || $elev < $min_elevation) continue;
        }

        $huts[] = [
            'official_name'       => $name,
            'operating_club'      => isset($col['operating_club'], $row[$col['operating_club']])
                                     ? trim($row[$col['operating_club']]) : '',
            'hut_id'              => isset($col['hut_id'], $row[$col['hut_id']])
                                     ? trim($row[$col['hut_id']]) : '',
            'latitude'            => $lat,
            'longitude'           => $lon,
            'elevation_m'         => $elev,
            'official_website_url'=> isset($col['official_website_url'], $row[$col['official_website_url']])
                                     ? trim($row[$col['official_website_url']]) : '',
            'distance_km'         => round($dist, 1),
            'forecast'            => null,
            'daily_scores'        => [],
            'hut_score'           => null,
            'error'               => null,
        ];
    }
    fclose($fh);

    usort($huts, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    return $huts;
}

// ---------------------------------------------------------------------------
// Cache
// ---------------------------------------------------------------------------
function cache_key(float $lat, float $lon): string {
    return sprintf('%.4f_%.4f', $lat, $lon);
}

function load_cache(string $path): array {
    if (!file_exists($path)) return [];
    $data = @json_decode(file_get_contents($path), true);
    // Detect old per-search format (has 'generated_at' at root) and discard
    if (!is_array($data) || isset($data['generated_at'])) return [];
    return $data;
}

function save_cache(array $cache, string $path): void {
    $tmp = $path . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($tmp, $path);
}

function is_cache_entry_valid(array $entry): bool {
    if (empty($entry['fetched_at'])) return false;
    $age = time() - strtotime($entry['fetched_at']);
    return $age < CACHE_TTL;
}

// ---------------------------------------------------------------------------
// Weather fetching
// ---------------------------------------------------------------------------
function fetch_weather_batch(array $hut_chunk): array {
    $lats = implode(',', array_column($hut_chunk, 'latitude'));
    $lons = implode(',', array_column($hut_chunk, 'longitude'));

    $query = 'latitude=' . urlencode($lats)
           . '&longitude=' . urlencode($lons)
           . '&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_sum,windspeed_10m_max,precipitation_probability_max'
           . '&forecast_days=' . FORECAST_DAYS
           . '&timezone=auto';
    // Open-Meteo accepts %2C but raw commas are cleaner and definitely work
    $query = str_replace('%2C', ',', $query);

    $url = OPENMETEO_URL . '?' . $query;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => USER_AGENT,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log("Open-Meteo batch failed: $err");
        return array_fill(0, count($hut_chunk), null);
    }

    $decoded = json_decode($body, true);
    if ($decoded === null) {
        error_log("Open-Meteo JSON decode error for batch");
        return array_fill(0, count($hut_chunk), null);
    }

    // Single hut returns object, multiple return array
    if (isset($decoded['daily'])) {
        $decoded = [$decoded];
    }

    $results = [];
    foreach ($hut_chunk as $i => $_) {
        $results[] = isset($decoded[$i]['daily']) ? $decoded[$i]['daily'] : null;
    }
    return $results;
}

function fetch_all_weather(array &$huts, string $cache_path): array {
    $cache   = load_cache($cache_path);
    $stats   = ['fetched' => 0, 'cached' => 0];
    $now_str = date('c');

    // Identify which huts need fetching
    $to_fetch_indices = [];
    foreach ($huts as $i => $hut) {
        $key = cache_key($hut['latitude'], $hut['longitude']);
        if (isset($cache[$key]) && is_cache_entry_valid($cache[$key])) {
            $huts[$i]['forecast'] = $cache[$key]['forecast'];
            $stats['cached']++;
        } else {
            $to_fetch_indices[] = $i;
        }
    }

    // Batch fetch uncached huts
    $chunks = array_chunk($to_fetch_indices, BATCH_SIZE);
    foreach ($chunks as $ci => $chunk_indices) {
        if ($ci > 0) {
            usleep((int)(BATCH_PAUSE * 1_000_000));
        }
        $chunk = array_map(fn($i) => $huts[$i], $chunk_indices);
        $forecasts = fetch_weather_batch($chunk);

        foreach ($chunk_indices as $j => $hut_i) {
            $forecast = $forecasts[$j] ?? null;
            $huts[$hut_i]['forecast'] = $forecast;
            if ($forecast !== null) {
                $key = cache_key($huts[$hut_i]['latitude'], $huts[$hut_i]['longitude']);
                $cache[$key] = ['fetched_at' => $now_str, 'forecast' => $forecast];
                $stats['fetched']++;
            } else {
                $huts[$hut_i]['error'] = 'Fetch failed';
            }
        }
    }

    if ($stats['fetched'] > 0) {
        save_cache($cache, $cache_path);
    }

    return $stats;
}

// ---------------------------------------------------------------------------
// Scoring
// ---------------------------------------------------------------------------
function lerp(float $x, float $x0, float $x1, float $y0, float $y1): float {
    if ($x1 == $x0) return $y0;
    $t = max(0.0, min(1.0, ($x - $x0) / ($x1 - $x0)));
    return $y0 + $t * ($y1 - $y0);
}

function score_precipitation(?float $mm): float {
    if ($mm === null) return 50.0;
    if ($mm <= 0)  return 100.0;
    if ($mm <= 5)  return lerp($mm, 0, 5, 100, 50);
    if ($mm <= 20) return lerp($mm, 5, 20, 50, 0);
    return 0.0;
}

function score_wind(?float $kmh): float {
    if ($kmh === null) return 50.0;
    if ($kmh <= 20) return 100.0;
    if ($kmh <= 80) return lerp($kmh, 20, 80, 100, 0);
    return 0.0;
}

function score_weathercode(?int $code): float {
    if ($code === null) return 50.0;
    if ($code <= 2)  return 100.0;
    if ($code === 3) return 80.0;
    if ($code <= 48) return 60.0;
    if ($code <= 57) return 40.0;
    if ($code <= 67) return 20.0;
    if ($code <= 82) return 10.0;
    return 5.0;
}

function score_temperature(?float $tmax): float {
    if ($tmax === null) return 50.0;
    if ($tmax < 0)   return 0.0;
    if ($tmax < 5)   return lerp($tmax, 0, 5, 0, 50);
    if ($tmax <= 20) return lerp($tmax, 5, 20, 50, 100);
    if ($tmax <= 25) return 100.0;
    if ($tmax <= 35) return lerp($tmax, 25, 35, 100, 50);
    return 0.0;
}

function score_day(int $day, array $forecast): float {
    $get = function(string $field) use ($day, $forecast): mixed {
        $vals = $forecast[$field] ?? null;
        if ($vals === null || !isset($vals[$day])) return null;
        return $vals[$day];
    };

    $precip = $get('precipitation_sum');
    $wind   = $get('windspeed_10m_max');
    $wcode  = $get('weathercode');
    $tmax   = $get('temperature_2m_max');

    return 0.35 * score_precipitation($precip !== null ? (float)$precip : null)
         + 0.25 * score_wind($wind !== null ? (float)$wind : null)
         + 0.25 * score_weathercode($wcode !== null ? (int)$wcode : null)
         + 0.15 * score_temperature($tmax !== null ? (float)$tmax : null);
}

function score_all_huts(array &$huts): void {
    foreach ($huts as &$hut) {
        if ($hut['forecast'] === null) continue;
        $daily = [];
        for ($d = 0; $d < FORECAST_DAYS; $d++) {
            $daily[] = score_day($d, $hut['forecast']);
        }
        $hut['daily_scores'] = $daily;
        $hut['hut_score']    = array_sum($daily) / count($daily);
    }
    unset($hut);

    usort($huts, function($a, $b) {
        if ($a['hut_score'] === null && $b['hut_score'] === null) return 0;
        if ($a['hut_score'] === null) return 1;
        if ($b['hut_score'] === null) return -1;
        return $b['hut_score'] <=> $a['hut_score'];
    });
}

// ---------------------------------------------------------------------------
// WMO descriptions
// ---------------------------------------------------------------------------
function wmo_description(?int $code): string {
    static $map = [
        0  => 'Clear sky',         1  => 'Mainly clear',      2  => 'Partly cloudy',
        3  => 'Overcast',          45 => 'Fog',                48 => 'Rime fog',
        51 => 'Light drizzle',     53 => 'Drizzle',            55 => 'Heavy drizzle',
        56 => 'Freezing drizzle',  57 => 'Hvy frzg drizzle',  61 => 'Light rain',
        63 => 'Rain',              65 => 'Heavy rain',         66 => 'Freezing rain',
        67 => 'Hvy frzg rain',     71 => 'Light snow',         73 => 'Snow',
        75 => 'Heavy snow',        77 => 'Snow grains',        80 => 'Light showers',
        81 => 'Showers',           82 => 'Heavy showers',      85 => 'Snow showers',
        86 => 'Hvy snow showers',  95 => 'Thunderstorm',       96 => 'Tstorm+hail',
        99 => 'Tstorm+hvy hail',
    ];
    if ($code === null) return '—';
    return $map[$code] ?? "Code $code";
}

function wmo_icon(?int $code): string {
    if ($code === null) return '';
    if ($code === 0)             return '☀️';
    if ($code === 1)             return '🌤️';
    if ($code === 2)             return '⛅';
    if ($code === 3)             return '☁️';
    if ($code <= 48)             return '🌫️';  // fog / rime fog
    if ($code <= 57)             return '🌦️';  // drizzle
    if ($code <= 67)             return '🌧️';  // rain / freezing rain
    if ($code <= 77)             return '❄️';   // snow
    if ($code <= 82)             return '🌦️';  // showers
    if ($code <= 86)             return '🌨️';  // snow showers
    return '⛈️';                               // thunderstorm
}

// ---------------------------------------------------------------------------
// Rendering helpers
// ---------------------------------------------------------------------------
function score_color_class(?float $score): string {
    if ($score === null) return '';
    if ($score >= 75) return 'score-good';
    if ($score >= 50) return 'score-ok';
    return 'score-bad';
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function render_form(?string $location, ?float $distance, ?float $min_elevation): void {
    $loc  = h($location ?? '');
    $dist = $distance  !== null ? (int)$distance : 50;
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
      <p class="hint">Large radii (&gt;100 km) may take 1–2 minutes on first load. Results are cached for 24 h.</p>
    </form>
    HTML;
}

function render_error(string $message): void {
    $m = h($message);
    echo "<div class=\"error-box\">$m</div>\n";
}

function render_results(
    array   $huts,
    string  $display_name,
    float   $distance_km,
    ?float  $min_elevation,
    int     $fetched,
    int     $cached
): void {
    $total   = count($huts);
    $scored  = count(array_filter($huts, fn($h) => $h['hut_score'] !== null));
    $failed  = $total - $scored;
    $dn      = h($display_name);
    $dist    = (int)$distance_km;
    $elev_s  = $min_elevation !== null ? ', min elevation ' . (int)$min_elevation . ' m' : '';

    echo "<div class=\"result-summary\">";
    echo "Found <strong>$total</strong> huts within {$dist} km of <em>$dn</em>$elev_s";
    echo " &mdash; <strong>$fetched</strong> fetched from API, <strong>$cached</strong> served from cache";
    if ($failed > 0) echo " &mdash; <strong>$failed</strong> failed";
    echo "</div>\n";

    // Collect forecast dates from first scored hut
    $dates = [];
    foreach ($huts as $hut) {
        if ($hut['forecast'] !== null && !empty($hut['forecast']['time'])) {
            $dates = array_slice($hut['forecast']['time'], 0, FORECAST_DAYS);
            break;
        }
    }
    $day_labels = array_map(fn($d) => date('D d.m', strtotime($d)), $dates);

    echo '<div style="overflow-x:auto">';
    echo '<table class="results-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th rowspan="2">#</th>';
    echo '<th rowspan="2">Hut</th>';
    echo '<th rowspan="2">Club</th>';
    echo '<th rowspan="2">Dist</th>';
    echo '<th rowspan="2">Elev</th>';
    echo '<th rowspan="2">Score</th>';
    foreach ($day_labels as $lbl) {
        echo '<th colspan="5" class="day-header">' . h($lbl) . '</th>';
    }
    echo '</tr>';
    echo '<tr>';
    for ($d = 0; $d < count($day_labels); $d++) {
        echo '<th class="sub-hdr">Weather</th>';
        echo '<th class="sub-hdr">Temp</th>';
        echo '<th class="sub-hdr">Precip</th>';
        echo '<th class="sub-hdr">Wind</th>';
        echo '<th class="sub-hdr">Score</th>';
    }
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    $rank = 1;
    foreach ($huts as $hut) {
        $name     = h($hut['official_name']);
        $club     = h($hut['operating_club'] ?? '');
        $url      = $hut['official_website_url'] ?? '';
        $name_td  = $url !== '' ? "<a href=\"" . h($url) . "\" target=\"_blank\">$name</a>" : $name;
        $dist_s   = $hut['distance_km'] . ' km';
        $elev_s2  = $hut['elevation_m'] !== null ? (int)$hut['elevation_m'] . ' m' : '?';
        $score    = $hut['hut_score'];
        $score_s  = $score !== null ? number_format($score, 1) : '—';
        $rank_s   = $score !== null ? $rank : '—';
        $score_cls = score_color_class($score);

        echo "<tr>";
        echo "<td class=\"rank\">$rank_s</td>";
        echo "<td class=\"hut-name\">$name_td</td>";
        echo "<td>$club</td>";
        echo "<td class=\"num\">$dist_s</td>";
        echo "<td class=\"num\">$elev_s2</td>";
        echo "<td class=\"num $score_cls\">$score_s</td>";

        if ($hut['forecast'] !== null) {
            for ($d = 0; $d < FORECAST_DAYS; $d++) {
                $fc      = $hut['forecast'];
                $wcode   = isset($fc['weathercode'][$d]) ? (int)$fc['weathercode'][$d] : null;
                $tmax    = isset($fc['temperature_2m_max'][$d]) ? (float)$fc['temperature_2m_max'][$d] : null;
                $precip  = isset($fc['precipitation_sum'][$d]) ? (float)$fc['precipitation_sum'][$d] : null;
                $wind    = isset($fc['windspeed_10m_max'][$d]) ? (float)$fc['windspeed_10m_max'][$d] : null;
                $dscore  = $hut['daily_scores'][$d] ?? null;

                $wmo_s   = wmo_icon($wcode) . ' ' . wmo_description($wcode);
                $tmax_s  = $tmax !== null ? number_format($tmax, 1) . '°' : '—';
                $prec_s  = $precip !== null ? number_format($precip, 1) . ' mm' : '—';
                $wind_s  = $wind !== null ? (int)$wind . ' km/h' : '—';
                $ds_s    = $dscore !== null ? number_format($dscore, 1) : '—';
                $ds_cls  = score_color_class($dscore);

                echo "<td class=\"weather\">$wmo_s</td>";
                echo "<td class=\"num\">$tmax_s</td>";
                echo "<td class=\"num\">$prec_s</td>";
                echo "<td class=\"num\">$wind_s</td>";
                echo "<td class=\"num $ds_cls\">$ds_s</td>";
            }
        } else {
            $colspan = FORECAST_DAYS * 5;
            $err = h($hut['error'] ?? 'No data');
            echo "<td colspan=\"$colspan\" class=\"fetch-error\">$err</td>";
        }

        echo "</tr>\n";
        if ($score !== null) $rank++;
    }

    echo '</tbody></table></div>';
}

// ---------------------------------------------------------------------------
// HTML page skeleton
// ---------------------------------------------------------------------------
$page_title = 'Alpine Hut Weather Planner';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($page_title) ?></title>
  <style>
    :root {
      --green:     #155724; --green-bg:  #d4edda;
      --yellow:    #856404; --yellow-bg: #fff3cd;
      --red:       #721c24; --red-bg:    #f8d7da;
      --border:    #dee2e6;
      --hdr-bg:    #343a40; --hdr-fg: #fff;
      --sub-bg:    #495057;
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
    input[type=text], input[type=number] {
      padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: .95rem; }
    button[type=submit] { padding: 8px 22px; background: #0d6efd; color: #fff; border: none;
                          border-radius: 4px; cursor: pointer; font-size: .95rem; font-weight: 600; }
    button[type=submit]:hover { background: #0b5ed7; }
    .hint { margin: .6rem 0 0; font-size: .78rem; color: #6c757d; }

    .error-box { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;
                 border-radius: 6px; padding: .75rem 1rem; margin-bottom: 1rem; }

    .result-summary { background: #fff; border: 1px solid var(--border); border-radius: 6px;
                      padding: .6rem 1rem; margin-bottom: .75rem; font-size: .9rem; }

    .searching { background: #fff3cd; color: #856404; border: 1px solid #ffeeba;
                 border-radius: 6px; padding: .75rem 1rem; margin-bottom: 1rem; font-weight: 600; }

    .results-table { border-collapse: collapse; width: 100%; font-size: .82rem;
                     background: #fff; border-radius: 6px; overflow: hidden; }
    .results-table th { background: var(--hdr-bg); color: var(--hdr-fg);
                        padding: 6px 8px; text-align: center; white-space: nowrap; }
    .results-table th.day-header { background: var(--sub-bg); }
    .results-table th.sub-hdr { background: #6c757d; font-size: .75rem; font-weight: 500; }
    .results-table td { border: 1px solid var(--border); padding: 5px 8px;
                        vertical-align: middle; }
    .results-table tr:nth-child(even) td { background: #f8f9fa; }
    .results-table tr:hover td { background: #e9f0ff; }
    .results-table td.rank { text-align: right; font-weight: 700; color: #6c757d; }
    .results-table td.hut-name { font-weight: 600; }
    .results-table td.hut-name a { color: #0d6efd; text-decoration: none; }
    .results-table td.hut-name a:hover { text-decoration: underline; }
    .results-table td.num { text-align: right; }
    .results-table td.weather { white-space: nowrap; }
    .results-table td.fetch-error { color: #721c24; font-style: italic; font-size: .8rem; }

    .score-good { background: var(--green-bg) !important; color: var(--green); font-weight: 700; }
    .score-ok   { background: var(--yellow-bg) !important; color: var(--yellow); font-weight: 700; }
    .score-bad  { background: var(--red-bg) !important; color: var(--red); font-weight: 700; }

    footer { margin-top: 1.5rem; text-align: center; font-size: .78rem; color: #6c757d; }
  </style>
</head>
<body>
<header>
  <h1>Alpine Hut Weather Planner</h1>
  <p>Find and rank alpine huts by 5-day weather forecast</p>
</header>

<?php

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------
$location      = isset($_GET['location']) ? trim($_GET['location']) : null;
$distance_km   = isset($_GET['distance']) && $_GET['distance'] !== '' ? (float)$_GET['distance'] : 50.0;
$min_elevation = isset($_GET['min_elevation']) && $_GET['min_elevation'] !== '' ? (float)$_GET['min_elevation'] : null;

render_form($location, $distance_km, $min_elevation);

if ($location !== null && $location !== '') {

    if ($distance_km <= 0 || $distance_km > 500) {
        render_error("Distance must be between 1 and 500 km.");
    } else {
        // Show "please wait" banner immediately
        echo '<div class="searching" id="wait-msg">&#8987; Fetching weather data&hellip; please wait.</div>';
        flush();

        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $geo     = geocode($location);
            $huts    = load_and_filter_huts(CSV_PATH, $geo['lat'], $geo['lon'], $distance_km, $min_elevation);

            if (empty($huts)) {
                $elev_hint = $min_elevation !== null ? " above {$min_elevation} m elevation" : '';
                render_error("No huts found within {$distance_km} km of &ldquo;" . h($location) . "&rdquo;$elev_hint.");
            } else {
                $stats = fetch_all_weather($huts, CACHE_PATH);
                score_all_huts($huts);

                // Remove the "please wait" banner via JS
                echo '<script>var w=document.getElementById("wait-msg");if(w)w.remove();</script>';
                render_results($huts, $geo['display_name'], $distance_km, $min_elevation,
                               $stats['fetched'], $stats['cached']);
            }
        } catch (RuntimeException $e) {
            echo '<script>var w=document.getElementById("wait-msg");if(w)w.remove();</script>';
            render_error($e->getMessage());
        }
    }
}
?>

<footer>
  Data: <a href="https://www.sac-cas.ch" target="_blank">SAC</a> &amp;
  <a href="https://www.openstreetmap.org" target="_blank">OpenStreetMap</a> &mdash;
  Weather: <a href="https://open-meteo.com" target="_blank">Open-Meteo</a> &mdash;
  Geocoding: <a href="https://nominatim.openstreetmap.org" target="_blank">Nominatim</a>
</footer>
</body>
</html>
