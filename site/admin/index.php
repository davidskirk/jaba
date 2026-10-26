<?php
// Owner dashboard: bookings, refunds, balance charges, setup checks.
require __DIR__ . '/../api/lib.php';

session_start();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$flash = '';
$self = strtok($_SERVER['REQUEST_URI'], '?');

if (isset($_POST['password'])) {
    if (hash_equals((string) cfg('admin_password'), (string) $_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } else {
        sleep(2);
        $flash = 'Wrong password.';
    }
}
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: $self");
    exit;
}
$loggedIn = !empty($_SESSION['admin']);

if ($loggedIn && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $flash = 'Session expired - please try again.';
    } else {
        try {
            $b = booking_by_ref((string) ($_POST['ref'] ?? ''));
            switch ($_POST['action']) {
                case 'cancel_full':
                    $b = cancel_booking($b, 'full', 'Cancelled by owner (full refund)');
                    $flash = "Cancelled {$b['ref']} and refunded " . money((int) $b['refunded_cents']) . '.';
                    break;
                case 'cancel_none':
                    $b = cancel_booking($b, 'none', 'Cancelled by owner (no refund)');
                    $flash = "Cancelled {$b['ref']} without refund.";
                    break;
                case 'charge_balance':
                    [$ok, $msg] = charge_balance($b);
                    $flash = ($ok ? 'Success: ' : 'Failed: ') . $msg;
                    break;
                case 'sync':
                    $ext = external_blocked_nights(true);
                    $flash = !$ext['configured'] ? 'No Airbnb calendar link in config.php yet.' : ($ext['stale'] ? 'Could not read the Airbnb calendar - check the link.' : 'Airbnb calendar synced: ' . count($ext['nights']) . ' blocked nights.');
                    break;
            }
        } catch (Throwable $e) {
            $flash = 'Error: ' . $e->getMessage();
        }
    }
}

