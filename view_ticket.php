<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$role = $user['role'] ?? '';
$pageTitle = 'Detail Tiket';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

require_once 'includes/header.php';

$conn = getDBConnection();

$result = pg_query_params($conn, "SELECT t.*, u.name AS created_by_name, u.username AS created_by_username, u.role AS created_by_role, a.name AS assignee_name FROM tickets t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN users a ON a.id = t.assigned_to WHERE t.id = $1", [$id]);
if (!$result || pg_num_rows($result) === 0) {
    if ($result) pg_free_result($result);
    pg_close($conn);
    header('Location: index.php');
    exit;
}
$ticket = pg_fetch_assoc($result);
pg_free_result($result);

if (!canViewTicket($ticket, $user)) {
    pg_close($conn);
    ?>
    <div class="alert alert-danger text-center py-5">
        <h2 class="h5 text-danger">Akses Ditolak</h2>
        <p>Anda tidak memiliki izin untuk melihat tiket ini.</p>
        <a href="index.php" class="btn btn-primary">Kembali</a>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? flash_error();
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// Respons staf pertama (menit kerja) untuk badge SLA respons 60 mnt
$respMin = null;
$respAt = null;
$rr = pg_query_params($conn, "SELECT MIN(h.created_at) AS fa FROM ticket_history h JOIN users u ON u.id = h.actor_id WHERE h.ticket_id = $1 AND u.role IN ('admin','teknisi')", [$id]);
if ($rr && pg_num_rows($rr) > 0) {
    $respAt = pg_fetch_result($rr, 0, 0);
$respLimit = slaResponseLimit();
    if ($respAt) $respMin = workingMinutesBetween(nextWorkingStart(strtotime($ticket['created_at'])), strtotime($respAt));
    pg_free_result($rr);
}
if ($respMin === null) {
    $ageMin = workingMinutesBetween(nextWorkingStart(strtotime($ticket['created_at'])), time());
}

$attachOk = !empty($ticket['attachment_path']) && file_exists(__DIR__ . '/' . $ticket['attachment_path']);
$attachName = $ticket['attachment_original'] ?: ($attachOk ? basename($ticket['attachment_path']) : '');
$attachExt = $attachOk ? strtolower(pathinfo($ticket['attachment_path'], PATHINFO_EXTENSION)) : '';
$isImage = in_array($attachExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

// Riwayat + komentar
$history = [];
$hr = pg_query_params($conn, "SELECT h.*, u.name AS actor_name FROM ticket_history h LEFT JOIN users u ON u.id = h.actor_id WHERE h.ticket_id = $1 ORDER BY h.created_at DESC LIMIT 30", [$id]);
if ($hr) {
    while ($row = pg_fetch_assoc($hr)) $history[] = $row;
    pg_free_result($hr);
}
$comments = [];
$cr = pg_query_params($conn, "SELECT c.*, u.name AS author_name, u.role AS author_role FROM ticket_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.ticket_id = $1 ORDER BY c.created_at DESC LIMIT 30", [$id]);
if ($cr) {
    while ($row = pg_fetch_assoc($cr)) $comments[] = $row;
    pg_free_result($cr);
}

// Tombol kontekstual per status (fleksibel, teknisi boleh loncat)
$st = $ticket['status'];
$canAct = canActOnTicket($ticket, $user);
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
        $actions[] = ['k' => 'reopen_open', 'label' => '↻ Buka Kembali', 'btn' => 'btn-warning', 'to' => 'open', 'desc' => 'Tiket dibuka ulang.'];
        $actions[] = ['k' => 'reopen_progress', 'label' => '▶ Kerjakan Lagi', 'btn' => 'btn-primary', 'to' => 'in_progress', 'desc' => 'Assign ke saya + kerjakan.'];
    }
}
// Template jawaban cepat untuk modal tindak lanjut
$templates = [];
$tr = pg_query($conn, "SELECT id, judul, isi FROM kb_templates ORDER BY id");
if ($tr) {
    while ($row = pg_fetch_assoc($tr)) $templates[] = $row;
    pg_free_result($tr);
}
?>

<?php if ($flashOk): ?><div class="alert alert-success"><?php echo e($flashOk); ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger"><?php echo e($flashErr); ?></div><?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <p class="text-danger fw-bold text-uppercase small mb-1" style="letter-spacing:.04em;"><?php echo e($ticket['ticket_number']); ?></p>
        <h2 class="h4 mb-1"><?php echo e($ticket['title']); ?></h2>
        <p class="text-secondary small mb-0">Dibuat <?php echo date('d M Y H:i', strtotime($ticket['created_at'])); ?> · Update <?php echo date('d M Y H:i', strtotime($ticket['updated_at'])); ?><?php if (!empty($ticket['assignee_name'])) echo ' · Ditangani: ' . e($ticket['assignee_name']); ?></p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
        <span class="status-badge status-<?php echo $ticket['status']; ?>"><?php echo e($ticket['status']); ?></span>
        <span class="priority-badge priority-<?php echo strtolower($ticket['priority']); ?>"><?php echo e($ticket['priority']); ?></span>
    </div>
</div>

