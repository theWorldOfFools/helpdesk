<?php
// Ruang notulensi peserta via token pribadi (tanpa login).
require_once 'config.php';
require_once 'includes/meeting_auth.php';

header('X-Robots-Tag: noindex, nofollow');

$token = trim((string)($_GET['p'] ?? $_POST['p'] ?? ''));
$conn = getDBConnection();
$attendee = meetingFetchAttendeeByToken($conn, $token);
if (!$attendee) {
    pg_close($conn);
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Link peserta tidak ditemukan</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="bg-light">
    <main class="container py-5" style="max-width:640px;">
        <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center">
            <i class="bi bi-person-x fs-1 text-secondary"></i>
            <h1 class="h5 mt-2 mb-1">Link peserta tidak ditemukan</h1>
            <p class="text-secondary mb-0">Link tidak valid, sudah dihapus host, atau bukan bagian dari rapat ini.</p>
        </div></div>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$meetingId = (int)$attendee['meeting_id'];
$meeting = [
    'id' => $meetingId,
    'meeting_code' => $attendee['meeting_code'],
    'title' => $attendee['title'],
    'agenda' => $attendee['agenda'],
    'start_at' => $attendee['start_at'],
    'end_at' => $attendee['end_at'],
    'location' => $attendee['location'],
    'online_url' => $attendee['online_url'],
    'status' => $attendee['status'],
    'notes_locked' => $attendee['notes_locked'],
    'host_name' => $attendee['host_name'],
];
$state = meetingStateMeta($meeting);
$notesWritable = meetingNotesWritable($meeting);
$notes = meetingGetNotes($conn, $meetingId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = trim((string)($_POST['act'] ?? ''));
    if (!verifyCsrf()) {
        $_SESSION['meeting_room_error'] = 'Sesi kedaluwarsa / token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif ($act === 'note_add') {
        if (!$notesWritable) {
            $_SESSION['meeting_room_error'] = 'Notulensi sedang dikunci oleh host.';
        } else {
            $body = trim((string)($_POST['body'] ?? ''));
            if ($body === '') {
                $_SESSION['meeting_room_error'] = 'Catatan tidak boleh kosong.';
            } elseif (mb_strlen($body, 'UTF-8') > 30000) {
                $_SESSION['meeting_room_error'] = 'Catatan terlalu panjang (maksimal 30.000 karakter).';
            } else {
                // Peserta publik tidak boleh mengimpor/menyisipkan gambar.
                $clean = meetingStripImages(sanitizeRichText($body));
                if (trim(strip_tags($clean)) === '') {
                    $_SESSION['meeting_room_error'] = 'Catatan tidak boleh kosong.';
                } else {
                    $ins = pg_query_params(
                        $conn,
                        "INSERT INTO meeting_notes (meeting_id, attendee_id, author_name, author_type, body) VALUES ($1,$2,$3,'participant',$4)",
                        [$meetingId, (int)$attendee['id'], (string)$attendee['name'], $clean]
                    );
                    if ($ins) {
                        $_SESSION['meeting_room_ok'] = 'Catatan berhasil ditambahkan.';
                    } else {
                        $_SESSION['meeting_room_error'] = 'Gagal menyimpan catatan. Silakan coba lagi.';
                    }
                }
            }
        }
        pg_close($conn);
        header('Location: meeting_room.php?p=' . rawurlencode($token));
        exit;
    } elseif ($act === 'note_delete') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        if (!$notesWritable) {
            $_SESSION['meeting_room_error'] = 'Notulensi sedang dikunci oleh host.';
        } elseif ($noteId <= 0) {
            $_SESSION['meeting_room_error'] = 'Catatan tidak valid.';
        } else {
            $del = pg_query_params(
                $conn,
                "DELETE FROM meeting_notes WHERE id=$1 AND meeting_id=$2 AND attendee_id=$3",
                [$noteId, $meetingId, (int)$attendee['id']]
            );
            if ($del && pg_affected_rows($del) > 0) {
                $_SESSION['meeting_room_ok'] = 'Catatan Anda dihapus.';
            } else {
                $_SESSION['meeting_room_error'] = 'Catatan tidak ditemukan atau bukan milik Anda.';
            }
        }
        pg_close($conn);
        header('Location: meeting_room.php?p=' . rawurlencode($token));
        exit;
    }
}

$flashOk = $_SESSION['meeting_room_ok'] ?? null;
$flashErr = $_SESSION['meeting_room_error'] ?? null;
unset($_SESSION['meeting_room_ok'], $_SESSION['meeting_room_error']);
if (($_GET['joined'] ?? '') === '1' && !$flashOk) {
    $flashOk = 'Pendaftaran berhasil. Anda sekarang bisa membaca dan menulis notulensi rapat.';
}

