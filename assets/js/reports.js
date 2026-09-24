/* Menu Laporan: KPI + analisa otomatis + tren + SLA respons/resolusi + breach */
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmtDur(min) {
    if (min == null) return '-';
    min = Math.round(min);
    if (min < 60) return min + ' mnt';
    var h = Math.floor(min / 60), r = min % 60;
    return h + ' jam' + (r ? ' ' + r + ' mnt' : '');
  }
  function badgePct(pct, target) {
    target = target || 100;
    var cls = pct >= target ? 'text-bg-success' : (pct >= target - 5 ? 'text-bg-warning' : 'text-bg-danger');
    return '<span class="badge ' + cls + '">' + pct + '%</span>';
  }
  function tbl(heads, rows, emptyMsg) {
    if (!rows.length) return '<div class="text-center text-secondary py-2 small">' + emptyMsg + '</div>';
    var h = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-dark"><tr>';
    heads.forEach(function (c) { h += '<th>' + c + '</th>'; });
    h += '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
    return h;
  }

  ready(function () {
    var form = document.getElementById('repFilter');
    if (!form) return;
    var monthEl = document.getElementById('fMonth');
    var divEl = document.getElementById('fDiv');
    var priEl = document.getElementById('fPri');
    var charts = {};

    function params() {
      var m = monthEl.value || new Date().toISOString().slice(0, 7);
      return { month: m, division: divEl.value || '', priority: priEl.value || '' };
    }
    function qs(p) {
      return 'month=' + encodeURIComponent(p.month) + '&division=' + encodeURIComponent(p.division) + '&priority=' + encodeURIComponent(p.priority);
    }
    function get(url) {
      return fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      });
    }

    function loadAll() {
      var p = params(), q = qs(p);

      // ---- KPI + narasi ----
      get('api/reports.php?type=kpi&' + q).then(function (k) {
        document.getElementById('kTotal').textContent = k.total;
        document.getElementById('kDone').textContent = k.done + ' (' + k.pct_done + '%)';
        document.getElementById('kResp').innerHTML = k.resp.pct + '% ' + badgePct(k.resp.pct, 100);
        document.getElementById('kRespFrac').textContent = k.resp.num + '/' + k.resp.den + ' tiket ≤60 mnt';
        document.getElementById('kRes').innerHTML = k.res.pct + '% ' + badgePct(k.res.pct, 90);
        document.getElementById('kResFrac').textContent = k.res.num + '/' + k.res.den + ' tepat SLA';
        document.getElementById('kMttr').textContent = k.mttr_min == null ? '-' : fmtDur(k.mttr_min);
        document.getElementById('kBreach').textContent = k.res.breach_open;

        var nar = [];
        nar.push('<strong>' + k.total + ' tiket</strong> pada periode <strong>' + esc(p.month) + '</strong>' +
          (p.division ? ' divisi <strong>' + esc(p.division) + '</strong>' : '') +
          (p.priority ? ' prioritas <strong>' + esc(p.priority) + '</strong>' : '') +
          '; selesai <strong>' + k.pct_done + '%</strong>.');
        nar.push('SLA respons ≤60 menit kerja: <strong>' + k.resp.pct + '%</strong> (' + k.resp.num + '/' + k.resp.den + ')' +
          (k.resp.pct < 100 ? ' — <span class="text-danger">di bawah target 100%, ' + (k.resp.den - k.resp.num) + ' tiket terlambat direspons. Prioritaskan patroli antrean open.</span>'
            : ' — <span class="text-success">target 100% tercapai, pertahankan.</span>'));
        nar.push('SLA resolusi: <strong>' + k.res.pct + '%</strong> tepat waktu; <strong>' + k.res.breach_open + ' tiket berjalan sudah overdue</strong>.' +
          (k.mttr_min != null ? ' Rata-rata penyelesaian (MTTR jam kerja): <strong>' + fmtDur(k.mttr_min) + '</strong>.' : ''));
        document.getElementById('repNarrative').innerHTML = '<ul class="mb-0 ps-3"><li>' + nar.join('</li><li>') + '</li></ul>';
      }).catch(function () {});

      // ---- Tren ----
      var cv = document.getElementById('chRepTrend');
      if (cv && window.Chart) {
        get('api/reports.php?type=trend&' + q).then(function (j) {
          if (charts.trend) charts.trend.destroy();
          charts.trend = new Chart(cv, {
            type: 'line',
            data: {
              labels: j.labels,
              datasets: [
                { label: 'Dibuat', data: j.created, borderColor: '#e94560', tension: .35 },
                { label: 'Selesai', data: j.resolved, borderColor: '#059669', tension: .35 },
                { label: 'Backlog', data: j.backlog, borderColor: '#64748b', borderDash: [5, 5], tension: .35 }
              ]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
          });
        }).catch(function () {});
      }

      // ---- SLA respons ----
      get('api/reports.php?type=sla_response&' + q).then(function (j) {
        document.getElementById('srPct').textContent = j.pct + '%';
        document.getElementById('srFrac').textContent = j.num + '/' + j.den + ' tiket · pending ' + j.pending;
        var bar = document.getElementById('srBar');
        bar.style.width = j.pct + '%';
        bar.className = 'progress-bar ' + (j.pct >= 100 ? 'bg-success' : (j.pct >= 95 ? 'bg-warning' : 'bg-danger'));
        document.getElementById('srBreach').innerHTML = tbl(
          ['Tiket', 'Judul', 'Pri', 'Keterlambatan', 'Penangan', ''],
          j.breach.map(function (b) {
            return '<tr><td><strong>' + esc(b.ticket_number) + '</strong></td><td>' + esc(b.title) + '<br><small class="text-secondary">' + esc(b.pelapor || '-') + ' · ' + esc(b.status) + '</small></td>' +
              '<td>' + esc(b.priority) + '</td><td><span class="badge text-bg-danger">+' + fmtDur(b.over_min) + '</span></td>' +
              '<td>' + esc(b.assignee || '—') + '</td>' +
              '<td><a class="btn btn-sm btn-primary" href="view_ticket.php?id=' + b.id + '">Tangani</a></td></tr>';
          }), 'Nihil — semua respons tepat waktu. 🎉');
        document.getElementById('srPending').innerHTML = tbl(
          ['Tiket', 'Judul', 'Umur'],
          j.pending_list.map(function (b) {
            return '<tr><td><strong>' + esc(b.ticket_number) + '</strong></td><td>' + esc(b.title) + '</td><td>' + fmtDur(b.age_min) + ' / 60 mnt</td></tr>';
          }), 'Tidak ada tiket dalam tenggang.');
      }).catch(function () {});

      // ---- SLA resolusi ----
      get('api/reports.php?type=sla_resolution&' + q).then(function (j) {
        document.getElementById('resPri').innerHTML = tbl(
          ['Prioritas', 'Selesai', 'Tepat SLA', 'MTTR'],
          j.by_priority.map(function (r) {
            return '<tr><td><strong>' + esc(r.label) + '</strong></td><td>' + r.done + '</td><td>' + badgePct(r.pct, 90) + ' ' + r.ontime + '/' + r.done + '</td><td>' + fmtDur(r.mttr) + '</td></tr>';
          }), 'Belum ada tiket selesai.');
        document.getElementById('resDiv').innerHTML = tbl(
          ['Divisi', 'Selesai', 'Tepat SLA', 'MTTR'],
          j.by_division.map(function (r) {
            return '<tr><td><strong>' + esc(r.label) + '</strong></td><td>' + r.done + '</td><td>' + badgePct(r.pct, 90) + '</td><td>' + fmtDur(r.mttr) + '</td></tr>';
          }), 'Belum ada data.');
      }).catch(function () {});

      // ---- Breach resolusi ----
      get('api/reports.php?type=breach&' + q).then(function (j) {
        document.getElementById('repBreach').innerHTML = tbl(
          ['Tiket', 'Judul', 'Pri', 'Status', 'Overdue', 'Penangan', ''],
          (j.data || []).map(function (b) {
            return '<tr><td><strong>' + esc(b.ticket_number) + '</strong></td><td>' + esc(b.title) + '<br><small class="text-secondary">' + esc(b.pelapor || '-') + '</small></td>' +
              '<td>' + esc(b.priority) + '</td><td>' + esc(b.status) + '</td><td><span class="badge text-bg-danger">+' + fmtDur(b.over_min) + '</span></td>' +
              '<td>' + esc(b.assignee || '—') + '</td>' +
              '<td><a class="btn btn-sm btn-primary" href="view_ticket.php?id=' + b.id + '">Tangani</a></td></tr>';
          }), 'Nihil — tidak ada breach resolusi. 🎉');
      }).catch(function () {});
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      loadAll();
      // sinkron tombol export dengan filter
      var p = params();
      document.querySelectorAll('a[href^="export.php"]').forEach(function (a) {
        var m = p.month.split('-');
        var last = new Date(+m[0], +m[1], 0).toISOString().slice(0, 10);
        var fmt = 'csv';
        var mf = a.href.match(/[?&]format=(csv|xlsx|pdf)/);
        if (mf) fmt = mf[1];
        a.href = 'export.php?' + 'date_from=' + m[0] + '-' + m[1] + '-01&date_to=' + last +
          '&filter_division=' + encodeURIComponent(p.division) + '&filter_priority=' + encodeURIComponent(p.priority) + '&format=' + fmt;
      });
    });
    loadAll();
  });
})();
