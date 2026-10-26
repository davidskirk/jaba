<?php
// POST JSON {checkin, checkout, guests, name, email, phone, notes, agree}
// Holds the dates and starts a Stripe payment for the deposit (or full amount).
require __DIR__ . '/lib.php';

run_api(function () {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('POST only', 405);
    }
    if (!stripe_configured()) {
        json_error('Online payments are not set up yet. Please contact us to book.', 503);
    }
    $in = request_json();
    $name = trim((string) ($in['name'] ?? ''));
    $email = trim((string) ($in['email'] ?? ''));
    $phone = trim((string) ($in['phone'] ?? ''));
    $notes = trim((string) ($in['notes'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        json_error('Please enter your name.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Please enter a valid email address.');
    }
    if (mb_strlen($phone) > 40 || mb_strlen($notes) > 2000) {
        json_error('Phone or notes too long.');
    }
    if (empty($in['agree'])) {
        json_error('Please agree to the house rules and cancellation policy.');
    }

    $q = quote_stay((string) ($in['checkin'] ?? ''), (string) ($in['checkout'] ?? ''), (int) ($in['guests'] ?? 0));
    if (!$q['ok']) {
        json_error($q['error']);
    }

    // Re-check availability against a fresh copy of the Airbnb calendar, and
    // insert the hold inside a write transaction so two guests can't grab the same dates.
    $pdo = db();
    $conflict = check_dates_free($q['checkin'], $q['checkout'], true);
    if ($conflict) {
        json_error($conflict, 409);
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $conflict = check_dates_free($q['checkin'], $q['checkout'], false);
        if ($conflict) {
            $pdo->exec('ROLLBACK');
            json_error($conflict, 409);
        }
        $ref = new_ref();
        $token = bin2hex(random_bytes(16));
        $now = date('c');
        $pdo->prepare('INSERT INTO bookings (ref, token, status, checkin, checkout, nights, guests, name, email, phone, notes,
            quote_json, total_cents, tax_cents, deposit_cents, balance_cents, balance_due_date, hold_expires_at, stripe_mode, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $ref, $token, 'pending', $q['checkin'], $q['checkout'], $q['nights'], $q['guests'], $name, $email, $phone, $notes,
            json_encode($q), $q['total_cents'], $q['tax_cents'], $q['deposit_cents'], $q['balance_cents'], $q['balance_due_date'],
            time() + cfg('hold_minutes') * 60, stripe_mode(), $now, $now,
        ]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
    $b = booking_by_ref($ref);

    try {
        $customer = stripe('POST', 'customers', [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'metadata' => ['booking_ref' => $ref],
        ]);
        $params = [
            'amount' => $q['deposit_cents'],
            'currency' => 'usd',
            'customer' => $customer['id'],
            'payment_method_types' => ['card'],
            'description' => cfg('site_name') . " $ref ({$q['checkin']} to {$q['checkout']})" . ($q['balance_cents'] > 0 ? ' - 50% deposit' : ''),
            'receipt_email' => $email,
            'metadata' => ['booking_ref' => $ref, 'kind' => 'deposit'],
        ];
        if ($q['balance_cents'] > 0) {
            // Save the card so the balance can be charged automatically later.
            $params['setup_future_usage'] = 'off_session';
        }
        $pi = stripe('POST', 'payment_intents', $params, "deposit-$ref");
    } catch (StripeError $e) {
        booking_update((int) $b['id'], ['status' => 'expired']);
        throw $e;
    }
    booking_update((int) $b['id'], ['stripe_customer' => $customer['id'], 'deposit_pi' => $pi['id']]);

    json_out([
        'ref' => $ref,
        'token' => $token,
        'client_secret' => $pi['client_secret'],
        'publishable_key' => stripe_key('publishable_key'),
        'amount_now_cents' => $q['deposit_cents'],
        'quote' => $q,
        'hold_minutes' => cfg('hold_minutes'),
    ]);
});
