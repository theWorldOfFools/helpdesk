<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$role = $user['role'] ?? '';
$pageTitle = $role === 'admin' ? 'Dashboard Monitoring' : ($role === 'teknisi' ? 'Dashboard Kerja' : 'Dashboard Saya');

require_once 'includes/header.php';

$conn = getDBConnection();
$isPelapor = ($role === 'pelapor');
$scopeWhere = $isPelapor ? "user_id = $1" : "1=1";
$scopeParams = $isPelapor ? [$user['id']] : [];

$countStatus = function ($status) use ($conn, $scopeWhere, $scopeParams) {
    $q = "SELECT COUNT(*) FROM tickets WHERE ($scopeWhere)";
    $p = $scopeParams;
    if ($status !== null) {
        $q .= " AND status = $" . (count($p) + 1);
        $p[] = $status;
    }
    return (int)pg_fetch_result(pg_query_params($conn, $q, $p), 0, 0);
};

$totalTickets = $countStatus(null);
$openTickets = $countStatus('open');
$inProgress = $countStatus('in_progress');
$resolved = $countStatus('resolved');
$closed = $countStatus('closed');

$grouped = function ($col) use ($conn, $scopeWhere, $scopeParams) {
    $allowed = ['status' => true, 'priority' => true, 'division' => true];
    if (!isset($allowed[$col])) return [];
    $r = pg_query_params($conn, "SELECT $col AS k, COUNT(*) AS c FROM tickets WHERE ($scopeWhere) GROUP BY $col ORDER BY $col", $scopeParams);
    $out = [];
    while ($row = pg_fetch_assoc($r)) $out[] = $row;
    pg_free_result($r);
    return $out;
};
$statusData = $grouped('status');
$priorityData = $grouped('priority');
$divisionData = $grouped('division');

$recentTickets = [];
$recentSql = "SELECT t.*, u.name AS pelapor_name FROM tickets t LEFT JOIN users u ON t.user_id = u.id WHERE ($scopeWhere) ORDER BY t.created_at DESC LIMIT " . ($isPelapor ? 8 : 5);
$rr = pg_query_params($conn, $recentSql, $scopeParams);
while ($row = pg_fetch_assoc($rr)) $recentTickets[] = $row;
pg_free_result($rr);

