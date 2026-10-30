<?php
// Daftar undangan rapat digital — admin & teknisi.
require_once 'config.php';
require_once 'includes/meeting_auth.php';
$user = requireRole(['admin', 'teknisi']);
$pageTitle = 'Daftar Rapat';

$conn = getDBConnection();

$tab = trim($_GET['tab'] ?? 'active');
if (!in_array($tab, ['active', 'completed', 'cancelled', 'all'], true)) $tab = 'active';
$mine = ($_GET['mine'] ?? '') === '1';
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$conds = ['1=1'];
$params = [];
if ($tab === 'active') $conds[] = "m.status = 'scheduled'";
elseif ($tab === 'completed') $conds[] = "m.status = 'completed'";
elseif ($tab === 'cancelled') $conds[] = "m.status = 'cancelled'";
if ($mine) {
    $params[] = (int)$user['id'];
    $conds[] = 'm.host_id = $' . count($params);
}
if ($search !== '') {
    $params[] = '%' . $search . '%';
    $n = count($params);
    $conds[] = "(m.meeting_code ILIKE $n OR m.title ILIKE $n OR m.location ILIKE $n OR h.name ILIKE $n)";
}
$where = implode(' AND ', $conds);

$countSql = "SELECT COUNT(*) FROM meetings m LEFT JOIN users h ON h.id = m.host_id WHERE $where";
$cr = pg_query_params($conn, $countSql, $params);
$totalRows = $cr ? (int)pg_fetch_result($cr, 0, 0) : 0;
if ($cr) pg_free_result($cr);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$dataParams = array_merge($params, [$perPage, $offset]);
$limN = count($params) + 1;
$offN = count($params) + 2;
$order = in_array($tab, ['completed', 'cancelled', 'all'], true) ? 'm.start_at DESC, m.id DESC' : 'm.start_at ASC, m.id ASC';
$sql = "SELECT m.*, h.name AS host_name,
        (SELECT COUNT(*) FROM meeting_attendees a WHERE a.meeting_id = m.id) AS attendee_count,
        (SELECT COUNT(*) FROM meeting_notes n WHERE n.meeting_id = m.id) AS note_count
        FROM meetings m LEFT JOIN users h ON h.id = m.host_id
        WHERE $where ORDER BY $order LIMIT \$$limN OFFSET \$$offN";
$meetings = [];
$r = pg_query_params($conn, $sql, $dataParams);
if ($r) {
    while ($row = pg_fetch_assoc($r)) $meetings[] = $row;
    pg_free_result($r);
}

function meetingsKeepQs($over = [])
{
    $q = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = $v;
    }
    unset($q['page']);
    return http_build_query($q);
}

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

require_once 'includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Daftar Rapat</h2>
        <p class="text-secondary mb-0">Undangan rapat digital dengan absensi QR dan notulensi kolaboratif.</p>
    </div>
    <a href="create_meeting.php" class="btn btn-primary flex-shrink-0">+ Buat Rapat</a>
</div>

<?php if ($flashOk): ?><div class="alert alert-success py-2"><?php echo e($flashOk); ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger py-2"><?php echo e($flashErr); ?></div><?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
        <ul class="nav nav-pills gap-1">
            <?php
            $tabs = [
                'active' => 'Aktif',
                'completed' => 'Selesai',
                'cancelled' => 'Dibatalkan',
                'all' => 'Semua',
            ];
            foreach ($tabs as $k => $label):
                $active = $tab === $k;
            ?>
            <li class="nav-item">
                <a class="nav-link btn-sm <?php echo $active ? 'active' : ''; ?>" href="meetings.php?<?php echo e(meetingsKeepQs(['tab' => $k])); ?>"><?php echo e($label); ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="mineFilter" <?php echo $mine ? 'checked' : ''; ?> data-href="meetings.php?<?php echo e(meetingsKeepQs(['mine' => $mine ? null : '1', 'page' => null])); ?>" onchange="window.location=this.dataset.href">
            <label class="form-check-label small" for="mineFilter">Rapat Saya</label>
        </div>
    </div>

    <form method="GET" class="row g-2 align-items-center">
        <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
        <?php if ($mine): ?><input type="hidden" name="mine" value="1"><?php endif; ?>
        <div class="col-12 col-lg-6">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" name="q" placeholder="Cari kode, judul, lokasi, atau host…" value="<?php echo e($search); ?>">
            </div>
        </div>
        <div class="col-auto d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">Cari</button>
            <?php if ($search !== ''): ?><a href="meetings.php?tab=<?php echo e($tab); ?><?php echo $mine ? '&mine=1' : ''; ?>" class="btn btn-outline-secondary btn-sm">Reset</a><?php endif; ?>
        </div>
        <div class="col text-secondary small text-lg-end"><?php echo $totalRows; ?> rapat</div>
    </form>
</div></div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Kode / Judul</th>
                        <th>Jadwal</th>
                        <th>Host</th>
                        <th>Lokasi</th>
                        <th class="text-center">Peserta</th>
                        <th class="text-center">Catatan</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($meetings)): ?>
                    <tr><td colspan="8" class="text-center text-secondary py-4">Belum ada rapat pada filter ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($meetings as $m): ?>
                    <?php $meta = meetingStateMeta($m); $manage = meetingCanManage($m, $user); ?>
                    <tr>
                        <td>
                            <a href="view_meeting.php?id=<?php echo (int)$m['id']; ?>" class="fw-semibold text-decoration-none"><?php echo e($m['title']); ?></a>
                            <div class="small text-secondary"><?php echo e($m['meeting_code']); ?></div>
                        </td>
                        <td>
                            <div class="small"><i class="bi bi-calendar-event me-1"></i><?php echo date('d M Y', strtotime($m['start_at'])); ?></div>
                            <div class="small text-secondary"><?php echo date('H:i', strtotime($m['start_at'])); ?>–<?php echo date('H:i', strtotime($m['end_at'])); ?> · <?php echo e(meetingFormatDuration(meetingDurationMinutes($m))); ?></div>
                        </td>
                        <td class="small"><?php echo e($m['host_name'] ?? '—'); ?></td>
                        <td class="small">
                            <?php echo e($m['location']); ?>
                            <?php if (!empty($m['online_url'])): ?><span class="badge text-bg-info ms-1">Online</span><?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge text-bg-light border"><?php echo (int)$m['attendee_count']; ?></span></td>
                        <td class="text-center"><span class="badge text-bg-light border"><?php echo (int)$m['note_count']; ?></span></td>
                        <td><span class="badge <?php echo e($meta['badge']); ?>"><?php echo e($meta['label']); ?></span></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a href="view_meeting.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-outline-primary" title="Detail">Detail</a>
                                <?php if ($manage): ?>
                                <a href="edit_meeting.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3" aria-label="Navigasi halaman rapat">
    <ul class="pagination pagination-sm justify-content-center mb-0">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="meetings.php?<?php echo e(meetingsKeepQs(['page' => max(1, $page - 1)])); ?>">‹</a></li>
        <li class="page-item disabled"><span class="page-link">Halaman <?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="meetings.php?<?php echo e(meetingsKeepQs(['page' => min($totalPages, $page + 1)])); ?>">›</a></li>
    </ul>
</nav>
<?php endif; ?>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
