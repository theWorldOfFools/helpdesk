<?php
// Detail mini-todo SDLC — khusus staf (admin/teknisi).
require_once 'config.php';
require_once 'includes/dev_auth.php';
requireLogin();

$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['admin', 'teknisi'], true)) {
    header('Location: dashboard.php');
    exit;
}
$pageTitle = 'Detail Todo SDLC';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: tasks.php');
    exit;
}

require_once 'includes/header.php';

$conn = getDBConnection();

$result = pg_query_params(
    $conn,
    "SELECT d.*, u.name AS owner_name, u.username AS owner_username, cb.name AS creator_name, t.ticket_number, t.title AS ticket_title, c.cr_number, c.aplikasi AS cr_aplikasi, c.fitur AS cr_fitur"
    . " FROM dev_tasks d LEFT JOIN users u ON u.id = d.owner_id LEFT JOIN users cb ON cb.id = d.created_by"
    . " LEFT JOIN tickets t ON t.id = d.ticket_id LEFT JOIN change_requests c ON c.id = d.cr_id WHERE d.id = $1",
    [$id]
);
if (!$result || pg_num_rows($result) === 0) {
    if ($result) pg_free_result($result);
    pg_close($conn);
    header('Location: tasks.php');
    exit;
}
$task = pg_fetch_assoc($result);
pg_free_result($result);

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? flash_error();
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$history = [];
$hr = pg_query_params($conn, "SELECT h.*, u.name AS actor_name FROM dev_task_history h LEFT JOIN users u ON u.id = h.actor_id WHERE h.task_id = $1 ORDER BY h.created_at DESC LIMIT 30", [$id]);
if ($hr) {
    while ($row = pg_fetch_assoc($hr)) $history[] = $row;
    pg_free_result($hr);
}
$comments = [];
$cr2 = pg_query_params($conn, "SELECT c.*, u.name AS author_name, u.role AS author_role FROM dev_task_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.task_id = $1 ORDER BY c.created_at DESC LIMIT 30", [$id]);
if ($cr2) {
    while ($row = pg_fetch_assoc($cr2)) $comments[] = $row;
    pg_free_result($cr2);
}

// Assignees multi-person + lampiran
$assignees = getTaskAssignees($conn, $id);
$assigneeIds = array_map(fn($a) => (int)$a['id'], $assignees);
$canManageAsg = canManageAssignees($task, $user);
$asgCandidates = [];
if ($canManageAsg) {
    foreach (getTaskOwnerCandidates($conn) as $c) {
        if (!in_array((int)$c['id'], $assigneeIds, true)) $asgCandidates[] = $c;
    }
}
$attachments = getTaskAttachments($conn, $id);
$attUsage = ['count' => count($attachments), 'bytes' => array_sum(array_map(fn($a) => (int)$a['file_size'], $attachments))];
$canManageAtt = canManageAttachments($task, $user, $assigneeIds);

$phases = devPhases();
$fromIdx = array_search($task['phase'], $phases, true);
$phaseBadge = ['backlog' => 'text-bg-secondary', 'siap' => 'text-bg-info', 'development' => 'text-bg-primary', 'testing' => 'text-bg-warning', 'deploy' => 'text-bg-dark', 'done' => 'text-bg-success'];

// Tombol aksi kontekstual per fase
$actions = [];
if ($fromIdx !== false) {
    if ($fromIdx < count($phases) - 1) $actions[] = ['k' => 'advance', 'label' => '▶ Maju ke ' . $phases[$fromIdx + 1], 'btn' => 'btn-primary', 'to' => $phases[$fromIdx + 1], 'desc' => 'Pindah ke fase berikutnya.'];
    if ($fromIdx > 0) $actions[] = ['k' => 'back', 'label' => '↩ Mundur ke ' . $phases[$fromIdx - 1], 'btn' => 'btn-outline-secondary', 'to' => $phases[$fromIdx - 1], 'desc' => 'Kembalikan ke fase sebelumnya.'];
    if ($task['phase'] !== 'done') $actions[] = ['k' => 'to_done', 'label' => '✓ Tandai Done', 'btn' => 'btn-success', 'to' => 'done', 'desc' => 'Langsung selesaikan todo ini.'];
    if ($task['phase'] !== 'backlog') $actions[] = ['k' => 'to_backlog', 'label' => '↺ Ke Backlog', 'btn' => 'btn-outline-warning', 'to' => 'backlog', 'desc' => 'Kembalikan ke antrean awal.'];
    if ((int)($task['owner_id'] ?? 0) !== (int)$user['id']) $actions[] = ['k' => 'take', 'label' => '🙋 Ambil (jadi owner)', 'btn' => 'btn-info', 'to' => $task['phase'], 'desc' => 'Jadikan saya owner tanpa ganti fase.'];
}
$od = isTaskOverdue($task);
?>

