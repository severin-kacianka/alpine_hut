<?php
// slotfinder.php — Find, independently per hut, every date within a range where a hut has
// enough free beds for a group, split into weekend and (optionally) weekday nights.
// Reads reservation_cache/<id> for availability, populated by update_cache.php.

define('CSV_PATH',     __DIR__ . '/data/alpine_huts_full.csv');
define('CACHE_DIR',    __DIR__ . '/reservation_cache');
define('CACHE_TTL',    86400 / 2);   // 12 h in seconds, matches update_cache.php
define('BOOKING_BASE', 'https://www.hut-reservation.org/reservation/book-hut/');

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
// Data loading
// ---------------------------------------------------------------------------

/** Load huts that have a reservation_id from the CSV, keyed by reservation_id. */
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
        if ($name === '') continue;

        $elev_raw = trim($row[$col['elevation_m']] ?? '');
        $elev     = ($elev_raw !== '' && strtolower($elev_raw) !== 'nan') ? (float)$elev_raw : null;

        $huts[$rid] = [
            'official_name'        => $name,
            'operating_club'       => trim($row[$col['operating_club']]       ?? ''),
            'elevation_m'          => $elev,
            'official_website_url' => trim($row[$col['official_website_url']] ?? ''),
            'reservation_id'       => $rid,
        ];
    }
    fclose($fh);
    return $huts;
}

/**
 * Find every date within [$start, $end] where the hut's cached availability reports at
 * least $min_people free beds, evaluated independently day by day. Returns null if no
 * cache file exists for the hut at all.
 */
function find_matching_dates(string $rid, int $min_people, string $start, string $end, bool $include_weekdays): ?array {
    $file = CACHE_DIR . '/' . $rid;
    if (!file_exists($file)) return null;

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return null;

    $cache_age = time() - filemtime($file);
    $matches   = [];

    foreach ($data as $entry) {
        $d = substr($entry['date'] ?? '', 0, 10);
        if ($d === '' || $d < $start || $d > $end) continue;

        $dow        = (int)date('N', strtotime($d));   // 1=Mon .. 7=Sun
        $is_weekend = ($dow === 5 || $dow === 6);       // Friday or Saturday night
        if (!$is_weekend && !$include_weekdays) continue;

        $status = $entry['hutStatus'] ?? '';
        $free   = $entry['freeBeds']  ?? null;
        if ($status === 'CLOSED') continue;
        if ($status === 'FULL' || $free === 0) continue;
        if ($free === null || (int)$free < $min_people) continue;

        $matches[] = ['date' => $d, 'free' => (int)$free, 'is_weekend' => $is_weekend];
    }

    usort($matches, fn($a, $b) => strcmp($a['date'], $b['date']));

    return ['cache_age' => $cache_age, 'matches' => $matches];
}

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------
$all_huts = load_huts_with_reservation();

$selected_rids     = isset($_GET['huts']) && is_array($_GET['huts'])
                   ? array_values(array_intersect($_GET['huts'], array_keys($all_huts))) : [];
$min_people        = isset($_GET['min_people']) && $_GET['min_people'] !== ''
                   ? max(1, (int)$_GET['min_people']) : null;
$start_date        = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : date('Y-m-d');
$end_date          = isset($_GET['end_date'])   && $_GET['end_date']   !== '' ? $_GET['end_date']   : date('Y-m-d', strtotime('+90 days'));
$include_weekdays  = isset($_GET['include_weekdays']);
$submitted         = $min_people !== null;

$results = [];   // rid => ['hut' => ..., 'cache_age' => int|null, 'matches' => [...], 'no_cache' => bool]