<div class="row g-3 align-items-start">
    <div class="col-lg-8">
        <div class="card"><div class="card-body">
        <h3 class="h6">Deskripsi</h3>
        <div class="rich-content"><?php echo renderTicketDescription($ticket['description']); ?></div>

        <?php if ($attachOk): ?>
        <h3 class="h6 mt-4">Lampiran</h3>
        <div class="bg-body-tertiary border rounded-3 p-3">
            <?php if ($isImage): ?>
                <a href="<?php echo e($ticket['attachment_path']); ?>" target="_blank" rel="noopener">
                    <img src="<?php echo e($ticket['attachment_path']); ?>" alt="Lampiran tiket" class="attach-preview">
                </a>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <span class="fw-semibold small">📎 <?php echo e($attachName); ?></span>
                <span class="d-flex gap-2">
                    <a class="btn btn-sm btn-primary" href="<?php echo e($ticket['attachment_path']); ?>" download>Unduh</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($ticket['attachment_path']); ?>" target="_blank" rel="noopener">Lihat</a>
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
            <h3 class="h6">Informasi Tiket</h3>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Kategori</span><strong><?php echo e($ticket['category']); ?></strong></li>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Divisi</span><strong><?php echo e($ticket['division']); ?></strong></li>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Pelapor</span><strong class="text-end"><?php echo e($ticket['created_by_name'] ?? '-'); ?><?php echo !empty($ticket['created_by_username']) ? ' (@' . e($ticket['created_by_username']) . ')' : ''; ?></strong></li>
                <?php if (!empty($ticket['created_by_role'])): ?><li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Role Pelapor</span><span class="status-badge status-<?php echo e($ticket['created_by_role']); ?>"><?php echo e($ticket['created_by_role']); ?></span></li><?php endif; ?>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Ditangani oleh</span><strong><?php echo e($ticket['assignee_name'] ?? '— Belum diassign —'); ?></strong></li>
                <?php if (!empty($ticket['sla_due_at'])): ?><li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Jatuh tempo SLA</span><strong><?php echo date('d M Y H:i', strtotime($ticket['sla_due_at'])); ?></strong></li><?php endif; ?>
                <li class="list-group-item d-flex justify-content-between gap-2 px-0"><span class="text-secondary">Respons pertama</span><strong>
                    <?php if ($respMin !== null): ?>
                        <?php echo fmtWorkingDuration($respMin); ?>
                        <?php if ($respMin <= $respLimit): ?><span class="badge text-bg-success">✓ ≤<?php echo $respLimit; ?> mnt</span><?php else: ?><span class="badge text-bg-danger">telat +<?php echo fmtWorkingDuration($respMin - $respLimit); ?></span><?php endif; ?>
                    <?php else: ?>
                        <span class="text-secondary">Belum direspons (<?php echo fmtWorkingDuration($ageMin); ?>)</span>
                        <?php if ($ageMin > $respLimit): ?><span class="badge text-bg-danger">breach</span><?php elseif ($ageMin >= $respLimit - 15): ?><span class="badge text-bg-warning">≤15 mnt lagi</span><?php endif; ?>
                    <?php endif; ?>
                </strong></li>
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
            <?php if (canEditTicket($ticket, $user)): ?>
                <a href="edit_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn btn-warning btn-sm">Edit (Admin)</a>
            <?php endif; ?>
            <?php if (canDeleteTicket($ticket, $user)): ?>
                <form method="POST" action="delete_ticket.php" class="d-inline" onsubmit="return confirm('Hapus tiket ini beserta lampirannya?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $ticket['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                </form>
            <?php endif; ?>
            <a href="index.php" class="btn btn-primary btn-sm">Kembali</a>
        </div>
    </div>
</div>

<div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" action="ticket_action.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="actionModalLabel">Tindak Lanjut</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo $ticket['id']; ?>">
        <input type="hidden" name="action" id="actionInput" value="comment">
        <p class="small mb-1"><span id="actionDesc" class="text-secondary"></span></p>
        <p class="small mb-2">Status saat ini: <span class="status-badge status-<?php echo $ticket['status']; ?>"><?php echo e($ticket['status']); ?></span> → Tujuan: <strong id="actionTo">-</strong></p>
        <label for="noteInput" class="form-label">Catatan wajib <span class="text-secondary">(min 10 karakter)</span></label>
        <div class="form-text mb-1">Mendukung markdown dan gambar — tempel gambar (Ctrl+V) atau klik ikon gambar di toolbar.</div>
        <?php if (!empty($templates)): ?>
        <div class="input-group mb-2">
            <select id="tplSelect" class="form-select form-select-sm" aria-label="Sisipkan template jawaban">
                <option value="">⚡ Sisipkan template…</option>
                <?php foreach ($templates as $tp): ?><option value="<?php echo $tp['id']; ?>"><?php echo e($tp['judul']); ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="tplBtn">Sisipkan</button>
        </div>
        <?php endif; ?>
        <textarea name="note" id="noteInput" class="form-control" rows="4" placeholder="Tulis apa yang dikerjakan / hasil pengecekan / langkah berikutnya…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary btn-sm">Simpan Tindak Lanjut</button>
      </div>
    </form>
  </div>
</div>
<script>
var KB_TEMPLATES = <?php echo json_encode(array_column($templates, 'isi', 'id'), JSON_UNESCAPED_UNICODE); ?>;
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
