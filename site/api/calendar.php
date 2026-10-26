<?php
// iCal export of bookings made on this site. Give this link to Airbnb
// (Availability > Connect calendars > Import calendar):
//   https://YOUR-SITE/api/calendar.php?token=YOUR_EXPORT_TOKEN
require __DIR__ . '/lib.php';

if (!hash_equals((string) cfg('export_token'), (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}
expire_holds();
$host = parse_url((string) cfg('site_url'), PHP_URL_HOST) ?: 'carriagehouse';
$rows = db()->query("SELECT * FROM bookings WHERE status = 'confirmed' AND checkout >= date('now','-30 days') ORDER BY checkin")->fetchAll();
$lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . cfg('site_name') . '//Direct bookings//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
foreach ($rows as $b) {
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $b['ref'] . '@' . $host;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z', strtotime($b['updated_at']));
    $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $b['checkin']);
    $lines[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', $b['checkout']);
    $lines[] = 'SUMMARY:Reserved (direct booking ' . $b['ref'] . ')';
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="carriagehouse.ics"');
echo implode("\r\n", $lines) . "\r\n";
