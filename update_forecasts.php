<?php
// update_forecasts.php — pre-download Open-Meteo 7-day forecasts for huts with a reservation_id
// Usage (CLI):  php update_forecasts.php
// Usage (web):  update_forecasts.php
//
// Reads data/alpine_huts_full.csv, fetches one forecast per hut that has a reservation_id,
// and writes the raw JSON response to forecasts/<reservation_id>.
// Files fresher than 6 h are skipped. Sleeps 0.5 s between requests.

define('CSV_FILE',      __DIR__ . '/data/alpine_huts_full.csv');
define('FORECAST_DIR',  __DIR__ . '/forecasts');
define('METEO_URL',     'https://api.open-meteo.com/v1/forecast');
define('CACHE_TTL',     6 * 3600);   // 6 h in seconds
define('SLEEP_US',      500000);     // 0.5 s
define('FORECAST_DAYS', 7);
define('DAILY_VARS',    'weathercode,temperature_2m_max,temperature_2m_min,precipitation_sum,windspeed_10m_max,precipitation_probability_max');

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------
function log_line(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

if (!is_dir(FORECAST_DIR)) {
    if (!mkdir(FORECAST_DIR, 0755, true)) {
        echo "Error: could not create forecast directory: " . FORECAST_DIR . "\n";
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// Parse CSV
// ---------------------------------------------------------------------------
$fh = fopen(CSV_FILE, 'r');
if ($fh === false) {
    echo "Error: could not open CSV: " . CSV_FILE . "\n";
    exit(1);
}

$hdr  = fgetcsv($fh);
$col  = array_flip($hdr);
$huts = array();

while (($row = fgetcsv($fh)) !== false) {
    $rid = isset($col['reservation_id'], $row[$col['reservation_id']])
        ? trim($row[$col['reservation_id']])
        : '';
    if ($rid === '') {
        continue;
    }
    $huts[] = array(
        'reservation_id' => $rid,
        'name'           => trim($row[$col['official_name']]),
        'lat'            => trim($row[$col['latitude']]),
        'lon'            => trim($row[$col['longitude']]),
    );
}
fclose($fh);

// ---------------------------------------------------------------------------
// Fetch loop
// ---------------------------------------------------------------------------
$now      = time();
$fetched  = 0;
$skipped  = 0;
$failed   = 0;
$total    = count($huts);

log_line("=== Run started: $total huts with reservation_id ===");

foreach ($huts as $hut) {
    $rid  = $hut['reservation_id'];
    $file = FORECAST_DIR . '/' . $rid;

    // Skip if file is fresh
    if (file_exists($file) && ($now - filemtime($file)) < CACHE_TTL) {
        log_line("SKIP  $rid  ({$hut['name']})");
        $skipped++;
        continue;
    }

    // Build URL
    $params = http_build_query(array(
        'latitude'      => $hut['lat'],
        'longitude'     => $hut['lon'],
        'daily'         => DAILY_VARS,
        'forecast_days' => FORECAST_DAYS,
        'timezone'      => 'auto',
    ));
    $url = METEO_URL . '?' . $params;

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'alpine-hut-search/1.0',
    ));
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $body === false) {
        log_line("FAIL  $rid  (curl error: $err)");
        $failed++;
        usleep(SLEEP_US);
        continue;
    }

    if ($code !== 200) {
        log_line("FAIL  $rid  (HTTP $code)");
        $failed++;
        usleep(SLEEP_US);
        continue;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['daily'])) {
        log_line("FAIL  $rid  (invalid JSON or missing daily key)");
        $failed++;
        usleep(SLEEP_US);
        continue;
    }

    if (file_put_contents($file, $body) === false) {
        log_line("FAIL  $rid  (could not write file)");
        $failed++;
        usleep(SLEEP_US);
        continue;
    }

    log_line("OK    $rid  ({$hut['name']})");
    $fetched++;
    usleep(SLEEP_US);
}

log_line("Done. Fetched: $fetched  Skipped: $skipped  Failed: $failed");
log_line("=== Run finished ===");
