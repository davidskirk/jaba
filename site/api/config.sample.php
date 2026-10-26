<?php
/*
 * Landenberg Carriage House - site configuration
 * ------------------------------------------------
 * 1. Copy this file to "config.php" in the same folder.
 * 2. Fill in your own values below (Stripe keys, Airbnb calendar link, passwords, emails).
 * 3. Never share config.php - it contains your secret keys.
 *
 * Money values are in US dollars. Percentages are plain numbers (15 = 15%).
 */
return [

    // ---- Site ---------------------------------------------------------------
    'site_name'   => 'Landenberg Carriage House',
    // The public web address of the site, WITHOUT a trailing slash.
    // Used in emails and in the Airbnb export link. Examples:
    //   'https://the-kirks.com/carriagehouse'
    //   'https://landenbergcarriagehouse.com'
    'site_url'    => 'https://the-kirks.com/carriagehouse',
    'timezone'    => 'America/New_York',

    // Where booking notifications go, and the "From" address on guest emails.
    // The From address should be a mailbox on the same domain as your hosting
    // (e.g. bookings@the-kirks.com) or emails may land in spam.
    'owner_email' => 'you@example.com',
    'from_email'  => 'bookings@example.com',

    // ---- Pricing ------------------------------------------------------------
    'rate_weekday'    => 120,   // Sun-Thu nights
    'rate_weekend'    => 140,   // Fri & Sat nights
    'rate_holiday'    => 140,   // US federal holidays and holiday-weekend nights
    'cleaning_fee'    => 90,    // per stay

    // Long-stay discounts on the nightly total (cleaning fee is not discounted).
    // The largest one that applies is used.
    'discounts' => [
        ['min_nights' => 28, 'percent' => 30, 'label' => 'Monthly discount (28+ nights)'],
        ['min_nights' => 7,  'percent' => 15, 'label' => 'Weekly discount (7+ nights)'],
    ],

    // ---- Stay rules ---------------------------------------------------------
    'min_nights'                 => 2,
    'min_nights_holiday_weekend' => 3,
    'max_guests'                 => 3,
    'checkin_time'               => '4:00 PM',
    'checkout_time'              => '11:00 AM',
    'booking_window_days'        => 365, // how far ahead guests can book
    'min_lead_days'              => 1,   // 1 = earliest check-in is tomorrow

    // Extra dates to price as holidays (YYYY-MM-DD), e.g. Christmas Eve / New Year's Eve.
    'extra_holidays' => ['2026-12-24', '2026-12-31', '2027-12-24', '2027-12-31'],
    // Federal holidays to IGNORE (by name) if you don't want them treated as holidays,
    // e.g. ['Columbus Day', 'Veterans Day'].
    'exclude_holidays' => [],

    // ---- Taxes (Pennsylvania / Chester County) ------------------------------
    // PA Hotel Occupancy Tax 6% + Chester County Hotel Room Rental Tax 5%.
    // Both apply to stays of fewer than 30 consecutive nights and are charged on
    // the rent, including the cleaning fee. Confirm with your accountant.
    'taxes' => [
        ['name' => 'PA Hotel Occupancy Tax (6%)',            'percent' => 6],
        ['name' => 'Chester County Hotel Room Rental Tax (5%)', 'percent' => 5],
    ],
    'tax_exempt_min_nights'   => 30,
    'tax_includes_cleaning'   => true,

    // ---- Payments -----------------------------------------------------------
    'deposit_percent'     => 50, // charged at booking
    'balance_days_before' => 7,  // remaining balance charged this many days before check-in
    'hold_minutes'        => 30, // dates are held this long while a guest is paying

    'stripe' => [
        // 'test' or 'live'. While 'test', no real money moves and a TEST MODE
        // banner shows on the site. Switch to 'live' when you are ready.
        'mode' => 'test',

        // From https://dashboard.stripe.com/test/apikeys
        'test_publishable_key' => 'pk_test_REPLACE_ME',
        'test_secret_key'      => 'sk_test_REPLACE_ME',

        // From https://dashboard.stripe.com/apikeys  (only needed for live mode)
        'live_publishable_key' => '',
        'live_secret_key'      => '',

        // Optional but recommended: webhook signing secret (whsec_...) for
        // https://YOUR-SITE/api/webhook.php - see README.
        'test_webhook_secret' => '',
        'live_webhook_secret' => '',
    ],

    // ---- Airbnb calendar sync ----------------------------------------------
    // Airbnb > Listing > Availability > Connect calendars > Export calendar.
    // Paste the link here (you can add more than one, e.g. VRBO).
    'import_ical_urls' => [
        // 'https://www.airbnb.com/calendar/ical/12345678.ics?s=abcdef...',
    ],
    'ical_cache_minutes' => 10,
    // If the Airbnb calendar cannot be read for this long, new bookings are paused
    // (to avoid double-booking) until it can be read again.
    'ical_max_stale_hours' => 6,

    // ---- Secrets (change all of these!) -------------------------------------
    'admin_password' => 'change-me-now',          // for /admin/
    'cron_key'       => 'change-me-random-string', // for the daily payments job
    'export_token'   => 'change-me-another-random-string', // for the calendar link you give Airbnb
];
