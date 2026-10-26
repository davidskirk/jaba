<?php
/*
 * Shared code: configuration, database, pricing, calendar sync, Stripe and email.
 */

declare(strict_types=1);

const APP_DIR  = __DIR__;
const DATA_DIR = __DIR__ . '/data';

function cfg(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = file_exists(APP_DIR . '/config.php') ? APP_DIR . '/config.php' : APP_DIR . '/config.sample.php';
        $cfg = require $file;
        $cfg['_using_sample'] = !file_exists(APP_DIR . '/config.php');
        date_default_timezone_set($cfg['timezone'] ?? 'America/New_York');
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

cfg();

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_out(['error' => $message], $status);
}

function request_json(): array
{
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($body) ? $body : [];
}

function run_api(callable $fn): void
{
    try {
        $fn();
    } catch (StripeError $e) {
        log_msg('Stripe error: ' . $e->getMessage());
        json_error('Payment error: ' . $e->getMessage(), 402);
    } catch (Throwable $e) {
        log_msg('Error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        json_error('Sorry, something went wrong. Please try again or contact us.', 500);
    }
}

function log_msg(string $msg): void
{
    @file_put_contents(DATA_DIR . '/app.log', date('c') . ' ' . $msg . "\n", FILE_APPEND);
}

// ---------------------------------------------------------------------------
// Database (SQLite file in api/data/)
// ---------------------------------------------------------------------------

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/bookings.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT UNIQUE NOT NULL,
        token TEXT NOT NULL,
        status TEXT NOT NULL,              -- pending | confirmed | cancelled | expired
        checkin TEXT NOT NULL,
        checkout TEXT NOT NULL,
        nights INTEGER NOT NULL,
        guests INTEGER NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT,
        notes TEXT,
        quote_json TEXT NOT NULL,
        total_cents INTEGER NOT NULL,
        tax_cents INTEGER NOT NULL DEFAULT 0,
        deposit_cents INTEGER NOT NULL,
        balance_cents INTEGER NOT NULL,
        balance_due_date TEXT,
        hold_expires_at INTEGER,
        stripe_customer TEXT,
        deposit_pi TEXT,
        deposit_paid_at TEXT,
        payment_method TEXT,
        balance_status TEXT NOT NULL DEFAULT 'none', -- none | scheduled | paid | failed
        balance_pi TEXT,
        balance_paid_at TEXT,
        balance_attempts INTEGER NOT NULL DEFAULT 0,
        reminder_sent INTEGER NOT NULL DEFAULT 0,
        refunded_cents INTEGER NOT NULL DEFAULT 0,
        cancelled_at TEXT,
        cancel_reason TEXT,
        stripe_mode TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    return $pdo;
}

function booking_by_ref(string $ref): ?array
{
    $st = db()->prepare('SELECT * FROM bookings WHERE ref = ?');
    $st->execute([$ref]);
    return $st->fetch() ?: null;
}

function booking_auth(string $ref, string $token): ?array
{
    $b = booking_by_ref($ref);
    return ($b && hash_equals($b['token'], $token)) ? $b : null;
}

function booking_update(int $id, array $fields): void
{
    $fields['updated_at'] = date('c');
    $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
    $fields['id'] = $id;
    db()->prepare("UPDATE bookings SET $sets WHERE id = :id")->execute($fields);
}

function new_ref(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $ref = 'LCH-';
        for ($i = 0; $i < 6; $i++) {
            $ref .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } while (booking_by_ref($ref));
    return $ref;
}

/** Mark unpaid holds whose time ran out as expired. */
function expire_holds(): void
{
    db()->prepare("UPDATE bookings SET status = 'expired', updated_at = ? WHERE status = 'pending' AND hold_expires_at < ?")
        ->execute([date('c'), time()]);
}

// ---------------------------------------------------------------------------
// Dates and US holidays
// ---------------------------------------------------------------------------

function today(): string
{
    return date('Y-m-d');
}

function add_days(string $date, int $days): string
{
    return date('Y-m-d', strtotime("$date $days days"));
}

