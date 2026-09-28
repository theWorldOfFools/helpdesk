<?php
// Board + List mini-todo SDLC internal — khusus staf (admin/teknisi).
require_once 'config.php';
require_once 'includes/dev_auth.php';
requireLogin();

$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['admin', 'teknisi'], true)) {
    header('Location: dashboard.php');
    exit;
}
$pageTitle = 'Board Todo SDLC';

require_once 'includes/header.php';

$conn = getDBConnection();
$phases = devPhases();

$search = trim($_GET['search'] ?? '');
$filterPhase = trim($_GET['phase'] ?? '');
$filterPriority = trim($_GET['priority'] ?? '');
$filterOwner = trim($_GET['owner'] ?? '');
$view = trim($_GET['view'] ?? 'board');
if (!in_array($view, ['board', 'list'], true)) $view = 'board';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$validPriority = ['low', 'medium', 'high', 'critical'];
if (!in_array($filterPhase, $phases, true)) $filterPhase = '';
if (!in_array($filterPriority, $validPriority, true)) $filterPriority = '';
if (!in_array($filterOwner, ['mine', 'unassigned'], true)) $filterOwner = '';

$conds = ['1=1'];
$params = [];
if ($search !== '') {
    $params[] = '%' . $search . '%';
    $conds[] = '(d.task_code ILIKE $' . count($params) . ' OR d.title ILIKE $' . count($params) . ' OR d.description ILIKE $' . count($params) . ')';
}
if ($filterPhase !== '') {
    $params[] = $filterPhase;
    $conds[] = 'd.phase = $' . count($params);
}
if ($filterPriority !== '') {
    $params[] = $filterPriority;
    $conds[] = 'd.priority = $' . count($params);
}
if ($filterOwner === 'mine') {
    $params[] = $user['id'];
    $n = count($params);
    $conds[] = '(d.owner_id = $' . $n . ' OR EXISTS (SELECT 1 FROM dev_task_assignees a WHERE a.task_id = d.id AND a.user_id = $' . $n . '))';
} elseif ($filterOwner === 'unassigned') {
    $conds[] = 'd.owner_id IS NULL AND NOT EXISTS (SELECT 1 FROM dev_task_assignees a WHERE a.task_id = d.id)';
}
$where = implode(' AND ', $conds);

$baseSelect = "SELECT d.*, u.name AS owner_name, t.ticket_number, c.cr_number,"
    . " (SELECT string_agg(u2.name, ', ' ORDER BY u2.name) FROM dev_task_assignees a JOIN users u2 ON u2.id = a.user_id WHERE a.task_id = d.id) AS assignee_names,"
    . " (SELECT COUNT(*) FROM dev_task_assignees a2 WHERE a2.task_id = d.id) AS assignee_count,"
    . " (SELECT COUNT(*) FROM dev_task_attachments at WHERE at.task_id = d.id) AS attachment_count FROM dev_tasks d"
    . " LEFT JOIN users u ON u.id = d.owner_id"
    . " LEFT JOIN tickets t ON t.id = d.ticket_id"
    . " LEFT JOIN change_requests c ON c.id = d.cr_id";

// ---- Board: semua yang cocok filter, dikelompokkan per fase ----
$board = array_fill_keys($phases, []);
if ($view === 'board') {
    $br = pg_query_params($conn, "$baseSelect WHERE $where ORDER BY d.created_at DESC LIMIT 500", $params);
    if ($br) {
        while ($row = pg_fetch_assoc($br)) $board[$row['phase']][] = $row;
        pg_free_result($br);
    }
}

// ---- List: paginasi ----
$tasks = [];
$totalRows = 0;
$totalPages = 1;
if ($view === 'list') {
    $cntR = pg_query_params($conn, "SELECT COUNT(*) FROM dev_tasks d WHERE $where", $params);
    $totalRows = $cntR ? (int)pg_fetch_result($cntR, 0, 0) : 0;
    if ($cntR) pg_free_result($cntR);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $dataParams = array_merge($params, [$perPage, $offset]);
    $limN = count($params) + 1;
    $offN = count($params) + 2;
    $lr = pg_query_params($conn, "$baseSelect WHERE $where ORDER BY d.created_at DESC LIMIT \$$limN OFFSET \$$offN", $dataParams);
    if ($lr) {
        while ($row = pg_fetch_assoc($lr)) $tasks[] = $row;
        pg_free_result($lr);
    }
}

