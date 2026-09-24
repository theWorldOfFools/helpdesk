<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$role = $user['role'] ?? '';
$pageTitle = $role === 'admin' ? 'Semua Tiket' : ($role === 'teknisi' ? 'Antrean Tiket' : 'Tiket Saya');

require_once 'includes/header.php';

$conn = getDBConnection();

// ---- Filter (aman, parameterized) ----
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['filter_status'] ?? '');
$filterPriority = trim($_GET['filter_priority'] ?? '');
$filterDivision = trim($_GET['filter_division'] ?? '');
$onlyAttach = isset($_GET['has_attachment']) && $_GET['has_attachment'] === '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$validStatus = ['open', 'in_progress', 'resolved', 'closed'];
$validPriority = ['low', 'medium', 'high', 'critical'];
if (!in_array($filterStatus, $validStatus, true)) $filterStatus = '';
if (!in_array($filterPriority, $validPriority, true)) $filterPriority = '';

$conds = ['1=1'];
$params = [];
if ($role === 'pelapor') {
    $params[] = $user['id'];
    $conds[] = 't.user_id = $' . count($params);
}
if ($search !== '') {
    $params[] = '%' . $search . '%';
    $conds[] = '(t.title ILIKE $' . count($params) . ' OR t.ticket_number ILIKE $' . count($params) . ' OR t.description ILIKE $' . count($params) . ')';
}
if ($filterStatus !== '') {
    $params[] = $filterStatus;
    $conds[] = 't.status = $' . count($params);
}
if ($filterPriority !== '') {
    $params[] = $filterPriority;
    $conds[] = 't.priority = $' . count($params);
}
if ($filterDivision !== '') {
    $params[] = $filterDivision;
    $conds[] = 't.division = $' . count($params);
}
if ($onlyAttach) {
    $conds[] = "t.attachment_path IS NOT NULL AND t.attachment_path <> ''";
}
$where = implode(' AND ', $conds);

// Total + pagination
$cntR = pg_query_params($conn, "SELECT COUNT(*) FROM tickets t WHERE $where", $params);
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
    "SELECT t.*, u.name AS created_by_name FROM tickets t LEFT JOIN users u ON t.user_id = u.id WHERE $where ORDER BY t.created_at DESC LIMIT \$$limN OFFSET \$$offN",
    $dataParams
);
$tickets = [];
if ($result) {
    while ($row = pg_fetch_assoc($result)) $tickets[] = $row;
    pg_free_result($result);
}

// Kartu status (scope role, tanpa filter lain)
$statusCounts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
$scopeP = $role === 'pelapor' ? [$user['id']] : [];
$scopeW = $role === 'pelapor' ? 'user_id = $1' : '1=1';
$sc = pg_query_params($conn, "SELECT status, COUNT(*) AS c FROM tickets WHERE $scopeW GROUP BY status", $scopeP);
while ($row = pg_fetch_assoc($sc)) $statusCounts[$row['status']] = (int)$row['c'];
pg_free_result($sc);

// Opsi divisi
$divisions = [];
$divP = $role === 'pelapor' ? [$user['id']] : [];
$divW = $role === 'pelapor' ? 'WHERE user_id = $1' : '';
$dr = pg_query_params($conn, "SELECT DISTINCT division FROM tickets $divW ORDER BY division", $divP);
while ($d = pg_fetch_assoc($dr)) $divisions[] = $d['division'];
pg_free_result($dr);

