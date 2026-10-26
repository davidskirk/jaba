<?php
// Daily job: charges balances that are due, sends reminders, tidies expired holds.
// Run once a day, either from cPanel "Cron Jobs":
//     php /home/USERNAME/public_html/carriagehouse/api/cron.php
// or by visiting/fetching:  https://YOUR-SITE/api/cron.php?key=YOUR_CRON_KEY
require __DIR__ . '/lib.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli && !hash_equals((string) cfg('cron_key'), (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}
header('Content-Type: text/plain');

expire_holds();
$out = [];

// Reminders 3 days before the balance is charged.
$st = db()->prepare("SELECT * FROM bookings WHERE status = 'confirmed' AND balance_status = 'scheduled' AND reminder_sent = 0 AND balance_due_date <= ?");
$st->execute([add_days(today(), 3)]);
foreach ($st->fetchAll() as $b) {
    if ($b['balance_due_date'] > today()) {
        send_mail($b['email'], "Upcoming payment for your stay - {$b['ref']}",
            "Hi {$b['name']},\n\nJust a reminder that the remaining balance of " . money((int) $b['balance_cents']) .
            " for your stay starting " . fmt_date($b['checkin']) . " will be charged to your card on " . fmt_date($b['balance_due_date']) .
            ".\nYou can still cancel for a full refund before then:\n" . manage_url($b) . "\n\n" . cfg('site_name'));
    }
    booking_update((int) $b['id'], ['reminder_sent' => 1]);
    $out[] = "Reminder {$b['ref']}";
}

// Charge balances that are due (and retry failed ones once a day, up to 3 tries).
$st = db()->prepare("SELECT * FROM bookings WHERE status = 'confirmed' AND balance_status IN ('scheduled','failed') AND balance_due_date <= ? AND balance_attempts < 3 AND balance_cents > 0");
$st->execute([today()]);
foreach ($st->fetchAll() as $b) {
    [$ok, $msg] = charge_balance($b);
    $out[] = "Balance {$b['ref']}: " . ($ok ? 'OK ' : 'FAILED ') . $msg;
}

// Refresh the Airbnb calendar cache.
$ext = external_blocked_nights(true);
$out[] = 'Calendar sync: ' . (!$ext['configured'] ? 'no import links configured' : ($ext['stale'] ? 'FAILED' : 'ok'));

$text = date('c') . "\n" . implode("\n", $out) . "\n";
log_msg('cron: ' . str_replace("\n", ' | ', trim($text)));
echo $text;
