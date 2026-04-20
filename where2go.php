<?php
// where2go.php — Find huts with enough free beds, ranked by 7-day weather score.
// Reads reservation_cache/<id> for availability and forecasts/<id> for weather.

setlocale(LC_ALL, 'C');

define('CSV_PATH',      __DIR__ . '/data/alpine_huts_full.csv');
define('CACHE_DIR',     __DIR__ . '/reservation_cache');
define('FORECAST_DIR',  __DIR__ . '/forecasts');
define('BOOKING_BASE',  'https://www.hut-reservation.org/reservation/book-hut/');

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
// Scoring — mirrors legacy/hut_search.py exactly
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
    if ($code == 3)  return 80.0;
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

// Score a single day from the daily arrays; $i is the day index.
function score_day(int $i, array $daily): float {
    $get = function(string $field) use ($i, $daily): ?float {
        $vals = $daily[$field] ?? [];
        return isset($vals[$i]) && $vals[$i] !== null ? (float)$vals[$i] : null;
    };
    return 0.35 * score_precipitation($get('precipitation_sum'))
         + 0.25 * score_wind($get('windspeed_10m_max'))
         + 0.25 * score_weathercode(isset($daily['weathercode'][$i]) ? (int)$daily['weathercode'][$i] : null)
         + 0.15 * score_temperature($get('temperature_2m_max'));
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

$WMO = [
    0  => 'Clear',          1  => 'Mainly clear',    2  => 'Partly cloudy',
    3  => 'Overcast',       45 => 'Fog',              48 => 'Rime fog',
    51 => 'Lt drizzle',     53 => 'Drizzle',          55 => 'Hvy drizzle',
    56 => 'Frzg drizzle',   57 => 'Hvy frzg drzl',   61 => 'Lt rain',
    63 => 'Rain',           65 => 'Hvy rain',         66 => 'Frzg rain',
    67 => 'Hvy frzg rain',  71 => 'Lt snow',          73 => 'Snow',
    75 => 'Hvy snow',       77 => 'Snow grains',      80 => 'Lt showers',
    81 => 'Showers',        82 => 'Hvy showers',      85 => 'Snow showers',
    86 => 'Hvy snow shwrs', 95 => 'Thunderstorm',     96 => 'Tstorm+hail',
    99 => 'Tstorm+hvy hail',
];

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

$results   = [];
$too_full  = [];
$error     = null;
$stats     = ['total' => 0, 'with_forecast' => 0, 'passed_beds' => 0];
// Canonical 7-day date list starting today
$date_list = [];
for ($i = 0; $i < 7; $i++) {
    $date_list[] = date('Y-m-d', strtotime("+{$i} days"));
}

if ($min_beds_input !== null) {
    try {
        $huts = load_huts_with_reservation();
        $stats['total'] = count($huts);

        foreach ($huts as &$hut) {
            $rid = $hut['reservation_id'];

            // Availability check
            $avail = min_free_beds($rid, 7);
            if ($avail === null) continue;

            if ($avail['min_free'] < $min_beds_input) {
                // Open but not enough beds — collect for second table
                if ($avail['is_open']) {
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Where to Go — Alpine Hut Weather Ranker</title>
  <style>
    :root {
      --border:     #dee2e6;
      --hdr-bg:     #343a40; --hdr-fg: #fff;
      --sub-bg:     #495057;
      --green:      #155724; --green-bg:  #d4edda;
      --red:        #721c24; --red-bg:    #f8d7da;
      --yellow:     #856404; --yellow-bg: #fff3cd;
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
    input[type=number] { padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px;
                         font-size: .95rem; width: 7rem; }
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

    footer { margin-top: 1.5rem; text-align: center; font-size: .78rem; color: #6c757d; }
  </style>
</head>
<body>
<header>
  <h1>Where to Go</h1>
  <p>Find huts with available beds, ranked by 7-day weather forecast quality</p>
</header>

<?php
// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
$beds_val = $min_beds_input !== null ? $min_beds_input : '';
echo '<div class="search-form">';
echo '<h2>Search</h2>';
echo '<form method="get" action="">';
echo '<div class="form-row">';
echo '<div class="form-group">';
echo '<label for="min_beds">Minimum free beds</label>';
echo '<input type="number" id="min_beds" name="min_beds" min="1" step="1" value="' . h((string)$beds_val) . '" placeholder="e.g. 4">';
echo '</div>';
echo '<div class="form-group">';
echo '<button type="submit">Search</button>';
echo '</div>';
echo '</div>';
echo '<p class="hint">Availability is read from local cache </p>';
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
        echo "Showing <strong>$n</strong> hut(s) with &ge; <strong>{$min_beds_input}</strong> free bed(s) over the next 7 days, ranked by average weather score.";
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
            echo '<th class="sub-hdr">wx / temp / precip</th>';
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

            // Weather row
            echo "<tr>";
            echo "<td class=\"rank\" rowspan=\"2\">$rank</td>";
            echo "<td class=\"hut-name\" rowspan=\"2\">$name_td</td>";
            echo "<td rowspan=\"2\">" . h($club) . "</td>";
            echo "<td class=\"num elev\" rowspan=\"2\">$elev_s</td>";
            echo "<td class=\"num score $score_cls\" rowspan=\"2\">" . number_format($score, 1) . "</td>";
            echo "<td class=\"reservation\" rowspan=\"2\">$book_td</td>";

            global $WMO;
            for ($i = 0; $i < $num_days; $i++) {
                $code   = isset($daily['weathercode'][$i])        ? (int)$daily['weathercode'][$i]          : null;
                $tmax   = isset($daily['temperature_2m_max'][$i]) ? (float)$daily['temperature_2m_max'][$i] : null;
                $precip = isset($daily['precipitation_sum'][$i])  ? (float)$daily['precipitation_sum'][$i]  : null;
                $ds     = score_day($i, $daily);

                if ($ds >= 75)      $wx_cls = 'wx-great';
                elseif ($ds >= 55)  $wx_cls = 'wx-good';
                elseif ($ds >= 35)  $wx_cls = 'wx-ok';
                else                $wx_cls = 'wx-poor';

                $wmo_label = $code !== null ? ($WMO[$code] ?? "WMO$code") : '?';
                $temp_s    = $tmax   !== null ? number_format($tmax, 0) . '°'    : '?';
                $prec_s    = $precip !== null ? number_format($precip, 1) . 'mm' : '?';

                echo "<td class=\"day-cell $wx_cls\">";
                echo h($wmo_label) . '<br>' . h($temp_s) . ' / ' . h($prec_s);
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

            echo "<tr>";
            echo "<td class=\"rank\">$rank</td>";
            echo "<td class=\"hut-name\">$name_td</td>";
            echo "<td>" . h($club) . "</td>";
            echo "<td class=\"num elev\">$elev_s</td>";
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

<footer>
  Data: <a href="https://www.sac-cas.ch" target="_blank">SAC</a> &amp;
  <a href="https://www.openstreetmap.org" target="_blank">OpenStreetMap</a> &mdash;
  Availability: <a href="https://www.hut-reservation.org" target="_blank">hut-reservation.org</a> &mdash;
  Weather: <a href="https://open-meteo.com" target="_blank">Open-Meteo</a>
</footer>
</body>
</html>
