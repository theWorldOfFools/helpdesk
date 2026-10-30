<?php
// Detail rapat: QR undangan, absensi peserta, dan notulensi kolaboratif.
require_once 'config.php';
require_once 'includes/meeting_auth.php';
$user = requireRole(['admin', 'teknisi']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: meetings.php');
    exit;
}

$conn = getDBConnection();
$mr = pg_query_params(
    $conn,
    "SELECT m.*, h.name AS host_name, h.username AS host_username, h.division AS host_division
     FROM meetings m LEFT JOIN users h ON h.id = m.host_id WHERE m.id = $1",
    [$id]
);
$meeting = ($mr && pg_num_rows($mr) > 0) ? pg_fetch_assoc($mr) : null;
if ($mr) pg_free_result($mr);
if (!$meeting) {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Rapat tidak ditemukan.';
    header('Location: meetings.php');
    exit;
}

$manage = meetingCanManage($meeting, $user);
$meta = meetingStateMeta($meeting);
$shareUrl = meetingShareUrl($meeting);
$shareEnabled = meetingBool($meeting['share_enabled']);
$registrationOpen = meetingBool($meeting['registration_open']);
$notesLocked = meetingBool($meeting['notes_locked']);
$duration = meetingDurationMinutes($meeting);

$attendeeSearch = trim($_GET['q'] ?? '');
$attendees = [];
$stats = ['total' => 0, 'registered' => 0, 'present' => 0, 'absent' => 0];
$notes = [];
if ($manage) {
    $stats = meetingAttendanceStats($conn, $id);
    $attendees = meetingGetAttendees($conn, $id, $attendeeSearch, 500);
    $notes = meetingGetNotes($conn, $id);
}

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$pageTitle = 'Detail Rapat';
require_once 'includes/header.php';
?>

