<?php
require_once 'config.php';
require_once 'includes/cr_auth.php';
requireLogin();

$user = getCurrentUser();
$role = $user['role'] ?? '';
$pageTitle = 'Detail Change Request';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: cr_list.php');
    exit;
}

require_once 'includes/header.php';

$conn = getDBConnection();

$result = pg_query_params($conn, "SELECT cr.*, u.name AS reporter_name, u.username AS reporter_username, u.role AS reporter_role, a.name AS assignee_name FROM change_requests cr LEFT JOIN users u ON cr.user_id = u.id LEFT JOIN users a ON a.id = cr.assigned_to WHERE cr.id = $1", [$id]);
if (!$result || pg_num_rows($result) === 0) {
    if ($result) pg_free_result($result);
    pg_close($conn);
    header('Location: cr_list.php');
    exit;
}
$cr = pg_fetch_assoc($result);
pg_free_result($result);

if (!canViewCr($cr, $user)) {
    pg_close($conn);
    ?>
    <div class="alert alert-danger text-center py-5">
        <h2 class="h5 text-danger">Akses Ditolak</h2>
        <p>Anda tidak memiliki izin untuk melihat Change Request ini.</p>
        <a href="cr_list.php" class="btn btn-primary">Kembali</a>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? flash_error();
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// Rincian item
$items = [];
$ir = pg_query_params($conn, "SELECT * FROM change_request_items WHERE cr_id = $1 ORDER BY id ASC", [$id]);
if ($ir) {
    while ($row = pg_fetch_assoc($ir)) $items[] = $row;
    pg_free_result($ir);
}

$attachOk = !empty($cr['attachment_path']) && file_exists(__DIR__ . '/' . $cr['attachment_path']);
$attachName = $cr['attachment_original'] ?: ($attachOk ? basename($cr['attachment_path']) : '');
$attachExt = $attachOk ? strtolower(pathinfo($cr['attachment_path'], PATHINFO_EXTENSION)) : '';
$isImage = in_array($attachExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

// Diskusi + riwayat
$history = [];
$hr = pg_query_params($conn, "SELECT h.*, u.name AS actor_name FROM change_request_history h LEFT JOIN users u ON u.id = h.actor_id WHERE h.cr_id = $1 ORDER BY h.created_at DESC LIMIT 30", [$id]);
if ($hr) {
    while ($row = pg_fetch_assoc($hr)) $history[] = $row;
    pg_free_result($hr);
}
$comments = [];
$cr2 = pg_query_params($conn, "SELECT c.*, u.name AS author_name, u.role AS author_role FROM change_request_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.cr_id = $1 ORDER BY c.created_at DESC LIMIT 30", [$id]);
if ($cr2) {
    while ($row = pg_fetch_assoc($cr2)) $comments[] = $row;
    pg_free_result($cr2);
}

// Tombol kontekstual per status (mirror view_ticket.php)
$st = $cr['status'];
$canAct = canActOnCr($cr, $user);
$isStaff = in_array($role, ['admin', 'teknisi'], true);
$actions = [];
if ($canAct && $isStaff) {
    if ($st === 'open') {
        $actions[] = ['k' => 'take', 'label' => '▶ Ambil & Kerjakan', 'btn' => 'btn-primary', 'to' => 'in_progress', 'desc' => 'Assign ke saya + status jadi in_progress.'];
        $actions[] = ['k' => 'resolve_direct', 'label' => '✓ Selesaikan Langsung', 'btn' => 'btn-success', 'to' => 'resolved', 'desc' => 'Langsung resolved tanpa via in_progress.'];
    } elseif ($st === 'in_progress') {
        $actions[] = ['k' => 'resolve', 'label' => '✓ Selesaikan', 'btn' => 'btn-success', 'to' => 'resolved', 'desc' => 'Tandai pekerjaan selesai.'];
        $actions[] = ['k' => 'unassign', 'label' => '↩ Kembalikan ke Antrean', 'btn' => 'btn-outline-secondary', 'to' => 'open', 'desc' => 'Lepas assignment + kembali open.'];
    } elseif ($st === 'resolved') {
        $actions[] = ['k' => 'close', 'label' => '🔒 Tutup', 'btn' => 'btn-dark', 'to' => 'closed', 'desc' => 'Tutup permanen.'];
        $actions[] = ['k' => 'reopen_progress', 'label' => '↻ Buka Kembali (Kerjakan)', 'btn' => 'btn-warning', 'to' => 'in_progress', 'desc' => 'Ada yang kurang, kerjakan lagi.'];
        $actions[] = ['k' => 'reopen_open', 'label' => '↻ Buka Kembali (Antrean)', 'btn' => 'btn-outline-warning', 'to' => 'open', 'desc' => 'Kembalikan ke antrean.'];
    } elseif ($st === 'closed') {
        $actions[] = ['k' => 'reopen_open', 'label' => '↻ Buka Kembali', 'btn' => 'btn-warning', 'to' => 'open', 'desc' => 'CR dibuka ulang.'];
        $actions[] = ['k' => 'reopen_progress', 'label' => '▶ Kerjakan Lagi', 'btn' => 'btn-primary', 'to' => 'in_progress', 'desc' => 'Assign ke saya + kerjakan.'];
    }
}

function crJenisBadge($jenis) {
    $map = ['Penambahan' => 'text-bg-primary', 'Perubahan' => 'text-bg-warning', 'Design' => 'text-bg-info'];
    $cls = $map[$jenis] ?? 'text-bg-secondary';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($jenis) . '</span>';
}
?>

<?php if ($flashOk): ?><div class="alert alert-success"><?php echo e($flashOk); ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger"><?php echo e($flashErr); ?></div><?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <p class="text-danger fw-bold text-uppercase small mb-1" style="letter-spacing:.04em;"><?php echo e($cr['cr_number']); ?></p>
        <h2 class="h4 mb-1"><?php echo e($cr['aplikasi']); ?> — <?php echo e($cr['fitur']); ?></h2>
        <p class="text-secondary small mb-0">Dibuat <?php echo date('d M Y H:i', strtotime($cr['created_at'])); ?> · Update <?php echo date('d M Y H:i', strtotime($cr['updated_at'])); ?><?php if (!empty($cr['assignee_name'])) echo ' · Ditangani: ' . e($cr['assignee_name']); ?></p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
        <span class="status-badge status-<?php echo $cr['status']; ?>"><?php echo e($cr['status']); ?></span>
        <span class="priority-badge priority-<?php echo strtolower($cr['priority']); ?>"><?php echo e($cr['priority']); ?></span>
    </div>
</div>

<div class="row g-3 align-items-start">
    <div class="col-lg-8">
        <div class="card"><div class="card-body">
        <h3 class="h6">Keterangan</h3>
        <div class="rich-content"><?php echo renderTicketDescription($cr['keterangan']); ?></div>

        <?php if (!empty($items)): ?>
        <h3 class="h6 mt-4">Rincian Perubahan (<?php echo count($items); ?>)</h3>
        <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:48px;" class="text-center">No</th>
                    <th style="width:140px;">Jenis</th>
                    <th>Uraian</th>
                    <th>Alasan / Manfaat</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                <tr>
                    <td class="text-center"><?php echo $idx + 1; ?></td>
                    <td><?php echo crJenisBadge($it['jenis']); ?></td>
                    <td><?php echo nl2br(e($it['uraian'])); ?></td>
                    <td><?php echo nl2br(e($it['alasan'] ?? '-')); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <?php if ($attachOk): ?>
        <h3 class="h6 mt-4">Lampiran</h3>
        <div class="bg-body-tertiary border rounded-3 p-3">
            <?php if ($isImage): ?>
                <a href="<?php echo e($cr['attachment_path']); ?>" target="_blank" rel="noopener">
                    <img src="<?php echo e($cr['attachment_path']); ?>" alt="Lampiran CR" class="attach-preview">
                </a>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <span class="fw-semibold small">📎 <?php echo e($attachName); ?></span>
                <span class="d-flex gap-2">
                    <a class="btn btn-sm btn-primary" href="<?php echo e($cr['attachment_path']); ?>" download>Unduh</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($cr['attachment_path']); ?>" target="_blank" rel="noopener">Lihat</a>
                </span>
            </div>
        </div>
        <?php endif; ?>
        </div></div>

        <div class="card mt-3"><div class="card-body">
            <h3 class="h6">Diskusi / Catatan Tindak Lanjut (<?php echo count($comments); ?>)</h3>
            <?php if (!empty($comments)): ?>
                <ul class="list-group list-group-flush">
                <?php foreach ($comments as $c): ?>
                    <li class="list-group-item px-0">
                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                            <strong><?php echo e($c['author_name'] ?? 'Sistem'); ?></strong>
                            <small class="text-secondary"><?php echo date('d M Y H:i', strtotime($c['created_at'])); ?><?php if (!empty($c['author_role'])) echo ' · ' . e($c['author_role']); ?></small>
                        </div>
                        <div class="rich-content mt-1"><?php echo renderTicketDescription($c['body']); ?></div>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-secondary small mb-0">Belum ada catatan. Setiap tindak lanjut wajib mengisi catatan.</p>
            <?php endif; ?>
        </div></div>

        <?php if (!empty($history)): ?>
        <div class="card mt-3"><div class="card-body">
            <h3 class="h6">Riwayat Status</h3>
            <ul class="list-group list-group-flush small">
            <?php foreach ($history as $h): ?>
                <li class="list-group-item px-0 d-flex justify-content-between gap-2 flex-wrap">
                    <span><strong><?php echo e($h['actor_name'] ?? '-'); ?></strong>: <?php echo e($h['from_status']); ?> → <strong><?php echo e($h['to_status']); ?></strong><br><span class="text-secondary"><?php echo e(mb_strimwidth(strip_tags($h['note'] ?? ''), 0, 120, '…')); ?></span></span>
                    <span class="text-secondary text-nowrap"><?php echo date('d M H:i', strtotime($h['created_at'])); ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        </div></div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <h3 class="h6">Informasi Change Request</h3>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Aplikasi</span><strong class="text-end"><?php echo e($cr['aplikasi']); ?></strong></li>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Unit</span><strong><?php echo e($cr['unit']); ?></strong></li>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Modul</span><strong class="text-end"><?php echo e($cr['modul']); ?></strong></li>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Fitur</span><strong class="text-end"><?php echo e($cr['fitur']); ?></strong></li>
                <?php if (!empty($cr['url'])): ?><li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">URL</span><a href="<?php echo e($cr['url']); ?>" target="_blank" rel="noopener" class="text-truncate" style="max-width:60%;"><?php echo e($cr['url']); ?></a></li><?php endif; ?>
                <?php if (!empty($cr['waktu_dibutuhkan'])): ?><li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Dibutuhkan</span><strong><?php echo date('d M Y H:i', strtotime($cr['waktu_dibutuhkan'])); ?></strong></li><?php endif; ?>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Pelapor</span><strong class="text-end"><?php echo e($cr['reporter_name'] ?? '-'); ?><?php echo !empty($cr['reporter_username']) ? ' (@' . e($cr['reporter_username']) . ')' : ''; ?></strong></li>
                <?php if (!empty($cr['reporter_role'])): ?><li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Role Pelapor</span><span class="status-badge status-<?php echo e($cr['reporter_role']); ?>"><?php echo e($cr['reporter_role']); ?></span></li><?php endif; ?>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Ditangani oleh</span><strong><?php echo e($cr['assignee_name'] ?? '— Belum diassign —'); ?></strong></li>
                <?php if (!empty($cr['sla_due_at'])): ?><li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Jatuh tempo SLA</span><strong><?php echo date('d M Y H:i', strtotime($cr['sla_due_at'])); ?></strong></li><?php endif; ?>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Lampiran</span><strong><?php echo $attachOk ? 'Ada (1 file)' : 'Tidak ada'; ?></strong></li>
            </ul>
        </div></div>

        <?php if (!empty($actions) || $canAct): ?>
        <div class="card mb-3"><div class="card-body">
            <h3 class="h6">Tindak Lanjut</h3>
            <p class="small text-secondary mb-2">Terpisah dari Edit. Setiap aksi wajib catatan ≥10 karakter dan tercatat di riwayat.</p>
            <div class="d-grid gap-2">
                <?php foreach ($actions as $a): ?>
                    <button type="button" class="btn <?php echo $a['btn']; ?> btn-sm" data-bs-toggle="modal" data-bs-target="#actionModal" data-action="<?php echo $a['k']; ?>" data-label="<?php echo e($a['label']); ?>" data-to="<?php echo e($a['to']); ?>" data-desc="<?php echo e($a['desc']); ?>"><?php echo e($a['label']); ?> <small class="opacity-75">→ <?php echo e($a['to']); ?></small></button>
                <?php endforeach; ?>
                <?php if ($canAct): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#actionModal" data-action="comment" data-label="Tambah Komentar" data-to="<?php echo e($st); ?>" data-desc="Diskusi tanpa mengubah status.">💬 Tambah Komentar</button>
                <?php endif; ?>
            </div>
        </div></div>
        <?php endif; ?>

        <div class="d-flex gap-2 flex-wrap">
            <?php if (canEditCr($cr, $user)): ?>
                <a href="edit_cr.php?id=<?php echo $cr['id']; ?>" class="btn btn-warning btn-sm">Edit (Admin)</a>
            <?php endif; ?>
            <?php if (canDeleteCr($cr, $user)): ?>
                <form method="POST" action="delete_cr.php" class="d-inline" onsubmit="return confirm('Hapus CR ini beserta lampiran dan rinciannya?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $cr['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                </form>
            <?php endif; ?>
            <a href="cr_list.php" class="btn btn-primary btn-sm">Kembali</a>
        </div>
    </div>
</div>

<div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="cr_action.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="actionModalLabel">Tindak Lanjut</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo $cr['id']; ?>">
        <input type="hidden" name="action" id="actionInput" value="comment">
        <p class="small mb-1"><span id="actionDesc" class="text-secondary"></span></p>
        <p class="small mb-2">Status saat ini: <span class="status-badge status-<?php echo $cr['status']; ?>"><?php echo e($cr['status']); ?></span> → Tujuan: <strong id="actionTo">-</strong></p>
        <label for="noteInput" class="form-label">Catatan wajib <span class="text-secondary">(min 10 karakter)</span></label>
        <textarea name="note" id="noteInput" class="form-control" rows="4" required minlength="10" placeholder="Tulis apa yang dikerjakan / hasil pengecekan / langkah berikutnya…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary btn-sm">Simpan Tindak Lanjut</button>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('actionModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    if (!btn) return;
    document.getElementById('actionInput').value = btn.getAttribute('data-action') || 'comment';
    document.getElementById('actionModalLabel').textContent = btn.getAttribute('data-label') || 'Tindak Lanjut';
    document.getElementById('actionTo').textContent = btn.getAttribute('data-to') || '-';
    document.getElementById('actionDesc').textContent = btn.getAttribute('data-desc') || '';
  });
});
</script>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
