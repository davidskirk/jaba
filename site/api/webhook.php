<?php
// Stripe webhook (optional but recommended): confirms bookings even if the
// guest closes their browser before returning to the site.
// Add endpoint https://YOUR-SITE/api/webhook.php in Stripe with the event
// "payment_intent.succeeded", and put its signing secret in config.php.
require __DIR__ . '/lib.php';

$payload = file_get_contents('php://input') ?: '';
$secret = (string) (cfg('stripe')[stripe_mode() . '_webhook_secret'] ?? '');
if ($secret === '' || !stripe_verify_webhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $secret)) {
    json_error('Invalid signature', 400);
}
run_api(function () use ($payload) {
    $event = json_decode($payload, true);
    if (($event['type'] ?? '') === 'payment_intent.succeeded') {
        $pi = $event['data']['object'];
        $b = booking_by_ref((string) ($pi['metadata']['booking_ref'] ?? ''));
        if ($b) {
            if (($pi['metadata']['kind'] ?? '') === 'balance') {
                finalize_balance($b, $pi);
            } elseif ($b['deposit_pi'] === $pi['id']) {
                finalize_deposit($b, $pi);
            }
        }
    }
    json_out(['received' => true]);
});
