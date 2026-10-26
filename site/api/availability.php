<?php
// GET: blocked nights, holiday info and rates for the booking calendar.
require __DIR__ . '/lib.php';

run_api(function () {
    $c = cfg();
    $from = today();
    $to = add_days($from, (int) $c['booking_window_days'] + 1);
    $ext = external_blocked_nights(false);
    $blocked = array_keys($ext['nights'] + local_blocked_nights());
    $blocked = array_values(array_filter($blocked, fn($d) => $d >= $from && $d <= $to));
    sort($blocked);
    $cal = holiday_calendar($from, $to);
    json_out([
        'today' => $from,
        'first_checkin' => add_days($from, (int) $c['min_lead_days']),
        'last_checkout' => $to,
        'blocked' => $blocked,
        'holidays' => $cal['holidays'],
        'holiday_weekend_nights' => $cal['weekend_nights'],
        'rates' => [
            'weekday' => $c['rate_weekday'],
            'weekend' => $c['rate_weekend'],
            'holiday' => $c['rate_holiday'],
            'cleaning' => $c['cleaning_fee'],
        ],
        'min_nights' => $c['min_nights'],
        'min_nights_holiday_weekend' => $c['min_nights_holiday_weekend'],
        'max_guests' => $c['max_guests'],
        'checkin_time' => $c['checkin_time'],
        'checkout_time' => $c['checkout_time'],
        'contact_email' => $c['owner_email'] === 'you@example.com' ? null : $c['owner_email'],
        'booking_enabled' => stripe_configured() && !$ext['stale'],
        'stripe_mode' => stripe_mode(),
        'calendar_synced_at' => $ext['synced_at'] ? date('c', $ext['synced_at']) : null,
    ]);
});
