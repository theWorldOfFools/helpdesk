<?php
require_once 'config.php';
require_once __DIR__ . '/includes/telegram.php';
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
// Akun SIMRS: profil + password mengikuti DB SIMRS (read-only di helpdesk)
$isSimrsUser = (($user['auth_source'] ?? 'local') === 'simrs');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'tg_gen') {
        // Generate kode OTP 6 digit, hash di DB, expired 10 menit, single-use.
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        @pg_query_params($conn, "UPDATE telegram_link_codes SET used_at = NOW() WHERE user_id = $1 AND used_at IS NULL", [$user['id']]);
        @pg_query_params($conn, "INSERT INTO telegram_link_codes (user_id, code_hash, expires_at) VALUES ($1,$2,NOW() + INTERVAL '10 minutes')", [$user['id'], hash('sha256', $code)]);
        logActivity($conn, $user['id'], 'telegram_code', 'Generate kode link Telegram');
        $_SESSION['tg_code_plain'] = $code;
        header('Location: profile.php#tg');
        exit;
    } elseif ($act === 'tg_unlink') {
        @pg_query_params($conn, "DELETE FROM telegram_identities WHERE user_id = $1", [$user['id']]);
        logActivity($conn, $user['id'], 'telegram_unlink', 'Putus via profil');
        $message = 'Tautan Telegram diputus.';
        $messageType = 'success';
    } elseif ($isSimrsUser) {
        $message = 'Akun SIMRS: nama, divisi, dan password mengikuti data SIMRS dan tidak bisa diubah di sini.';
        $messageType = 'info';
    } elseif ($act === 'info') {
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

// Status tautan Telegram + kode plain sekali tampil
$tgLink = null;
try {
    $r = @pg_query_params($conn, "SELECT telegram_id, telegram_username, linked_via, linked_at FROM telegram_identities WHERE user_id = $1", [$user['id']]);
    if ($r && pg_num_rows($r) > 0) $tgLink = pg_fetch_assoc($r);
    if ($r) pg_free_result($r);
} catch (Throwable $e) {
}
$tgCode = $_SESSION['tg_code_plain'] ?? null;
unset($_SESSION['tg_code_plain']);
$tgBot = tgConfig()['username'] ?? '';
?>

<h1 class="h4 mb-3">Profil Saya</h1>

<?php if ($warn || $force): ?><div class="alert alert-warning"><?php echo htmlspecialchars($warn ?: 'Anda wajib mengganti password default terlebih dahulu.'); ?></div><?php endif; ?>
<?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h3 class="h6">Data Diri</h3>
        <?php if ($isSimrsUser): ?><div class="alert alert-info small py-2">Akun SIMRS — nama & divisi disinkron dari SIMRS setiap login.</div><?php endif; ?>
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
        <?php if ($isSimrsUser): ?>
            <div class="alert alert-info mb-0 small">Akun Anda dari SIMRS — password diganti di aplikasi SIMRS, bukan di sini. Login helpdesk otomatis mengikuti password SIMRS terbaru.</div>
        <?php else: ?>
        <form method="POST" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="act" value="password">
            <div class="mb-3"><label class="form-label" for="pfOld">Password Lama</label><input type="password" class="form-control" id="pfOld" name="old_password" required autocomplete="current-password"></div>
            <div class="mb-3"><label class="form-label" for="pfNew">Password Baru (min 8)</label><input type="password" class="form-control" id="pfNew" name="new_password" required minlength="8" autocomplete="new-password"></div>
            <div class="mb-3"><label class="form-label" for="pfConf">Konfirmasi Baru</label><input type="password" class="form-control" id="pfConf" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
            <button class="btn btn-warning" type="submit">Ganti Password</button>
        </form>
        <?php endif; ?>
    </div></div></div>
</div>

<div class="card mt-3" id="tg"><div class="card-body">
    <h3 class="h6">🤖 Hubungkan Telegram</h3>
    <?php if ($tgLink): ?>
        <div class="alert alert-success small mb-2">Terhubung: telegram_id <code><?php echo e($tgLink['telegram_id']); ?></code><?php echo $tgLink['telegram_username'] ? ' (@' . e($tgLink['telegram_username']) . ')' : ''; ?> · via <?php echo e($tgLink['linked_via']); ?> · <?php echo e($tgLink['linked_at']); ?></div>
        <form method="POST" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="act" value="tg_unlink"><button class="btn btn-sm btn-outline-danger" type="submit">Putus tautan</button></form>
        <span class="small text-secondary ms-2">Cek via bot: <code>/siapa</code></span>
    <?php else: ?>
        <p class="small text-secondary mb-2">Generate kode 6 digit, lalu kirim <code>/hubung KODE</code> ke bot (lebih aman via DM). Berlaku 10 menit, sekali pakai.</p>
        <?php if ($tgCode): ?>
            <div class="alert alert-warning">Kode kamu: <strong style="font-size:1.4em;letter-spacing:3px;"><?php echo e($tgCode); ?></strong>
            <?php if ($tgBot): ?><br>Klik: <a href="https://t.me/<?php echo e($tgBot); ?>?start=<?php echo e($tgCode); ?>" target="_blank" rel="noopener">t.me/<?php echo e($tgBot); ?>?start=<?php echo e($tgCode); ?></a><?php endif; ?>
            <br><span class="small">Atau kirim manual: <code>/hubung <?php echo e($tgCode); ?></code></span></div>
        <?php endif; ?>
        <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="act" value="tg_gen"><button class="btn btn-sm btn-primary" type="submit">Generate kode baru</button></form>
    <?php endif; ?>
</div></div>

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
