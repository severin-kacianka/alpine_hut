<?php
// update_cache.php — pre-download hut-reservation.org availability data to local files
// Usage (CLI):  php update_cache.php <start_id> <end_id>
// Usage (web):  update_cache.php?start_id=1&end_id=500
//
// IDs with name NOT_FOUND in data/hut_reservation_scraped.csv are skipped (shown as NF).
// Files older than 24 h are refreshed; fresh files are skipped.

define('CACHE_DIR',       __DIR__ . '/reservation_cache');
define('CSV_PATH',        __DIR__ . '/data/hut_reservation_scraped.csv');
define('CACHE_TTL',       86400 / 2);  // 12 h in seconds
define('AVAIL_URL',       'https://www.hut-reservation.org/api/v1/reservation/getHutAvailability');
define('FETCH_PAUSE_SEC', 0.5);
define('LOG_FILE',        __DIR__ . '/reservation_cache/update_log.txt');

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------
$_log_fh = null;

function log_open(): void {
    global $_log_fh;
    // Ensure directory exists before opening log
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
    $_log_fh = fopen(LOG_FILE, 'a');
}

function log_line(string $msg): void {
    global $_log_fh;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    if ($_log_fh) {
        fwrite($_log_fh, $line);
        fflush($_log_fh);
    }
}

function log_close(): void {
    global $_log_fh;
    if ($_log_fh) {
        fclose($_log_fh);
        $_log_fh = null;
    }
}

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
// Load valid IDs from CSV (name = NOT_FOUND means the ID doesn't exist)
// ---------------------------------------------------------------------------
$valid_ids = array();  // id => true for fetchable IDs
$fh = fopen(CSV_PATH, 'r');
if ($fh !== false) {
    $hdr = fgetcsv($fh);
    if ($hdr !== false) {
        $hcol = array_flip($hdr);
        while (($row = fgetcsv($fh)) !== false) {
            $rid  = isset($hcol['id'],   $row[$hcol['id']])   ? (int)trim($row[$hcol['id']])   : 0;
            $name = isset($hcol['name'], $row[$hcol['name']]) ? trim($row[$hcol['name']]) : '';
            if ($rid > 0 && strtoupper($name) !== 'NOT_FOUND' && $name !== '') {
                $valid_ids[$rid] = true;
            }
        }
    }
    fclose($fh);
}

// ---------------------------------------------------------------------------
// Ensure cache directory exists
// ---------------------------------------------------------------------------
if (!is_dir(CACHE_DIR)) {
    if (!mkdir(CACHE_DIR, 0755, true)) {
        echo "Error: could not create cache directory: " . CACHE_DIR . "\n";
        exit(1);
    }
}

log_open();
log_line("=== Run started: IDs $start_id–$end_id ===");

if (!is_dir(CACHE_DIR)) {
    log_line("Created cache directory: " . CACHE_DIR);
}

// ---------------------------------------------------------------------------
// Fetch loop
// ---------------------------------------------------------------------------
$now      = time();
$fetched  = 0;
$skipped  = 0;
$failed   = 0;
$total    = $end_id - $start_id + 1;

log_line("Updating reservation cache for IDs $start_id–$end_id ($total total)...");

for ($id = $start_id; $id <= $end_id; $id++) {
    $file = CACHE_DIR . '/' . $id;

    // Skip IDs marked NOT_FOUND or absent in the CSV
    if (!isset($valid_ids[$id])) {
        log_line("NF    $id");
        continue;
    }

    // Skip if file is fresh
    if (file_exists($file) && ($now - filemtime($file)) < CACHE_TTL) {
        log_line("SKIP  $id");
        $skipped++;
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
        log_line("FAIL  $id  (curl error: $err)");
        $failed++;
        usleep((int)(FETCH_PAUSE_SEC * 1000000));
        continue;
    }

    if ($code !== 200) {
        log_line("FAIL  $id  (HTTP $code)");
        $failed++;
        if ($code === 403) {
            log_line("--- HTTP 403, pausing 5s ---");
            sleep(5);
        } else {
            usleep((int)(FETCH_PAUSE_SEC * 1000000));
        }
        continue;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        log_line("FAIL  $id  (invalid JSON)");
        $failed++;
        usleep((int)(FETCH_PAUSE_SEC * 1000000));
        continue;
    }

    // Write raw JSON body to cache file
    if (file_put_contents($file, $body) === false) {
        log_line("FAIL  $id  (could not write file)");
        $failed++;
        usleep((int)(FETCH_PAUSE_SEC * 1000000));
        continue;
    }

    log_line("OK    $id");
    $fetched++;

    if ($fetched % 10 === 0) {
        log_line("--- pausing 5s after $fetched successful fetches ---");
        sleep(5);
    } else {
        usleep((int)(FETCH_PAUSE_SEC * 1000000));
    }
}

log_line("Done. Fetched: $fetched  Skipped: $skipped  Failed: $failed");
log_line("=== Run finished ===");
log_close();
