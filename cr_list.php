<?php
// Daftar Change Request — terpisah dari tiket (index.php tidak dicampur).
// Scope role: pelapor hanya milik sendiri; admin/teknisi semua (mirror canViewCr).
require_once 'config.php';
require_once 'includes/cr_auth.php';
requireLogin();

$user = getCurrentUser();
$role = $user['role'] ?? '';
$isPelapor = ($role === 'pelapor');
$pageTitle = $isPelapor ? 'CR Saya' : 'Daftar Change Request';

require_once 'includes/header.php';

$conn = getDBConnection();

// ---- Filter (parameterized, mirror index.php) ----
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['filter_status'] ?? '');
$filterPriority = trim($_GET['filter_priority'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$validStatus = ['open', 'in_progress', 'resolved', 'closed'];
$validPriority = ['low', 'medium', 'high', 'critical'];
if (!in_array($filterStatus, $validStatus, true)) $filterStatus = '';
if (!in_array($filterPriority, $validPriority, true)) $filterPriority = '';

$conds = ['1=1'];
$params = [];
if ($isPelapor) {
    $params[] = $user['id'];
    $conds[] = 'cr.user_id = $' . count($params);
}
if ($search !== '') {
    $params[] = '%' . $search . '%';
    $conds[] = '(cr.cr_number ILIKE $' . count($params) . ' OR cr.aplikasi ILIKE $' . count($params) . ' OR cr.modul ILIKE $' . count($params) . ' OR cr.fitur ILIKE $' . count($params) . ' OR cr.keterangan ILIKE $' . count($params) . ')';
}
if ($filterStatus !== '') {
    $params[] = $filterStatus;
    $conds[] = 'cr.status = $' . count($params);
}
if ($filterPriority !== '') {
    $params[] = $filterPriority;
    $conds[] = 'cr.priority = $' . count($params);
}
$where = implode(' AND ', $conds);

$cntR = pg_query_params($conn, "SELECT COUNT(*) FROM change_requests cr WHERE $where", $params);
$totalRows = $cntR ? (int)pg_fetch_result($cntR, 0, 0) : 0;
if ($cntR) pg_free_result($cntR);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$dataParams = array_merge($params, [$perPage, $offset]);
$limN = count($params) + 1;
$offN = count($params) + 2;
$result = pg_query_params(
    $conn,
    "SELECT cr.*, u.name AS reporter_name FROM change_requests cr LEFT JOIN users u ON cr.user_id = u.id WHERE $where ORDER BY cr.created_at DESC LIMIT \$$limN OFFSET \$$offN",
    $dataParams
);
$crs = [];
if ($result) {
    while ($row = pg_fetch_assoc($result)) $crs[] = $row;
    pg_free_result($result);
}

// Kartu status (scope role, tanpa filter lain)
$statusCounts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
$scopeP = $isPelapor ? [$user['id']] : [];
$scopeW = $isPelapor ? 'user_id = $1' : '1=1';
$sc = pg_query_params($conn, "SELECT status, COUNT(*) AS c FROM change_requests WHERE $scopeW GROUP BY status", $scopeP);
if ($sc) {
    while ($row = pg_fetch_assoc($sc)) $statusCounts[$row['status']] = (int)$row['c'];
    pg_free_result($sc);
}

function crKeepQS($over = []) {
    $q = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = $v;
    }
    unset($q['page']);
    return http_build_query($q);
}
$hasFilter = $search !== '' || $filterStatus !== '' || $filterPriority !== '';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1"><?php echo htmlspecialchars($pageTitle); ?></h2>
        <p class="text-secondary mb-0"><?php echo $totalRows; ?> CR ditemukan<?php echo $isPelapor ? ' · milik kamu' : ''; ?>. Klik kartu status untuk filter cepat.</p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <a href="index.php" class="btn btn-outline-secondary">← Tiket</a>
        <a href="create_cr.php" class="btn btn-primary">+ Buat CR</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['k' => 'open', 'label' => 'Open', 'icon' => 'bi-circle-fill text-warning', 'border' => 'border-warning'],
        ['k' => 'in_progress', 'label' => 'In Progress', 'icon' => 'bi-arrow-repeat text-primary', 'border' => 'border-primary'],
        ['k' => 'resolved', 'label' => 'Resolved', 'icon' => 'bi-check-circle-fill text-success', 'border' => 'border-success'],
        ['k' => 'closed', 'label' => 'Closed', 'icon' => 'bi-dash-circle text-secondary', 'border' => 'border-secondary'],
    ];
    foreach ($cards as $c):
        $isActive = $filterStatus === $c['k'];
        $qs = crKeepQS(['filter_status' => $isActive ? '' : $c['k']]);
    ?>
    <div class="col-6 col-xl-3">
        <a href="cr_list.php<?php echo $qs ? '?' . htmlspecialchars($qs) : ''; ?>" data-cr-status-link="<?php echo $c['k']; ?>" class="card h-100 text-decoration-none link-dark <?php echo $isActive ? 'border-danger border-2' : $c['border']; ?>" style="<?php echo $isActive ? '' : 'border-top-width:4px !important;'; ?>">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="fs-3 fw-bold"><?php echo $statusCounts[$c['k']] ?? 0; ?></div>
                    <div class="text-secondary small"><i class="bi <?php echo $c['icon']; ?>"></i> <?php echo $c['label']; ?></div>
                </div>
                <?php if ($isActive): ?><span class="badge text-bg-danger">✓ aktif</span><?php endif; ?>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" data-cr-filter class="row g-2 align-items-center">
            <div class="col-12 col-lg-5">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search" placeholder="Cari nomor CR, aplikasi, modul, fitur…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <select name="filter_status" class="form-select" aria-label="Filter status">
                    <option value="">Semua Status</option>
                    <?php foreach ($validStatus as $s): ?><option value="<?php echo $s; ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>><?php echo $s; ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <select name="filter_priority" class="form-select" aria-label="Filter prioritas">
                    <option value="">Semua Prioritas</option>
                    <?php foreach ($validPriority as $p): ?><option value="<?php echo $p; ?>" <?php echo $filterPriority === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-auto d-flex gap-2 align-items-center">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($hasFilter): ?><a href="cr_list.php" class="btn btn-outline-secondary btn-sm">Reset</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
    <div class="table-responsive">
    <table id="grid-cr" class="table table-hover align-middle mb-0" style="display:none;width:100%;">
        <thead class="table-dark">
            <tr>
                <th>CR Number</th>
                <th>Aplikasi</th>
                <th>Modul</th>
                <th>Fitur</th>
                <th>Status</th>
                <th>Prioritas</th>
                <th>Waktu</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    </div>
    <div id="tbl-cr-fallback-wrap">
    <?php if (!empty($crs)): ?>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>CR Number</th>
                    <th>Aplikasi</th>
                    <th>Modul</th>
                    <th>Fitur</th>
                    <th>Status</th>
                    <th>Prioritas</th>
                    <th>Waktu</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody class="table-group-divider">
                <?php foreach ($crs as $row): ?>
                    <?php $hasFile = !empty($row['attachment_path']); ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['cr_number']); ?></strong><?php if ($hasFile): ?> <span class="badge text-bg-info" title="Ada lampiran">📎</span><?php endif; ?></td>
                        <td><?php echo htmlspecialchars($row['aplikasi']); ?><br><small class="text-secondary"><?php echo htmlspecialchars($row['reporter_name'] ?? '-'); ?></small></td>
                        <td><?php echo htmlspecialchars($row['modul']); ?></td>
                        <td><?php echo htmlspecialchars(mb_strimwidth($row['fitur'], 0, 60, '…')); ?></td>
                        <td><span class="status-badge status-<?php echo $row['status']; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                        <td><span class="priority-badge priority-<?php echo strtolower($row['priority']); ?>"><?php echo htmlspecialchars($row['priority']); ?></span></td>
                        <td class="text-nowrap small"><?php echo !empty($row['waktu_dibutuhkan']) ? date('d M Y H:i', strtotime($row['waktu_dibutuhkan'])) : '<span class="text-secondary">-</span>'; ?><br><small class="text-secondary">buat: <?php echo date('d M Y', strtotime($row['created_at'])); ?></small></td>
                        <td class="text-nowrap">
                            <a href="view_cr.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">Detail</a>
                            <?php if ($role === 'admin'): ?>
                                <a href="edit_cr.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

        <?php if ($totalPages > 1): ?>
        <nav class="d-flex justify-content-center align-items-center gap-3 p-3" aria-label="Pagination">
            <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="cr_list.php?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>">← Prev</a><?php endif; ?>
            <span class="text-secondary small">Halaman <?php echo $page; ?> / <?php echo $totalPages; ?> · <?php echo $totalRows; ?> data</span>
            <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline-secondary" href="cr_list.php?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>">Next →</a><?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="text-center text-secondary p-5">
            <div class="fs-1">🔍</div>
            <strong>Tidak ada Change Request ditemukan.</strong><br>
            <span>Coba ubah kata kunci / filter, atau buat CR baru.</span><br><br>
            <?php if ($hasFilter): ?><a href="cr_list.php" class="btn btn-outline-secondary btn-sm">Reset Filter</a> <?php endif; ?>
            <a href="create_cr.php" class="btn btn-primary btn-sm">+ Buat CR</a>
        </div>
    <?php endif; ?>
    </div>
    <p class="small text-secondary mt-2 mb-0">Tabel interaktif DataTables server-side (api/crs.php) aktif bila CDN terjangkau; fallback PHP di atas tetap tampil bila offline.</p>
    </div>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