function taskKeepQS($over = []) {
    $q = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = $v;
    }
    unset($q['page']);
    return http_build_query($q);
}
$hasFilter = $search !== '' || $filterPhase !== '' || $filterPriority !== '' || $filterOwner !== '';
$phaseBadge = ['backlog' => 'text-bg-secondary', 'siap' => 'text-bg-info', 'development' => 'text-bg-primary', 'testing' => 'text-bg-warning', 'deploy' => 'text-bg-dark', 'done' => 'text-bg-success'];
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Board Todo SDLC</h2>
        <p class="text-secondary mb-0">Kerja internal tim IT: backlog → siap → development → testing → deploy → done.</p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <div class="btn-group btn-group-sm" role="group" aria-label="Tampilan">
            <a href="tasks.php?<?php echo htmlspecialchars(taskKeepQS(['view' => 'board'])); ?>" class="btn <?php echo $view === 'board' ? 'btn-primary' : 'btn-outline-primary'; ?>">Board</a>
            <a href="tasks.php?<?php echo htmlspecialchars(taskKeepQS(['view' => 'list'])); ?>" class="btn <?php echo $view === 'list' ? 'btn-primary' : 'btn-outline-primary'; ?>">List</a>
        </div>
        <a href="create_task.php" class="btn btn-primary btn-sm">+ Buat Todo</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" data-task-filter class="row g-2 align-items-center">
            <input type="hidden" name="view" value="<?php echo $view; ?>">
            <div class="col-12 col-lg-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search" placeholder="Cari kode / judul…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <select name="phase" class="form-select" aria-label="Filter fase">
                    <option value="">Semua Fase</option>
                    <?php foreach ($phases as $ph): ?><option value="<?php echo $ph; ?>" <?php echo $filterPhase === $ph ? 'selected' : ''; ?>><?php echo $ph; ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <select name="priority" class="form-select" aria-label="Filter prioritas">
                    <option value="">Semua Prioritas</option>
                    <?php foreach ($validPriority as $p): ?><option value="<?php echo $p; ?>" <?php echo $filterPriority === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <select name="owner" class="form-select" aria-label="Filter owner">
                    <option value="">Semua Owner</option>
                    <option value="mine" <?php echo $filterOwner === 'mine' ? 'selected' : ''; ?>>Milik saya</option>
                    <option value="unassigned" <?php echo $filterOwner === 'unassigned' ? 'selected' : ''; ?>>Belum ada owner</option>
                </select>
            </div>
            <div class="col-6 col-lg-auto d-flex gap-2 align-items-center">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($hasFilter): ?><a href="tasks.php?view=<?php echo $view; ?>" class="btn btn-outline-secondary btn-sm">Reset</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($view === 'board'): ?>