pg_close($conn);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#1a1a2e">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title>Notulensi · <?php echo e($meeting['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/vendor/toastui/toastui-editor.min.css">
    <style>
        .room-hero { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%); }
        .room-card { border: 0; box-shadow: 0 14px 40px rgba(15, 23, 42, .12); }
        .note-item { border-left: 4px solid #0d6efd; }
        .note-item.host { border-left-color: #dc3545; }
        .note-item.admin { border-left-color: #212529; }
    </style>
</head>
<body class="bg-body-tertiary">
<main class="container py-4 py-lg-5" style="max-width:900px;">
    <div class="text-center mb-3">
        <span class="badge text-bg-dark px-3 py-2"><i class="bi bi-journal-text me-1"></i> Ruang Notulensi Peserta</span>
    </div>

    <?php if ($flashOk): ?><div class="alert alert-success py-2"><?php echo e($flashOk); ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="alert alert-danger py-2"><?php echo e($flashErr); ?></div><?php endif; ?>

    <div class="card room-card mb-4 overflow-hidden">
        <div class="room-hero text-white p-4 p-lg-5">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div>
                    <div class="text-white-50 small text-uppercase mb-2" style="letter-spacing:.1em;"><?php echo e($meeting['meeting_code']); ?></div>
                    <h1 class="h4 mb-2"><?php echo e($meeting['title']); ?></h1>
                    <div class="text-white-50 small"><i class="bi bi-person-circle me-1"></i>Host: <?php echo e($meeting['host_name'] ?? '—'); ?></div>
                </div>
                <span class="badge <?php echo e($state['badge']); ?> fs-6"><?php echo e($state['label']); ?></span>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="small text-secondary">Jadwal</div>
                    <div class="fw-semibold"><?php echo date('d M Y', strtotime($meeting['start_at'])); ?></div>
                    <div class="small text-secondary"><?php echo date('H:i', strtotime($meeting['start_at'])); ?>–<?php echo date('H:i', strtotime($meeting['end_at'])); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-secondary">Lokasi</div>
                    <div class="fw-semibold"><?php echo e($meeting['location']); ?></div>
                    <?php if (!empty($meeting['online_url'])): ?><a href="<?php echo e($meeting['online_url']); ?>" target="_blank" rel="noopener" class="small">Buka link online <i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
                </div>
                <div class="col-md-4">
                    <div class="small text-secondary">Peserta</div>
                    <div class="fw-semibold"><?php echo e($attendee['name']); ?></div>
                    <div class="small text-secondary"><?php echo e($attendee['installation']); ?> · <?php echo e($attendee['department']); ?> · <?php echo e($attendee['room']); ?></div>
                </div>
            </div>
            <?php if ($meeting['agenda'] !== ''): ?>
            <hr>
            <div class="rich-content mb-0"><?php echo renderTicketDescription($meeting['agenda']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card room-card mb-4"><div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
            <div>
                <h2 class="h5 mb-1"><i class="bi bi-journal-text"></i> Notulensi Rapat</h2>
                <p class="text-secondary small mb-0"><?php echo count($notes); ?> catatan. Anda dapat membaca semua catatan dan menulis catatan baru.</p>
            </div>
            <span class="badge <?php echo $notesWritable ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $notesWritable ? 'Terbuka' : 'Dikunci Host'; ?></span>
        </div>

        <?php if (empty($notes)): ?>
            <p class="text-secondary small">Belum ada catatan. Jadilah yang pertama menulis notulensi.</p>
        <?php else: ?>
        <div class="d-flex flex-column gap-3 mb-4">
            <?php foreach ($notes as $n): ?>
            <?php $ab = meetingNoteAuthorBadge($n['author_type']); $own = (int)($n['attendee_id'] ?? 0) === (int)$attendee['id']; ?>
            <div class="note-item <?php echo e($n['author_type']); ?> border rounded-3 p-3 bg-white">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-1">
                    <div class="small">
                        <span class="fw-semibold"><?php echo e($n['author_name']); ?></span>
                        <span class="badge <?php echo e($ab['badge']); ?> ms-1"><?php echo e($ab['label']); ?></span>
                        <span class="text-secondary ms-1"><?php echo date('d M Y H:i', strtotime($n['created_at'])); ?></span>
                    </div>
                    <?php if ($own && $notesWritable): ?>
                    <form method="POST" action="meeting_room.php" onsubmit="return confirm('Hapus catatan Anda ini?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="p" value="<?php echo e($token); ?>">
                        <input type="hidden" name="act" value="note_delete">
                        <input type="hidden" name="note_id" value="<?php echo (int)$n['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Hapus catatan saya"><i class="bi bi-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="rich-content"><?php echo renderTicketDescription($n['body']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($notesWritable): ?>
        <form method="POST" action="meeting_room.php" id="participantNoteForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="p" value="<?php echo e($token); ?>">
            <input type="hidden" name="act" value="note_add">
            <label for="noteBody" class="form-label">Tambah Catatan Notulensi</label>
            <textarea id="noteBody" name="body" class="form-control" rows="6" placeholder="Tulis poin pembahasan, hasil keputusan, atau tindak lanjut…"></textarea>
            <button type="submit" class="btn btn-primary mt-2"><i class="bi bi-send me-1"></i>Kirim Catatan</button>
        </form>
        <?php else: ?>
        <div class="alert alert-secondary py-2 mb-0"><i class="bi bi-lock me-1"></i>Notulensi sudah dikunci oleh host. Anda masih bisa membaca catatan yang ada.</div>
        <?php endif; ?>
    </div></div>

    <p class="text-center text-secondary small mt-3 mb-0">Link peserta ini bersifat pribadi. Jangan bagikan ke orang lain.</p>
</main>
<script src="assets/vendor/toastui/toastui-editor-all.min.js"></script>
<script src="assets/js/helpdesk-markdown-editor.js"></script>
<script>
(function () {
    var note = document.getElementById('noteBody');
    if (window.createHelpdeskEditor && note) {
        window.createHelpdeskEditor(note, { allowImage: false }).catch(function () {});
    }
})();
</script>
</body>
</html>
