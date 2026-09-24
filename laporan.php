<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$role = $user['role'] ?? '';
$pageTitle = 'Laporan Eksekutif';
require_once 'includes/header.php';

$conn = getDBConnection();

// ---- Kelola hari libur (admin only) ----
$libMsg = '';
$libType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'admin' && isset($_POST['lib_action'])) {
    requireCsrf();
    if ($_POST['lib_action'] === 'add') {
        $tgl = trim($_POST['tanggal'] ?? '');
        $ket = trim($_POST['keterangan'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) && $ket !== '') {
            $r = pg_query_params($conn, "INSERT INTO holidays (tanggal, keterangan) VALUES ($1,$2) ON CONFLICT (tanggal) DO UPDATE SET keterangan = EXCLUDED.keterangan", [$tgl, $ket]);
            if ($r) {
                $libMsg = "Hari libur $tgl ditambahkan.";
                $libType = 'success';
                pg_free_result($r);
            } else {
                $libMsg = 'Gagal: ' . pg_last_error($conn);
                $libType = 'danger';
            }
        } else {
            $libMsg = 'Tanggal & keterangan wajib diisi.';
            $libType = 'danger';
        }
    } elseif ($_POST['lib_action'] === 'del') {
        $id = (int)($_POST['hid'] ?? 0);
        pg_query_params($conn, "DELETE FROM holidays WHERE id = $1", [$id]);
        $libMsg = 'Hari libur dihapus.';
        $libType = 'success';
    } elseif ($_POST['lib_action'] === 'recalc') {
        // Hitung ulang SLA tiket yang masih berjalan
        $r = pg_query($conn, "SELECT id, priority, created_at FROM tickets WHERE status NOT IN ('resolved','closed')");
        $n = 0;
        while ($t = pg_fetch_assoc($r)) {
            $due = slaDueForPriority($t['priority'], $t['created_at']);
            pg_query_params($conn, "UPDATE tickets SET sla_due_at = $1 WHERE id = $2", [$due, $t['id']]);
            $n++;
        }
        pg_free_result($r);
        $libMsg = "SLA $n tiket berjalan dihitung ulang.";
        $libType = 'success';
    } elseif ($_POST['lib_action'] === 'settings') {
        // Ubah target SLA (jam kerja) — berlaku untuk tiket baru + hitung ulang manual
        $defs = ['sla_critical_hours' => [1, 720], 'sla_high_hours' => [1, 720], 'sla_medium_hours' => [1, 720], 'sla_low_hours' => [1, 720], 'sla_response_minutes' => [1, 1440], 'sla_response_target' => [1, 100]];
        $ok = true;
        foreach ($defs as $k => [$min, $max]) {
            $v = (int)($_POST[$k] ?? 0);
            if ($v < $min || $v > $max) {
                $ok = false;
                break;
            }
            pg_query_params($conn, "UPDATE settings SET nilai = $1, updated_at = NOW() WHERE kunci = $2", [(string)$v, $k]);
        }
        if ($ok) {
            $libMsg = 'Target SLA disimpan. Berlaku untuk tiket baru; gunakan "Hitung ulang" untuk tiket berjalan.';
            $libType = 'success';
        } else {
            $libMsg = 'Nilai target tidak valid.';
            $libType = 'danger';
        }
    }
}

// Filter (dipertahankan untuk export + dibaca JS)
$fMonth = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $fMonth)) $fMonth = date('Y-m');
$fDiv = trim($_GET['division'] ?? '');
$fPri = trim($_GET['priority'] ?? '');
$divs = [];
$dr = pg_query($conn, "SELECT DISTINCT division FROM tickets ORDER BY division");
while ($d = pg_fetch_assoc($dr)) $divs[] = $d['division'];
pg_free_result($dr);

$holidays = [];
if ($role === 'admin') {
    $hr = pg_query($conn, "SELECT * FROM holidays ORDER BY tanggal DESC");
    while ($h = pg_fetch_assoc($hr)) $holidays[] = $h;
    pg_free_result($hr);
}
$exportQs = http_build_query(array_filter(['date_from' => $fMonth . '-01', 'date_to' => date('Y-m-t', strtotime($fMonth . '-01')), 'filter_division' => $fDiv, 'filter_priority' => $fPri, 'format' => 'csv']));