if ($submitted && !empty($selected_rids)) {
    foreach ($selected_rids as $rid) {
        $hut = $all_huts[$rid] ?? null;
        if ($hut === null) continue;

        $found = find_matching_dates($rid, $min_people, $start_date, $end_date, $include_weekdays);
        $results[$rid] = [
            'hut'       => $hut,
            'no_cache'  => ($found === null),
            'cache_age' => $found['cache_age'] ?? null,
            'matches'   => $found['matches']   ?? [],
        ];
    }
}
require_once __DIR__ . '/layout.php';
render_page_head(
    'Slot Finder — Alpine Hut Weather Planner',
    'Slot Finder',
    'Pick huts and a group size, find every date each hut alone has enough free beds',
    'slotfinder'
);
?>
<style>
    .hut-picker       { margin-top: .9rem; }
    .hut-picker-label { font-size: .8rem; font-weight: 600; color: #495057; margin-bottom: .35rem; }
    .toggle-btn       { margin-left: .4rem; padding: 1px 8px; font-size: .75rem; background: #e9ecef;
                        border: 1px solid #ced4da; border-radius: 3px; cursor: pointer; }
    .toggle-btn:hover { background: #dee2e6; }
    .hut-list  { max-height: 12rem; overflow-y: auto; border: 1px solid #ced4da; border-radius: 4px;
                 padding: .5rem; margin-top: .3rem; background: #fbfbfb; }
    .hut-item  { font-size: .85rem; display: flex; align-items: center; gap: .4rem;
                 padding: 2px 0; cursor: pointer; }
    .hut-item input { cursor: pointer; }
    .checkbox-row { display: flex; align-items: center; gap: .4rem; margin-top: .6rem; font-size: .85rem; }

    .hut-result { background: #fff; border: 1px solid var(--border); border-radius: 6px;
                  padding: .9rem 1.1rem; margin-bottom: 1rem; }
    .hut-result h3 { margin: 0 0 .3rem; font-size: 1.05rem; }
    .hut-result .meta { font-size: .8rem; color: #6c757d; margin-bottom: .6rem; }
    .hut-result .meta a { color: #0d6efd; text-decoration: none; }
    .hut-result .meta a:hover { text-decoration: underline; }

    .results-table { border-collapse: collapse; width: 100%; font-size: .85rem; }
    .results-table th { background: var(--hdr-bg); color: var(--hdr-fg); padding: 5px 8px; text-align: left; }
    .results-table td { border: 1px solid var(--border); padding: 5px 8px; }
    .results-table tr:nth-child(even) td { background: #f8f9fa; }
    .avail-open { background: var(--green-bg) !important; color: var(--green); font-weight: 700; }
    .tag-weekend { background: #cfe2ff; color: #084298; border-radius: 3px; padding: 1px 6px; font-size: .72rem; }
    .tag-weekday { background: #e9ecef; color: #495057; border-radius: 3px; padding: 1px 6px; font-size: .72rem; }
</style>

<?php
// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
$people_val = $min_people !== null ? $min_people : '';
echo '<div class="search-form">';
echo '<h2>Search</h2>';
echo '<form method="get" action="">';

echo '<div class="hut-picker">';
echo '<div class="hut-picker-label">Huts'
   . '<button type="button" class="toggle-btn" onclick="clearHuts()">Clear</button>'
   . '</div>';
echo '<input type="text" id="hut-filter" placeholder="Filter by name..." oninput="filterHuts()">';
echo '<div class="hut-list" id="hut-list">';
$sorted = $all_huts;
uasort($sorted, fn($a, $b) => strcasecmp($a['official_name'], $b['official_name']));
foreach ($sorted as $rid => $hut) {
    $checked = in_array($rid, $selected_rids) ? ' checked' : '';
    echo '<label class="hut-item" data-name="' . h(strtolower($hut['official_name'])) . '">'
       . '<input type="checkbox" name="huts[]" value="' . h($rid) . '"' . $checked . '> '
       . h($hut['official_name'])
       . '</label>';
}
echo '</div>';
echo '</div>';

echo '<div class="form-row" style="margin-top:.9rem">';
echo '<div class="form-group">';
echo '<label for="min_people">People</label>';
echo '<input type="number" id="min_people" name="min_people" min="1" step="1" value="' . h((string)$people_val) . '" placeholder="e.g. 4">';
echo '</div>';
echo '<div class="form-group">';
echo '<label for="start_date">From</label>';
echo '<input type="date" id="start_date" name="start_date" value="' . h($start_date) . '">';
echo '</div>';
echo '<div class="form-group">';
echo '<label for="end_date">To</label>';
echo '<input type="date" id="end_date" name="end_date" value="' . h($end_date) . '">';
echo '</div>';
echo '<div class="form-group">';
echo '<button type="submit">Search</button>';
echo '</div>';
echo '</div>';

echo '<label class="checkbox-row"><input type="checkbox" name="include_weekdays" value="1"'
   . ($include_weekdays ? ' checked' : '') . '> Also include weekdays (default: Friday and Saturday nights only)</label>';

echo '<p class="hint">Availability is read from local cache; each hut is checked independently.</p>';
echo '</form>';
echo '</div>';

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------
if ($submitted) {
    if (empty($selected_rids)) {
        echo '<div class="warn-box">Select at least one hut to search.</div>';
    } else {
        foreach ($selected_rids as $rid) {
            $res = $results[$rid] ?? null;
            if ($res === null) continue;
            $hut = $res['hut'];
            $name = h($hut['official_name']);
            $club = h($hut['operating_club']);
            $url  = $hut['official_website_url'];
            $elev_s = $hut['elevation_m'] !== null ? number_format((int)$hut['elevation_m']) . ' m' : '?';
            $name_html = $url !== '' ? '<a href="' . h($url) . '" target="_blank">' . $name . '</a>' : $name;
            $book_url = h(BOOKING_BASE . $rid . '/wizard');

            echo '<div class="hut-result">';
            echo "<h3>$name_html</h3>";
            echo '<div class="meta">' . h($club) . ' &middot; ' . h($elev_s)
               . ' &middot; <a href="' . $book_url . '" target="_blank">Book now</a>';

            if ($res['no_cache']) {
                echo ' &middot; <span style="color:#856404">no cached availability yet</span></div>';
                echo '<div class="warn-box">No reservation cache found for this hut. Run <code>update_cache.php</code> to populate it.</div>';
            } else {
                $stale  = $res['cache_age'] > CACHE_TTL;
                $age_td = format_age((int)$res['cache_age']);
                echo ' &middot; cache ' . h($age_td)
                   . ($stale ? ' <span style="color:#856404;font-weight:600">(stale)</span>' : '') . '</div>';

                if (empty($res['matches'])) {
                    echo '<div class="warn-box">No matching dates with at least ' . (int)$min_people
                       . ' free bed(s) between ' . h($start_date) . ' and ' . h($end_date) . '.</div>';
                } else {
                    echo '<table class="results-table"><thead><tr><th>Date</th><th>Type</th><th>Free beds</th></tr></thead><tbody>';
                    foreach ($res['matches'] as $m) {
                        $day_lbl = date('D d.m.Y', strtotime($m['date']));
                        $tag     = $m['is_weekend']
                                 ? '<span class="tag-weekend">weekend</span>'
                                 : '<span class="tag-weekday">weekday</span>';
                        echo '<tr>';
                        echo '<td>' . h($day_lbl) . '</td>';
                        echo "<td>$tag</td>";
                        echo '<td class="avail-open">' . (int)$m['free'] . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
            }
            echo '</div>';
        }
    }
}
?>

<script>
function clearHuts() {
    document.querySelectorAll('#hut-list input[name="huts[]"]').forEach(function(cb) {
        cb.checked = false;
    });
}

function filterHuts() {
    var q = document.getElementById('hut-filter').value.trim().toLowerCase();
    document.querySelectorAll('#hut-list .hut-item').forEach(function(el) {
        el.style.display = el.dataset.name.includes(q) ? '' : 'none';
    });
}
</script>

<?php render_page_foot([
    'Availability: <a href="https://www.hut-reservation.org" target="_blank">hut-reservation.org</a>',
]); ?>
