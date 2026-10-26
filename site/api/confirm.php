<?php
// GET ?ref=&t=  : called by the confirmation page after Stripe redirects back.
// Checks the payment with Stripe and confirms the booking.
require __DIR__ . '/lib.php';

run_api(function () {
    $b = booking_auth((string) ($_GET['ref'] ?? ''), (string) ($_GET['t'] ?? ''));
    if (!$b) {
        json_error('Booking not found.', 404);
    }
    $kind = ($_GET['kind'] ?? 'deposit') === 'balance' ? 'balance' : 'deposit';
    $piId = $kind === 'balance' ? $b['balance_pi'] : $b['deposit_pi'];
    $paymentStatus = null;
    if ($piId && ($kind === 'deposit' ? !in_array($b['status'], ['confirmed', 'cancelled'], true) : $b['balance_status'] !== 'paid')) {
        $pi = stripe('GET', 'payment_intents/' . $piId);
        $paymentStatus = $pi['status'];
        if ($pi['status'] === 'succeeded' && ($pi['metadata']['booking_ref'] ?? '') === $b['ref']) {
            $b = $kind === 'balance' ? finalize_balance($b, $pi) : finalize_deposit($b, $pi);
        }
    }
    json_out(['booking' => public_booking($b), 'payment_status' => $paymentStatus, 'site_name' => cfg('site_name'), 'owner_email' => cfg('owner_email')]);
});
