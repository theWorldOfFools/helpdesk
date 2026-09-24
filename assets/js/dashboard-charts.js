/* Dashboard Chart.js per-role + leaderboard bulanan (fetch api/dashboard.php) */
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    if (!window.Chart) return;
    Chart.defaults.font.family = "'Inter',system-ui,sans-serif";
    var monthInput = document.getElementById('dashMonth');
    var month = monthInput ? monthInput.value : new Date().toISOString().slice(0, 7);
    var charts = {};

    function getJSON(url) {
      return fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      });
    }

    function medal(i) {
      return i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : '#' + (i + 1);
    }

    function renderLeader(elId, rows, cols, meHtml) {
      var el = document.getElementById(elId);
      if (!el) return;
      if (!rows || !rows.length) {
        el.innerHTML = '<div class="text-center text-secondary py-3 small">Belum ada data bulan ini.</div>' + (meHtml || '');
        return;
      }
      var h = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-dark"><tr>';
      cols.forEach(function (c) { h += '<th>' + c + '</th>'; });
      h += '</tr></thead><tbody>';
      rows.slice(0, 10).forEach(function (r, i) {
        h += '<tr>' + r + '</tr>';
        void medal(i);
      });
      h += '</tbody></table></div>' + (meHtml || '');
      el.innerHTML = h;
    }

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function loadAdmin(m) {
      // Tren
      var c1 = document.getElementById('chTrend');
      if (c1) {
        getJSON('api/dashboard.php?type=trend&days=30').then(function (j) {
          if (charts.trend) charts.trend.destroy();
          charts.trend = new Chart(c1, {
            type: 'line',
            data: { labels: j.labels, datasets: [{ label: 'Dibuat', data: j.created, borderColor: '#e94560', tension: .35 }, { label: 'Resolved', data: j.resolved, borderColor: '#059669', tension: .35 }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
          });
        }).catch(function () {});
      }
      // Status donut
      var c2 = document.getElementById('chStatus');
      if (c2) {
        getJSON('api/dashboard.php?type=status').then(function (j) {
          if (charts.status) charts.status.destroy();
          charts.status = new Chart(c2, {
            type: 'doughnut',
            data: { labels: j.data.map(function (x) { return x.label; }), datasets: [{ data: j.data.map(function (x) { return x.value; }), backgroundColor: ['#f59e0b', '#2563eb', '#059669', '#64748b'] }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
          });
        }).catch(function () {});
      }
      // Prioritas bar
      var c3 = document.getElementById('chPriority');
      if (c3) {
        getJSON('api/dashboard.php?type=priority').then(function (j) {
          if (charts.pri) charts.pri.destroy();
          charts.pri = new Chart(c3, {
            type: 'bar',
            data: { labels: j.data.map(function (x) { return x.label; }), datasets: [{ label: 'Tiket', data: j.data.map(function (x) { return x.value; }), backgroundColor: '#1a1a2e' }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
          });
        }).catch(function () {});
      }
      // Leaderboard teknisi + pelapor
      getJSON('api/dashboard.php?type=leader_teknisi&month=' + encodeURIComponent(m)).then(function (j) {
        var rows = (j.data || []).map(function (r, i) {
          return '<td>' + medal(i) + '</td><td><strong>' + esc(r.name) + '</strong></td><td>' + r.resolved + '</td><td>' + (r.avg_h == null ? '-' : r.avg_h + ' jam') + '</td><td><span class="badge text-bg-primary">' + r.score + '</span></td>';
        });
        var meHtml = j.me ? '<p class="small text-secondary mt-2 mb-0">Posisi kamu: <strong>' + esc(j.me.name) + '</strong> #' + (j.me.rank || '-') + ' • skor ' + j.me.score + '</p>' : '';
        renderLeader('leaderTeknisi', rows, ['#', 'Teknisi', 'Resolved', 'Rata-rata', 'Skor'], meHtml);
      }).catch(function () {});
      getJSON('api/dashboard.php?type=leader_pelapor&month=' + encodeURIComponent(m)).then(function (j) {
        var rows = (j.data || []).map(function (r, i) {
          return '<td>' + medal(i) + '</td><td><strong>' + esc(r.name) + '</strong><br><small class="text-secondary">' + esc(r.division || '-') + '</small></td><td><span class="badge text-bg-success">' + r.total + ' tiket</span></td>';
        });
        var meHtml = j.me ? '<p class="small text-secondary mt-2 mb-0">Laporan kamu bulan ini: <strong>' + j.me.total + '</strong>' + (j.me.rank ? ' • peringkat #' + j.me.rank : ' • belum masuk top 10 — ayo lapor lagi!') + '</p>' : '';
        renderLeader('leaderPelapor', rows, ['#', 'Pelapor', 'Total'], meHtml);
      }).catch(function () {});
    }

    function loadTeknisi(m) {
      var c = document.getElementById('chMyTrend');
      if (c) {
        getJSON('api/dashboard.php?type=trend&days=30').then(function (j) {
          if (charts.mytrend) charts.mytrend.destroy();
          charts.mytrend = new Chart(c, {
            type: 'line',
            data: { labels: j.labels, datasets: [{ label: 'Resolved (global)', data: j.resolved, borderColor: '#2563eb', tension: .35 }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
          });
        }).catch(function () {});
      }
      getJSON('api/dashboard.php?type=leader_teknisi&month=' + encodeURIComponent(m)).then(function (j) {
        var rows = (j.data || []).slice(0, 5).map(function (r, i) {
          return '<td>' + medal(i) + '</td><td><strong>' + esc(r.name) + '</strong></td><td>' + r.resolved + '</td><td>' + (r.avg_h == null ? '-' : r.avg_h + ' jam') + '</td>';
        });
        var meHtml = j.me
          ? '<div class="alert alert-info py-2 mt-2 mb-0 small">Peringkat kamu <strong>#' + (j.me.rank || '-') + '</strong> • ' + j.me.resolved + ' resolved • rata-rata ' + (j.me.avg_h == null ? '-' : j.me.avg_h + ' jam') + ' • skor ' + j.me.score + '</div>'
          : '<div class="alert alert-light border py-2 mt-2 mb-0 small">Kamu belum resolved bulan ini — ambil antrean untuk masuk leaderboard!</div>';
        renderLeader('leaderTeknisiMini', rows, ['#', 'Teknisi', 'Done', 'Rata-rata'], meHtml);
      }).catch(function () {});
    }

    function loadPelapor(m) {
      var c1 = document.getElementById('chMyStatus');
      if (c1) {
        getJSON('api/dashboard.php?type=status').then(function (j) {
          if (charts.myst) charts.myst.destroy();
          charts.myst = new Chart(c1, {
            type: 'doughnut',
            data: { labels: j.data.map(function (x) { return x.label; }), datasets: [{ data: j.data.map(function (x) { return x.value; }), backgroundColor: ['#f59e0b', '#2563eb', '#059669', '#64748b'] }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
          });
        }).catch(function () {});
      }
      getJSON('api/dashboard.php?type=leader_pelapor&month=' + encodeURIComponent(m)).then(function (j) {
        var rows = (j.data || []).slice(0, 5).map(function (r, i) {
          return '<td>' + medal(i) + '</td><td><strong>' + esc(r.name) + '</strong></td><td><span class="badge text-bg-success">' + r.total + '</span></td>';
        });
        var meHtml = j.me
          ? '<p class="small text-secondary mt-2 mb-0">Laporan kamu: <strong>' + j.me.total + ' tiket</strong>' + (j.me.rank ? ' • peringkat #' + j.me.rank + ' 🎉' : '') + '</p>'
          : '<p class="small text-secondary mt-2 mb-0">Belum ada laporan bulan ini. <a href="create_ticket.php">Buat tiket pertama →</a></p>';
        renderLeader('leaderPelaporMini', rows, ['#', 'Pelapor', 'Total'], meHtml);
      }).catch(function () {});
    }

    var role = document.body.getAttribute('data-role') || '';
    function reloadAll() {
      var m = monthInput ? monthInput.value : month;
      if (role === 'admin') loadAdmin(m);
      else if (role === 'teknisi') loadTeknisi(m);
      else loadPelapor(m);
    }
    if (monthInput) monthInput.addEventListener('change', reloadAll);
    reloadAll();
  });
})();
