/* Renders a booking summary (used by confirmation and manage pages). */
window.BookingView = {
  details: function (b) {
    var CH = window.CH;
    var paid = b.paid_cents, bal = b.balance_cents;
    var balText = '';
    if (bal > 0) {
      balText = b.balance_status === 'paid' ? CH.money(bal) + ' (paid)'
        : b.status === 'cancelled' ? '—'
        : CH.money(bal) + ' due ' + CH.fmtDate(b.balance_due_date) + (b.balance_status === 'failed' ? ' — payment failed' : ' (charged automatically)');
    }
    var table = document.createElement('table');
    table.className = 'breakdown';
    CH.renderBreakdown(table, b.quote, { schedule: false });
    return '<dl class="kv">' +
      '<dt>Booking reference</dt><dd>' + CH.esc(b.ref) + '</dd>' +
      '<dt>Check-in</dt><dd>' + CH.fmtDate(b.checkin) + ' from ' + CH.esc(b.checkin_time) + '</dd>' +
      '<dt>Check-out</dt><dd>' + CH.fmtDate(b.checkout) + ' by ' + CH.esc(b.checkout_time) + '</dd>' +
      '<dt>Guests</dt><dd>' + b.guests + '</dd>' +
      '<dt>Paid so far</dt><dd>' + CH.money(paid) + '</dd>' +
      (balText ? '<dt>Balance</dt><dd>' + balText + '</dd>' : '') +
      (b.refunded_cents > 0 ? '<dt>Refunded</dt><dd>' + CH.money(b.refunded_cents) + '</dd>' : '') +
      '</dl>' + table.outerHTML;
  }
};