<?php if ($flashOk): ?><div class="alert alert-success"><?php echo e($flashOk); ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger"><?php echo e($flashErr); ?></div><?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <p class="text-danger fw-bold text-uppercase small mb-1" style="letter-spacing:.04em;"><?php echo e($task['task_code']); ?></p>
        <h2 class="h4 mb-1"><?php echo e($task['title']); ?></h2>
        <p class="text-secondary small mb-0">Dibuat <?php echo date('d M Y H:i', strtotime($task['created_at'])); ?> · Update <?php echo date('d M Y H:i', strtotime($task['updated_at'])); ?><?php if (!empty($task['owner_name'])) echo ' · Owner: ' . e($task['owner_name']); ?><?php if ($od) echo ' · <span class="badge text-bg-danger">overdue</span>'; ?></p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
        <span class="badge <?php echo $phaseBadge[$task['phase']]; ?> fs-6"><?php echo e($task['phase']); ?></span>
        <span class="priority-badge priority-<?php echo strtolower($task['priority']); ?>"><?php echo e($task['priority']); ?></span>
    </div>
</div>

<div class="row g-3 align-items-start">
    <div class="col-lg-8">
        <div class="card"><div class="card-body">
        <h3 class="h6">Deskripsi</h3>
        <div class="rich-content"><?php echo renderTicketDescription($task['description']); ?></div>

        <?php if (!empty($attachments)): ?>
        <h3 class="h6 mt-4">Lampiran (<?php echo $attUsage['count']; ?>/10 · <?php echo round($attUsage['bytes'] / 1048576, 1); ?>MB dari 20MB)</h3>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($attachments as $at): ?>
            <?php $aExt = strtolower(pathinfo($at['file_path'], PATHINFO_EXTENSION)); $aImg = in_array($aExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true); ?>
            <div class="bg-body-tertiary border rounded-3 p-2">
                <?php if ($aImg): ?>
                    <a href="<?php echo e($at['file_path']); ?>" target="_blank" rel="noopener">
                        <img src="<?php echo e($at['file_path']); ?>" alt="Lampiran todo" class="attach-preview">
                    </a>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <span class="fw-semibold small">📎 <?php echo e($at['original_name']); ?> <small class="text-secondary">(<?php echo round((int)$at['file_size'] / 1024); ?> KB<?php if (!empty($at['uploader_name'])) echo ' · ' . e($at['uploader_name']); ?>)</small></span>
                    <span class="d-flex gap-2 align-items-center">
                        <a class="btn btn-sm btn-primary" href="<?php echo e($at['file_path']); ?>" download>Unduh</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($at['file_path']); ?>" target="_blank" rel="noopener">Lihat</a>
                        <?php if ($canManageAtt): ?>
                        <form method="POST" action="task_action.php" class="d-inline" onsubmit="return confirm('Hapus lampiran <?php echo e($at['original_name']); ?>?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
                            <input type="hidden" name="action" value="attach_remove">
                            <input type="hidden" name="attachment_id" value="<?php echo (int)$at['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus lampiran">×</button>
                        </form>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($canManageAtt && $attUsage['count'] < 10): ?>
        <h3 class="h6 mt-3">Tambah Lampiran</h3>
        <form method="POST" action="task_action.php" enctype="multipart/form-data" class="d-flex gap-2 align-items-start flex-wrap">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
            <input type="hidden" name="action" value="attach_add">
            <input type="file" class="form-control form-control-sm" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar" style="max-width:320px;" required>
            <button type="submit" class="btn btn-sm btn-outline-primary">Upload</button>
        </form>
        <p class="form-text">Maks 10 file, total 20MB per todo (terpakai <?php echo round($attUsage['bytes'] / 1048576, 1); ?>MB).</p>
        <?php endif; ?>

        <?php if (!empty($task['ticket_id']) || !empty($task['cr_id'])): ?>
        <h3 class="h6 mt-4">Relasi</h3>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (!empty($task['ticket_id'])): ?><a href="view_ticket.php?id=<?php echo (int)$task['ticket_id']; ?>" class="btn btn-sm btn-info">🎫 <?php echo e($task['ticket_number'] ?? ''); ?> — <?php echo e(mb_strimwidth($task['ticket_title'] ?? '', 0, 50, '…')); ?></a><?php endif; ?>
            <?php if (!empty($task['cr_id'])): ?><a href="view_cr.php?id=<?php echo (int)$task['cr_id']; ?>" class="btn btn-sm btn-warning">🔄 <?php echo e($task['cr_number'] ?? ''); ?> — <?php echo e($task['cr_aplikasi'] ?? ''); ?> / <?php echo e(mb_strimwidth($task['cr_fitur'] ?? '', 0, 40, '…')); ?></a><?php endif; ?>
        </div>
        <?php endif; ?>
        </div></div>

        <div class="card mt-3"><div class="card-body">
            <h3 class="h6">Diskusi / Catatan (<?php echo count($comments); ?>)</h3>
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
            <h3 class="h6">Riwayat Fase</h3>
            <ul class="list-group list-group-flush small">
            <?php foreach ($history as $h): ?>
                <li class="list-group-item px-0 d-flex justify-content-between gap-2 flex-wrap">
                    <span><strong><?php echo e($h['actor_name'] ?? '-'); ?></strong>: <?php echo e($h['from_phase'] ?? '-'); ?> → <strong><?php echo e($h['to_phase']); ?></strong><br><span class="text-secondary"><?php echo e(mb_strimwidth(strip_tags($h['note'] ?? ''), 0, 120, '…')); ?></span></span>
                    <span class="text-secondary text-nowrap"><?php echo date('d M H:i', strtotime($h['created_at'])); ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        </div></div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <h3 class="h6">Informasi Todo</h3>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Owner</span><strong><?php echo e($task['owner_name'] ?? '— Belum ada —'); ?></strong></li>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Dibuat oleh</span><strong><?php echo e($task['creator_name'] ?? '-'); ?></strong></li>
                <?php if (!empty($task['due_date'])): ?><li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Due date</span><strong class="<?php echo $od ? 'text-danger' : ''; ?>"><?php echo date('d M Y', strtotime($task['due_date'])); ?></strong></li><?php endif; ?>
                <?php if (!empty($task['estimate_hours'])): ?><li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Estimasi</span><strong><?php echo (int)$task['estimate_hours']; ?> jam</strong></li><?php endif; ?>
                <?php if (!empty($task['done_at'])): ?><li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Selesai</span><strong><?php echo date('d M Y H:i', strtotime($task['done_at'])); ?></strong></li><?php endif; ?>
            </ul>
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                <h3 class="h6 mb-0">Assignees (<?php echo count($assignees); ?>)</h3>
                <?php if ($canManageAsg && !empty($asgCandidates) && count($assignees) < 10): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#assigneeModal">+ Tugaskan</button>
                <?php endif; ?>
            </div>
            <?php if (!empty($assignees)): ?>
                <ul class="list-group list-group-flush">
                <?php foreach ($assignees as $a): ?>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                        <span><strong><?php echo e($a['name']); ?></strong><?php if ((int)$a['id'] === (int)$task['owner_id']) echo ' <span class="badge text-bg-success" title="Penanggung jawab utama">owner</span>'; ?><?php if ((int)$a['id'] === (int)$task['created_by']) echo ' <span class="badge text-bg-info" title="Pembuat todo">pembuat</span>'; ?><br><small class="text-secondary">@<?php echo e($a['username']); ?> · <?php echo e($a['role']); ?></small></span>
                        <?php if ($canManageAsg && (int)$a['id'] !== (int)$task['owner_id']): ?>
                            <form method="POST" action="task_action.php" class="d-inline" onsubmit="return confirm('Hapus <?php echo e($a['name']); ?> dari assignee?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
                                <input type="hidden" name="action" value="assignee_remove">
                                <input type="hidden" name="assignee_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Hapus assignee">×</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-secondary small mb-0">Belum ada assignee.</p>
            <?php endif; ?>
            <?php if ($canManageAsg): ?><p class="small text-secondary mt-2 mb-0">Hanya admin/pembuat yang bisa mengatur assignee.</p><?php endif; ?>
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <h3 class="h6">Tindak Lanjut</h3>
            <p class="small text-secondary mb-2">Setiap aksi wajib catatan ≥10 karakter dan tercatat di riwayat.</p>
            <div class="d-grid gap-2">
                <?php foreach ($actions as $a): ?>
                    <button type="button" class="btn <?php echo $a['btn']; ?> btn-sm" data-bs-toggle="modal" data-bs-target="#actionModal" data-action="<?php echo $a['k']; ?>" data-label="<?php echo e($a['label']); ?>" data-to="<?php echo e($a['to']); ?>" data-desc="<?php echo e($a['desc']); ?>"><?php echo e($a['label']); ?> <small class="opacity-75">→ <?php echo e($a['to']); ?></small></button>
                <?php endforeach; ?>
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#actionModal" data-action="comment" data-label="Tambah Komentar" data-to="<?php echo e($task['phase']); ?>" data-desc="Diskusi tanpa mengubah fase.">💬 Tambah Komentar</button>
            </div>
        </div></div>

        <div class="d-flex gap-2 flex-wrap">
            <?php if (canEditTask($task, $user)): ?>
                <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
            <?php endif; ?>
            <?php if (canDeleteTask($task, $user)): ?>
                <form method="POST" action="delete_task.php" class="d-inline" onsubmit="return confirm('Hapus todo ini beserta komentar dan riwayatnya?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                </form>
            <?php endif; ?>
            <a href="tasks.php" class="btn btn-primary btn-sm">Kembali</a>
        </div>
    </div>
