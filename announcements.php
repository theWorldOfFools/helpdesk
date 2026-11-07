<?php
// Kelola pengumuman global — admin saja.
require_once 'config.php';
require_once 'includes/announcement.php';
$admin = requireRole(['admin']);
$pageTitle = 'Pengumuman';

$message = '';
$messageType = '';
$old = [
    'id' => 0,
    'judul' => '',
    'isi' => '',
    'is_active' => true,
    'ends_at' => '',
];

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['act'] ?? '';

    if ($act === 'save') {
        $old['id'] = (int)($_POST['id'] ?? 0);
        $old['judul'] = trim($_POST['judul'] ?? '');
        $old['isi'] = trim($_POST['isi'] ?? '');
        $old['is_active'] = !empty($_POST['is_active']);
        $old['ends_at'] = trim($_POST['ends_at'] ?? '');

        $err = null;
        if ($old['judul'] === '') {
            $err = 'Judul pengumuman wajib diisi.';
        } elseif (mb_strlen($old['judul']) > 255) {
            $err = 'Judul pengumuman maksimal 255 karakter.';
        } elseif ($old['isi'] === '') {
            $err = 'Isi pengumuman wajib diisi.';
        }

        $endsAt = null;
        if (!$err && $old['ends_at'] !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['ends_at'])) {
                $err = 'Format tanggal berakhir tidak valid.';
            } else {
                $endsAt = $old['ends_at'] . ' 23:59:59';
            }
        }

        $isiClean = '';
        $imageFailures = 0;
        if (!$err) {
            $isiClean = prepareRichTextForStorage($old['isi'], $imageFailures);
            $plainRaw = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags($old['isi']), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
            $plain = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags($isiClean), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
            if ($plainRaw === '' && $imageFailures > 0) {
                $err = 'Gambar pada isi pengumuman gagal diimpor. Silakan unggah ulang gambar.';
            } elseif ($plain === '' && !preg_match('/<img\b/i', $isiClean)) {
                $err = 'Isi pengumuman tidak valid atau kosong.';
            }
        }

        if ($err) {
            $message = $err;
            $messageType = 'danger';
        } else {
            $activeDb = $old['is_active'] ? 't' : 'f';
            if ($old['id'] > 0) {
                $r = pg_query_params(
                    $conn,
                    "UPDATE announcements SET judul = $1, isi = $2, is_active = $3, ends_at = $4, updated_at = NOW() WHERE id = $5",
                    [$old['judul'], $isiClean, $activeDb, $endsAt, $old['id']]
                );
                $ok = $r && pg_affected_rows($r) > 0;
                if ($ok) {
                    logActivity($conn, $admin['id'], 'announcement_update', 'Ubah pengumuman #' . $old['id'] . ': ' . $old['judul']);
                    $_SESSION['flash_ok'] = 'Pengumuman berhasil diperbarui.';
                } else {
                    $message = 'Gagal memperbarui pengumuman: ' . pg_last_error($conn);
                    $messageType = 'danger';
                }
            } else {
                $r = pg_query_params(
                    $conn,
                    "INSERT INTO announcements (judul, isi, is_active, ends_at, created_by) VALUES ($1,$2,$3,$4,$5) RETURNING id",
                    [$old['judul'], $isiClean, $activeDb, $endsAt, (int)$admin['id']]
                );
                if ($r && pg_num_rows($r) > 0) {
                    $newId = (int)pg_fetch_result($r, 0, 0);
                    pg_free_result($r);
                    logActivity($conn, $admin['id'], 'announcement_create', 'Buat pengumuman #' . $newId . ': ' . $old['judul']);
                    $_SESSION['flash_ok'] = 'Pengumuman berhasil dibuat dan akan tampil ke seluruh pengguna.';
                } else {
                    $message = 'Gagal membuat pengumuman: ' . pg_last_error($conn);
                    $messageType = 'danger';
                }
            }

            if ($messageType !== 'danger') {
                pg_close($conn);
                header('Location: announcements.php');
                exit;
            }
        }
    } elseif ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['flash_error'] = 'ID pengumuman tidak valid.';
        } else {
            $r = pg_query_params($conn, "UPDATE announcements SET is_active = NOT is_active, updated_at = NOW() WHERE id = $1", [$id]);
            if ($r && pg_affected_rows($r) > 0) {
                logActivity($conn, $admin['id'], 'announcement_toggle', 'Toggle aktif pengumuman #' . $id);
                $_SESSION['flash_ok'] = 'Status pengumuman diperbarui.';
            } else {
                $_SESSION['flash_error'] = 'Pengumuman tidak ditemukan.';
            }
        }
        pg_close($conn);
        header('Location: announcements.php');
        exit;
    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['flash_error'] = 'ID pengumuman tidak valid.';
        } else {
            $r = pg_query_params($conn, "DELETE FROM announcements WHERE id = $1", [$id]);
            if ($r && pg_affected_rows($r) > 0) {
                logActivity($conn, $admin['id'], 'announcement_delete', 'Hapus pengumuman #' . $id);
                $_SESSION['flash_ok'] = 'Pengumuman berhasil dihapus.';
            } else {
                $_SESSION['flash_error'] = 'Pengumuman tidak ditemukan.';
            }
        }
        pg_close($conn);
        header('Location: announcements.php');
        exit;
    }
}

