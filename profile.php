<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$pageTitle = 'Profil Saya';
require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';
$force = isset($_GET['force']) && $_GET['force'] === '1';
$warn = $_SESSION['flash_warn'] ?? null;
unset($_SESSION['flash_warn']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'info') {
        $name = trim($_POST['name'] ?? '');
        $division = trim($_POST['division'] ?? '');
        if ($name === '') {
            $message = 'Nama lengkap wajib diisi.';
            $messageType = 'danger';
        } else {
            $r = pg_query_params($conn, "UPDATE users SET name = $1, division = $2 WHERE id = $3", [$name, $division !== '' ? $division : null, $user['id']]);
            if ($r) {
                $_SESSION['name'] = $name;
                $user = getCurrentUser();
                logActivity($conn, $user['id'], 'profile_update', 'Ubah profil');
                $message = 'Profil diperbarui.';
                $messageType = 'success';
            } else {
                $message = 'Gagal: ' . pg_last_error($conn);
                $messageType = 'danger';
            }
        }
    } elseif ($act === 'password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        $chk = pg_query_params($conn, "SELECT password, username FROM users WHERE id = $1", [$user['id']]);
        $row = $chk ? pg_fetch_assoc($chk) : null;
        if ($chk) pg_free_result($chk);
        if (!$row || !verifyPassword($old, $row['password'])) {
            $message = 'Password lama salah.';
            $messageType = 'danger';
        } elseif (strlen($new) < 8) {
            $message = 'Password baru minimal 8 karakter.';
            $messageType = 'danger';
        } elseif ($new !== $conf) {
            $message = 'Konfirmasi password tidak cocok.';
            $messageType = 'danger';
        } elseif (strcasecmp($new, $row['username']) === 0) {
            $message = 'Password tidak boleh sama dengan username.';
            $messageType = 'danger';
        } else {
            $r = pg_query_params($conn, "UPDATE users SET password = $1, must_change_password = FALSE, remember_token = NULL WHERE id = $2", [hashPassword($new), $user['id']]);
            if ($r) {
                logActivity($conn, $user['id'], 'password_change', 'Ganti password');
                $message = 'Password berhasil diganti. Silakan login ulang bila di perangkat lain.';
                $messageType = 'success';
                $force = false;
            } else {
                $message = 'Gagal: ' . pg_last_error($conn);
                $messageType = 'danger';
            }
        }
    }
}

// Riwayat aktivitas saya
$logs = [];
$lr = pg_query_params($conn, "SELECT aksi, detail, ip, created_at FROM activity_log WHERE user_id = $1 ORDER BY id DESC LIMIT 15", [$user['id']]);
if ($lr) {
    while ($row = pg_fetch_assoc($lr)) $logs[] = $row;
    pg_free_result($lr);
}
?>

<h1 class="h4 mb-3">Profil Saya</h1>

<?php if ($warn || $force): ?><div class="alert alert-warning"><?php echo htmlspecialchars($warn ?: 'Anda wajib mengganti password default terlebih dahulu.'); ?></div><?php endif; ?>
<?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h3 class="h6">Data Diri</h3>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="info">
            <div class="mb-3"><label class="form-label">Username</label><input class="form-control" value="<?php echo e($user['username']); ?>" disabled></div>
            <div class="mb-3"><label class="form-label">Role</label><input class="form-control" value="<?php echo e($user['role']); ?>" disabled></div>
            <div class="mb-3"><label class="form-label" for="pfName">Nama Lengkap</label><input class="form-control" id="pfName" name="name" required maxlength="100" value="<?php echo e($user['name']); ?>"></div>
            <div class="mb-3"><label class="form-label" for="pfDiv">Divisi</label><input class="form-control" id="pfDiv" name="division" maxlength="100" value="<?php echo e($user['division'] ?? ''); ?>"></div>
            <button class="btn btn-primary" type="submit">Simpan</button>
        </form>
    </div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h3 class="h6">Ganti Password</h3>
        <form method="POST" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="password">
            <div class="mb-3"><label class="form-label" for="pfOld">Password Lama</label><input type="password" class="form-control" id="pfOld" name="old_password" required autocomplete="current-password"></div>
            <div class="mb-3"><label class="form-label" for="pfNew">Password Baru (min 8)</label><input type="password" class="form-control" id="pfNew" name="new_password" required minlength="8" autocomplete="new-password"></div>
            <div class="mb-3"><label class="form-label" for="pfConf">Konfirmasi Baru</label><input type="password" class="form-control" id="pfConf" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
            <button class="btn btn-warning" type="submit">Ganti Password</button>
        </form>
    </div></div></div>
</div>

<div class="card mt-3"><div class="card-body">
    <h3 class="h6">Aktivitas Terakhir Saya</h3>
    <?php if ($logs): ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead class="table-dark"><tr><th>Waktu</th><th>Aksi</th><th>Detail</th><th>IP</th></tr></thead>
        <tbody><?php foreach ($logs as $l): ?><tr><td class="text-nowrap"><?php echo date('d M Y H:i', strtotime($l['created_at'])); ?></td><td><?php echo e($l['aksi']); ?></td><td><?php echo e($l['detail'] ?? '-'); ?></td><td><?php echo e($l['ip'] ?? '-'); ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><p class="text-secondary small mb-0">Belum ada aktivitas tercatat.</p><?php endif; ?>
</div></div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
