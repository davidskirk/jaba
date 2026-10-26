/* Shared helpers for the booking pages. */
(function () {
  'use strict';

  function money(cents) {
    return '$' + (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function parseDate(iso) {
    var p = iso.split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]);
  }

  function isoDate(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function addDays(iso, n) {
    var d = parseDate(iso);
    d.setDate(d.getDate() + n);
    return isoDate(d);
  }

  function fmtDate(iso, opts) {
    return parseDate(iso).toLocaleDateString('en-US', opts || { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  }

  function api(path, options) {
    options = options || {};
    var init = { method: options.method || 'GET', headers: {} };
    if (options.body) {
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }
    return fetch('api/' + path, init).then(function (r) {
      return r.text().then(function (text) {
        var data;
        try { data = JSON.parse(text); } catch (e) { data = { error: 'Unexpected server response (' + r.status + '). Is PHP enabled on this hosting?' }; }
        if (!r.ok || data.error) { throw new Error(data.error || ('HTTP ' + r.status)); }
        return data;
      });
    });
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function row(label, value, cls) {
    return '<tr' + (cls ? ' class="' + cls + '"' : '') + '><td>' + label + '</td><td>' + value + '</td></tr>';
  }

  /** Render a price breakdown into a <table>. opts.schedule: show payment schedule rows. */
  function renderBreakdown(table, q, opts) {
    opts = opts || {};
    var html = '';
    // Group nights by rate so the summary stays short.
    var groups = {};
    q.lines.forEach(function (l) {
      var key = l.cents;
      groups[key] = groups[key] || { count: 0, cents: l.cents };
      groups[key].count++;
    });
    Object.keys(groups).sort(function (a, b) { return a - b; }).forEach(function (k) {
      var g = groups[k];
      html += row(money(g.cents) + ' &times; ' + g.count + ' night' + (g.count > 1 ? 's' : ''), money(g.cents * g.count));
    });
    if (q.discount) html += row(esc(q.discount.label), '&minus;' + money(q.discount.cents), 'disc');
    html += row('Cleaning fee', money(q.cleaning_cents));
    q.taxes.forEach(function (t) { html += row(esc(t.name), money(t.cents)); });
    if (q.tax_exempt) html += row('Occupancy taxes (30+ nights: exempt)', '$0.00', 'disc');
    html += row('Total', money(q.total_cents), 'total');
    if (opts.schedule !== false) {
      if (q.balance_cents > 0) {
        html += row('Due today (50%)', money(q.deposit_cents), 'sched now');
        html += row('Due ' + fmtDate(q.balance_due_date, { month: 'short', day: 'numeric', year: 'numeric' }) + ' (50%)', money(q.balance_cents), 'sched');
      } else {
        html += row('Due today (paid in full)', money(q.deposit_cents), 'sched now');
      }
    }
    table.innerHTML = html;
    table.classList.remove('hidden');
  }

  function message(el, text, kind) {
    el.innerHTML = text ? '<div class="msg ' + (kind || 'error') + '">' + esc(text) + '</div>' : '';
  }

  function param(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  window.CH = {
    money: money, parseDate: parseDate, isoDate: isoDate, addDays: addDays, fmtDate: fmtDate,
    api: api, esc: esc, renderBreakdown: renderBreakdown, message: message, param: param
  };
})();