function keepQS($over = []) {
    $q = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = $v;
    }
    unset($q['page']);
    return http_build_query($q);
}
$hasFilter = $search !== '' || $filterStatus !== '' || $filterPriority !== '' || $filterDivision !== '' || $onlyAttach;
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1"><?php echo htmlspecialchars($pageTitle); ?></h2>
        <p class="text-secondary mb-0"><?php echo $totalRows; ?> tiket ditemukan<?php echo $role === 'pelapor' ? ' · milik kamu' : ''; ?>. Klik kartu status untuk filter cepat.</p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <a href="cr_list.php" class="btn btn-outline-primary">Daftar CR</a>
        <a href="create_ticket.php" class="btn btn-primary">+ Tiket Baru</a>
        <a href="create_cr.php" class="btn btn-outline-primary">+ Buat CR</a>
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
        $qs = keepQS(['filter_status' => $isActive ? '' : $c['k']]);
    ?>
    <div class="col-6 col-xl-3">
        <a href="index.php<?php echo $qs ? '?' . htmlspecialchars($qs) : ''; ?>" data-status-link="<?php echo $c['k']; ?>" class="card h-100 text-decoration-none link-dark <?php echo $isActive ? 'border-danger border-2' : $c['border']; ?>" style="<?php echo $isActive ? '' : 'border-top-width:4px !important;'; ?>">
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
        <form method="GET" data-dt-filter class="row g-2 align-items-center">
            <div class="col-12 col-lg-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search" placeholder="Cari nomor, judul, deskripsi…" value="<?php echo htmlspecialchars($search); ?>">
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
            <div class="col-6 col-lg-2">
                <select name="filter_division" class="form-select" aria-label="Filter divisi">
                    <option value="">Semua Divisi</option>
                    <?php foreach ($divisions as $div): ?><option value="<?php echo htmlspecialchars($div); ?>" <?php echo $filterDivision === $div ? 'selected' : ''; ?>><?php echo htmlspecialchars($div); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-auto d-flex gap-2 align-items-center">
                <label class="form-check mb-0 text-nowrap" title="Hanya yang ada lampiran"><input type="checkbox" class="form-check-input" name="has_attachment" value="1" <?php echo $onlyAttach ? 'checked' : ''; ?>> 📎</label>
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($hasFilter): ?><a href="index.php" class="btn btn-outline-secondary btn-sm">Reset</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
    <div class="table-responsive">
    <table id="grid-tiket" class="table table-hover align-middle mb-0" style="display:none;width:100%;">
        <thead class="table-dark">
            <tr>
                <th>Ticket</th>
                <th>Judul &amp; Pelapor</th>
                <th>Prioritas</th>
                <th>Status</th>
                <th>Divisi</th>
                <th>Tanggal</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    </div>
    <div id="tbl-tiket-fallback-wrap">
    <?php if (!empty($tickets)): ?>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Ticket</th>
                    <th>Judul & Pelapor</th>
                    <th>Prioritas</th>
                    <th>Status</th>
                    <th>Divisi</th>
                    <th>Tanggal</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody class="table-group-divider">
                <?php foreach ($tickets as $row): ?>
                    <?php $hasFile = !empty($row['attachment_path']); ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['ticket_number']); ?></strong><?php if ($hasFile): ?> <span class="badge text-bg-info" title="Ada lampiran: <?php echo htmlspecialchars($row['attachment_original'] ?: 'file'); ?>">📎</span><?php endif; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($row['title']); ?></strong><br>
                            <small class="text-secondary"><?php echo htmlspecialchars($row['created_by_name'] ?? '-'); ?> · <?php echo htmlspecialchars($row['category']); ?></small>
                        </td>
                        <td><span class="priority-badge priority-<?php echo strtolower($row['priority']); ?>"><?php echo htmlspecialchars($row['priority']); ?></span></td>
                        <td><span class="status-badge status-<?php echo $row['status']; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($row['division']); ?></td>
                        <td class="text-nowrap"><?php echo date('d M Y H:i', strtotime($row['created_at'])); ?></td>
                        <td class="text-nowrap">
                            <a href="view_ticket.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">Detail</a>
                            <?php if ($role === 'admin'): ?>
                                <a href="edit_ticket.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

        <?php if ($totalPages > 1): ?>
        <nav class="d-flex justify-content-center align-items-center gap-3 p-3" aria-label="Pagination">
            <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="index.php?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>">← Prev</a><?php endif; ?>
            <span class="text-secondary small">Halaman <?php echo $page; ?> / <?php echo $totalPages; ?> · <?php echo $totalRows; ?> data</span>
            <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline-secondary" href="index.php?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>">Next →</a><?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="text-center text-secondary p-5">
            <div class="fs-1">🔍</div>
            <strong>Tidak ada tiket ditemukan.</strong><br>
            <span>Coba ubah kata kunci / filter, atau buat tiket baru.</span><br><br>
            <?php if ($hasFilter): ?><a href="index.php" class="btn btn-outline-secondary btn-sm">Reset Filter</a> <?php endif; ?>
            <a href="create_ticket.php" class="btn btn-primary btn-sm">+ Buat Tiket</a>
        </div>
    <?php endif; ?>
    </div>
    <p class="small text-secondary mt-2 mb-0">Tabel interaktif DataTables server-side aktif bila CDN terjangkau; fallback PHP di atas tetap tampil bila offline.</p>
    </div>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