function action_button(string $action, string $ref, string $label, string $confirm = '', string $cls = ''): string
{
    return '<form method="post" class="inline" ' . ($confirm ? 'onsubmit="return confirm(\'' . h($confirm) . '\')"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="ref" value="' . h($ref) . '">'
        . '<button class="' . $cls . '">' . h($label) . '</button></form>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Bookings Admin - <?= h(cfg('site_name')) ?></title>
<style>
  body { font: 15px/1.45 system-ui, sans-serif; margin: 0; background: #f6f3ee; color: #222; }
  header { background: #2f3e34; color: #fff; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
  header a { color: #e7d7b4; }
  main { max-width: 1200px; margin: 0 auto; padding: 20px 16px; }
  .card { background: #fff; border-radius: 10px; padding: 16px 18px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow-x: auto; }
  table { border-collapse: collapse; width: 100%; font-size: 14px; }
  th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  th { font-size: 12px; text-transform: uppercase; color: #666; }
  .inline { display: inline; }
  button { font: inherit; font-size: 13px; padding: 4px 9px; margin: 2px; border-radius: 6px; border: 1px solid #999; background: #fff; cursor: pointer; }
  button.danger { border-color: #b33; color: #b33; }
  button.primary { background: #2f3e34; color: #fff; border-color: #2f3e34; }
  .flash { background: #fff7d6; border: 1px solid #e5cf73; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .pill { display: inline-block; padding: 1px 8px; border-radius: 99px; font-size: 12px; background: #eee; }
  .pill.confirmed { background: #d8f0dc; } .pill.cancelled { background: #f6d6d6; } .pill.pending { background: #fff1c2; } .pill.failed { background: #f6d6d6; }
  .ok { color: #1d7a35; } .bad { color: #b33; font-weight: 600; }
  code { background: #f1efe9; padding: 2px 5px; border-radius: 4px; word-break: break-all; }
  .test { background: #ff9f1c; color: #000; text-align: center; padding: 4px; font-weight: 600; }
  input[type=password] { font: inherit; padding: 8px; border-radius: 6px; border: 1px solid #aaa; }
  .muted { color: #777; font-size: 13px; }
</style>
</head>
<body>
<?php if (stripe_mode() === 'test'): ?><div class="test">STRIPE TEST MODE - no real money is moving</div><?php endif; ?>
<header><strong><?= h(cfg('site_name')) ?> &middot; Bookings</strong><?php if ($loggedIn): ?><a href="?logout=1">Log out</a><?php endif; ?></header>
<main>
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

<?php if (!$loggedIn): ?>
  <div class="card" style="max-width:360px">
    <form method="post"><p>Owner password</p><input type="password" name="password" autofocus> <button class="primary">Log in</button></form>
    <?php if (cfg('admin_password') === 'change-me-now'): ?><p class="bad">Set your own admin_password in api/config.php!</p><?php endif; ?>
  </div>
<?php else:
    expire_holds();
    $upcoming = db()->query("SELECT * FROM bookings WHERE status IN ('confirmed','pending') AND checkout >= date('now','localtime') ORDER BY checkin")->fetchAll();
    $past = db()->query("SELECT * FROM bookings WHERE status = 'cancelled' OR (status = 'confirmed' AND checkout < date('now','localtime')) ORDER BY checkin DESC LIMIT 100")->fetchAll();
    $ext = external_blocked_nights(false);
    $exportUrl = cfg('site_url') . '/api/calendar.php?token=' . urlencode((string) cfg('export_token'));
    $cronUrl = cfg('site_url') . '/api/cron.php?key=' . urlencode((string) cfg('cron_key'));
?>
  <div class="card">
    <h2 style="margin-top:0">Upcoming bookings</h2>
    <?php if (!$upcoming): ?><p class="muted">No upcoming direct bookings yet.</p><?php else: ?>
    <table>
      <tr><th>Ref</th><th>Dates</th><th>Guest</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr>
      <?php foreach ($upcoming as $b): ?>
      <tr>
        <td><strong><?= h($b['ref']) ?></strong><br><span class="muted"><?= h($b['stripe_mode']) ?></span></td>
        <td><?= h(fmt_date($b['checkin'])) ?> &rarr;<br><?= h(fmt_date($b['checkout'])) ?><br><span class="muted"><?= (int) $b['nights'] ?> nights, <?= (int) $b['guests'] ?> guests</span></td>
        <td><?= h($b['name']) ?><br><a href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a><br><?= h($b['phone']) ?><?php if ($b['notes']): ?><br><em class="muted"><?= h($b['notes']) ?></em><?php endif; ?></td>
        <td><?= money((int) $b['total_cents']) ?><br><span class="muted">incl. tax <?= money((int) $b['tax_cents']) ?></span></td>
        <td><?= money(paid_cents($b)) ?></td>
        <td><?php if ((int) $b['balance_cents'] > 0): ?><?= money((int) $b['balance_cents']) ?><br><span class="pill <?= h($b['balance_status']) ?>"><?= h($b['balance_status']) ?></span><br><span class="muted">due <?= h($b['balance_due_date']) ?></span><?php else: ?>&mdash;<?php endif; ?></td>
        <td><span class="pill <?= h($b['status']) ?>"><?= h($b['status']) ?></span><?php if ($b['status'] === 'pending'): ?><br><span class="muted">awaiting payment</span><?php endif; ?></td>
        <td>
          <?php if ($b['status'] === 'confirmed'): ?>
            <?php if (in_array($b['balance_status'], ['scheduled', 'failed'], true)) echo action_button('charge_balance', $b['ref'], 'Charge balance now', 'Charge the saved card now?'); ?>
            <?= action_button('cancel_full', $b['ref'], 'Cancel + full refund', 'Cancel and refund everything paid?', 'danger') ?>
            <?= action_button('cancel_none', $b['ref'], 'Cancel, no refund', 'Cancel WITHOUT refund?', 'danger') ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Calendar sync</h2>
    <p>Airbnb import: <?php if (!$ext['configured']): ?><span class="bad">no Airbnb link in config.php yet</span>
      <?php elseif ($ext['stale']): ?><span class="bad">could not be read recently - bookings are paused</span>
      <?php else: ?><span class="ok">OK</span> &middot; <?= count($ext['nights']) ?> blocked nights &middot; last read <?= h(date('M j g:ia', (int) $ext['synced_at'])) ?><?php endif; ?>
      <?= action_button('sync', '', 'Sync now') ?></p>
    <p>Give this link to Airbnb (<em>Availability &rarr; Connect calendars &rarr; Import calendar</em>) so Airbnb blocks dates booked here:<br><code><?= h($exportUrl) ?></code></p>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Setup checklist</h2>
    <?php
    $checks = [
        ['config.php created', !cfg('_using_sample'), 'Copy api/config.sample.php to api/config.php'],
        ['site_url set', cfg('site_url') !== '', 'Set site_url in config.php'],
        ['Admin password changed', cfg('admin_password') !== 'change-me-now', 'Change admin_password'],
        ['Secret links changed', strpos((string) cfg('cron_key') . cfg('export_token'), 'change-me') === false, 'Change cron_key and export_token'],
        ['Owner email set', cfg('owner_email') !== 'you@example.com', 'Set owner_email and from_email'],
        ['Stripe keys (' . stripe_mode() . ' mode)', stripe_configured(), 'Add your Stripe ' . stripe_mode() . ' keys'],
        ['Stripe webhook secret', (string) (cfg('stripe')[stripe_mode() . '_webhook_secret'] ?? '') !== '', 'Optional: add webhook signing secret'],
        ['Airbnb calendar import', $ext['configured'] && !$ext['stale'], 'Add your Airbnb export link to import_ical_urls'],
        ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '7.4', '>='), 'Choose PHP 7.4 or newer in cPanel'],
        ['PHP curl extension', function_exists('curl_init'), 'Enable curl in cPanel > Select PHP Version'],
        ['PHP SQLite (pdo_sqlite)', extension_loaded('pdo_sqlite'), 'Enable pdo_sqlite in cPanel > Select PHP Version'],
        ['Data folder writable', is_writable(DATA_DIR), 'Make api/data writable (permissions 755)'],
    ];
    ?>
    <table>
      <?php foreach ($checks as [$label, $ok, $fix]): ?>
        <tr><td><?= h($label) ?></td><td><?= $ok ? '<span class="ok">&#10003; OK</span>' : '<span class="bad">&#10007; ' . h($fix) . '</span>' ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted">Daily payments job (set up in cPanel &rarr; Cron Jobs): <code>php <?= h(realpath(APP_DIR . '/cron.php')) ?></code><br>
      or have a cron service fetch: <code><?= h($cronUrl) ?></code></p>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Past &amp; cancelled</h2>
    <?php if (!$past): ?><p class="muted">Nothing yet.</p><?php else: ?>
    <table>
      <tr><th>Ref</th><th>Dates</th><th>Guest</th><th>Total</th><th>Paid</th><th>Tax</th><th>Refunded</th><th>Status</th></tr>
      <?php foreach ($past as $b): ?>
      <tr>
        <td><?= h($b['ref']) ?></td>
        <td><?= h($b['checkin']) ?> &rarr; <?= h($b['checkout']) ?></td>
        <td><?= h($b['name']) ?></td>
        <td><?= money((int) $b['total_cents']) ?></td>
        <td><?= money(paid_cents($b)) ?></td>
        <td><?= money((int) $b['tax_cents']) ?></td>
        <td><?= money((int) $b['refunded_cents']) ?></td>
        <td><span class="pill <?= h($b['status']) ?>"><?= h($b['status']) ?></span><?php if ($b['cancel_reason']): ?><br><span class="muted"><?= h($b['cancel_reason']) ?></span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
</main>
</body>
</html>
