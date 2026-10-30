<?php
// Buat undangan rapat digital — admin & teknisi.
require_once 'config.php';
require_once 'includes/meeting_auth.php';
$user = requireRole(['admin', 'teknisi']);
$pageTitle = 'Buat Rapat';

$message = '';
$messageType = '';
$old = [
    'title' => '',
    'agenda' => '',
    'date' => date('Y-m-d'),
    'time' => date('H:i', strtotime('+1 hour')),
    'duration' => '60',
    'location' => '',
    'online_url' => '',
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
    if (!$err && ($duration < 5 || $duration > 720)) {
        $err = 'Estimasi durasi harus 5–720 menit.';
    }
    if (!$err && mb_strlen($old['location']) > 255) {
        $err = 'Lokasi maksimal 255 karakter.';
    }

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
        if ($agenda === '' && trim(strip_tags($old['agenda'])) !== '') {
            $agenda = '';
        }
        $endAt = date('Y-m-d H:i:s', $startTs + ($duration * 60));
        $startAt = date('Y-m-d H:i:s', $startTs);
        $token = bin2hex(random_bytes(16));

        $conn = getDBConnection();
        pg_query($conn, 'BEGIN');
        pg_query($conn, 'LOCK TABLE meetings IN SHARE ROW EXCLUSIVE MODE');
        $code = generateMeetingCode($conn);
        $ins = pg_query_params(
            $conn,
            "INSERT INTO meetings (meeting_code, title, agenda, host_id, start_at, end_at, location, online_url, status, share_token)
             VALUES ($1,$2,$3,$4,$5,$6,$7,$8,'scheduled',$9) RETURNING id",
            [$code, meetingCleanText($old['title'], 255), $agenda, (int)$user['id'], $startAt, $endAt, meetingCleanText($old['location'], 255), $onlineUrl, $token]
        );
        if ($ins && pg_num_rows($ins) > 0) {
            $meetingId = (int)pg_fetch_result($ins, 0, 0);
            pg_free_result($ins);
            pg_query($conn, 'COMMIT');
            logActivity($conn, $user['id'], 'meeting_create', 'Rapat ' . $code . ' dibuat: ' . meetingCleanText($old['title'], 255));
            pg_close($conn);
            $_SESSION['flash_ok'] = 'Rapat ' . $code . ' berhasil dibuat. QR undangan siap dibagikan.';
            header('Location: view_meeting.php?id=' . $meetingId);
            exit;
        }
        pg_query($conn, 'ROLLBACK');
        $message = 'Gagal membuat rapat: ' . pg_last_error($conn);
        $messageType = 'danger';
        pg_close($conn);
    }
}

require_once 'includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Buat Undangan Rapat</h2>
        <p class="text-secondary mb-0">Setelah disimpan, sistem membuat QR dan link undangan yang bisa dibagikan.</p>
    </div>
    <a href="meetings.php" class="btn btn-outline-secondary btn-sm flex-shrink-0">← Daftar Rapat</a>
</div>

<?php if ($message): ?><div class="alert alert-<?php echo e($messageType); ?>"><?php echo e($message); ?></div><?php endif; ?>

<form method="POST" id="meetingForm" novalidate>
    <?php echo csrf_field(); ?>
    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
                <div class="mb-3">
                    <label for="title" class="form-label">Judul Rapat <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title" name="title" required maxlength="255" placeholder="cth: Koordinasi Implementasi SIMRS Bulan November" value="<?php echo e($old['title']); ?>">
                </div>
                <div class="mb-0">
                    <label for="agenda" class="form-label">Agenda / Deskripsi Undangan</label>
                    <textarea id="agenda" class="form-control" name="agenda" rows="8" placeholder="Agenda, tujuan rapat, peserta yang diundang, catatan persiapan…"><?php echo e($old['agenda']); ?></textarea>
                    <div class="form-text">Mendukung format bold, list, link, dan gambar. Agenda tampil di halaman undangan publik.</div>
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
                    <input type="text" class="form-control" id="location" name="location" required maxlength="255" placeholder="cth: Ruang Meeting Lt. 3" value="<?php echo e($old['location']); ?>">
                </div>
                <div class="mb-0">
                    <label for="online_url" class="form-label">Link Online <span class="text-secondary fw-normal">(opsional)</span></label>
                    <input type="url" class="form-control" id="online_url" name="online_url" maxlength="500" placeholder="https://zoom.us/…" value="<?php echo e($old['online_url']); ?>">
                    <div class="form-text">Untuk rapat hybrid/online. Harus diawali http:// atau https://.</div>
                </div>
            </div></div>

            <div class="card mb-3"><div class="card-body">
                <h3 class="h6 mb-2">Setelah Dibuat</h3>
                <ul class="small text-secondary mb-0 ps-3">
                    <li>QR undangan otomatis dibuat.</li>
                    <li>Link bisa disalin, dibagikan, atau dicetak.</li>
                    <li>Peserta mengisi form tanpa login.</li>
                    <li>Peserta terdaftar mendapat link notulensi pribadi.</li>
                </ul>
            </div></div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Simpan &amp; Buat QR</button>
                <a href="meetings.php" class="btn btn-outline-secondary">Batal</a>
            </div>
        </div>
    </div>
</form>

<script src="assets/vendor/toastui/toastui-editor-all.min.js"></script>
<script src="assets/js/helpdesk-markdown-editor.js"></script>
<script>
(function () {
    var agenda = document.getElementById('agenda');
    if (window.createHelpdeskEditor && agenda) {
        window.createHelpdeskEditor(agenda).catch(function () {});
    }
    var form = document.getElementById('meetingForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!document.getElementById('title').value.trim()
                || !document.getElementById('date').value
                || !document.getElementById('time').value
                || !document.getElementById('location').value.trim()) {
                e.preventDefault();
                alert('Judul, tanggal, jam mulai, dan lokasi wajib diisi.');
                return;
            }
            var d = parseInt(document.getElementById('duration').value || '0', 10);
            if (!d || d < 5 || d > 720) {
                e.preventDefault();
                alert('Estimasi durasi harus 5–720 menit.');
            }
        });
    }
})();
</script>

<?php require_once 'includes/footer.php'; ?>