function valid_date(?string $d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

function days_between(string $a, string $b): int
{
    return (int) round((strtotime("$b 12:00") - strtotime("$a 12:00")) / 86400);
}

function nth_weekday(int $year, int $month, int $weekday, int $n): string
{
    // $weekday: 1 = Monday ... 7 = Sunday (ISO). $n = -1 means last.
    if ($n > 0) {
        $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        while ((int) $d->format('N') !== $weekday) {
            $d->modify('+1 day');
        }
        $d->modify('+' . (7 * ($n - 1)) . ' days');
    } else {
        $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $d->modify('last day of this month');
        while ((int) $d->format('N') !== $weekday) {
            $d->modify('-1 day');
        }
    }
    return $d->format('Y-m-d');
}

/** US federal holidays for a year: [['name','date','observed'], ...] */
function federal_holidays(int $year): array
{
    $fixed = [
        "New Year's Day"   => sprintf('%04d-01-01', $year),
        'Juneteenth'       => sprintf('%04d-06-19', $year),
        'Independence Day' => sprintf('%04d-07-04', $year),
        'Veterans Day'     => sprintf('%04d-11-11', $year),
        'Christmas Day'    => sprintf('%04d-12-25', $year),
    ];
    $floating = [
        'Martin Luther King Jr. Day' => nth_weekday($year, 1, 1, 3),
        "Presidents' Day"            => nth_weekday($year, 2, 1, 3),
        'Memorial Day'               => nth_weekday($year, 5, 1, -1),
        'Labor Day'                  => nth_weekday($year, 9, 1, 1),
        'Columbus Day'               => nth_weekday($year, 10, 1, 2),
        'Thanksgiving'               => nth_weekday($year, 11, 4, 4),
    ];
    $out = [];
    foreach ($fixed as $name => $date) {
        $dow = (int) date('N', strtotime($date));
        $observed = $dow === 6 ? add_days($date, -1) : ($dow === 7 ? add_days($date, 1) : $date);
        $out[] = ['name' => $name, 'date' => $date, 'observed' => $observed];
    }
    foreach ($floating as $name => $date) {
        $out[] = ['name' => $name, 'date' => $date, 'observed' => $date];
    }
    $exclude = cfg('exclude_holidays') ?: [];
    return array_values(array_filter($out, fn($h) => !in_array($h['name'], $exclude, true)));
}

/**
 * Holiday calendar between two dates.
 * Returns ['holidays' => [date => name], 'weekend_nights' => [date => name]]
 * where weekend_nights are the nights of a long (holiday) weekend.
 */
function holiday_calendar(string $from, string $to): array
{
    $holidays = [];
    $weekendNights = [];
    $y1 = (int) substr($from, 0, 4) - 1;
    $y2 = (int) substr($to, 0, 4) + 1;
    for ($y = $y1; $y <= $y2; $y++) {
        foreach (federal_holidays($y) as $h) {
            $holidays[$h['date']] = $h['name'];
            $holidays[$h['observed']] = $h['name'] . ($h['observed'] !== $h['date'] ? ' (observed)' : '');
            $d = $h['observed'];
            $dow = (int) date('N', strtotime($d));
            $nights = [];
            if ($dow === 1) {            // Monday holiday: Fri, Sat, Sun nights
                $nights = [-3, -2, -1];
            } elseif ($dow === 5) {      // Friday holiday: Thu, Fri, Sat nights
                $nights = [-1, 0, 1];
            } elseif ($h['name'] === 'Thanksgiving') { // Wed - Sat nights
                $nights = [-1, 0, 1, 2];
            }
            foreach ($nights as $off) {
                $weekendNights[add_days($d, $off)] = $h['name'] . ' weekend';
            }
        }
    }
    foreach (cfg('extra_holidays') ?: [] as $d) {
        if (valid_date($d)) {
            $holidays[$d] = $holidays[$d] ?? 'Holiday';
        }
    }
    $inRange = fn($arr) => array_filter($arr, fn($d) => $d >= $from && $d <= $to, ARRAY_FILTER_USE_KEY);
    return ['holidays' => $inRange($holidays), 'weekend_nights' => $inRange($weekendNights)];
}

// ---------------------------------------------------------------------------
// Pricing
// ---------------------------------------------------------------------------

function cents($dollars): int
{
    return (int) round(((float) $dollars) * 100);
}

function money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

/**
 * Price a stay. Returns an array with 'ok' => true and the breakdown, or
 * 'ok' => false and an 'error' message.
 * $bookedOn lets the payment schedule be computed for a date other than today.
 */
function quote_stay(string $checkin, string $checkout, int $guests = 1, ?string $bookedOn = null): array
{
    $c = cfg();
    $bookedOn = $bookedOn ?: today();
    if (!valid_date($checkin) || !valid_date($checkout)) {
        return ['ok' => false, 'error' => 'Please choose check-in and check-out dates.'];
    }
    $nights = days_between($checkin, $checkout);
    if ($nights <= 0) {
        return ['ok' => false, 'error' => 'Check-out must be after check-in.'];
    }
    if ($guests < 1 || $guests > (int) $c['max_guests']) {
        return ['ok' => false, 'error' => 'Maximum ' . $c['max_guests'] . ' guests.'];
    }
    if ($checkin < add_days(today(), (int) $c['min_lead_days'])) {
        return ['ok' => false, 'error' => 'That check-in date is too soon to book online. Please contact us.'];
    }
    if ($checkout > add_days(today(), (int) $c['booking_window_days'] + 1)) {
        return ['ok' => false, 'error' => 'We only take bookings up to ' . round($c['booking_window_days'] / 30) . ' months ahead.'];
    }

    $cal = holiday_calendar($checkin, $checkout);
    $lines = [];
    $nightsCents = 0;
    $holidayWeekend = null;
    for ($d = $checkin; $d < $checkout; $d = add_days($d, 1)) {
        $dow = (int) date('N', strtotime($d));
        if (isset($cal['holidays'][$d]) || isset($cal['weekend_nights'][$d])) {
            $type = 'holiday';
            $rate = $c['rate_holiday'];
            $label = $cal['holidays'][$d] ?? $cal['weekend_nights'][$d];
        } elseif ($dow === 5 || $dow === 6) {
            $type = 'weekend';
            $rate = $c['rate_weekend'];
            $label = 'Weekend';
        } else {
            $type = 'weekday';
            $rate = $c['rate_weekday'];
            $label = 'Weeknight';
        }
        if (isset($cal['weekend_nights'][$d]) && !$holidayWeekend) {
            $holidayWeekend = $cal['weekend_nights'][$d];
        }
        $lines[] = ['date' => $d, 'type' => $type, 'label' => $label, 'cents' => cents($rate)];
        $nightsCents += cents($rate);
    }

    $minNights = $holidayWeekend ? (int) $c['min_nights_holiday_weekend'] : (int) $c['min_nights'];
    if ($nights < $minNights) {
        return [
            'ok' => false,
            'error' => $holidayWeekend
                ? "This stay includes the $holidayWeekend, which has a $minNights-night minimum."
                : "There is a $minNights-night minimum stay.",
        ];
    }

    $discount = null;
    $discountCents = 0;
    $discounts = $c['discounts'] ?: [];
    usort($discounts, fn($a, $b) => $b['min_nights'] <=> $a['min_nights']);
    foreach ($discounts as $dsc) {
        if ($nights >= $dsc['min_nights']) {
            $discountCents = (int) round($nightsCents * $dsc['percent'] / 100);
            $discount = ['label' => $dsc['label'], 'percent' => $dsc['percent'], 'cents' => $discountCents];
            break;
        }
    }

    $cleaningCents = cents($c['cleaning_fee']);
    $taxable = $nightsCents - $discountCents + ($c['tax_includes_cleaning'] ? $cleaningCents : 0);
    $taxes = [];
    $taxCents = 0;
    $taxExempt = $nights >= (int) $c['tax_exempt_min_nights'];
    if (!$taxExempt) {
        foreach ($c['taxes'] ?: [] as $t) {
            $amt = (int) round($taxable * $t['percent'] / 100);
            $taxes[] = ['name' => $t['name'], 'percent' => $t['percent'], 'cents' => $amt];
            $taxCents += $amt;
        }
    }

    $total = $nightsCents - $discountCents + $cleaningCents + $taxCents;

    // Payment schedule
    $balanceDue = add_days($checkin, -(int) $c['balance_days_before']);
    if ($bookedOn >= $balanceDue) {
        $deposit = $total;
        $balance = 0;
        $balanceDue = null;
    } else {
        $deposit = (int) round($total * $c['deposit_percent'] / 100);
        $balance = $total - $deposit;
    }

    $avg = (int) round($nightsCents / $nights);
    return [
        'ok' => true,
        'checkin' => $checkin,
        'checkout' => $checkout,
        'nights' => $nights,
        'guests' => $guests,
        'lines' => $lines,
        'nights_cents' => $nightsCents,
        'avg_night_cents' => $avg,
        'discount' => $discount,
        'cleaning_cents' => $cleaningCents,
        'taxes' => $taxes,
        'tax_cents' => $taxCents,
        'tax_exempt' => $taxExempt,
        'total_cents' => $total,
        'deposit_cents' => $deposit,
        'balance_cents' => $balance,
        'balance_due_date' => $balanceDue,
        'refundable_until' => $balanceDue,
        'holiday_weekend' => $holidayWeekend,
        'min_nights' => $minNights,
    ];
}

// ---------------------------------------------------------------------------
// Availability: Airbnb (iCal) + bookings made on this site
// ---------------------------------------------------------------------------

/** Parse an iCal feed into a list of [start, end) date ranges. */
function parse_ical(string $ics): array
{
    $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // unfold long lines
    $ranges = [];
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $events)) {
        return [];
    }
    foreach ($events[1] as $ev) {
        if (!preg_match('/^DTSTART[^:\r\n]*:(\d{8})/m', $ev, $s)) {
            continue;
        }
        $start = substr($s[1], 0, 4) . '-' . substr($s[1], 4, 2) . '-' . substr($s[1], 6, 2);
        $end = add_days($start, 1);
        if (preg_match('/^DTEND[^:\r\n]*:(\d{8})(T\d{4})?/m', $ev, $e)) {
            $end = substr($e[1], 0, 4) . '-' . substr($e[1], 4, 2) . '-' . substr($e[1], 6, 2);
            // A timed event ending after midnight on its end day still blocks that night.
            if (!empty($e[2]) && $e[2] !== 'T0000') {
                $end = add_days($end, 1);
            }
            if ($end <= $start) {
                $end = add_days($start, 1);
            }
        }
        $ranges[] = [$start, $end];
    }
    return $ranges;
}

