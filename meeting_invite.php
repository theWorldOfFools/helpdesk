<?php
// Halaman undangan rapat publik (tanpa login) + form absensi peserta via token QR.
require_once 'config.php';
require_once 'includes/meeting_auth.php';

header('X-Robots-Tag: noindex, nofollow');

$token = trim((string)($_GET['t'] ?? $_POST['token'] ?? ''));
$newForm = (($_GET['new'] ?? '') === '1');
$conn = getDBConnection();
$meeting = meetingFetchMeetingByToken($conn, $token, true);
if (!$meeting) {
    pg_close($conn);
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Undangan tidak ditemukan</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="bg-light">
    <main class="container py-5" style="max-width:640px;">
        <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center">
            <i class="bi bi-qr-code-scan fs-1 text-secondary"></i>
            <h1 class="h5 mt-2 mb-1">Undangan tidak ditemukan</h1>
            <p class="text-secondary mb-0">QR/link undangan tidak valid, sudah diacak, atau dinonaktifkan oleh host.</p>
        </div></div>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$meetingId = (int)$meeting['id'];
$state = meetingStateMeta($meeting);
$registrationOpen = meetingIsRegistrationOpen($meeting);
$duration = meetingDurationMinutes($meeting);

$sessionTokens = $_SESSION['meeting_attendee_tokens'] ?? [];
$registeredToken = (string)($sessionTokens[$meetingId] ?? '');
$registeredAttendee = null;
if ($registeredToken !== '') {
    $candidate = meetingFetchAttendeeByToken($conn, $registeredToken);
    if ($candidate && (int)$candidate['meeting_id'] === $meetingId) {
        $registeredAttendee = $candidate;
    } else {
        unset($_SESSION['meeting_attendee_tokens'][$meetingId]);
    }
}
if ($newForm) $registeredAttendee = null;

$old = ['name' => '', 'installation' => '', 'department' => '', 'room' => '', 'position' => '', 'phone' => ''];
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name'] = trim((string)($_POST['name'] ?? ''));
    $old['installation'] = trim((string)($_POST['installation'] ?? ''));
    $old['department'] = trim((string)($_POST['department'] ?? ''));
    $old['room'] = trim((string)($_POST['room'] ?? ''));
    $old['position'] = trim((string)($_POST['position'] ?? ''));
    $old['phone'] = trim((string)($_POST['phone'] ?? ''));
    $honeypot = trim((string)($_POST['website'] ?? ''));

    if (!meetingIsRegistrationOpen($meeting)) {
        $error = 'Registrasi untuk rapat ini sudah ditutup.';
    } elseif (!verifyCsrf()) {
        $error = 'Sesi kedaluwarsa / token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif ($honeypot !== '') {
        $error = 'Permintaan tidak valid.';
    } else {
        $name = meetingCleanText($old['name'], 150);
        $installation = meetingCleanText($old['installation'], 150);
        $department = meetingCleanText($old['department'], 150);
        $room = meetingCleanText($old['room'], 150);
        $position = meetingCleanText($old['position'], 150);
        $phone = meetingCleanText($old['phone'], 30);

        if ($name === '' || $installation === '' || $department === '' || $room === '') {
            $error = 'Nama, instalasi, bagian, dan ruangan wajib diisi.';
        } elseif ($phone !== '' && !preg_match('/^[0-9+\-().\s]{6,30}$/', $phone)) {
            $error = 'Nomor HP tidak valid. Gunakan angka, spasi, +, -, atau tanda kurung.';
        } else {
            $last = (int)($_SESSION['meeting_join_last'][$meetingId] ?? 0);
            if ($last > 0 && (time() - $last) < 3) {
                $error = 'Terlalu cepat. Tunggu beberapa detik lalu coba lagi.';
            } else {
                $accessToken = bin2hex(random_bytes(16));
                $ins = pg_query_params(
                    $conn,
                    "INSERT INTO meeting_attendees (meeting_id, access_token, name, installation, department, room, position, phone)
                     VALUES ($1,$2,$3,$4,$5,$6,$7,$8) RETURNING id",
                    [$meetingId, $accessToken, $name, $installation, $department, $room, $position !== '' ? $position : null, $phone !== '' ? $phone : null]
                );
                if ($ins && pg_num_rows($ins) > 0) {
                    pg_free_result($ins);
                    $_SESSION['meeting_attendee_tokens'][$meetingId] = $accessToken;
                    $_SESSION['meeting_join_last'][$meetingId] = time();
                    pg_close($conn);
                    header('Location: meeting_room.php?p=' . rawurlencode($accessToken) . '&joined=1');
                    exit;
                }
                $error = 'Gagal menyimpan data peserta. Silakan coba lagi.';
                if ($ins) pg_free_result($ins);
            }
        }
    }
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
    <title><?php echo e($meeting['title']); ?> · Undangan Rapat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .invite-hero { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%); }
        .invite-card { border: 0; box-shadow: 0 14px 40px rgba(15, 23, 42, .12); }
        .invite-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; color: #64748b; }
    </style>
