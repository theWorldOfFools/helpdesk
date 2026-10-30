<?php
// Halaman cetak undangan + QR rapat — host/admin.
require_once 'config.php';
require_once 'includes/meeting_auth.php';
$user = requireRole(['admin', 'teknisi']);

$id = (int)($_GET['id'] ?? 0);
$conn = getDBConnection();
$r = pg_query_params(
    $conn,
    "SELECT m.*, h.name AS host_name FROM meetings m LEFT JOIN users h ON h.id = m.host_id WHERE m.id = $1",
    [$id]
);
$meeting = ($r && pg_num_rows($r) > 0) ? pg_fetch_assoc($r) : null;
if ($r) pg_free_result($r);
if (!$meeting || !meetingCanManage($meeting, $user)) {
    pg_close($conn);
    http_response_code(404);
    echo 'Rapat tidak ditemukan atau Anda tidak berhak mencetaknya.';
    exit;
}
$shareEnabled = meetingBool($meeting['share_enabled']);
$shareUrl = meetingShareUrl($meeting);
$duration = meetingDurationMinutes($meeting);
pg_close($conn);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Cetak Undangan · <?php echo e($meeting['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #eef2f7; }
        .print-toolbar { position: sticky; top: 0; z-index: 10; }
        .invite-sheet {
            width: 210mm;
            min-height: 148mm;
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 18px 50px rgba(15, 23, 42, .16);
            overflow: hidden;
        }
        .invite-head { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%); }
        .qr-box [data-meeting-qr] canvas,
        .qr-box [data-meeting-qr] img { width: 100% !important; height: auto !important; display: block; }
        @media print {
            @page { size: A5 landscape; margin: 8mm; }
            body { background: #fff !important; }
            .print-toolbar, .no-print { display: none !important; }
            .invite-sheet { width: 100%; min-height: auto; box-shadow: none; border-radius: 0; }
        }
    </style>
</head>
<body>
<div class="print-toolbar bg-white border-bottom py-2 mb-4 no-print">
    <div class="container d-flex justify-content-between align-items-center gap-2">
        <a href="view_meeting.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Cetak / Simpan PDF</button>
    </div>
</div>

<main class="container pb-5">
    <?php if (!$shareEnabled || $shareUrl === ''): ?>
        <div class="alert alert-warning no-print">Link undangan sedang nonaktif. Aktifkan link terlebih dahulu agar QR bisa dicetak.</div>
    <?php endif; ?>

    <div class="invite-sheet">
        <div class="invite-head text-white p-4 p-lg-5">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div>
                    <div class="text-white-50 small text-uppercase mb-2" style="letter-spacing:.12em;">Undangan Rapat Digital</div>
                    <h1 class="h3 mb-1"><?php echo e($meeting['title']); ?></h1>
                    <div class="text-white-50 small"><?php echo e($meeting['meeting_code']); ?></div>
                </div>
                <span class="badge text-bg-light">Helpdesk IT</span>
            </div>
        </div>
        <div class="p-4 p-lg-5">
            <div class="row g-4 align-items-center">
                <div class="col-md-7">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th class="text-secondary fw-normal" style="width:120px;">Tanggal</th><td class="fw-semibold"><?php echo date('d M Y', strtotime($meeting['start_at'])); ?></td></tr>
                        <tr><th class="text-secondary fw-normal">Waktu</th><td class="fw-semibold"><?php echo date('H:i', strtotime($meeting['start_at'])); ?>–<?php echo date('H:i', strtotime($meeting['end_at'])); ?> <span class="text-secondary fw-normal small">(<?php echo e(meetingFormatDuration($duration)); ?>)</span></td></tr>
                        <tr><th class="text-secondary fw-normal">Lokasi</th><td class="fw-semibold"><?php echo e($meeting['location']); ?></td></tr>
                        <tr><th class="text-secondary fw-normal">Host</th><td class="fw-semibold"><?php echo e($meeting['host_name'] ?? '—'); ?></td></tr>
                        <?php if (!empty($meeting['online_url'])): ?>
                        <tr><th class="text-secondary fw-normal">Online</th><td><a href="<?php echo e($meeting['online_url']); ?>" target="_blank" rel="noopener"><?php echo e($meeting['online_url']); ?></a></td></tr>
                        <?php endif; ?>
                    </table>
                    <?php if ($meeting['agenda'] !== ''): ?>
                    <hr>
                    <div class="rich-content small"><?php echo renderTicketDescription($meeting['agenda']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-5 text-center">
                    <?php if ($shareEnabled && $shareUrl !== ''): ?>
                    <div class="qr-box border rounded-4 p-2 d-inline-block bg-white" style="width:240px;max-width:100%;" data-meeting-qr-wrap>
                        <div data-meeting-qr data-url="<?php echo e($shareUrl); ?>" style="width:100%;aspect-ratio:1/1;"></div>
                    </div>
                    <div class="small fw-semibold mt-3">Scan untuk konfirmasi kehadiran</div>
                    <div class="text-secondary" style="font-size:.7rem;word-break:break-all;"><?php echo e($shareUrl); ?></div>
                    <?php else: ?>
                    <div class="alert alert-secondary small mb-0">QR tidak tersedia karena link undangan nonaktif.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="border-top px-4 px-lg-5 py-3 small text-secondary d-flex justify-content-between gap-2 flex-wrap">
            <span>Mohon hadir tepat waktu dan isi daftar hadir melalui QR.</span>
            <span>Dibuat oleh <?php echo e($meeting['host_name'] ?? 'Helpdesk IT'); ?></span>
        </div>
    </div>
</main>

<script src="assets/vendor/qrcodejs/qrcode.min.js"></script>
<script src="assets/js/meeting-qr.js"></script>
</body>
</html>