function http_get(string $url, int $timeout = 15): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CarriageHouseCalendarSync/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
}

/**
 * Blocked nights imported from external calendars (Airbnb etc.).
 * Returns ['nights' => [date => true], 'stale' => bool, 'synced_at' => int|null]
 */
function external_blocked_nights(bool $forceRefresh = false): array
{
    $urls = array_values(array_filter(cfg('import_ical_urls') ?: []));
    $nights = [];
    $stale = false;
    $oldest = null;
    foreach ($urls as $url) {
        $cache = DATA_DIR . '/ical-' . md5($url) . '.ics';
        $age = file_exists($cache) ? time() - filemtime($cache) : PHP_INT_MAX;
        if ($forceRefresh || $age > cfg('ical_cache_minutes') * 60) {
            $body = http_get($url);
            if ($body !== null && strpos($body, 'BEGIN:VCALENDAR') !== false) {
                file_put_contents($cache, $body, LOCK_EX);
                $age = 0;
            } else {
                log_msg("Calendar import failed: $url");
            }
        }
        if (!file_exists($cache) || $age > cfg('ical_max_stale_hours') * 3600) {
            $stale = true;
        }
        if (file_exists($cache)) {
            $oldest = min($oldest ?? PHP_INT_MAX, filemtime($cache));
            foreach (parse_ical((string) file_get_contents($cache)) as [$s, $e]) {
                for ($d = $s; $d < $e; $d = add_days($d, 1)) {
                    $nights[$d] = true;
                }
            }
        }
    }
    return ['nights' => $nights, 'stale' => $stale, 'synced_at' => $oldest, 'configured' => count($urls) > 0];
}