// ---- Change Request: ringkasan TERPISAH dari tiket (periode + filter prioritas) ----
$crScope = "cr.created_at::date >= $1 AND cr.created_at::date <= $2";
$crP = [$fMonth . '-01', date('Y-m-t', strtotime($fMonth . '-01'))];
if ($fPri !== '' && in_array($fPri, ['low','medium','high','critical'], true)) {
    $crP[] = $fPri;
    $crScope .= ' AND cr.priority = $' . count($crP);
}
if (($user['role'] ?? '') === 'pelapor') {
    $crP[] = $user['id'];
    $crScope .= ' AND cr.user_id = $' . count($crP);
}
$crKpi = ['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
$crR = pg_query_params($conn, "SELECT status, COUNT(*) AS c FROM change_requests cr WHERE $crScope GROUP BY status", $crP);
if ($crR) {
    while ($row = pg_fetch_assoc($crR)) {
        $crKpi[$row['status']] = (int)$row['c'];
        $crKpi['total'] += (int)$row['c'];
    }
    pg_free_result($crR);
}
$crExportQs = http_build_query(array_filter(['type' => 'cr', 'date_from' => $fMonth . '-01', 'date_to' => date('Y-m-t', strtotime($fMonth . '-01')), 'filter_priority' => $fPri, 'format' => 'csv']));
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Laporan Eksekutif</h2>
        <p class="text-secondary mb-0">Analisa kinerja helpdesk berbasis <strong>jam kerja Senin–Sabtu 08:00–17:00</strong> (Minggu & hari libur dikecualikan). Target respons ≤ <?php echo slaResponseLimit(); ?> menit kerja: <strong><?php echo slaResponseTarget(); ?>%</strong>.</p>
    </div>
    <div class="btn-group flex-shrink-0" role="group" aria-label="Export">
        <a href="export.php?<?php echo htmlspecialchars($exportQs); ?>" class="btn btn-success">⬇ CSV</a>
        <a href="export.php?<?php echo htmlspecialchars(str_replace('format=csv', 'format=xlsx', $exportQs)); ?>" class="btn btn-success">XLSX</a>
        <a href="export.php?<?php echo htmlspecialchars(str_replace('format=csv', 'format=pdf', $exportQs)); ?>" class="btn btn-success">PDF</a>
    </div>
</div>

<div class="card mb-4 border-info"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
        <h3 class="h6 mb-0">🔄 Change Request periode <?php echo htmlspecialchars($fMonth); ?> <small class="text-secondary">(terpisah dari tiket — <?php echo $crKpi['total']; ?> CR)</small></h3>
        <span class="d-flex gap-2 flex-wrap">
            <a href="cr_list.php" class="btn btn-sm btn-primary">Daftar CR</a>
            <a href="export.php?<?php echo htmlspecialchars($crExportQs); ?>" class="btn btn-sm btn-success">CR CSV</a>
            <a href="export.php?<?php echo htmlspecialchars(str_replace('format=csv', 'format=xlsx', $crExportQs)); ?>" class="btn btn-sm btn-success">CR XLSX</a>
            <a href="export.php?<?php echo htmlspecialchars(str_replace('format=csv', 'format=pdf', $crExportQs)); ?>" class="btn btn-sm btn-success">CR PDF</a>
        </span>
    </div>
    <div class="row g-2 text-center">
        <div class="col-4 col-lg-2"><div class="border rounded p-2"><div class="fw-bold"><?php echo $crKpi['total']; ?></div><small class="text-secondary">Total CR</small></div></div>
        <div class="col-4 col-lg-2"><div class="border rounded p-2"><div class="fw-bold text-warning"><?php echo $crKpi['open']; ?></div><small class="text-secondary">Open</small></div></div>
        <div class="col-4 col-lg-2"><div class="border rounded p-2"><div class="fw-bold text-primary"><?php echo $crKpi['in_progress']; ?></div><small class="text-secondary">In Progress</small></div></div>
        <div class="col-4 col-lg-2"><div class="border rounded p-2"><div class="fw-bold text-success"><?php echo $crKpi['resolved']; ?></div><small class="text-secondary">Resolved</small></div></div>
        <div class="col-4 col-lg-2"><div class="border rounded p-2"><div class="fw-bold"><?php echo $crKpi['closed']; ?></div><small class="text-secondary">Closed</small></div></div>
        <div class="col-4 col-lg-2"><div class="border rounded p-2"><span class="status-badge status-open">badge</span> <span class="priority-badge priority-high">konsisten</span><br><small class="text-secondary">SLA/badge = tiket</small></div></div>
    </div>
</div></div>

<div class="card mb-4"><div class="card-body">
    <form method="GET" id="repFilter" class="row g-2 align-items-end">
        <div class="col-6 col-lg-3">
            <label class="form-label" for="fMonth">Periode</label>
            <input type="month" id="fMonth" name="month" class="form-control" value="<?php echo htmlspecialchars($fMonth); ?>">
        </div>
        <div class="col-6 col-lg-3">
            <label class="form-label" for="fDiv">Divisi</label>
            <select id="fDiv" name="division" class="form-select">
                <option value="">Semua Divisi</option>
                <?php foreach ($divs as $dv): ?><option value="<?php echo htmlspecialchars($dv); ?>" <?php echo $fDiv === $dv ? 'selected' : ''; ?>><?php echo htmlspecialchars($dv); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-lg-3">
            <label class="form-label" for="fPri">Prioritas</label>
            <select id="fPri" name="priority" class="form-select">
                <option value="">Semua</option>
                <?php foreach (['low', 'medium', 'high', 'critical'] as $p): ?><option value="<?php echo $p; ?>" <?php echo $fPri === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-lg-3">
            <button type="submit" class="btn btn-primary w-100">Terapkan</button>
        </div>
    </form>
</div></div>

<div class="row g-3 mb-4" id="repKpi">
    <div class="col-6 col-xl-2"><div class="card h-100"><div class="card-body"><div class="fs-3 fw-bold" id="kTotal">…</div><div class="text-secondary small">Total Tiket</div></div></div></div>
    <div class="col-6 col-xl-2"><div class="card h-100"><div class="card-body"><div class="fs-3 fw-bold text-success" id="kDone">…</div><div class="text-secondary small">Selesai (%)</div></div></div></div>
    <div class="col-6 col-xl-2"><div class="card h-100"><div class="card-body"><div class="fs-3 fw-bold text-primary" id="kResp">…</div><div class="text-secondary small">Respons ≤60 mnt <span title="numerator/denominator">ⓘ</span></div><div class="small" id="kRespFrac"></div></div></div></div>
    <div class="col-6 col-xl-2"><div class="card h-100"><div class="card-body"><div class="fs-3 fw-bold text-warning" id="kRes">…</div><div class="text-secondary small">Resolusi tepat SLA</div><div class="small" id="kResFrac"></div></div></div></div>
    <div class="col-6 col-xl-2"><div class="card h-100"><div class="card-body"><div class="fs-3 fw-bold" id="kMttr">…</div><div class="text-secondary small">MTTR (jam kerja)</div></div></div></div>
    <div class="col-6 col-xl-2"><div class="card h-100"><div class="card-body"><div class="fs-3 fw-bold text-danger" id="kBreach">…</div><div class="text-secondary small">Breach terbuka</div></div></div></div>
</div>

<div class="card mb-4 border-primary"><div class="card-body">
    <h3 class="h6">📊 Analisa Otomatis</h3>
    <div id="repNarrative" class="small"><span class="text-secondary">Memuat analisa…</span></div>
</div></div>

<div class="card mb-4"><div class="card-body">
    <h3 class="h6">Tren Harian (Dibuat vs Selesai vs Backlog)</h3>
    <canvas id="chRepTrend" height="110"></canvas>
</div></div>

<div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h3 class="h6">⏱ SLA Respons ≤ 60 mnt kerja (target 100%)</h3>
        <p class="mb-1"><span class="fs-4 fw-bold" id="srPct">…</span> <small class="text-secondary" id="srFrac"></small></p>
        <div class="progress mb-3" style="height:10px;"><div class="progress-bar" id="srBar" style="width:0%"></div></div>
        <h4 class="h6 text-danger">Breach respons (tertunda &gt; 60 mnt)</h4>
        <div id="srBreach"><div class="text-center text-secondary py-2 small">Memuat…</div></div>
        <h4 class="h6 mt-3">Masih dalam tenggang (&lt; 60 mnt)</h4>
        <div id="srPending"><div class="text-center text-secondary py-2 small">Memuat…</div></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h3 class="h6">🎯 SLA Resolusi per Prioritas (jam kerja)</h3>
        <div id="resPri"><div class="text-center text-secondary py-2 small">Memuat…</div></div>
        <h3 class="h6 mt-3">MTTR per Divisi</h3>
        <div id="resDiv"><div class="text-center text-secondary py-2 small">Memuat…</div></div>
    </div></div></div>
</div>

<div class="card mb-4"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h3 class="h6 mb-0">🚨 Breach Resolusi (overdue / selesai telat, jam kerja)</h3>
        <a href="index.php?overdue=1" class="btn btn-sm btn-outline-danger">Lihat di Daftar Tiket</a>
    </div>
    <div id="repBreach"><div class="text-center text-secondary py-2 small">Memuat…</div></div>
</div></div>

<div class="card mb-4"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h3 class="h6 mb-0">Detail Tiket Periode Ini</h3>
        <span class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm btn-primary">Buka Daftar Tiket</a>
            <a href="export.php?<?php echo htmlspecialchars($exportQs); ?>" class="btn btn-sm btn-success">CSV</a>
            <a href="export.php?<?php echo htmlspecialchars(str_replace('format=csv', 'format=xlsx', $exportQs)); ?>" class="btn btn-sm btn-success">XLSX</a>
            <a href="export.php?<?php echo htmlspecialchars(str_replace('format=csv', 'format=pdf', $exportQs)); ?>" class="btn btn-sm btn-success">PDF</a>
        </span>
    </div>
    <p class="small text-secondary mb-0">Gunakan filter daftar tiket (status, assignee, overdue, tanggal) untuk drill-down, lalu export bila perlu.</p>
</div></div>

<?php if ($role === 'admin'): ?>
<div class="card mb-4" id="target-sla"><div class="card-body">
    <h3 class="h6">⚙ Target SLA (jam kerja — berlaku untuk tiket baru)</h3>
    <?php if (!empty($libMsg)): ?><div class="alert alert-<?php echo $libType; ?> py-2"><?php echo htmlspecialchars($libMsg); ?></div><?php endif; ?>
    <form method="POST" class="row g-2 align-items-end">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="lib_action" value="settings">
        <?php foreach ([['sla_critical_hours', 'Critical (jam)'], ['sla_high_hours', 'High (jam)'], ['sla_medium_hours', 'Medium (jam)'], ['sla_low_hours', 'Low (jam)'], ['sla_response_minutes', 'Respons (mnt)'], ['sla_response_target', 'Target respons (%)']] as [$sk, $sl]): ?>
        <div class="col-4 col-lg-2"><label class="form-label small"><?php echo $sl; ?></label><input type="number" name="<?php echo $sk; ?>" class="form-control" required min="1" value="<?php echo (int)appSetting($sk, 0, $conn); ?>"></div>
        <?php endforeach; ?>
        <div class="col-12 col-lg-12 mt-2"><button class="btn btn-primary btn-sm" type="submit">Simpan Target</button></div>
    </form>
</div></div>
<div class="card mb-4" id="libur"><div class="card-body">
    <h3 class="h6">📅 Hari Libur (dikecualikan dari SLA)</h3>
    <form method="POST" class="row g-2 align-items-end mb-3">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="lib_action" value="add">
        <div class="col-5 col-lg-3"><label class="form-label">Tanggal</label><input type="date" name="tanggal" class="form-control" required></div>
        <div class="col-7 col-lg-6"><label class="form-label">Keterangan</label><input type="text" name="keterangan" class="form-control" required maxlength="255" placeholder="cth: Cuti bersama"></div>
        <div class="col-12 col-lg-3"><button class="btn btn-primary w-100" type="submit">Tambah</button></div>
    </form>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-2">
        <thead class="table-dark"><tr><th>Tanggal</th><th>Keterangan</th><th>Aksi</th></tr></thead>
        <tbody>
            <?php foreach ($holidays as $h): ?>
            <tr><td><?php echo date('d M Y', strtotime($h['tanggal'])); ?></td><td><?php echo htmlspecialchars($h['keterangan']); ?></td>
            <td><form method="POST" class="d-inline" onsubmit="return confirm('Hapus libur ini?')"><?php echo csrf_field(); ?><input type="hidden" name="lib_action" value="del"><input type="hidden" name="hid" value="<?php echo $h['id']; ?>"><button class="btn btn-sm btn-outline-danger">Hapus</button></form></td></tr>
            <?php endforeach; ?>
            <?php if (empty($holidays)): ?><tr><td colspan="3" class="text-center text-secondary">Belum ada hari libur.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <form method="POST" class="d-inline" onsubmit="return confirm('Hitung ulang SLA semua tiket yang masih berjalan?')">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="lib_action" value="recalc">
        <button class="btn btn-sm btn-warning" type="submit">🔄 Hitung ulang SLA tiket berjalan</button>
    </form>
</div></div>
<?php endif; ?>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
