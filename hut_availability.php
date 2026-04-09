<?php
// Parse parameters
$hutId = (PHP_SAPI === 'cli') ? ($argv[1] ?? null) : ($_GET['hutId'] ?? null);
$datesInput = (PHP_SAPI === 'cli') ? ($argv[2] ?? null) : ($_GET['dates'] ?? null);

if (!$hutId || !ctype_digit((string)$hutId)) {
    die("Usage: php hut_availability.php <hutId> [<dates>]\n"
        . "  dates: Optional comma-separated list of dates in YYYY-MM-DD format\n"
        . "  Example: php hut_availability.php 52 2026-07-01,2026-07-05,2026-07-10\n");
}

// Parse and validate dates if provided
$requestedDates = [];
if ($datesInput) {
    $dateStrings = array_map('trim', explode(',', $datesInput));
    foreach ($dateStrings as $dateStr) {
        // Validate date format YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            die("Error: Invalid date format '$dateStr'. Use YYYY-MM-DD format.\n");
        }
        // Validate date is real
        $parts = explode('-', $dateStr);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            die("Error: Invalid date '$dateStr'.\n");
        }
        $requestedDates[] = $dateStr;
    }
}

$url = 'https://www.hut-reservation.org/api/v1/reservation/getHutAvailability'
     . '?hutId=' . urlencode($hutId) . '&step=WIZARD';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                            . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
    ],
]);

$body = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    die("curl error: $err\n");
}

$data = json_decode($body, true);
if ($data === null) {
    echo "HTTP $code — raw response:\n$body\n";
    exit(1);
}

// Filter by requested dates if specified
if (!empty($requestedDates)) {
    $filtered = [];
    $found = [];

    foreach ($data as $entry) {
        // Extract date in YYYY-MM-DD format from ISO 8601
        $isoDate = $entry['date'] ?? '';
        $entryDate = substr($isoDate, 0, 10); // Extract YYYY-MM-DD

        if (in_array($entryDate, $requestedDates)) {
            $filtered[] = [
                'date' => $entryDate,
                'freeBeds' => $entry['freeBeds'],
                'hutStatus' => $entry['hutStatus'],
                'totalSleepingPlaces' => $entry['totalSleepingPlaces'],
                'percentage' => $entry['percentage'] ?? null
            ];
            $found[] = $entryDate;
        }
    }

    // Check for requested dates not found in API response
    $notFound = array_diff($requestedDates, $found);
    if (!empty($notFound)) {
        fprintf(STDERR, "Warning: Dates not found in API response: %s\n", implode(', ', $notFound));
    }

    if (empty($filtered)) {
        die("Error: None of the requested dates were found in the API response.\n");
    }

    // Output filtered results
    echo json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    // No date filter - output all data as before
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