</div>

<div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="task_action.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="actionModalLabel">Tindak Lanjut</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
        <input type="hidden" name="action" id="actionInput" value="comment">
        <p class="small mb-1"><span id="actionDesc" class="text-secondary"></span></p>
        <p class="small mb-2">Fase saat ini: <span class="badge <?php echo $phaseBadge[$task['phase']]; ?>"><?php echo e($task['phase']); ?></span> → Tujuan: <strong id="actionTo">-</strong></p>
        <label for="noteInput" class="form-label">Catatan wajib <span class="text-secondary">(min 10 karakter)</span></label>
        <textarea name="note" id="noteInput" class="form-control" rows="4" required minlength="10" placeholder="Tulis progres / hasil testing / langkah berikutnya…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary btn-sm">Simpan Tindak Lanjut</button>
      </div>
    </form>
  </div>
</div>
<?php if ($canManageAsg && !empty($asgCandidates) && count($assignees) < 10): ?>
<div class="modal fade" id="assigneeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="task_action.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tugaskan — <?php echo e($task['task_code']); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo $task['id']; ?>">
        <input type="hidden" name="action" value="assignee_add">
        <label for="assigneeSelect" class="form-label">Pilih staf <span class="text-secondary">(admin/teknisi aktif)</span></label>
        <select name="assignee_id" id="assigneeSelect" class="form-select" required>
          <option value="">Pilih user…</option>
          <?php foreach ($asgCandidates as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?> · @<?php echo e($c['username']); ?> (<?php echo e($c['role']); ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary btn-sm">Tugaskan</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
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