// Mode edit via GET.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $r = pg_query_params($conn, "SELECT * FROM announcements WHERE id = $1", [$id]);
    if ($r && pg_num_rows($r) > 0) {
        $row = pg_fetch_assoc($r);
        $old['id'] = (int)$row['id'];
        $old['judul'] = $row['judul'];
        $old['isi'] = $row['isi'];
        $old['is_active'] = ($row['is_active'] === 't' || $row['is_active'] == 1);
        $old['ends_at'] = !empty($row['ends_at']) ? date('Y-m-d', strtotime($row['ends_at'])) : '';
    } else {
        $message = 'Pengumuman tidak ditemukan.';
        $messageType = 'danger';
    }
    if ($r) pg_free_result($r);
}

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
if ($flashErr) {
    $message = $flashErr;
    $messageType = 'danger';
} elseif ($flashOk) {
    $message = $flashOk;
    $messageType = 'success';
}

$announcements = [];
$ar = @pg_query(
    $conn,
    "SELECT a.*, u.name AS author_name,
            (SELECT COUNT(*) FROM announcement_dismissals d
             WHERE d.announcement_id = a.id AND d.dismissed_until > NOW()) AS dismissed_count
     FROM announcements a
     LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.is_active DESC, a.created_at DESC, a.id DESC"
);
if ($ar) {
    while ($row = pg_fetch_assoc($ar)) $announcements[] = $row;
    pg_free_result($ar);
} else {
    $message = 'Tabel pengumuman belum tersedia. Jalankan migrations/024_announcements.sql terlebih dahulu.';
    $messageType = 'danger';
}