<?php if ($flashOk): ?><div class="alert alert-success py-2"><?php echo e($flashOk); ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger py-2"><?php echo e($flashErr); ?></div><?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <p class="text-secondary fw-semibold small mb-1"><?php echo e($meeting['meeting_code']); ?></p>
        <h2 class="h4 mb-1"><?php echo e($meeting['title']); ?></h2>
        <p class="text-secondary small mb-0">
            <i class="bi bi-person-circle me-1"></i>Host: <?php echo e($meeting['host_name'] ?? '—'); ?>
            · <i class="bi bi-calendar-event me-1"></i><?php echo date('d M Y', strtotime($meeting['start_at'])); ?>
            · <i class="bi bi-clock me-1"></i><?php echo date('H:i', strtotime($meeting['start_at'])); ?>–<?php echo date('H:i', strtotime($meeting['end_at'])); ?>
            · <?php echo e(meetingFormatDuration($duration)); ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
        <span class="badge <?php echo e($meta['badge']); ?> fs-6"><i class="bi <?php echo e($meta['icon']); ?> me-1"></i><?php echo e($meta['label']); ?></span>
        <a href="meetings.php" class="btn btn-outline-secondary btn-sm">← Daftar Rapat</a>
        <?php if ($manage): ?>
        <a href="edit_meeting.php?id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
        <a href="meeting_print.php?id=<?php echo $id; ?>" class="btn btn-outline-dark btn-sm" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Cetak</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 align-items-start">
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="small text-secondary">Tanggal</div>
                    <div class="fw-semibold"><?php echo date('d M Y', strtotime($meeting['start_at'])); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-secondary">Waktu &amp; Estimasi</div>
                    <div class="fw-semibold"><?php echo date('H:i', strtotime($meeting['start_at'])); ?>–<?php echo date('H:i', strtotime($meeting['end_at'])); ?> <span class="text-secondary fw-normal small">(<?php echo e(meetingFormatDuration($duration)); ?>)</span></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-secondary">Lokasi</div>
                    <div class="fw-semibold"><?php echo e($meeting['location']); ?><?php if (!empty($meeting['online_url'])): ?> <span class="badge text-bg-info">Online</span><?php endif; ?></div>
                </div>
            </div>
            <?php if (!empty($meeting['online_url'])): ?>
            <p class="mb-3"><a href="<?php echo e($meeting['online_url']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-camera-video"></i> Buka Link Online</a></p>
            <?php endif; ?>
            <h3 class="h6">Agenda / Deskripsi</h3>
            <div class="rich-content">
                <?php echo $meeting['agenda'] !== '' ? renderTicketDescription($meeting['agenda']) : '<span style="color:#999;">Belum ada agenda.</span>'; ?>
            </div>
        </div></div>

        <?php if ($manage): ?>
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-3">
                <div>
                    <h3 class="h6 mb-1"><i class="bi bi-qr-code"></i> QR Undangan</h3>
                    <p class="text-secondary small mb-0">Bagikan QR atau link ini ke peserta. Peserta mengisi form tanpa login.</p>
                </div>
                <span class="badge <?php echo $shareEnabled ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $shareEnabled ? 'Link Aktif' : 'Link Nonaktif'; ?></span>
            </div>

            <?php if ($shareEnabled && $shareUrl !== ''): ?>
            <div class="row g-3 align-items-center">
                <div class="col-auto">
                    <div class="meeting-qr-box bg-white border rounded-3 p-2" data-meeting-qr-wrap>
                        <div data-meeting-qr data-url="<?php echo e($shareUrl); ?>" style="width:220px;height:220px;"></div>
                    </div>
                </div>
                <div class="col" style="min-width:240px;">
                    <label for="meetingShareUrl" class="form-label small fw-semibold">Link Undangan</label>
                    <input type="text" id="meetingShareUrl" class="form-control form-control-sm" readonly value="<?php echo e($shareUrl); ?>">
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-primary" data-meeting-copy><i class="bi bi-clipboard"></i> Salin Link</button>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-meeting-share data-title="<?php echo e($meeting['title']); ?>" data-url="<?php echo e($shareUrl); ?>"><i class="bi bi-share"></i> Bagikan</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-meeting-qr-download data-filename="<?php echo e($meeting['meeting_code']); ?>-qr"><i class="bi bi-download"></i> Download QR</button>
                        <a href="https://wa.me/?text=<?php echo rawurlencode('Undangan rapat: ' . $meeting['title'] . ' — ' . $shareUrl); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success"><i class="bi bi-whatsapp"></i> WhatsApp</a>
                    </div>
                    <div class="form-text">QR ini mengarah ke halaman undangan + form peserta.</div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary py-2 mb-0">Link undangan sedang nonaktif. Aktifkan kembali dari panel aksi di samping.</div>
            <?php endif; ?>
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                <div>
                    <h3 class="h6 mb-1"><i class="bi bi-people"></i> Absensi Peserta</h3>
                    <p class="text-secondary small mb-0">Total <?php echo (int)$stats['total']; ?> terdaftar · <?php echo (int)$stats['present']; ?> hadir · <?php echo (int)$stats['absent']; ?> tidak hadir</p>
                </div>
                <a href="meeting_export.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-csv"></i> Export CSV</a>
            </div>

            <form method="GET" class="row g-2 mb-3">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <div class="col">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" name="q" placeholder="Cari nama / instalasi / bagian / ruangan…" value="<?php echo e($attendeeSearch); ?>">
                    </div>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm">Cari</button>
                    <?php if ($attendeeSearch !== ''): ?><a href="view_meeting.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm">Reset</a><?php endif; ?>
                </div>
            </form>

            <?php if (empty($attendees)): ?>
                <p class="text-center text-secondary py-4 mb-0">Belum ada peserta<?php echo $attendeeSearch !== '' ? ' yang cocok dengan pencarian' : ''; ?>.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nama</th>
                            <th>Instalasi / Bagian / Ruangan</th>
                            <th>Jabatan / HP</th>
                            <th>Terdaftar</th>
                            <th>Status</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($attendees as $a): ?>
                        <?php $st = meetingAttendeeStatusLabel($a['attendance_status']); ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($a['name']); ?></td>
                            <td class="small">
                                <div><?php echo e($a['installation']); ?></div>
                                <div class="text-secondary"><?php echo e($a['department']); ?> · <?php echo e($a['room']); ?></div>
                            </td>
                            <td class="small">
                                <div><?php echo e($a['position'] ?: '—'); ?></div>
                                <div class="text-secondary"><?php echo e($a['phone'] ?: '—'); ?></div>
                            </td>
                            <td class="small text-secondary"><?php echo date('d M H:i', strtotime($a['registered_at'])); ?></td>
                            <td><span class="badge <?php echo e($st['badge']); ?>"><?php echo e($st['label']); ?></span></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1 align-items-center">
                                    <form method="POST" action="meeting_action.php" class="d-inline-flex gap-1">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="action" value="attendee_status">
                                        <input type="hidden" name="attendee_id" value="<?php echo (int)$a['id']; ?>">
                                        <select name="status" class="form-select form-select-sm" style="width:auto;" aria-label="Status kehadiran <?php echo e($a['name']); ?>">
                                            <?php foreach (meetingAttendeeStatuses() as $s): ?>
                                            <option value="<?php echo e($s); ?>" <?php echo $a['attendance_status'] === $s ? 'selected' : ''; ?>><?php echo e(meetingAttendeeStatusLabel($s)['label']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Simpan status">Set</button>
                                    </form>
                                    <form method="POST" action="meeting_action.php" data-confirm="<?php echo e('Hapus peserta ' . $a['name'] . '? Catatan yang pernah dibuat tetap tersimpan sebagai arsip.'); ?>" onsubmit="return confirm(this.dataset.confirm)">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="action" value="attendee_delete">
                                        <input type="hidden" name="attendee_id" value="<?php echo (int)$a['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus peserta"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div></div>

        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                <div>
                    <h3 class="h6 mb-1"><i class="bi bi-journal-text"></i> Notulensi Rapat</h3>
                    <p class="text-secondary small mb-0"><?php echo count($notes); ?> catatan dari host dan peserta.</p>
                </div>
                <span class="badge <?php echo $notesLocked ? 'text-bg-secondary' : 'text-bg-success'; ?>"><?php echo $notesLocked ? 'Notulensi Dikunci' : 'Notulensi Terbuka'; ?></span>
            </div>

            <?php if (empty($notes)): ?>
                <p class="text-secondary small">Belum ada catatan. Host dapat menulis notulensi pertama di bawah ini.</p>
            <?php else: ?>
            <div class="d-flex flex-column gap-3 mb-4">
                <?php foreach ($notes as $n): ?>
                <?php $ab = meetingNoteAuthorBadge($n['author_type']); ?>
                <div class="border rounded-3 p-3 bg-body-tertiary">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-1">
                        <div class="small">
                            <span class="fw-semibold"><?php echo e($n['author_name']); ?></span>
                            <span class="badge <?php echo e($ab['badge']); ?> ms-1"><?php echo e($ab['label']); ?></span>
                            <span class="text-secondary ms-1"><?php echo date('d M Y H:i', strtotime($n['created_at'])); ?></span>
                        </div>
                        <form method="POST" action="meeting_action.php" onsubmit="return confirm('Hapus catatan ini?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <input type="hidden" name="action" value="note_delete">
                            <input type="hidden" name="note_id" value="<?php echo (int)$n['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Hapus catatan"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                    <div class="rich-content"><?php echo renderTicketDescription($n['body']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="meeting_action.php" id="hostNoteForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" name="action" value="note_add">
                <label for="noteBody" class="form-label">Tambah Catatan / Notulensi</label>
                <textarea id="noteBody" name="body" class="form-control" rows="7" placeholder="Poin pembahasan, keputusan, tindak lanjut…"></textarea>
                <div class="d-flex gap-2 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Simpan Catatan</button>
                    <span class="form-text mb-0">Catatan host bisa memuat gambar.</span>
                </div>
            </form>
        </div></div>
        <?php else: ?>
        <div class="card"><div class="card-body">
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-1"></i> Anda dapat melihat detail rapat. QR undangan, daftar absensi, dan notulensi hanya dapat diakses oleh host pembuat rapat atau admin.
            </div>
        </div></div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <h3 class="h6 mb-3">Status Rapat</h3>
            <dl class="row small mb-0">
                <dt class="col-5 text-secondary fw-normal">Status</dt><dd class="col-7"><span class="badge <?php echo e($meta['badge']); ?>"><?php echo e($meta['label']); ?></span></dd>
                <dt class="col-5 text-secondary fw-normal">Registrasi</dt><dd class="col-7"><span class="badge <?php echo $registrationOpen ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $registrationOpen ? 'Terbuka' : 'Ditutup'; ?></span></dd>
                <dt class="col-5 text-secondary fw-normal">Notulensi</dt><dd class="col-7"><span class="badge <?php echo $notesLocked ? 'text-bg-secondary' : 'text-bg-success'; ?>"><?php echo $notesLocked ? 'Dikunci' : 'Terbuka'; ?></span></dd>
                <dt class="col-5 text-secondary fw-normal">Link Undangan</dt><dd class="col-7"><span class="badge <?php echo $shareEnabled ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $shareEnabled ? 'Aktif' : 'Nonaktif'; ?></span></dd>
                <?php if ($manage): ?>
                <dt class="col-5 text-secondary fw-normal">Peserta</dt><dd class="col-7"><?php echo (int)$stats['total']; ?> terdaftar</dd>
                <?php endif; ?>
            </dl>
        </div></div>

        <?php if ($manage): ?>
        <div class="card mb-3"><div class="card-body">
            <h3 class="h6 mb-3">Kelola Rapat</h3>
            <div class="d-flex flex-column gap-2">
                <?php if ($meeting['status'] === 'scheduled'): ?>
                <form method="POST" action="meeting_action.php" onsubmit="return confirm('Tandai rapat ini sebagai selesai?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="status_complete">
                    <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-check2-circle"></i> Tandai Selesai</button>
                </form>
                <form method="POST" action="meeting_action.php" onsubmit="return confirm('Batalkan rapat ini? Link undangan dan registrasi akan ditutup.')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="status_cancel">
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-x-circle"></i> Batalkan Rapat</button>
                </form>
                <?php else: ?>
                <form method="POST" action="meeting_action.php" onsubmit="return confirm('Buka kembali rapat ini sebagai rapat aktif?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="status_reopen">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-arrow-counterclockwise"></i> Buka Kembali</button>
                </form>
                <?php endif; ?>

                <form method="POST" action="meeting_action.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="<?php echo $registrationOpen ? 'registration_close' : 'registration_open'; ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-person-check"></i> <?php echo $registrationOpen ? 'Tutup Registrasi' : 'Buka Registrasi'; ?></button>
                </form>

                <form method="POST" action="meeting_action.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="<?php echo $notesLocked ? 'notes_unlock' : 'notes_lock'; ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-lock"></i> <?php echo $notesLocked ? 'Buka Kunci Notulensi' : 'Kunci Notulensi Peserta'; ?></button>
                </form>

                <form method="POST" action="meeting_action.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="<?php echo $shareEnabled ? 'share_disable' : 'share_enable'; ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-link-45deg"></i> <?php echo $shareEnabled ? 'Nonaktifkan Link' : 'Aktifkan Link'; ?></button>
                </form>

                <form method="POST" action="meeting_action.php" onsubmit="return confirm('Acak token undangan? QR dan link lama tidak akan berlaku lagi.')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="token_rotate">
                    <button type="submit" class="btn btn-outline-warning btn-sm w-100"><i class="bi bi-arrow-repeat"></i> Acak Token / QR Baru</button>
                </form>
            </div>
        </div></div>
        <?php endif; ?>

        <div class="card"><div class="card-body small text-secondary">
            <div class="mb-1"><i class="bi bi-hash me-1"></i><?php echo e($meeting['meeting_code']); ?></div>
            <div class="mb-1"><i class="bi bi-person me-1"></i><?php echo e($meeting['host_name'] ?? '—'); ?></div>
            <div class="mb-1"><i class="bi bi-geo-alt me-1"></i><?php echo e($meeting['location']); ?></div>
            <div><i class="bi bi-clock-history me-1"></i>Dibuat <?php echo date('d M Y H:i', strtotime($meeting['created_at'])); ?></div>
        </div></div>
    </div>
</div>

<?php if ($manage && $shareEnabled && $shareUrl !== ''): ?>
<style>
.meeting-qr-box [data-meeting-qr] canvas,
.meeting-qr-box [data-meeting-qr] img { width: 100% !important; height: auto !important; display: block; }
</style>
<script src="assets/vendor/qrcodejs/qrcode.min.js"></script>
<script src="assets/js/meeting-qr.js"></script>
<?php endif; ?>

<?php if ($manage): ?>
<script src="assets/vendor/toastui/toastui-editor-all.min.js"></script>
<script src="assets/js/helpdesk-markdown-editor.js"></script>
<script>
(function () {
    var note = document.getElementById('noteBody');
    if (window.createHelpdeskEditor && note) {
        window.createHelpdeskEditor(note).catch(function () {});
    }
    var form = document.getElementById('hostNoteForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!document.getElementById('noteBody').value.trim()) {
                e.preventDefault();
                alert('Catatan tidak boleh kosong.');
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