</head>
<body class="bg-body-tertiary">
<main class="container py-4 py-lg-5" style="max-width:860px;">
    <div class="text-center mb-3">
        <span class="badge text-bg-dark px-3 py-2"><i class="bi bi-calendar2-event me-1"></i> Undangan Rapat Digital</span>
    </div>

    <div class="card invite-card mb-4 overflow-hidden">
        <div class="invite-hero text-white p-4 p-lg-5">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div>
                    <div class="text-white-50 small text-uppercase mb-2" style="letter-spacing:.1em;"><?php echo e($meeting['meeting_code']); ?></div>
                    <h1 class="h3 mb-2"><?php echo e($meeting['title']); ?></h1>
                    <div class="text-white-50"><i class="bi bi-person-circle me-1"></i>Host: <?php echo e($meeting['host_name'] ?? '—'); ?></div>
                </div>
                <span class="badge <?php echo e($state['badge']); ?> fs-6"><?php echo e($state['label']); ?></span>
            </div>
        </div>
        <div class="card-body p-4 p-lg-5">
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="invite-label">Tanggal</div>
                    <div class="fw-semibold"><?php echo date('d M Y', strtotime($meeting['start_at'])); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="invite-label">Waktu &amp; Estimasi</div>
                    <div class="fw-semibold"><?php echo date('H:i', strtotime($meeting['start_at'])); ?>–<?php echo date('H:i', strtotime($meeting['end_at'])); ?></div>
                    <div class="small text-secondary"><?php echo e(meetingFormatDuration($duration)); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="invite-label">Lokasi</div>
                    <div class="fw-semibold"><?php echo e($meeting['location']); ?></div>
                    <?php if (!empty($meeting['online_url'])): ?>
                    <a href="<?php echo e($meeting['online_url']); ?>" target="_blank" rel="noopener" class="small">Buka link online <i class="bi bi-box-arrow-up-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>

            <h2 class="h6">Agenda / Deskripsi</h2>
            <div class="rich-content mb-0">
                <?php echo $meeting['agenda'] !== '' ? renderTicketDescription($meeting['agenda']) : '<span class="text-secondary">Belum ada agenda.</span>'; ?>
            </div>
        </div>
    </div>

    <?php if ($meeting['status'] === 'cancelled'): ?>
        <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i>Rapat ini telah <strong>dibatalkan</strong>. Form absensi tidak menerima pendaftaran baru.</div>
    <?php elseif ($meeting['status'] === 'completed'): ?>
        <div class="alert alert-success"><i class="bi bi-check2-circle me-1"></i>Rapat ini telah <strong>selesai</strong>.</div>
    <?php endif; ?>

    <?php if ($registeredAttendee): ?>
        <div class="card invite-card mb-4"><div class="card-body p-4 p-lg-5">
            <div class="d-flex align-items-start gap-3">
                <span class="badge text-bg-success fs-5"><i class="bi bi-check2"></i></span>
                <div class="flex-grow-1">
                    <h2 class="h5 mb-1">Anda sudah terdaftar</h2>
                    <p class="text-secondary mb-3">Terdaftar sebagai <strong><?php echo e($registeredAttendee['name']); ?></strong> — <?php echo e($registeredAttendee['installation']); ?> / <?php echo e($registeredAttendee['department']); ?> / <?php echo e($registeredAttendee['room']); ?>.</p>
                    <a href="meeting_room.php?p=<?php echo rawurlencode($registeredAttendee['access_token']); ?>" class="btn btn-primary"><i class="bi bi-journal-text me-1"></i>Buka Ruang Notulensi</a>
                    <a href="meeting_invite.php?t=<?php echo rawurlencode($meeting['share_token']); ?>&amp;new=1" class="btn btn-outline-secondary ms-1"><i class="bi bi-person-plus me-1"></i>Daftarkan Peserta Lain</a>
                    <div class="form-text mt-2">Simpan link ruang notulensi ini agar bisa dibuka kembali nanti.</div>
                </div>
            </div>
        </div></div>
    <?php elseif ($registrationOpen): ?>
        <div class="card invite-card mb-4"><div class="card-body p-4 p-lg-5">
            <h2 class="h5 mb-1">Form Kehadiran Peserta</h2>
            <p class="text-secondary small mb-4">Isi data berikut untuk mengonfirmasi kehadiran. Setelah terdaftar, Anda mendapat link pribadi untuk ikut menulis notulensi rapat.</p>

            <?php if ($error !== ''): ?><div class="alert alert-danger py-2"><?php echo e($error); ?></div><?php endif; ?>

            <form method="POST" action="meeting_invite.php" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo e($meeting['share_token']); ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required maxlength="150" autocomplete="name" value="<?php echo e($old['name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="installation" class="form-label">Instalasi <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="installation" name="installation" required maxlength="150" placeholder="cth: Instalasi Rawat Inap" value="<?php echo e($old['installation']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="department" class="form-label">Bagian / Unit <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="department" name="department" required maxlength="150" placeholder="cth: Keperawatan" value="<?php echo e($old['department']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="room" class="form-label">Ruangan <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="room" name="room" required maxlength="150" placeholder="cth: Ruang Meeting Lt. 3" value="<?php echo e($old['room']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="position" class="form-label">Jabatan <span class="text-secondary fw-normal">(opsional)</span></label>
                        <input type="text" class="form-control" id="position" name="position" maxlength="150" value="<?php echo e($old['position']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="phone" class="form-label">No. HP <span class="text-secondary fw-normal">(opsional)</span></label>
                        <input type="tel" class="form-control" id="phone" name="phone" maxlength="30" inputmode="tel" autocomplete="tel" value="<?php echo e($old['phone']); ?>">
                    </div>
                </div>
                <div class="d-none" aria-hidden="true">
                    <label for="website">Website</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>
                <button type="submit" class="btn btn-primary mt-4"><i class="bi bi-check2-circle me-1"></i>Konfirmasi Kehadiran</button>
            </form>
        </div></div>
    <?php else: ?>
        <div class="alert alert-secondary"><i class="bi bi-lock me-1"></i>Registrasi peserta untuk rapat ini sedang ditutup.</div>
    <?php endif; ?>

    <p class="text-center text-secondary small mt-3 mb-0">Dibagikan dari sistem Helpdesk IT · <a href="login.php">Masuk Helpdesk</a></p>
</main>
</body>
</html>
