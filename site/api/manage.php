<?php
// Guest self-service.
// GET  ?ref=&t=                         -> booking details
// POST {ref, t, action: "cancel"}       -> cancel with full refund (before the balance due date)
// POST {ref, t, action: "pay_balance"}  -> start a card payment for an unpaid balance
require __DIR__ . '/lib.php';

run_api(function () {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $b = booking_auth((string) ($_GET['ref'] ?? ''), (string) ($_GET['t'] ?? ''));
        if (!$b) {
            json_error('Booking not found. Please use the link from your confirmation email.', 404);
        }
        json_out(['booking' => public_booking($b), 'owner_email' => cfg('owner_email')]);
    }

    $in = request_json();
    $b = booking_auth((string) ($in['ref'] ?? ''), (string) ($in['t'] ?? ''));
    if (!$b) {
        json_error('Booking not found.', 404);
    }
    $action = $in['action'] ?? '';

    if ($action === 'cancel') {
        if (!guest_can_cancel_with_refund($b)) {
            json_error('This booking can no longer be cancelled online for a refund. Please contact us.', 409);
        }
        $b = cancel_booking($b, 'full', 'Cancelled by guest');
        json_out(['booking' => public_booking($b)]);
    }

    if ($action === 'pay_balance') {
        if ($b['status'] !== 'confirmed' || $b['balance_status'] === 'paid' || (int) $b['balance_cents'] <= 0) {
            json_error('There is no balance to pay.', 409);
        }
        $pi = stripe('POST', 'payment_intents', [
            'amount' => (int) $b['balance_cents'],
            'currency' => 'usd',
            'customer' => $b['stripe_customer'],
            'payment_method_types' => ['card'],
            'description' => cfg('site_name') . " balance {$b['ref']} ({$b['checkin']} to {$b['checkout']})",
            'receipt_email' => $b['email'],
            'metadata' => ['booking_ref' => $b['ref'], 'kind' => 'balance'],
        ]);
        booking_update((int) $b['id'], ['balance_pi' => $pi['id']]);
        json_out(['client_secret' => $pi['client_secret'], 'publishable_key' => stripe_key('publishable_key')]);
    }

    json_error('Unknown action.');
});
