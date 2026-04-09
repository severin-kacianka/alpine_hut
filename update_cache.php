<?php
// update_cache.php — pre-download hut-reservation.org availability data to local files
// Usage (CLI):  php update_cache.php <start_id> <end_id>
// Usage (web):  update_cache.php?start_id=1&end_id=500
//
// Creates/updates reservation_cache/<N> for each ID in the range.
// Files older than 24 h are refreshed; fresh files are skipped.

define('CACHE_DIR',       __DIR__ . '/reservation_cache');
define('CACHE_TTL',       86400);  // 24 h in seconds
define('AVAIL_URL',       'https://www.hut-reservation.org/api/v1/reservation/getHutAvailability');
define('FETCH_PAUSE_SEC', 0.5);

// ---------------------------------------------------------------------------
// Parse + validate inputs
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli') {
    $start_id = isset($argv[1]) ? $argv[1] : null;
    $end_id   = isset($argv[2]) ? $argv[2] : null;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $start_id = isset($_GET['start_id']) ? $_GET['start_id'] : null;
    $end_id   = isset($_GET['end_id'])   ? $_GET['end_id']   : null;
}

if ($start_id === null || $end_id === null
    || !ctype_digit((string)$start_id) || !ctype_digit((string)$end_id)) {
    $usage = "Usage: php update_cache.php <start_id> <end_id>\n"
           . "  Both IDs must be positive integers.\n"
           . "  Example: php update_cache.php 1 500\n";
    if (PHP_SAPI !== 'cli') {
        echo nl2br(htmlspecialchars($usage, ENT_QUOTES, 'UTF-8'));
    } else {
        echo $usage;
    }
    exit(1);
}

$start_id = (int)$start_id;
$end_id   = (int)$end_id;

if ($start_id < 1 || $end_id < $start_id) {
    echo "Error: start_id must be >= 1 and end_id must be >= start_id.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Ensure cache directory exists
// ---------------------------------------------------------------------------
if (!is_dir(CACHE_DIR)) {
    if (!mkdir(CACHE_DIR, 0755, true)) {
        echo "Error: could not create cache directory: " . CACHE_DIR . "\n";
        exit(1);
    }
    echo "Created cache directory: " . CACHE_DIR . "\n";
}

// ---------------------------------------------------------------------------
// Fetch loop
// ---------------------------------------------------------------------------
$now      = time();
$fetched  = 0;
$skipped  = 0;
$failed   = 0;
$total    = $end_id - $start_id + 1;

echo "Updating reservation cache for IDs $start_id–$end_id ($total total)...\n";
flush();

for ($id = $start_id; $id <= $end_id; $id++) {
    $file = CACHE_DIR . '/' . $id;

    // Skip if file is fresh
    if (file_exists($file) && ($now - filemtime($file)) < CACHE_TTL) {
        echo "SKIP  $id\n";
        $skipped++;
        flush();
        continue;
    }

    // Fetch from API
    $url = AVAIL_URL . '?hutId=' . $id . '&step=WIZARD';
    $ch  = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => array(
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
        ),
    ));
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $body === false) {
        echo "FAIL  $id  (curl error: $err)\n";
        $failed++;
        flush();
        usleep((int)(FETCH_PAUSE_SEC * 1000000));
        continue;
    }

    if ($code !== 200) {
        echo "FAIL  $id  (HTTP $code)\n";
        $failed++;
        flush();
        if ($code === 403) {
            echo "--- HTTP 403, pausing 5s ---\n";
            flush();
            sleep(5);
        } else {
            usleep((int)(FETCH_PAUSE_SEC * 1000000));
        }
        continue;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        echo "FAIL  $id  (invalid JSON)\n";
        $failed++;
        flush();
        usleep((int)(FETCH_PAUSE_SEC * 1000000));
        continue;
    }

    // Write raw JSON body to cache file
    if (file_put_contents($file, $body) === false) {
        echo "FAIL  $id  (could not write file)\n";
        $failed++;
        flush();
        usleep((int)(FETCH_PAUSE_SEC * 1000000));
        continue;
    }

    echo "OK    $id\n";
    $fetched++;
    flush();

    if ($fetched % 10 === 0) {
        echo "--- pausing 5s after $fetched successful fetches ---\n";
        flush();
        sleep(5);
    } else {
        usleep((int)(FETCH_PAUSE_SEC * 1000000));
    }
}

echo "\nDone. Fetched: $fetched  Skipped: $skipped  Failed: $failed\n";
