/* Availability calendar and booking flow for index.html */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var CH = window.CH;

  var data = null;            // response from api/availability.php
  var blocked = {};           // nights that are unavailable
  var view = null;            // Date: first of the left-hand month
  var checkin = null, checkout = null;
  var quote = null;
  var stripe = null, elements = null, booking = null, holdTimer = null;

  // ---------------------------------------------------------------- setup
  CH.api('availability.php').then(function (d) {
    data = d;
    d.blocked.forEach(function (n) { blocked[n] = true; });
    var first = CH.parseDate(d.first_checkin);
    view = new Date(first.getFullYear(), first.getMonth(), 1);
    applyBindings(d);
    if (d.stripe_mode === 'test') $('test-banner').classList.remove('hidden');
    if (!d.booking_enabled) {
      var box = $('booking-disabled');
      box.innerHTML = 'Online booking is not available right now. ' +
        (d.contact_email ? 'Please <a href="mailto:' + CH.esc(d.contact_email) + '">email us</a> to book.' : 'Please contact us to book.');
      box.classList.remove('hidden');
    }
    var guests = $('guests');
    guests.innerHTML = '';
    for (var g = 1; g <= d.max_guests; g++) {
      guests.insertAdjacentHTML('beforeend', '<option value="' + g + '"' + (g === Math.min(2, d.max_guests) ? ' selected' : '') + '>' + g + ' guest' + (g > 1 ? 's' : '') + '</option>');
    }
    restoreFromUrl();
    render();
  }).catch(function (e) {
    $('months').innerHTML = '<div class="msg error">Could not load availability: ' + CH.esc(e.message) + '</div>';
  });

  function applyBindings(d) {
    var values = {
      rate_weekday: d.rates.weekday, rate_weekend: d.rates.weekend, rate_holiday: d.rates.holiday,
      cleaning_fee: d.rates.cleaning, max_guests: d.max_guests, checkin_time: d.checkin_time, checkout_time: d.checkout_time
    };
    document.querySelectorAll('[data-bind]').forEach(function (el) {
      var v = values[el.getAttribute('data-bind')];
      if (v !== undefined && v !== null) el.textContent = v;
    });
    document.querySelectorAll('[data-contact-email]').forEach(function (el) {
      if (d.contact_email) { el.href = 'mailto:' + d.contact_email; el.textContent = d.contact_email; }
    });
  }

  function restoreFromUrl() {
    var ci = CH.param('checkin'), co = CH.param('checkout');
    if (ci && co && isValidCheckin(ci) && rangeFree(ci, co)) {
      checkin = ci; checkout = co;
      var d = CH.parseDate(ci);
      view = new Date(d.getFullYear(), d.getMonth(), 1);
      fetchQuote();
    }
  }

  // ---------------------------------------------------------------- calendar rules
  function nightRate(iso) {
    if (data.holidays[iso] || data.holiday_weekend_nights[iso]) return data.rates.holiday;
    var dow = CH.parseDate(iso).getDay();
    return (dow === 5 || dow === 6) ? data.rates.weekend : data.rates.weekday;
  }

  function isValidCheckin(iso) {
    return iso >= data.first_checkin && iso < data.last_checkout && !blocked[iso];
  }

  function rangeFree(from, to) {
    if (to <= from || to > data.last_checkout) return false;
    for (var d = from; d < to; d = CH.addDays(d, 1)) if (blocked[d]) return false;
    return true;
  }

  function selectingCheckout() { return checkin && !checkout; }

  function onDayClick(iso) {
    if (booking) return; // locked once payment has started
    if (selectingCheckout() && iso > checkin && rangeFree(checkin, iso)) {
      checkout = iso;
      fetchQuote();
    } else if (isValidCheckin(iso)) {
      checkin = iso; checkout = null; quote = null;
      updatePanel();
    }
    render();
  }

  // ---------------------------------------------------------------- render
  function render() {
    if (!data) return;
    var html = '';
    for (var m = 0; m < 2; m++) {
      var first = new Date(view.getFullYear(), view.getMonth() + m, 1);
      html += '<div class="month"><h4>' + first.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) + '</h4>';
      html += '<div class="dow">' + ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].map(function (x) { return '<span>' + x + '</span>'; }).join('') + '</div><div class="days">';
      for (var i = 0; i < first.getDay(); i++) html += '<span class="day empty"></span>';
      var daysIn = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
      for (var day = 1; day <= daysIn; day++) {
        var iso = CH.isoDate(new Date(first.getFullYear(), first.getMonth(), day));
        html += dayCell(iso, day);
      }
      html += '</div></div>';
    }
    $('months').innerHTML = html;
    var minView = CH.parseDate(data.first_checkin);
    $('cal-prev').disabled = view <= new Date(minView.getFullYear(), minView.getMonth(), 1);
    var maxView = CH.parseDate(data.last_checkout);
    $('cal-next').disabled = new Date(view.getFullYear(), view.getMonth() + 1, 1) >= new Date(maxView.getFullYear(), maxView.getMonth(), 1);
    $('cal-hint').textContent = !checkin ? 'Select check-in date' : (!checkout ? 'Select check-out date' : CH.fmtDate(checkin, { month: 'short', day: 'numeric' }) + ' – ' + CH.fmtDate(checkout, { month: 'short', day: 'numeric' }));
  }

  function dayCell(iso, n) {
    var cls = ['day'];
    var canCheckout = selectingCheckout() && iso > checkin && rangeFree(checkin, iso);
    var enabled = canCheckout || isValidCheckin(iso);
    var past = iso < data.first_checkin || iso > data.last_checkout;
    if (blocked[iso] && !past) cls.push('blocked');
    if (canCheckout && blocked[iso]) cls.push('checkout-only');
    if (data.holidays[iso]) cls.push('holiday');
    if (checkin && iso === checkin) cls.push('start');
    if (checkout && iso === checkout) cls.push('end');
    if (checkin && checkout && iso > checkin && iso < checkout) cls.push('in-range');
    var title = data.holidays[iso] || data.holiday_weekend_nights[iso] || '';
    if (blocked[iso] && !past) title = (title ? title + ' – ' : '') + (canCheckout ? 'Check-out only' : 'Unavailable');
    var price = (!blocked[iso] && !past && iso < data.last_checkout) ? '$' + nightRate(iso) : '&nbsp;';
    return '<button type="button" class="' + cls.join(' ') + '" data-date="' + iso + '"' + (enabled ? '' : ' disabled') +
      (title ? ' title="' + CH.esc(title) + '"' : '') + '><span class="n">' + n + '</span><span class="p">' + price + '</span></button>';
  }

  $('months').addEventListener('click', function (e) {
    var b = e.target.closest('.day');
    if (b && !b.disabled) onDayClick(b.getAttribute('data-date'));
  });
  $('cal-prev').addEventListener('click', function () { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); render(); });
  $('cal-next').addEventListener('click', function () { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); render(); });
  $('btn-clear').addEventListener('click', function () { checkin = checkout = quote = null; updatePanel(); render(); });
  $('guests').addEventListener('change', function () { if (checkout) fetchQuote(); });

  // ---------------------------------------------------------------- quote panel
  function updatePanel() {
    $('ci-label').textContent = checkin ? CH.fmtDate(checkin, { weekday: 'short', month: 'short', day: 'numeric' }) : 'Add date';
    $('co-label').textContent = checkout ? CH.fmtDate(checkout, { weekday: 'short', month: 'short', day: 'numeric' }) : 'Add date';
    $('btn-clear').classList.toggle('hidden', !checkin);
    $('btn-reserve').disabled = !(quote && quote.ok && quote.available !== false && data.booking_enabled);
    if (!quote) { $('breakdown').classList.add('hidden'); CH.message($('quote-msg'), ''); }
  }

  var quoteSeq = 0;
  function fetchQuote() {
    quote = null;
    updatePanel();
    CH.message($('quote-msg'), 'Calculating price…', 'info');
    var seq = ++quoteSeq;
    CH.api('quote.php?checkin=' + checkin + '&checkout=' + checkout + '&guests=' + $('guests').value).then(function (q) {
      if (seq !== quoteSeq) return;
      if (!q.ok) { CH.message($('quote-msg'), q.error); return; }
      quote = q;
      CH.renderBreakdown($('breakdown'), q);
      if (q.available === false) CH.message($('quote-msg'), 'Sorry, those dates are no longer available.');
      else CH.message($('quote-msg'), q.nights + ' night' + (q.nights > 1 ? 's' : '') + ' · ' + CH.fmtDate(q.checkin, { month: 'short', day: 'numeric' }) + ' – ' + CH.fmtDate(q.checkout, { month: 'short', day: 'numeric', year: 'numeric' }), 'ok');
      updatePanel();
    }).catch(function (e) {
      if (seq === quoteSeq) CH.message($('quote-msg'), e.message);
    });
  }

  // ---------------------------------------------------------------- steps
  function show(step) {
    ['step-quote', 'step-details', 'step-pay'].forEach(function (s) { $(s).classList.toggle('hidden', s !== step); });
    if (window.innerWidth < 960) $('panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  $('btn-reserve').addEventListener('click', function () { if (quote && quote.ok) show('step-details'); });
  $('btn-back').addEventListener('click', function () { show('step-quote'); });

  $('step-details').addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = $('details-msg');
    var body = {
      checkin: checkin, checkout: checkout, guests: +$('guests').value,
      name: $('f-name').value.trim(), email: $('f-email').value.trim(),
      phone: $('f-phone').value.trim(), notes: $('f-notes').value.trim(), agree: $('f-agree').checked
    };
    if (!body.name) return CH.message(msg, 'Please enter your name.');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(body.email)) return CH.message(msg, 'Please enter a valid email address.');
    if (!body.agree) return CH.message(msg, 'Please tick the box to agree to the house rules and policies.');
    if (typeof Stripe === 'undefined') return CH.message(msg, 'The payment system could not load. Please check your connection or disable ad-blockers and reload.');
    var btn = $('btn-to-pay');
    btn.disabled = true; btn.textContent = 'Holding your dates…';
    CH.message(msg, '');
    CH.api('book.php', { body: body }).then(function (res) {
      booking = res;
      startPayment(res);
    }).catch(function (err) {
      CH.message(msg, err.message);
    }).then(function () {
      btn.disabled = false; btn.textContent = 'Continue to payment';
    });
  });

  function startPayment(res) {
    stripe = Stripe(res.publishable_key);
    elements = stripe.elements({
      clientSecret: res.client_secret,
      appearance: { theme: 'stripe', variables: { colorPrimary: '#2f3e34', borderRadius: '10px', fontFamily: 'Inter, system-ui, sans-serif' } }
    });
    elements.create('payment', { fields: { billingDetails: { name: 'auto', email: 'auto' } }, defaultValues: { billingDetails: { name: $('f-name').value, email: $('f-email').value } } }).mount('#payment-element');
    CH.renderBreakdown($('pay-breakdown'), res.quote);
    $('btn-pay').textContent = 'Pay ' + CH.money(res.amount_now_cents) + ' now';
    var until = Date.now() + res.hold_minutes * 60000;
    var tick = function () {
      var left = Math.max(0, until - Date.now());
      var mm = Math.floor(left / 60000), ss = Math.floor(left % 60000 / 1000);
      $('hold-note').textContent = left > 0
        ? 'We\'re holding these dates for you for ' + mm + ':' + String(ss).padStart(2, '0') + '. Booking ref ' + res.ref + '.'
        : 'Your hold has expired. Please reload the page and try again.';
      if (left <= 0) { clearInterval(holdTimer); $('btn-pay').disabled = true; }
    };
    tick();
    holdTimer = setInterval(tick, 1000);
    show('step-pay');
  }

  $('step-pay').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = $('btn-pay');
    var label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Processing…';
    CH.message($('pay-msg'), '');
    var returnUrl = new URL('confirmation.html', window.location.href);
    returnUrl.searchParams.set('ref', booking.ref);
    returnUrl.searchParams.set('t', booking.token);
    stripe.confirmPayment({ elements: elements, confirmParams: { return_url: returnUrl.href } }).then(function (result) {
      // Only reached if there is an immediate error (otherwise Stripe redirects).
      if (result.error) {
        CH.message($('pay-msg'), result.error.message);
        btn.disabled = false; btn.textContent = label;
      }
    });
  });

  // ---------------------------------------------------------------- gallery lightbox
  var lb = $('lightbox');
  document.querySelectorAll('.gallery img').forEach(function (img) {
    img.parentNode.addEventListener('click', function () { lb.querySelector('img').src = img.src; lb.querySelector('img').alt = img.alt; lb.classList.add('open'); });
  });
  lb.addEventListener('click', function () { lb.classList.remove('open'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lb.classList.remove('open'); });
})();
