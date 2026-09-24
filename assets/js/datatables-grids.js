/* DataTables server-side untuk semua tabel helpdesk.
   Fallback: bila window.__dtReady false, tabel PHP fallback tetap terlihat. */
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

  function fmtDate(iso) {
    if (!iso) return '-';
    var d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d)) return esc(iso);
    var bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return p(d.getDate()) + ' ' + bulan[d.getMonth()] + ' ' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  ready(function () {
    if (!window.__dtReady || !window.jQuery || !window.jQuery.fn.DataTable) return;
    var $ = window.jQuery;
    var role = document.body.getAttribute('data-role') || '';
    var roleMeta = document.querySelector('meta[name="user-role"]');
    if (roleMeta) role = roleMeta.getAttribute('content') || role;

    // Bahasa Indonesia ringkas
    var langID = {
      processing: 'Memuat…',
      search: 'Cari:',
      lengthMenu: 'Tampil _MENU_ baris',
      info: 'Menampil _START_–_END_ dari _TOTAL_ data',
      infoEmpty: 'Tidak ada data',
      infoFiltered: '(difilter dari _MAX_ total)',
      zeroRecords: 'Tidak ada data yang cocok',
      emptyTable: 'Belum ada data',
      paginate: { first: '«', last: '»', next: '›', previous: '‹' }
    };

    // ---- Tabel utama tiket (index.php) ----
    var $tiket = $('#grid-tiket');
    if ($tiket.length) {
      var qs = new URLSearchParams(window.location.search);
      var filters = {
        search: qs.get('search') || '',
        filter_status: qs.get('filter_status') || '',
        filter_priority: qs.get('filter_priority') || '',
        filter_division: qs.get('filter_division') || '',
        has_attachment: qs.get('has_attachment') || '',
        assignee: qs.get('assignee') || '',
        overdue: qs.get('overdue') || ''
      };

      $('#tbl-tiket-fallback-wrap').hide();
      $tiket.show();

      var dt = $tiket.DataTable({
        serverSide: true,
        processing: true,
        pageLength: 15,
        lengthMenu: [15, 30, 50],
        language: langID,
        responsive: true,
        searching: false, // search global dimatikan; pakai filter form sendiri
        ajax: {
          url: 'api/tickets.php',
          data: function (d) {
            // d sudah berisi draw/start/length/order/columns (protokol DataTables)
            d.search = filters.search;
            d.filter_status = filters.filter_status;
            d.filter_priority = filters.filter_priority;
            d.filter_division = filters.filter_division;
            d.has_attachment = filters.has_attachment;
            d.assignee = filters.assignee;
            d.overdue = filters.overdue;
          },
          error: function (xhr) {
            if (xhr && xhr.status === 401) window.location.href = 'login.php';
          }
        },
        order: [[5, 'desc']],
        columns: [
          {
            data: 'ticket_number', title: 'Ticket',
            render: function (data, type, row) {
              if (type !== 'display') return data;
              var h = '<strong>' + esc(row.ticket_number) + '</strong>';
              if (row.has_attachment) h += ' <span class="badge text-bg-info" title="Ada lampiran">📎</span>';
              if (row.is_overdue) h += ' <span class="badge text-bg-danger">overdue</span>';
              return h;
            }
          },
          {
            data: 'title', title: 'Judul & Pelapor',
            render: function (data, type, row) {
              if (type !== 'display') return data;
              var h = '<strong>' + esc(row.title) + '</strong><br><small class="text-secondary">' +
                esc(row.created_by_name || '-') + ' · ' + esc(row.category || '');
              if (row.assignee_name) h += ' · ditangani: ' + esc(row.assignee_name);
              return h + '</small>';
            }
          },
          {
            data: 'priority', title: 'Prioritas',
            render: function (data, type, row) {
              if (type !== 'display') return data;
              return '<span class="priority-badge priority-' + esc(String(row.priority).toLowerCase()) + '">' + esc(row.priority) + '</span>';
            }
          },
          {
            data: 'status', title: 'Status',
            render: function (data, type, row) {
              if (type !== 'display') return data;
              return '<span class="status-badge status-' + esc(row.status) + '">' + esc(row.status) + '</span>';
            }
          },
          { data: 'division', title: 'Divisi', render: function (d, t) { return t !== 'display' ? d : esc(d); } },
          { data: 'created_at', title: 'Tanggal', render: function (d, t) { return t !== 'display' ? d : fmtDate(d); } },
          {
            data: null, title: 'Aksi', orderable: false, searchable: false,
            render: function (data, type, row) {
              if (type !== 'display') return '';
              var h = '<a class="btn btn-sm btn-primary" href="view_ticket.php?id=' + encodeURIComponent(row.id) + '">Detail</a>';
              if (role === 'admin') h += ' <a class="btn btn-sm btn-warning" href="edit_ticket.php?id=' + encodeURIComponent(row.id) + '">Edit</a>';
              return h;
            }
          }
        ]
      });

      // Form filter lama -> reload via API (tanpa reload halaman)
      var form = document.querySelector('form[data-dt-filter]');
      if (form) {
        // samakan attribute baru agar konsisten
        form.setAttribute('data-dt-filter', '');
        form.addEventListener('submit', function (ev) {
          ev.preventDefault();
          var fd = new FormData(form);
          filters.search = (fd.get('search') || '').toString();
          filters.filter_status = (fd.get('filter_status') || '').toString();
          filters.filter_priority = (fd.get('filter_priority') || '').toString();
          filters.filter_division = (fd.get('filter_division') || '').toString();
          filters.has_attachment = fd.get('has_attachment') ? '1' : '';
          dt.ajax.reload();
        });
      }
      // Kartu status cepat
      document.querySelectorAll('[data-status-link]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
          ev.preventDefault();
          var v = a.getAttribute('data-status-link') || '';
          // toggle: klik kartu aktif = reset
          filters.filter_status = (filters.filter_status === v) ? '' : v;
          var sel = form ? form.querySelector('select[name="filter_status"]') : null;
          if (sel) sel.value = filters.filter_status;
          document.querySelectorAll('[data-status-link]').forEach(function (x) { x.classList.remove('border-danger', 'border-2'); });
          if (filters.filter_status) a.classList.add('border-danger', 'border-2');
          dt.ajax.reload();
        });
      });
    }

    // ---- Tabel Change Request (cr_list.php) — terpisah dari tiket ----
    var $cr = $('#grid-cr');
    if ($cr.length) {
      var cqs = new URLSearchParams(window.location.search);
      var cfilters = {
        search: cqs.get('search') || '',
        filter_status: cqs.get('filter_status') || '',
        filter_priority: cqs.get('filter_priority') || ''
      };
      var crFallback = document.getElementById('tbl-cr-fallback-wrap');
      if (crFallback) crFallback.style.display = 'none';
      $cr.show();
      var crDt = $cr.DataTable({
        serverSide: true,
        processing: true,
        pageLength: 15,
        lengthMenu: [15, 30, 50],
        language: langID,
        responsive: true,
        searching: false,
        ajax: {
          url: 'api/crs.php',
          data: function (d) {
            d.search = cfilters.search;
            d.filter_status = cfilters.filter_status;
            d.filter_priority = cfilters.filter_priority;
          },
          error: function (xhr) {
            if (xhr && xhr.status === 401) window.location.href = 'login.php';
          }
        },
        order: [[6, 'desc']],
        columns: [
          {
            data: 'cr_number', title: 'CR Number',
            render: function (data, type, row) {
              if (type !== 'display') return data;
              var h = '<strong>' + esc(row.cr_number) + '</strong>';
              if (row.has_attachment) h += ' <span class="badge text-bg-info" title="Ada lampiran">\uD83D\uDCCE</span>';
              if (row.is_overdue) h += ' <span class="badge text-bg-danger">overdue</span>';
              return h;
            }
          },
          {
            data: 'aplikasi', title: 'Aplikasi',
            render: function (data, type, row) {
              if (type !== 'display') return data;
              return '<strong>' + esc(row.aplikasi) + '</strong><br><small class="text-secondary">' + esc(row.reporter_name || '-') + '</small>';
            }
          },
          { data: 'modul', title: 'Modul', render: function (d, t) { return t !== 'display' ? d : esc(d); } },
          { data: 'fitur', title: 'Fitur', render: function (d, t, row) { return t !== 'display' ? d : esc(String(row.fitur || '').substring(0, 60)); } },
          {
            data: 'status', title: 'Status',
            render: function (data, type, row) {
              if (type !== 'display') return data;
              return '<span class="status-badge status-' + esc(row.status) + '">' + esc(row.status) + '</span>';
            }
          },
          {
            data: 'priority', title: 'Prioritas',
            render: function (data, type, row) {
              if (type !== 'display') return data;
              return '<span class="priority-badge priority-' + esc(String(row.priority).toLowerCase()) + '">' + esc(row.priority) + '</span>';
            }
          },
          {
            data: 'created_at', title: 'Waktu',
            render: function (d, t, row) {
              if (t !== 'display') return d;
              var w = row.waktu_dibutuhkan ? fmtDate(row.waktu_dibutuhkan) : '-';
              return '<span class="small">' + esc(w) + '</span><br><small class="text-secondary">' + esc(fmtDate(row.created_at)) + '</small>';
            }
          },
          {
            data: null, title: 'Aksi', orderable: false, searchable: false,
            render: function (data, type, row) {
              if (type !== 'display') return '';
              var h = '<a class="btn btn-sm btn-primary" href="view_cr.php?id=' + encodeURIComponent(row.id) + '">Detail</a>';
              if (role === 'admin') h += ' <a class="btn btn-sm btn-warning" href="edit_cr.php?id=' + encodeURIComponent(row.id) + '">Edit</a>';
              return h;
            }
          }
        ]
      });
      var crForm = document.querySelector('form[data-cr-filter]');
      if (crForm) {
        crForm.addEventListener('submit', function (ev) {
          ev.preventDefault();
          var fd = new FormData(crForm);
          cfilters.search = (fd.get('search') || '').toString();
          cfilters.filter_status = (fd.get('filter_status') || '').toString();
          cfilters.filter_priority = (fd.get('filter_priority') || '').toString();
          crDt.ajax.reload();
        });
      }
      document.querySelectorAll('[data-cr-status-link]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
          ev.preventDefault();
          var v = a.getAttribute('data-cr-status-link') || '';
          cfilters.filter_status = (cfilters.filter_status === v) ? '' : v;
          var sel = crForm ? crForm.querySelector('select[name="filter_status"]') : null;
          if (sel) sel.value = cfilters.filter_status;
          crDt.ajax.reload();
        });
      });
    }

    // ---- Tabel users (user_management.php) ----
    var $users = $('#grid-users');
    if ($users.length) {
      $('#tbl-users-fallback').hide();
      $users.show();
      var csrfTok = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
      var myId = (document.querySelector('meta[name="user-id"]') || {}).content || '';
      var csrfH = '<input type="hidden" name="csrf_token" value="' + esc(csrfTok) + '">';
      var roleOpts = ['admin', 'teknisi', 'pelapor'];
      $users.DataTable({
        serverSide: true,
        processing: true,
        pageLength: 15,
        lengthMenu: [15, 30, 50],
        language: langID,
        ajax: {
          url: 'api/users.php',
          error: function (xhr) { if (xhr && xhr.status === 401) window.location.href = 'login.php'; }
        },
        order: [[1, 'asc']],
        columns: [
          { data: 'username', title: 'Username', render: function (d, t, row) { return t !== 'display' ? d : '<strong>' + esc(d) + '</strong>' + (row && row.auth_source === 'simrs' ? ' <span class="badge text-bg-info" title="Akun dari SIMRS">SIMRS</span>' : ''); } },
          { data: 'name', title: 'Nama', render: function (d, t) { return t !== 'display' ? d : esc(d); } },
          { data: 'role', title: 'Role', render: function (d, t) { return t !== 'display' ? d : '<span class="status-badge status-' + esc(d) + '">' + esc(d) + '</span>'; } },
          { data: 'division', title: 'Divisi', render: function (d, t) { return t !== 'display' ? d : esc(d || '-'); } },
          { data: 'is_active', title: 'Aktif', orderable: false, render: function (d, t) { return t !== 'display' ? d : (d ? '<span class="badge text-bg-success">Ya</span>' : '<span class="badge text-bg-secondary">Tidak</span>'); } },
          {
            data: null, title: 'Aksi', orderable: false, searchable: false, render: function (d, t, row) {
              if (t !== 'display' || !row) return '';
              var self = String(row.id) === String(myId);
              var dis = self ? 'disabled title="Akun sendiri"' : '';
              var isSimrs = row.auth_source === 'simrs';
              var h = '';
              h += '<form method="POST" class="d-inline" onsubmit="return confirm(\'Nonaktifkan/mengaktifkan pengguna ini?\')">' + csrfH + '<input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="' + esc(row.id) + '"><button type="submit" class="btn btn-sm btn-warning" ' + dis + '>' + (row.is_active ? 'Nonaktifkan' : 'Aktifkan') + '</button></form> ';
              h += '<form method="POST" class="d-inline" onsubmit="return confirm(\'Ubah role pengguna ini?\')">' + csrfH + '<input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="' + esc(row.id) + '"><select name="role" class="form-select form-select-sm d-inline-block w-auto align-middle" aria-label="Role baru">';
              for (var i = 0; i < roleOpts.length; i++) {
                h += '<option value="' + roleOpts[i] + '"' + (row.role === roleOpts[i] ? ' selected' : '') + '>' + roleOpts[i] + '</option>';
              }
              h += '</select> <button type="submit" class="btn btn-sm btn-outline-primary" ' + dis + '>Set Role</button></form>';
              if (!isSimrs) {
                h += ' <form method="POST" class="d-inline" onsubmit="return confirm(\'Reset password pengguna ini ke acak sementara?\')">' + csrfH + '<input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="' + esc(row.id) + '"><button type="submit" class="btn btn-sm btn-outline-secondary" ' + dis + '>Reset PW</button></form>';
              }
              h += ' <a class="btn btn-sm btn-outline-secondary" href="edit_user.php?id=' + esc(row.id) + '">Edit</a>';
              return h;
            }
          }
        ]
      });
    }

    // ---- Tabel ringkas dashboard (client-side, data kecil) ----
    function simpleTable(sel, url, cols) {
      var $el = $(sel);
      if (!$el.length) return;
      $.getJSON(url, function (j) {
        var rows = (j && j.data) || [];
        $el.DataTable({
          data: rows,
          columns: cols,
          paging: false,
          searching: false,
          info: false,
          ordering: true,
          language: langID
        });
      });
    }
    simpleTable('#grid-div-pivot', 'api/dashboard.php?type=div_pivot', [
      { data: 'division', title: 'Divisi' },
      { data: 'total', title: 'Total' },
      { data: 'open', title: 'Open' },
      { data: 'in_progress', title: 'In Progress' },
      { data: 'resolved', title: 'Resolved' },
      { data: 'closed', title: 'Closed' }
    ]);
    simpleTable('#grid-workload', 'api/dashboard.php?type=workload', [
      { data: 'name', title: 'Teknisi' },
      { data: 'active', title: 'Aktif' },
      { data: 'done', title: 'Selesai bln ini' }
    ]);
  });
})();