/** Nights taken by bookings on this site (confirmed, or held while paying). */
function local_blocked_nights(?int $excludeId = null): array
{
    expire_holds();
    $st = db()->prepare("SELECT id, checkin, checkout FROM bookings WHERE status IN ('confirmed','pending')");
    $st->execute();
    $nights = [];
    foreach ($st->fetchAll() as $b) {
        if ($excludeId !== null && (int) $b['id'] === $excludeId) {
            continue;
        }
        for ($d = $b['checkin']; $d < $b['checkout']; $d = add_days($d, 1)) {
            $nights[$d] = true;
        }
    }
    return $nights;
}

/** Returns null if the dates are free, or an error message. */
function check_dates_free(string $checkin, string $checkout, bool $forceRefresh, ?int $excludeId = null): ?string
{
    $ext = external_blocked_nights($forceRefresh);
    if ($ext['stale']) {
        return 'Online booking is briefly unavailable while we sync our calendar. Please try again shortly or contact us.';
    }
    $blocked = $ext['nights'] + local_blocked_nights($excludeId);
    for ($d = $checkin; $d < $checkout; $d = add_days($d, 1)) {
        if (isset($blocked[$d])) {
            return 'Sorry - some of those dates have just been booked. Please choose different dates.';
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Stripe (plain HTTPS calls - no extra libraries to upload)
// ---------------------------------------------------------------------------

class StripeError extends Exception
{
    public $stripe;
    public function __construct(string $message, array $stripe = [])
    {
        parent::__construct($message);
        $this->stripe = $stripe;
    }
}

function stripe_mode(): string
{
    return (cfg('stripe')['mode'] ?? 'test') === 'live' ? 'live' : 'test';
}

function stripe_key(string $kind): string
{
    return (string) (cfg('stripe')[stripe_mode() . '_' . $kind] ?? '');
}

function stripe_configured(): bool
{
    $sk = stripe_key('secret_key');
    $pk = stripe_key('publishable_key');
    return $sk !== '' && $pk !== '' && strpos($sk, 'REPLACE') === false && strpos($pk, 'REPLACE') === false;
}

function stripe(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
{
    $base = rtrim(cfg('stripe')['api_base'] ?? 'https://api.stripe.com', '/');
    $url = $base . '/v1/' . ltrim($path, '/');
    $query = http_build_query($params);
    $ch = curl_init($method === 'GET' && $query ? "$url?$query" : $url);
    $headers = ['Stripe-Version: 2024-06-20'];
    if ($idempotencyKey) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => stripe_key('secret_key') . ':',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 40,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    }
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new StripeError('Could not reach Stripe: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($body, true) ?: [];
    if ($code >= 400) {
        throw new StripeError($data['error']['message'] ?? "Stripe HTTP $code", $data['error'] ?? []);
    }
    return $data;
}

function stripe_verify_webhook(string $payload, string $sigHeader, string $secret): bool
{
    $parts = [];
    foreach (explode(',', $sigHeader) as $kv) {
        [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
        $parts[$k][] = $v;
    }
    $t = $parts['t'][0] ?? '';
    if ($t === '' || abs(time() - (int) $t) > 600) {
        return false;
    }
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($parts['v1'] ?? [] as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Booking lifecycle
// ---------------------------------------------------------------------------

function manage_url(array $b): string
{
    return cfg('site_url') . '/manage.html?ref=' . urlencode($b['ref']) . '&t=' . urlencode($b['token']);
}

/** Called once the deposit (or full) payment has succeeded. Idempotent. */
function finalize_deposit(array $b, array $pi): array
{
    if ($b['status'] === 'confirmed') {
        return $b;
    }
    if (!in_array($b['status'], ['pending', 'expired'], true)) {
        return $b;
    }
    // If the hold expired before payment completed, make sure nobody took the dates.
    if ($b['status'] === 'expired' && check_dates_free($b['checkin'], $b['checkout'], true, (int) $b['id'])) {
        refund_payment($pi['id'], null);
        booking_update((int) $b['id'], [
            'status' => 'cancelled', 'cancelled_at' => date('c'), 'deposit_pi' => $pi['id'],
            'refunded_cents' => (int) $pi['amount_received'], 'cancel_reason' => 'Dates no longer available - refunded automatically',
        ]);
        $b = booking_by_ref($b['ref']);
        send_mail($b['email'], 'Booking not completed - payment refunded', "Hi {$b['name']},\n\nSorry, the dates you chose were booked by someone else while your payment was processing. Your payment of " . money((int) $pi['amount_received']) . " has been refunded in full.\n\n" . cfg('site_name'));
        send_mail(cfg('owner_email'), "Auto-refunded {$b['ref']} (date conflict)", booking_summary_text($b));
        return $b;
    }
    $fields = [
        'status' => 'confirmed',
        'deposit_pi' => $pi['id'],
        'deposit_paid_at' => date('c'),
        'payment_method' => is_array($pi['payment_method'] ?? null) ? $pi['payment_method']['id'] : ($pi['payment_method'] ?? null),
        'balance_status' => (int) $b['balance_cents'] > 0 ? 'scheduled' : 'none',
        'hold_expires_at' => null,
    ];
    booking_update((int) $b['id'], $fields);
    $b = booking_by_ref($b['ref']);
    send_confirmation_emails($b);
    return $b;
}

function finalize_balance(array $b, array $pi): array
{
    if ($b['balance_status'] === 'paid') {
        return $b;
    }
    booking_update((int) $b['id'], ['balance_status' => 'paid', 'balance_pi' => $pi['id'], 'balance_paid_at' => date('c')]);
    $b = booking_by_ref($b['ref']);
    send_mail($b['email'], "Payment received - {$b['ref']}", "Hi {$b['name']},\n\nThank you - we've received your final payment of " . money((int) $b['balance_cents']) . ". Your stay is fully paid.\n\n" . booking_summary_text($b) . "\n\nWe look forward to welcoming you!\n" . cfg('site_name'));
    send_mail(cfg('owner_email'), "Balance paid - {$b['ref']}", booking_summary_text($b));
    return $b;
}

/** Charge the saved card for the remaining balance. Returns [ok, message]. */
function charge_balance(array $b): array
{
    if ($b['status'] !== 'confirmed' || $b['balance_status'] === 'paid' || (int) $b['balance_cents'] <= 0) {
        return [false, 'Nothing to charge.'];
    }
    if (!$b['payment_method'] || !$b['stripe_customer']) {
        return [false, 'No saved card on file.'];
    }
    $attempt = (int) $b['balance_attempts'] + 1;
    booking_update((int) $b['id'], ['balance_attempts' => $attempt]);
    try {
        $pi = stripe('POST', 'payment_intents', [
            'amount' => (int) $b['balance_cents'],
            'currency' => 'usd',
            'customer' => $b['stripe_customer'],
            'payment_method' => $b['payment_method'],
            'off_session' => 'true',
            'confirm' => 'true',
            'description' => cfg('site_name') . " balance {$b['ref']} ({$b['checkin']} to {$b['checkout']})",
            'receipt_email' => $b['email'],
            'metadata' => ['booking_ref' => $b['ref'], 'kind' => 'balance'],
        ], "balance-{$b['ref']}-$attempt");
        if (($pi['status'] ?? '') === 'succeeded') {
            finalize_balance($b, $pi);
            return [true, 'Charged ' . money((int) $b['balance_cents'])];
        }
        $msg = 'Payment status: ' . ($pi['status'] ?? 'unknown');
    } catch (StripeError $e) {
        $msg = $e->getMessage();
    }
    booking_update((int) $b['id'], ['balance_status' => 'failed']);
    $b = booking_by_ref($b['ref']);
    send_mail($b['email'], "Action needed: final payment for {$b['ref']}", "Hi {$b['name']},\n\nWe weren't able to charge your card for the remaining balance of " . money((int) $b['balance_cents']) . " for your stay on {$b['checkin']}.\n\nPlease complete the payment here:\n" . manage_url($b) . "\n\nIf you have any questions just reply to this email.\n\n" . cfg('site_name'));
    send_mail(cfg('owner_email'), "Balance charge FAILED - {$b['ref']}", "Reason: $msg\n\n" . booking_summary_text($b));
    return [false, $msg];
}

function refund_payment(string $paymentIntent, ?int $amountCents): array
{
    $params = ['payment_intent' => $paymentIntent];
    if ($amountCents !== null) {
        $params['amount'] = $amountCents;
    }
    return stripe('POST', 'refunds', $params);
}

/** Amount actually paid so far (cents). */
function paid_cents(array $b): int
{
    $paid = 0;
    if ($b['deposit_paid_at']) {
        $paid += (int) $b['deposit_cents'];
    }
    if ($b['balance_status'] === 'paid') {
        $paid += (int) $b['balance_cents'];
    }
    return $paid;
}

/** Guests get a full refund up to (but not including) the balance due date. */
function guest_can_cancel_with_refund(array $b): bool
{
    if ($b['status'] !== 'confirmed') {
        return false;
    }
    $due = $b['balance_due_date'];
    return $due !== null && $due !== '' && today() < $due && $b['balance_status'] !== 'paid';
}

/** Cancel a confirmed booking, refunding $refund ('full' | 'none' | cents). */
function cancel_booking(array $b, $refund, string $reason): array
{
    $refunded = 0;
    if ($refund !== 'none') {
        $remaining = $refund === 'full' ? paid_cents($b) - (int) $b['refunded_cents'] : (int) $refund;
        foreach ([['deposit_pi', (int) $b['deposit_cents'], (bool) $b['deposit_paid_at']], ['balance_pi', (int) $b['balance_cents'], $b['balance_status'] === 'paid']] as [$col, $amt, $paid]) {
            if ($remaining <= 0 || !$paid || !$b[$col]) {
                continue;
            }
            $part = min($remaining, $amt);
            refund_payment($b[$col], $part);
            $refunded += $part;
            $remaining -= $part;
        }
    }
    booking_update((int) $b['id'], [
        'status' => 'cancelled',
        'cancelled_at' => date('c'),
        'cancel_reason' => $reason,
        'refunded_cents' => (int) $b['refunded_cents'] + $refunded,
        'balance_status' => $b['balance_status'] === 'paid' ? 'paid' : 'none',
    ]);
    $b = booking_by_ref($b['ref']);
    $msg = "Hi {$b['name']},\n\nYour booking {$b['ref']} ({$b['checkin']} to {$b['checkout']}) has been cancelled.\n";
    $msg .= $refunded > 0 ? "A refund of " . money($refunded) . " has been issued to your card. It usually appears within 5-10 business days.\n" : "No refund was due under the cancellation policy.\n";
    send_mail($b['email'], "Booking cancelled - {$b['ref']}", $msg . "\n" . cfg('site_name'));
    send_mail(cfg('owner_email'), "Cancelled {$b['ref']} - refunded " . money($refunded), "Reason: $reason\n\n" . booking_summary_text($b));
    return $b;
}

function public_booking(array $b): array
{
    $q = json_decode($b['quote_json'], true);
    return [
        'ref' => $b['ref'],
        'status' => $b['status'],
        'checkin' => $b['checkin'],
        'checkout' => $b['checkout'],
        'nights' => (int) $b['nights'],
        'guests' => (int) $b['guests'],
        'name' => $b['name'],
        'email' => $b['email'],
        'quote' => $q,
        'total_cents' => (int) $b['total_cents'],
        'deposit_cents' => (int) $b['deposit_cents'],
        'balance_cents' => (int) $b['balance_cents'],
        'balance_due_date' => $b['balance_due_date'],
        'balance_status' => $b['balance_status'],
        'paid_cents' => paid_cents($b),
        'refunded_cents' => (int) $b['refunded_cents'],
        'can_cancel_with_refund' => guest_can_cancel_with_refund($b),
        'cancel_reason' => $b['cancel_reason'],
        'checkin_time' => cfg('checkin_time'),
        'checkout_time' => cfg('checkout_time'),
    ];
}

// ---------------------------------------------------------------------------
// Email
// ---------------------------------------------------------------------------

function send_mail(string $to, string $subject, string $body): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $from = cfg('from_email');
    $name = cfg('site_name');
    $headers = [
        'From: ' . mb_encode_mimeheader($name) . " <$from>",
        'Reply-To: ' . cfg('owner_email'),
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $ok = @mail($to, mb_encode_mimeheader($subject), $body, implode("\r\n", $headers), '-f' . $from);
    log_msg(($ok ? 'Mail sent' : 'Mail FAILED') . " to $to: $subject");
}

function fmt_date(string $d): string
{
    return date('D, M j, Y', strtotime($d));
}

function booking_summary_text(array $b): string
{
    $q = json_decode($b['quote_json'], true);
    $t = "Booking reference: {$b['ref']}\n";
    $t .= "Guest: {$b['name']} <{$b['email']}> {$b['phone']}\n";
    $t .= 'Check-in:  ' . fmt_date($b['checkin']) . ' from ' . cfg('checkin_time') . "\n";
    $t .= 'Check-out: ' . fmt_date($b['checkout']) . ' by ' . cfg('checkout_time') . "\n";
    $t .= "Nights: {$b['nights']}   Guests: {$b['guests']}\n\n";
    $t .= sprintf("%-40s %10s\n", "{$b['nights']} nights", money($q['nights_cents']));
    if ($q['discount']) {
        $t .= sprintf("%-40s %10s\n", $q['discount']['label'], '-' . money($q['discount']['cents']));
    }
    $t .= sprintf("%-40s %10s\n", 'Cleaning fee', money($q['cleaning_cents']));
    foreach ($q['taxes'] as $tx) {
        $t .= sprintf("%-40s %10s\n", $tx['name'], money($tx['cents']));
    }
    $t .= sprintf("%-40s %10s\n", 'TOTAL', money((int) $b['total_cents']));
    if ((int) $b['balance_cents'] > 0) {
        $t .= sprintf("%-40s %10s\n", 'Paid at booking', money((int) $b['deposit_cents']));
        $t .= sprintf("%-40s %10s\n", 'Balance (' . ($b['balance_status'] === 'paid' ? 'paid' : 'due ' . fmt_date($b['balance_due_date'])) . ')', money((int) $b['balance_cents']));
    }
    if ($b['notes']) {
        $t .= "\nNotes: {$b['notes']}\n";
    }
    return $t;
}

function send_confirmation_emails(array $b): void
{
    $site = cfg('site_name');
    $policy = (int) $b['balance_cents'] > 0
        ? "Your remaining balance of " . money((int) $b['balance_cents']) . " will be charged automatically to the same card on " . fmt_date($b['balance_due_date']) . ".\nYou can cancel for a FULL refund any time before then using the link below."
        : "Your stay is paid in full. As check-in is less than " . cfg('balance_days_before') . " days away, this booking is non-refundable.";
    send_mail($b['email'], "Booking confirmed - $site ({$b['ref']})",
        "Hi {$b['name']},\n\nThank you for booking $site! Your reservation is confirmed.\n\n" . booking_summary_text($b) .
        "\n$policy\n\nView or manage your booking:\n" . manage_url($b) .
        "\n\nHouse rules: no pets, no smoking, maximum " . cfg('max_guests') . " guests.\nWe'll send check-in details closer to your arrival.\n\nSee you soon!\n$site");
    send_mail(cfg('owner_email'), "NEW BOOKING {$b['ref']}: {$b['checkin']} to {$b['checkout']}", booking_summary_text($b) . "\nRemember: the Airbnb calendar will pick this up automatically if you have imported the export link.");
}