$adminExtra = [];
if ($role === 'admin') {
    $adminExtra['total_users'] = (int)pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM users WHERE is_active = TRUE"), 0, 0);
    $adminExtra['today'] = (int)pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM tickets WHERE created_at::date = CURRENT_DATE"), 0, 0);
    $ur = pg_query($conn, "SELECT role, COUNT(*) AS c FROM users GROUP BY role");
    $adminExtra['users_by_role'] = [];
    while ($row = pg_fetch_assoc($ur)) $adminExtra['users_by_role'][$row['role']] = (int)$row['c'];
    pg_free_result($ur);

    $pivot = pg_query($conn, "SELECT division, status, COUNT(*) AS c FROM tickets GROUP BY division, status ORDER BY division");
    $adminExtra['div_pivot'] = [];
    while ($row = pg_fetch_assoc($pivot)) {
        $d = $row['division'];
        if (!isset($adminExtra['div_pivot'][$d])) $adminExtra['div_pivot'][$d] = ['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
        $adminExtra['div_pivot'][$d][$row['status']] = (int)$row['c'];
        $adminExtra['div_pivot'][$d]['total'] += (int)$row['c'];
    }
    pg_free_result($pivot);
}

$teknisiExtra = [];
if ($role === 'teknisi') {
    $q = pg_query($conn, "SELECT t.*, u.name AS pelapor_name FROM tickets t LEFT JOIN users u ON t.user_id = u.id WHERE t.status = 'open' ORDER BY CASE t.priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END, t.created_at DESC LIMIT 6");
    while ($row = pg_fetch_assoc($q)) $teknisiExtra['urgent'][] = $row;
    pg_free_result($q);
    $q2 = pg_query($conn, "SELECT t.*, u.name AS pelapor_name FROM tickets t LEFT JOIN users u ON t.user_id = u.id WHERE t.status = 'in_progress' ORDER BY t.updated_at DESC LIMIT 6");
    while ($row = pg_fetch_assoc($q2)) $teknisiExtra['working'][] = $row;
    pg_free_result($q2);
    // Peringatan dini respons 60 mnt: open tanpa aksi staf, umur kerja 45+ mnt
    $respLimit = slaResponseLimit();
    $warnMin = max(1, $respLimit - 15);
    $qw = pg_query($conn, "SELECT t.id, t.ticket_number, t.title, t.priority, t.created_at, u.name AS pelapor_name,
            working_minutes(next_working_start(t.created_at), LOCALTIMESTAMP) AS age_min
        FROM tickets t LEFT JOIN users u ON u.id = t.user_id
        WHERE t.status = 'open'
          AND NOT EXISTS (SELECT 1 FROM ticket_history h JOIN users uu ON uu.id = h.actor_id WHERE h.ticket_id = t.id AND uu.role IN ('admin','teknisi'))
          AND working_minutes(next_working_start(t.created_at), LOCALTIMESTAMP) >= $warnMin
        ORDER BY age_min DESC LIMIT 5");
    while ($row = pg_fetch_assoc($qw)) $teknisiExtra['warn_resp'][] = $row;
    pg_free_result($qw);
}

$pelaporExtra = [];
if ($role === 'pelapor') {
    $done = $resolved + $closed;
    $pelaporExtra['rate'] = $totalTickets > 0 ? round($done / $totalTickets * 100) : 0;
    $pelaporExtra['waiting'] = $openTickets;
}

// ---- Change Request: hitungan TERPISAH dari tickets (tabel change_requests) ----
// Scope sama: pelapor hanya milik sendiri; admin/teknisi semua.
$crScopeWhere = $isPelapor ? "user_id = $1" : "1=1";
$crScopeParams = $isPelapor ? [$user['id']] : [];
$countCr = function ($status) use ($conn, $crScopeWhere, $crScopeParams) {
    $q = "SELECT COUNT(*) FROM change_requests WHERE ($crScopeWhere)";
    $pp = $crScopeParams;
    if ($status !== null) {
        $q .= " AND status = $" . (count($pp) + 1);
        $pp[] = $status;
    }
    $r = pg_query_params($conn, $q, $pp);
    return $r ? (int)pg_fetch_result($r, 0, 0) : 0;
};
$totalCr = $countCr(null);
$openCr = $countCr('open');
$progCr = $countCr('in_progress');
$resCr = $countCr('resolved');
$closedCr = $countCr('closed');
$recentCr = [];
$rr2 = pg_query_params($conn, "SELECT cr.*, u.name AS reporter_name FROM change_requests cr LEFT JOIN users u ON cr.user_id = u.id WHERE ($crScopeWhere) ORDER BY cr.created_at DESC LIMIT 5", $crScopeParams);
if ($rr2) {
    while ($row = pg_fetch_assoc($rr2)) $recentCr[] = $row;
    pg_free_result($rr2);
}
?>

<?php if ($role === 'admin'): ?>
    <div class="welcome-banner welcome-admin text-white p-4 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 position-relative z-1">
            <div>
                <h2 class="h4 mb-1 text-white">Halo, <?php echo htmlspecialchars($user['name']); ?></h2>
                <p class="mb-0 text-white-50">Semua aktivitas helpdesk hari ini: <strong class="text-white"><?php echo $adminExtra['today']; ?> tiket baru</strong> · <?php echo $openTickets; ?> menunggu · <?php echo $adminExtra['total_users']; ?> pengguna aktif.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="index.php" class="btn btn-light btn-sm">Lihat Semua Tiket</a>
                <a href="user_management.php" class="btn btn-outline-light btn-sm">Kelola Pengguna</a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl"><div class="card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-2"><span class="stat-icon">🎫</span></div><div class="fs-2 fw-bold"><?php echo $totalTickets; ?></div><div class="text-secondary small">Total Tiket</div><div class="text-secondary small"><?php echo $adminExtra['today']; ?> dibuat hari ini</div></div></div></div>
        <div class="col-6 col-xl"><div class="card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-2"><span class="stat-icon">🟡</span></div><div class="fs-2 fw-bold text-warning"><?php echo $openTickets; ?></div><div class="text-secondary small">Open</div><div class="text-secondary small">Menunggu penanganan</div></div></div></div>
        <div class="col-6 col-xl"><div class="card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-2"><span class="stat-icon">🔵</span></div><div class="fs-2 fw-bold text-primary"><?php echo $inProgress; ?></div><div class="text-secondary small">In Progress</div><div class="text-secondary small">Sedang dikerjakan</div></div></div></div>
        <div class="col-6 col-xl"><div class="card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-2"><span class="stat-icon">🟢</span></div><div class="fs-2 fw-bold text-success"><?php echo $resolved; ?></div><div class="text-secondary small">Resolved</div><div class="text-secondary small"><?php echo $closed; ?> closed</div></div></div></div>
        <div class="col-6 col-xl"><div class="card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-2"><span class="stat-icon">👥</span></div><div class="fs-2 fw-bold"><?php echo $adminExtra['total_users']; ?></div><div class="text-secondary small">Pengguna Aktif</div><div class="text-secondary small"><?php foreach ($adminExtra['users_by_role'] as $k => $v) echo htmlspecialchars($k) . ": $v "; ?></div></div></div></div>
    </div>

    <div class="card mb-4 border-info"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
            <h3 class="h6 mb-0">🔄 Change Request <small class="text-secondary">(terpisah dari tiket)</small></h3>
            <span class="d-flex gap-2">
                <a href="cr_list.php" class="btn btn-sm btn-primary">Daftar CR</a>
                <a href="create_cr.php" class="btn btn-sm btn-outline-primary">+ Buat CR</a>
                <a href="export.php?type=cr&format=csv" class="btn btn-sm btn-success">Export CR</a>
            </span>
        </div>
        <div class="row g-3">
            <div class="col-6 col-xl-3"><div class="border rounded p-3 h-100"><div class="fs-4 fw-bold"><?php echo $totalCr; ?></div><div class="text-secondary small">Total CR</div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded p-3 h-100"><div class="fs-4 fw-bold text-warning"><?php echo $openCr; ?></div><div class="text-secondary small">Open</div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded p-3 h-100"><div class="fs-4 fw-bold text-primary"><?php echo $progCr; ?></div><div class="text-secondary small">In Progress</div></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded p-3 h-100"><div class="fs-4 fw-bold text-success"><?php echo $resCr + $closedCr; ?></div><div class="text-secondary small">Resolved + Closed (<?php echo $resCr; ?> + <?php echo $closedCr; ?>)</div></div></div>
        </div>
        <?php if (!empty($recentCr)): ?>
        <div class="table-responsive mt-3"><table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-dark"><tr><th>CR</th><th>Aplikasi / Modul</th><th>Status</th><th>Prioritas</th><th>Dibuat</th></tr></thead>
            <tbody>
                <?php foreach ($recentCr as $rc): ?>
                <tr>
                    <td><a href="view_cr.php?id=<?php echo $rc['id']; ?>"><strong><?php echo htmlspecialchars($rc['cr_number']); ?></strong></a></td>
                    <td><?php echo htmlspecialchars($rc['aplikasi']); ?> / <?php echo htmlspecialchars($rc['modul']); ?><br><small class="text-secondary"><?php echo htmlspecialchars($rc['reporter_name'] ?? '-'); ?></small></td>
                    <td><span class="status-badge status-<?php echo $rc['status']; ?>"><?php echo htmlspecialchars($rc['status']); ?></span></td>
                    <td><span class="priority-badge priority-<?php echo strtolower($rc['priority']); ?>"><?php echo htmlspecialchars($rc['priority']); ?></span></td>
                    <td class="text-nowrap"><?php echo date('d M Y', strtotime($rc['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><p class="text-secondary small mt-3 mb-0">Belum ada Change Request.</p><?php endif; ?>
    </div></div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-body">
                <h3 class="h6">Status Tiket</h3>
                <canvas id="chStatus" height="220"></canvas>
                <ul class="list-group list-group-flush mt-2">
                    <?php foreach ($statusData as $item): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="status-badge status-<?php echo htmlspecialchars($item['k']); ?>"><?php echo htmlspecialchars($item['k']); ?></span><strong><?php echo $item['c']; ?></strong></li>
                    <?php endforeach; ?>
                    <?php if (empty($statusData)): ?><li class="list-group-item text-center text-secondary">Belum ada data.</li><?php endif; ?>
                </ul>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-body">
                <h3 class="h6">Prioritas Tiket</h3>
                <canvas id="chPriority" height="220"></canvas>
                <ul class="list-group list-group-flush mt-2">
                    <?php foreach ($priorityData as $item): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="priority-badge priority-<?php echo strtolower(htmlspecialchars($item['k'])); ?>"><?php echo htmlspecialchars($item['k']); ?></span><strong><?php echo $item['c']; ?></strong></li>
                    <?php endforeach; ?>
                    <?php if (empty($priorityData)): ?><li class="list-group-item text-center text-secondary">Belum ada data.</li><?php endif; ?>
                </ul>
            </div></div>
        </div>
    </div>

    <div class="card mb-4"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
            <h3 class="h6 mb-0">Tren 30 Hari (Dibuat vs Resolved)</h3>
            <label class="small text-secondary mb-0">Periode leaderboard: <input type="month" id="dashMonth" class="form-control form-control-sm d-inline-block" style="width:auto;" value="<?php echo date('Y-m'); ?>"></label>
        </div>
        <canvas id="chTrend" height="110"></canvas>
    </div></div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6"><div class="card h-100"><div class="card-body">
            <h3 class="h6">🏆 Leaderboard Teknisi — <span class="text-secondary">bulanan, tercepat + terbanyak</span></h3>
            <div id="leaderTeknisi"><div class="text-center text-secondary py-3 small">Memuat…</div></div>
        </div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-body">
            <h3 class="h6">📣 Leaderboard Pelapor — <span class="text-secondary">paling banyak melapor bulan ini</span></h3>
            <div id="leaderPelapor"><div class="text-center text-secondary py-3 small">Memuat…</div></div>
        </div></div></div>
    </div>

    <div class="card mb-4"><div class="card-body">
        <h3 class="h6">Workload Teknisi Aktif (DataTables)</h3>
        <div class="table-responsive"><table id="grid-workload" class="table table-hover align-middle mb-0" style="width:100%;">
            <thead class="table-dark"><tr><th>Teknisi</th><th>Aktif</th><th>Selesai bln ini</th></tr></thead>
            <tbody></tbody>
        </table></div>
    </div></div>

    <div class="card mb-4" id="divisi">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h6 mb-0">Ringkasan Semua Divisi</h3><a href="index.php" class="btn btn-sm btn-primary">Kelola Tiket</a></div>
            <div class="table-responsive mb-3"><table id="grid-div-pivot" class="table table-hover align-middle mb-0" style="width:100%;">
                <thead class="table-dark"><tr><th>Divisi</th><th>Total</th><th>Open</th><th>In Progress</th><th>Resolved</th><th>Closed</th></tr></thead>
                <tbody></tbody>
            </table></div>
            <?php if (!empty($adminExtra['div_pivot'])): ?>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-dark"><tr><th>Divisi</th><th>Total</th><th>Open</th><th>In Progress</th><th>Resolved</th><th>Closed</th></tr></thead>
                <tbody>
                    <?php foreach ($adminExtra['div_pivot'] as $div => $s): ?>
                    <tr><td><strong><?php echo htmlspecialchars($div); ?></strong></td><td><?php echo $s['total']; ?></td><td><?php echo $s['open']; ?></td><td><?php echo $s['in_progress']; ?></td><td><?php echo $s['resolved']; ?></td><td><?php echo $s['closed']; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?><div class="text-center text-secondary py-4">Belum ada tiket per divisi.</div><?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h6 mb-0">Tiket Terakhir</h3><a href="index.php" class="btn btn-sm btn-primary">Lihat Semua</a></div>
            <?php if (!empty($recentTickets)): ?>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-dark"><tr><th>Ticket</th><th>Judul</th><th>Pelapor</th><th>Status</th><th>Tanggal</th></tr></thead>
                <tbody>
                    <?php foreach ($recentTickets as $rt): ?>
                    <tr><td><strong><?php echo htmlspecialchars($rt['ticket_number']); ?></strong></td><td><?php echo htmlspecialchars($rt['title']); ?></td><td><?php echo htmlspecialchars($rt['pelapor_name'] ?? '-'); ?></td><td><span class="status-badge status-<?php echo $rt['status']; ?>"><?php echo $rt['status']; ?></span></td><td><?php echo date('d M Y', strtotime($rt['created_at'])); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?><div class="text-center text-secondary py-4">Belum ada tiket.</div><?php endif; ?>
        </div>
    </div>

<?php elseif ($role === 'teknisi'): ?>
    <div class="welcome-banner welcome-teknisi text-white p-4 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 position-relative z-1">
            <div>
                <h2 class="h4 mb-1 text-white">Siap kerja, <?php echo htmlspecialchars($user['name']); ?>?</h2>
                <p class="mb-0 text-white-50"><strong class="text-white"><?php echo $openTickets; ?> tiket menunggu</strong> untuk ditangani · <?php echo $inProgress; ?> sedang berjalan · <?php echo count($teknisiExtra['urgent'] ?? []); ?> prioritas tinggi di antrean.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="index.php?filter_status=open" class="btn btn-light btn-sm">Ambil Antrean</a>
                <a href="index.php" class="btn btn-outline-light btn-sm">Semua Tiket</a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="mb-2"><span class="stat-icon">⏳</span></div><div class="fs-2 fw-bold text-warning"><?php echo $openTickets; ?></div><div class="text-secondary small">Perlu Ditangani</div><div class="text-secondary small">Status open</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="mb-2"><span class="stat-icon">🛠️</span></div><div class="fs-2 fw-bold text-primary"><?php echo $inProgress; ?></div><div class="text-secondary small">Sedang Dikerjakan</div><div class="text-secondary small">Status in-progress</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="mb-2"><span class="stat-icon">✅</span></div><div class="fs-2 fw-bold text-success"><?php echo $resolved; ?></div><div class="text-secondary small">Resolved</div><div class="text-secondary small"><?php echo $closed; ?> closed</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="mb-2"><span class="stat-icon">📊</span></div><div class="fs-2 fw-bold"><?php echo $totalTickets; ?></div><div class="text-secondary small">Total Tiket Sistem</div><div class="progress mt-2" style="height:8px;"><div class="progress-bar" style="width:<?php echo $totalTickets ? round(($resolved + $closed) / $totalTickets * 100) : 0; ?>%"></div></div></div></div></div>
    </div>

    <div class="card mb-4 border-info"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
            <h3 class="h6 mb-0">🔄 Change Request <small class="text-secondary">(terpisah dari tiket — <?php echo $totalCr; ?> total · <?php echo $openCr; ?> open · <?php echo $progCr; ?> progres)</small></h3>
            <span class="d-flex gap-2"><a href="cr_list.php" class="btn btn-sm btn-primary">Daftar CR</a><a href="create_cr.php" class="btn btn-sm btn-outline-primary">+ Buat CR</a></span>
        </div>
        <?php if (!empty($recentCr)): ?>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-dark"><tr><th>CR</th><th>Aplikasi</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($recentCr, 0, 5) as $rc): ?>
                <tr><td><strong><?php echo htmlspecialchars($rc['cr_number']); ?></strong></td><td><?php echo htmlspecialchars($rc['aplikasi']); ?> / <?php echo htmlspecialchars($rc['modul']); ?></td><td><span class="status-badge status-<?php echo $rc['status']; ?>"><?php echo htmlspecialchars($rc['status']); ?></span></td><td><a class="btn btn-sm btn-primary" href="view_cr.php?id=<?php echo $rc['id']; ?>">Tangani</a></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><p class="text-secondary small mb-0">Belum ada Change Request.</p><?php endif; ?>
    </div></div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6"><div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2"><h3 class="h6 mb-0">Performa Saya 30 Hari</h3><input type="month" id="dashMonth" class="form-control form-control-sm" style="width:auto;" value="<?php echo date('Y-m'); ?>"></div>
            <canvas id="chMyTrend" height="220"></canvas>
        </div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-body">
            <h3 class="h6">🏆 Leaderboard Teknisi Bulan Ini</h3>
            <div id="leaderTeknisiMini"><div class="text-center text-secondary py-3 small">Memuat…</div></div>
        </div></div></div>
    </div>

    <div class="card mb-4 border-warning"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <h3 class="h6 mb-0">⏱ Perlu Respons Cepat <small class="text-secondary">(target ≤ 60 mnt kerja)</small></h3>
            <a href="laporan.php" class="btn btn-sm btn-outline-secondary">Lihat Laporan SLA</a>
        </div>
        <?php if (!empty($teknisiExtra['warn_resp'])): ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($teknisiExtra['warn_resp'] as $w): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center gap-2 px-0">
                <span><strong><?php echo htmlspecialchars($w['ticket_number']); ?></strong> — <?php echo htmlspecialchars($w['title']); ?><br>
                <small class="text-secondary">oleh <?php echo htmlspecialchars($w['pelapor_name'] ?? '-'); ?> · umur <?php echo (int)$w['age_min']; ?> mnt kerja</small></span>
                <span class="d-flex gap-2 align-items-center">
                    <?php if ((int)$w['age_min'] > $respLimit): ?><span class="badge text-bg-danger">breach</span><?php else: ?><span class="badge text-bg-warning">≤<?php echo $respLimit - (int)$w['age_min']; ?> mnt lagi</span><?php endif; ?>
                    <a class="btn btn-sm btn-primary" href="view_ticket.php?id=<?php echo $w['id']; ?>">Respons</a>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?><div class="text-center text-secondary py-2 small">🎉 Tidak ada tiket menunggu respons kritis.</div><?php endif; ?>
    </div></div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h6 mb-0">Prioritas Utama — Tangani Dulu</h3><a href="index.php?filter_status=open" class="btn btn-sm btn-primary">Lihat Antrean</a></div>
            <?php if (!empty($teknisiExtra['urgent'])): ?>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-dark"><tr><th>Ticket</th><th>Judul</th><th>Prioritas</th><th>Divisi</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach ($teknisiExtra['urgent'] as $t): ?>
                    <tr><td><strong><?php echo htmlspecialchars($t['ticket_number']); ?></strong></td><td><?php echo htmlspecialchars($t['title']); ?><br><small class="text-secondary">oleh <?php echo htmlspecialchars($t['pelapor_name'] ?? '-'); ?> · <?php echo date('d M Y', strtotime($t['created_at'])); ?></small></td><td><span class="priority-badge priority-<?php echo strtolower($t['priority']); ?>"><?php echo htmlspecialchars($t['priority']); ?></span></td><td><?php echo htmlspecialchars($t['division']); ?></td><td><a class="btn btn-sm btn-primary" href="view_ticket.php?id=<?php echo $t['id']; ?>">Tangani</a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php else: ?><div class="text-center text-secondary py-4"><div class="fs-2">🎉</div>Antrean bersih. Tidak ada tiket open saat ini.</div><?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-body">
                <h3 class="h6">Sedang Berjalan</h3>
                <ul class="list-group list-group-flush">
                    <?php foreach (($teknisiExtra['working'] ?? []) as $t): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2 px-0"><span><strong><?php echo htmlspecialchars($t['ticket_number']); ?></strong><br><small class="text-secondary"><?php echo htmlspecialchars($t['title']); ?></small></span><a class="btn btn-sm btn-primary" href="view_ticket.php?id=<?php echo $t['id']; ?>">Tindak Lanjut</a></li>
                    <?php endforeach; ?>
                    <?php if (empty($teknisiExtra['working'] ?? [])): ?><li class="list-group-item text-center text-secondary">Tidak ada tiket in-progress.</li><?php endif; ?>
                </ul>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-body">
                <h3 class="h6">Aktivitas Terakhir</h3>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentTickets as $rt): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2 px-0"><span><strong><?php echo htmlspecialchars($rt['ticket_number']); ?></strong><br><small class="text-secondary"><?php echo htmlspecialchars($rt['title']); ?></small></span><span class="status-badge status-<?php echo $rt['status']; ?>"><?php echo $rt['status']; ?></span></li>
                    <?php endforeach; ?>
                    <?php if (empty($recentTickets)): ?><li class="list-group-item text-center text-secondary">Belum ada aktivitas.</li><?php endif; ?>
                </ul>
            </div></div>
        </div>
    </div>

<?php else: /* pelapor */ ?>
    <div class="welcome-banner welcome-pelapor text-white p-4 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 position-relative z-1">
            <div>
                <h2 class="h4 mb-1 text-white">Halo, <?php echo htmlspecialchars($user['name']); ?></h2>
                <p class="mb-0 text-white-50">Ada kendala IT? Buat tiket baru dan pantau progresnya di sini. <strong class="text-white"><?php echo $pelaporExtra['rate']; ?>% tiket kamu sudah selesai.</strong></p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="create_ticket.php" class="btn btn-light btn-sm">+ Buat Tiket Baru</a>
                <a href="index.php" class="btn btn-outline-light btn-sm">Tiket Saya</a>
                <a href="cr_list.php" class="btn btn-outline-light btn-sm">CR Saya</a>
                <a href="create_cr.php" class="btn btn-light btn-sm">+ Buat CR</a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="mb-2"><span class="stat-icon">🎫</span></div><div class="fs-2 fw-bold"><?php echo $totalTickets; ?></div><div class="text-secondary small">Tiket Saya</div><div class="text-secondary small">Total laporan kamu</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="mb-2"><span class="stat-icon">🟡</span></div><div class="fs-2 fw-bold text-warning"><?php echo $openTickets; ?></div><div class="text-secondary small">Menunggu</div><div class="text-secondary small">Belum ditangani</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="mb-2"><span class="stat-icon">🔵</span></div><div class="fs-2 fw-bold text-primary"><?php echo $inProgress; ?></div><div class="text-secondary small">Diproses</div><div class="text-secondary small">Teknisi sedang bekerja</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="mb-2"><span class="stat-icon">✅</span></div><div class="fs-2 fw-bold text-success"><?php echo $resolved + $closed; ?></div><div class="text-secondary small">Selesai</div><div class="progress mt-2" style="height:8px;"><div class="progress-bar bg-success" style="width:<?php echo $pelaporExtra['rate']; ?>%"></div></div></div></div></div>
    </div>

    <div class="card mb-4 border-info"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
            <h3 class="h6 mb-0">🔄 CR Saya <small class="text-secondary">(terpisah dari tiket — <?php echo $totalCr; ?> total)</small></h3>
            <span class="d-flex gap-2"><a href="cr_list.php" class="btn btn-sm btn-primary">Lihat CR Saya</a><a href="create_cr.php" class="btn btn-sm btn-outline-primary">+ Buat CR</a></span>
        </div>
        <div class="row g-2 text-center">
            <div class="col-3"><div class="border rounded p-2"><div class="fw-bold"><?php echo $totalCr; ?></div><small class="text-secondary">Total</small></div></div>
            <div class="col-3"><div class="border rounded p-2"><div class="fw-bold text-warning"><?php echo $openCr; ?></div><small class="text-secondary">Open</small></div></div>
            <div class="col-3"><div class="border rounded p-2"><div class="fw-bold text-primary"><?php echo $progCr; ?></div><small class="text-secondary">Progres</small></div></div>
            <div class="col-3"><div class="border rounded p-2"><div class="fw-bold text-success"><?php echo $resCr + $closedCr; ?></div><small class="text-secondary">Selesai</small></div></div>
        </div>
    </div></div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6"><div class="card h-100"><div class="card-body">
            <h3 class="h6">Distribusi Status Saya</h3>
            <canvas id="chMyStatus" height="220"></canvas>
        </div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2"><h3 class="h6 mb-0">🏆 Top Pelapor Bulan Ini</h3><input type="month" id="dashMonth" class="form-control form-control-sm" style="width:auto;" value="<?php echo date('Y-m'); ?>"></div>
            <div id="leaderPelaporMini"><div class="text-center text-secondary py-3 small">Memuat…</div></div>
        </div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h6 mb-0">Riwayat Tiket Saya</h3><a href="index.php" class="btn btn-sm btn-primary">Lihat Semua</a></div>
                <?php if (!empty($recentTickets)): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentTickets as $rt): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2 px-0">
                            <span><strong><?php echo htmlspecialchars($rt['ticket_number']); ?></strong> — <?php echo htmlspecialchars($rt['title']); ?><br><small class="text-secondary"><?php echo date('d M Y H:i', strtotime($rt['created_at'])); ?> · <?php echo htmlspecialchars($rt['division']); ?></small></span>
                            <span class="status-badge status-<?php echo $rt['status']; ?>"><?php echo $rt['status']; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <div class="text-center text-secondary py-4"><div class="fs-2">📝</div>Belum ada tiket.<br><a href="create_ticket.php" class="btn btn-primary btn-sm mt-3">Buat tiket pertama kamu</a></div>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3"><div class="card-body">
                <h3 class="h6">Status Laporan</h3>
                <ul class="list-group list-group-flush">
                    <?php foreach ($statusData as $item): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0"><span class="status-badge status-<?php echo htmlspecialchars($item['k']); ?>"><?php echo htmlspecialchars($item['k']); ?></span><strong><?php echo $item['c']; ?></strong></li>
                    <?php endforeach; ?>
                    <?php if (empty($statusData)): ?><li class="list-group-item text-center text-secondary">Belum ada data.</li><?php endif; ?>
                </ul>
            </div></div>
            <div class="card"><div class="card-body">
                <h3 class="h6">Cara Lapor Kendala</h3>
                <ol class="list-group list-group-numbered list-group-flush">
                    <li class="list-group-item px-0"><strong>Buat tiket</strong> dengan judul dan deskripsi yang jelas.</li>
                    <li class="list-group-item px-0"><strong>Pantau status</strong> dari open sampai resolved.</li>
                    <li class="list-group-item px-0"><strong>Konfirmasi selesai</strong> saat kendala sudah beres.</li>
                </ol>
            </div></div>
        </div>
    </div>
<?php endif; ?>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
