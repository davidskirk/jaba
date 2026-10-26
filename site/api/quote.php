<?php
// GET ?checkin=YYYY-MM-DD&checkout=YYYY-MM-DD&guests=N : price breakdown.
require __DIR__ . '/lib.php';

run_api(function () {
    $q = quote_stay((string) ($_GET['checkin'] ?? ''), (string) ($_GET['checkout'] ?? ''), (int) ($_GET['guests'] ?? 1));
    if ($q['ok']) {
        $q['available'] = check_dates_free($q['checkin'], $q['checkout'], false) === null;
    }
    json_out($q);
});