<div class="row g-3 flex-nowrap overflow-auto pb-2" style="min-height:40vh;">
    <?php foreach ($phases as $ph): ?>
    <div class="col-11 col-md-6 col-xl-4 col-xxl-2">
        <div class="card h-100 bg-body-tertiary">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="badge <?php echo $phaseBadge[$ph]; ?>"><?php echo $ph; ?></span>
                <span class="badge text-bg-light border" data-phase-count="<?php echo $ph; ?>"><?php echo count($board[$ph]); ?></span>
            </div>
            <div class="card-body d-flex flex-column gap-2 p-2" data-drop-phase="<?php echo $ph; ?>">
                <?php foreach ($board[$ph] as $t): ?>
                <?php $od = isTaskOverdue($t); ?>
                <div class="card task-card <?php echo $od ? 'border-danger' : ''; ?>" draggable="true" data-task-id="<?php echo (int)$t['id']; ?>" data-phase="<?php echo $t['phase']; ?>">
                    <div class="card-body p-2">
                        <div class="d-flex justify-content-between gap-2 align-items-start">
                            <a href="view_task.php?id=<?php echo $t['id']; ?>" class="fw-semibold text-decoration-none small"><?php echo htmlspecialchars($t['task_code']); ?></a>
                            <span class="priority-badge priority-<?php echo strtolower($t['priority']); ?>"><?php echo htmlspecialchars($t['priority']); ?></span>
                        </div>
                        <div class="small mt-1"><?php echo htmlspecialchars(mb_strimwidth($t['title'], 0, 80, '…')); ?></div>
                        <div class="d-flex justify-content-between align-items-center gap-2 mt-2 flex-wrap">
                            <small class="text-secondary">👤 <?php echo htmlspecialchars($t['owner_name'] ?? '—'); ?><?php $ac = (int)($t['assignee_count'] ?? 0); if ($ac > 1): ?> <span class="badge text-bg-primary" title="<?php echo htmlspecialchars($t['assignee_names'] ?? ''); ?>">+<?php echo $ac - 1; ?></span><?php endif; ?></small>
                            <span class="d-flex gap-1 align-items-center"><?php $atc = (int)($t['attachment_count'] ?? 0); if ($atc > 0): ?><small title="<?php echo $atc; ?> lampiran">📎<?php echo $atc; ?></small><?php endif; ?><?php if (!empty($t['due_date'])): ?><small class="<?php echo $od ? 'text-danger fw-bold' : 'text-secondary'; ?>">📅 <?php echo date('d M', strtotime($t['due_date'])); ?></small><?php endif; ?></span>
                        </div>
                        <?php if (!empty($t['ticket_number']) || !empty($t['cr_number'])): ?>
                        <div class="mt-1 d-flex gap-1 flex-wrap">
                            <?php if (!empty($t['ticket_number'])): ?><a href="view_ticket.php?id=<?php echo (int)$t['ticket_id']; ?>" class="badge text-bg-info text-decoration-none">🎫 <?php echo htmlspecialchars($t['ticket_number']); ?></a><?php endif; ?>
                            <?php if (!empty($t['cr_number'])): ?><a href="view_cr.php?id=<?php echo (int)$t['cr_id']; ?>" class="badge text-bg-warning text-decoration-none">🔄 <?php echo htmlspecialchars($t['cr_number']); ?></a><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($board[$ph])): ?><p class="text-secondary small text-center my-3">Kosong</p><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body">
    <div class="table-responsive">
    <table id="grid-tasks" class="table table-hover align-middle mb-0" style="display:none;width:100%;">
        <thead class="table-dark">
            <tr>
                <th>Kode</th>
                <th>Judul</th>
                <th>Owner</th>
                <th>Fase</th>
                <th>Prioritas</th>
                <th>Due</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    </div>
    <div id="tbl-tasks-fallback-wrap">
    <?php if (!empty($tasks)): ?>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Kode</th>
                    <th>Judul</th>
                    <th>Owner</th>
                    <th>Fase</th>
                    <th>Prioritas</th>
                    <th>Due</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody class="table-group-divider">
                <?php foreach ($tasks as $row): ?>
                <?php $od = isTaskOverdue($row); ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['task_code']); ?></strong><?php if (!empty($row['ticket_number'])): ?> <a href="view_ticket.php?id=<?php echo (int)$row['ticket_id']; ?>" class="badge text-bg-info text-decoration-none">🎫</a><?php endif; ?><?php if (!empty($row['cr_number'])): ?> <a href="view_cr.php?id=<?php echo (int)$row['cr_id']; ?>" class="badge text-bg-warning text-decoration-none">🔄</a><?php endif; ?><?php if ((int)($row['attachment_count'] ?? 0) > 0): ?> <span class="badge text-bg-secondary" title="<?php echo (int)$row['attachment_count']; ?> lampiran">📎<?php echo (int)$row['attachment_count']; ?></span><?php endif; ?></td>
                    <td><?php echo htmlspecialchars(mb_strimwidth($row['title'], 0, 60, '…')); ?><br><small class="text-secondary">buat: <?php echo date('d M Y', strtotime($row['created_at'])); ?></small></td>
                    <td><?php echo htmlspecialchars($row['owner_name'] ?? '—'); ?><?php $rac = (int)($row['assignee_count'] ?? 0); if ($rac > 1): ?><br><small class="text-secondary" title="<?php echo htmlspecialchars($row['assignee_names'] ?? ''); ?>">+<?php echo $rac - 1; ?> anggota</small><?php endif; ?></td>
                    <td><span class="badge <?php echo $phaseBadge[$row['phase']]; ?>"><?php echo htmlspecialchars($row['phase']); ?></span></td>
                    <td><span class="priority-badge priority-<?php echo strtolower($row['priority']); ?>"><?php echo htmlspecialchars($row['priority']); ?></span></td>
                    <td class="text-nowrap small"><?php echo !empty($row['due_date']) ? ('<span class="' . ($od ? 'text-danger fw-bold' : '') . '">' . date('d M Y', strtotime($row['due_date'])) . '</span>') : '<span class="text-secondary">-</span>'; ?></td>
                    <td class="text-nowrap"><a href="view_task.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">Detail</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($totalPages > 1): ?>
        <nav class="d-flex justify-content-center align-items-center gap-3 p-3" aria-label="Pagination">
            <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="tasks.php?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>">← Prev</a><?php endif; ?>
            <span class="text-secondary small">Halaman <?php echo $page; ?> / <?php echo $totalPages; ?> · <?php echo $totalRows; ?> data</span>
            <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline-secondary" href="tasks.php?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>">Next →</a><?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="text-center text-secondary p-5">
            <div class="fs-1">📋</div>
            <strong>Tidak ada todo ditemukan.</strong><br>
            <span>Coba ubah filter, atau buat todo baru.</span><br><br>
            <?php if ($hasFilter): ?><a href="tasks.php?view=list" class="btn btn-outline-secondary btn-sm">Reset Filter</a> <?php endif; ?>
            <a href="create_task.php" class="btn btn-primary btn-sm">+ Buat Todo</a>
        </div>
    <?php endif; ?>
    </div>
    <p class="small text-secondary mt-2 mb-0">Tabel interaktif DataTables server-side (api/tasks.php) aktif bila CDN terjangkau; fallback PHP di atas tetap tampil bila offline.</p>
    </div>
</div>
<?php endif; ?>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
<?php if ($view === 'board'): ?>
<script src="assets/js/tasks-board.js"></script>
<?php endif; ?>