require_once 'includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Pengumuman</h2>
        <p class="text-secondary mb-0">Pengumuman aktif akan tampil sebagai dialog ke seluruh pengguna setelah login. Pengguna dapat menutupnya dan tidak akan melihatnya lagi selama 24 jam.</p>
    </div>
    <a href="announcements.php" class="btn btn-outline-secondary btn-sm flex-shrink-0 <?php echo $old['id'] > 0 ? '' : 'invisible'; ?>">+ Buat Baru</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo e($messageType); ?>"><?php echo e($message); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h3 class="h6 mb-3"><?php echo $old['id'] > 0 ? 'Edit Pengumuman' : 'Buat Pengumuman Baru'; ?></h3>
        <form method="POST" id="announcementForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="save">
            <input type="hidden" name="id" value="<?php echo (int)$old['id']; ?>">

            <div class="mb-3">
                <label for="judul" class="form-label">Judul <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="judul" name="judul" required maxlength="255" placeholder="cth: Pemeliharaan Server Akhir Pekan" value="<?php echo e($old['judul']); ?>">
            </div>

            <div class="mb-3">
                <label for="isi" class="form-label">Isi Pengumuman <span class="text-danger">*</span></label>
                <textarea id="isi" name="isi" class="form-control" rows="8" placeholder="Tulis informasi yang ingin disampaikan ke seluruh pengguna…"><?php echo e($old['isi']); ?></textarea>
                <div class="form-text">Mendukung format bold, list, link, dan gambar. Pengumuman tampil sebagai dialog setelah login.</div>
            </div>

            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="ends_at" class="form-label">Berlaku Sampai <span class="text-secondary fw-normal">(opsional)</span></label>
                    <input type="date" class="form-control" id="ends_at" name="ends_at" value="<?php echo e($old['ends_at']); ?>">
                    <div class="form-text">Kosong = tampil terus sampai dinonaktifkan. Tanggal lampau = otomatis tidak ditampilkan.</div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="isActive" name="is_active" value="1" <?php echo $old['is_active'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="isActive">Aktifkan pengumuman</label>
                    </div>
                </div>
                <div class="col-12 col-md-4 d-flex gap-2 justify-content-md-end">
                    <button type="submit" class="btn btn-primary"><?php echo $old['id'] > 0 ? 'Simpan Perubahan' : 'Buat Pengumuman'; ?></button>
                    <?php if ($old['id'] > 0): ?><a href="announcements.php" class="btn btn-outline-secondary">Batal</a><?php endif; ?>
                </div>
            </div>
        </form>
        <div class="alert alert-light border mt-3 mb-0 small text-secondary">
            <i class="bi bi-info-circle me-1"></i> Setelah dibuat, dialog muncul pada halaman berikutnya yang dibuka pengguna. Tombol tutup pada dialog menyimpan jeda 24 jam per pengguna per pengumuman.
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <h3 class="h6 p-3 pb-0">Daftar Pengumuman (<?php echo count($announcements); ?>)</h3>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Judul / Isi</th>
                        <th class="text-center">Status</th>
                        <th>Berlaku Sampai</th>
                        <th>Dibuat</th>
                        <th class="text-center">Ditutup 24 Jam</th>
                        <th style="min-width:220px;">Aksi</th>
                    </tr>
                </thead>
                <tbody class="table-group-divider">
                    <?php if (empty($announcements)): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada pengumuman. Buat pengumuman pertama di form atas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($announcements as $a): ?>
                            <?php
                            $status = announcementStatus($a);
                            $active = ($a['is_active'] === 't' || $a['is_active'] == 1 || $a['is_active'] === true);
                            $preview = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($a['isi']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                            if (mb_strlen($preview) > 160) $preview = mb_substr($preview, 0, 160) . '…';
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($a['judul']); ?></strong>
                                    <?php if ($preview !== ''): ?><div class="small text-secondary"><?php echo e($preview); ?></div><?php endif; ?>
                                </td>
                                <td class="text-center"><span class="badge <?php echo e($status['badge']); ?>"><?php echo e($status['label']); ?></span></td>
                                <td class="small"><?php echo !empty($a['ends_at']) ? date('d M Y', strtotime($a['ends_at'])) : '—'; ?></td>
                                <td class="small">
                                    <?php echo date('d M Y H:i', strtotime($a['created_at'])); ?>
                                    <div class="text-secondary"><?php echo e($a['author_name'] ?? '—'); ?></div>
                                </td>
                                <td class="text-center"><span class="badge text-bg-light border"><?php echo (int)$a['dismissed_count']; ?></span></td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <a href="announcements.php?edit=<?php echo (int)$a['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $active ? 'Nonaktifkan' : 'Aktifkan'; ?> pengumuman ini?')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="act" value="toggle">
                                            <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning"><?php echo $active ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                                        </form>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Hapus pengumuman ini? Tindakan tidak bisa dibatalkan.')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="act" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                        </form>
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

<script src="assets/vendor/toastui/toastui-editor-all.min.js"></script>
<script src="assets/js/helpdesk-markdown-editor.js"></script>
<script>
(function () {
    var isi = document.getElementById('isi');
    if (window.createHelpdeskEditor && isi) {
        window.createHelpdeskEditor(isi).catch(function () {});
    }
})();
</script>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
