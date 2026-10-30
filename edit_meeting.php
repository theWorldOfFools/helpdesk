<?php
// Edit undangan rapat — host atau admin.
require_once 'config.php';
require_once 'includes/meeting_auth.php';
$user = requireRole(['admin', 'teknisi']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: meetings.php');
    exit;
}

$conn = getDBConnection();
$mr = pg_query_params($conn, "SELECT * FROM meetings WHERE id = $1", [$id]);
$meeting = ($mr && pg_num_rows($mr) > 0) ? pg_fetch_assoc($mr) : null;
if ($mr) pg_free_result($mr);
if (!$meeting) {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Rapat tidak ditemukan.';
    header('Location: meetings.php');
    exit;
}
if (!meetingCanManage($meeting, $user)) {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Hanya host pembuat rapat atau admin yang dapat mengedit rapat ini.';
    header('Location: view_meeting.php?id=' . $id);
    exit;
}

$pageTitle = 'Edit Rapat';
$message = '';
$messageType = '';
$old = [
    'title' => (string)$meeting['title'],
    'agenda' => (string)$meeting['agenda'],
    'date' => date('Y-m-d', strtotime($meeting['start_at'])),
    'time' => date('H:i', strtotime($meeting['start_at'])),
    'duration' => (string)max(1, meetingDurationMinutes($meeting)),
    'location' => (string)$meeting['location'],
    'online_url' => (string)($meeting['online_url'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $old['title'] = trim($_POST['title'] ?? '');
    $old['agenda'] = trim($_POST['agenda'] ?? '');
    $old['date'] = trim($_POST['date'] ?? '');
    $old['time'] = trim($_POST['time'] ?? '');
    $old['duration'] = trim($_POST['duration'] ?? '60');
    $old['location'] = trim($_POST['location'] ?? '');
    $old['online_url'] = trim($_POST['online_url'] ?? '');

    $err = null;
    if ($old['title'] === '' || $old['date'] === '' || $old['time'] === '' || $old['location'] === '') {
        $err = 'Judul, tanggal, jam mulai, dan lokasi wajib diisi.';
    } elseif (mb_strlen($old['title']) > 255) {
        $err = 'Judul maksimal 255 karakter.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['date'])) {
        $err = 'Format tanggal tidak valid.';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $old['time'])) {
        $err = 'Format jam mulai tidak valid.';
    } else {
        $startTs = strtotime($old['date'] . ' ' . $old['time'] . ':00');
        if (!$startTs) $err = 'Tanggal/jam mulai tidak valid.';
    }
    $duration = (int)$old['duration'];
    if (!$err && ($duration < 5 || $duration > 720)) $err = 'Estimasi durasi harus 5–720 menit.';
    if (!$err && mb_strlen($old['location']) > 255) $err = 'Lokasi maksimal 255 karakter.';

    $onlineUrl = null;
    if (!$err && $old['online_url'] !== '') {
        $onlineUrl = meetingValidateHttpUrl($old['online_url']);
        if ($onlineUrl === null) $err = 'Link online harus URL http/https yang valid (maks 500 karakter).';
    }

    if ($err) {
        $message = $err;
        $messageType = 'danger';
    } else {
        $agenda = sanitizeRichText(prepareRichTextForStorage($old['agenda']));
        $startAt = date('Y-m-d H:i:s', $startTs);
        $endAt = date('Y-m-d H:i:s', $startTs + ($duration * 60));
        $up = pg_query_params(
            $conn,
            "UPDATE meetings SET title=$1, agenda=$2, start_at=$3, end_at=$4, location=$5, online_url=$6, updated_at=NOW() WHERE id=$7",
            [meetingCleanText($old['title'], 255), $agenda, $startAt, $endAt, meetingCleanText($old['location'], 255), $onlineUrl, $id]
        );
        if ($up) {
            logActivity($conn, $user['id'], 'meeting_update', 'Rapat ' . $meeting['meeting_code'] . ' diperbarui');
            pg_close($conn);
            $_SESSION['flash_ok'] = 'Rapat berhasil diperbarui.';
            header('Location: view_meeting.php?id=' . $id);
            exit;
        }
        $message = 'Gagal menyimpan perubahan: ' . pg_last_error($conn);
        $messageType = 'danger';
    }
}

require_once 'includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <p class="text-secondary small mb-1"><?php echo e($meeting['meeting_code']); ?></p>
        <h2 class="h4 mb-1">Edit Rapat</h2>
        <p class="text-secondary mb-0">Perubahan langsung berlaku pada halaman undangan dan QR yang sama.</p>
    </div>
    <a href="view_meeting.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm flex-shrink-0">← Detail Rapat</a>
</div>

<?php if ($message): ?><div class="alert alert-<?php echo e($messageType); ?>"><?php echo e($message); ?></div><?php endif; ?>

<form method="POST" id="meetingForm" novalidate>
    <?php echo csrf_field(); ?>
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
                <div class="mb-3">
                    <label for="title" class="form-label">Judul Rapat <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title" name="title" required maxlength="255" value="<?php echo e($old['title']); ?>">
                </div>
                <div class="mb-0">
                    <label for="agenda" class="form-label">Agenda / Deskripsi Undangan</label>
                    <textarea id="agenda" class="form-control" name="agenda" rows="8"><?php echo e($old['agenda']); ?></textarea>
                </div>
            </div></div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3"><div class="card-body">
                <h3 class="h6 mb-3">Jadwal &amp; Lokasi</h3>
                <div class="mb-3">
                    <label for="date" class="form-label">Tanggal <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="date" name="date" required value="<?php echo e($old['date']); ?>">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label for="time" class="form-label">Jam Mulai <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" id="time" name="time" required value="<?php echo e($old['time']); ?>">
                    </div>
                    <div class="col-6">
                        <label for="duration" class="form-label">Estimasi (menit) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="duration" name="duration" required min="5" max="720" value="<?php echo e($old['duration']); ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="location" class="form-label">Lokasi / Ruangan <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="location" name="location" required maxlength="255" value="<?php echo e($old['location']); ?>">
                </div>
                <div class="mb-0">
                    <label for="online_url" class="form-label">Link Online <span class="text-secondary fw-normal">(opsional)</span></label>
                    <input type="url" class="form-control" id="online_url" name="online_url" maxlength="500" value="<?php echo e($old['online_url']); ?>">
                </div>
            </div></div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                <a href="view_meeting.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Batal</a>
            </div>
        </div>
    </div>
</form>

<?php if (($user['role'] ?? '') === 'admin'): ?>
<div class="card border-danger mt-4"><div class="card-body">
    <h3 class="h6 text-danger">Hapus Rapat</h3>
    <p class="text-secondary small mb-2">Menghapus rapat juga menghapus seluruh data peserta dan notulensi. Tindakan ini tidak bisa dibatalkan. Jika hanya ingin menutup undangan, gunakan tombol Batalkan di halaman detail.</p>
    <form method="POST" action="meeting_action.php" onsubmit="return confirm('Hapus rapat <?php echo e($meeting['meeting_code']); ?> beserta seluruh peserta dan notulensinya?')">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-outline-danger btn-sm">Hapus Permanen</button>
    </form>
</div></div>
<?php endif; ?>

<script src="assets/vendor/toastui/toastui-editor-all.min.js"></script>
<script src="assets/js/helpdesk-markdown-editor.js"></script>
<script>
(function () {
    var agenda = document.getElementById('agenda');
    if (window.createHelpdeskEditor && agenda) {
        window.createHelpdeskEditor(agenda).catch(function () {});
    }
})();
</script>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
