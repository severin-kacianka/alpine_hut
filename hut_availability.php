<?php
$hutId = (PHP_SAPI === 'cli') ? ($argv[1] ?? null) : ($_GET['hutId'] ?? null);
if (!$hutId || !ctype_digit((string)$hutId)) {
    die("Usage: php hut_availability.php <hutId>\n");
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
} else {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
