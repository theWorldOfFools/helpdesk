<?php
require_once 'config.php';
// Auth dulu SEBELUM output header (agar redirect header() tidak "headers already sent")
$user = requireRole(['admin']);
$pageTitle = 'Manajemen Pengguna';
require_once 'includes/header.php';

$conn = getDBConnection();

$message = '';
$messageType = '';
$flash = flash_error();
if ($flash) {
    $message = $flash;
    $messageType = 'danger';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $username = trim($_POST['username'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $division = trim($_POST['division'] ?? '');
        $validRoles = ['admin', 'teknisi', 'pelapor'];

        if ($username === '' || $name === '' || !in_array($role, $validRoles, true)) {
            $message = 'Username, nama, dan role wajib diisi dengan benar!';
            $messageType = 'danger';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $message = 'Username 3-50 karakter, huruf/angka/_.- saja!';
            $messageType = 'danger';
        } else {
            $password = password_hash('admin123', PASSWORD_BCRYPT);
            $checkResult = pg_query_params($conn, "SELECT id FROM users WHERE username = $1", [$username]);
            if ($checkResult && pg_num_rows($checkResult) > 0) {
                $message = 'Username sudah digunakan!';
                $messageType = 'danger';
            } else {
                $ins = pg_query_params(
                    $conn,
                    "INSERT INTO users (username, password, name, role, division, must_change_password) VALUES ($1,$2,$3,$4,$5,TRUE)",
                    [$username, $password, $name, $role, $division !== '' ? $division : null]
                );
                if ($ins) {
                    $message = 'Pengguna ' . $username . ' berhasil ditambahkan! Password default: admin123 (wajib diganti saat login pertama).';
                    $messageType = 'success';
                    logActivity($conn, $user['id'], 'user_add', 'Tambah pengguna ' . $username);
                    pg_free_result($ins);
                } else {
                    $message = 'Gagal menambahkan pengguna: ' . pg_last_error($conn);
                    $messageType = 'danger';
                }
            }
            if (isset($checkResult) && $checkResult) pg_free_result($checkResult);
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'toggle') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id <= 0) {
            $message = 'ID pengguna tidak valid.';
            $messageType = 'danger';
        } elseif ($id === (int)$user['id']) {
            $message = 'Tidak bisa menonaktifkan akun sendiri!';
            $messageType = 'danger';
        } else {
            pg_query_params($conn, "UPDATE users SET is_active = NOT is_active WHERE id = $1", [$id]);
            logActivity($conn, $user['id'], 'user_toggle', 'Toggle aktif user_id=' . $id);
            $message = 'Status pengguna diperbarui!';
            $messageType = 'success';
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'reset') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id <= 0) {
            $message = 'ID pengguna tidak valid.';
            $messageType = 'danger';
        } elseif ($id === (int)$user['id']) {
            $message = 'Gunakan menu Profil untuk mengganti password sendiri!';
            $messageType = 'danger';
        } else {
            // Password sementara acak 10 karakter
            $tmp = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
            $r = pg_query_params($conn, "UPDATE users SET password = $1, must_change_password = TRUE, remember_token = NULL WHERE id = $2", [hashPassword($tmp), $id]);
            if ($r) {
                $chk = pg_query_params($conn, "SELECT username FROM users WHERE id = $1", [$id]);
                $uname = $chk ? pg_fetch_result($chk, 0, 0) : ('id ' . $id);
                if ($chk) pg_free_result($chk);
                logActivity($conn, $user['id'], 'user_reset_pw', 'Reset password ' . $uname);
                $message = 'Password ' . $uname . ' direset menjadi: ' . $tmp . ' (catat sekarang, wajib diganti saat login).';
                $messageType = 'success';
            } else {
                $message = 'Gagal reset: ' . pg_last_error($conn);
                $messageType = 'danger';
            }
        }
    }
}

$usersResult = pg_query($conn, "SELECT * FROM users ORDER BY role, name");
$actLogs = [];
$alr = pg_query($conn, "SELECT l.aksi, l.detail, l.ip, l.created_at, u.username FROM activity_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 20");
if ($alr) {
    while ($row = pg_fetch_assoc($alr)) $actLogs[] = $row;
    pg_free_result($alr);
}
?>

<h1 class="h4 mb-3">Manajemen Pengguna</h1>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
    <h3 class="h6">Tambah Pengguna</h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add">
        <div class="row g-3">
            <div class="col-md-6 col-xl-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" required placeholder="Username" pattern="[A-Za-z0-9_.-]{3,50}">
            </div>
            <div class="col-md-6 col-xl-3">
                <label for="name" class="form-label">Nama Lengkap</label>
                <input type="text" class="form-control" id="name" name="name" required placeholder="Nama lengkap" maxlength="100">
            </div>
            <div class="col-md-6 col-xl-3">
                <label for="role" class="form-label">Role</label>
                <select id="role" name="role" class="form-select" required>
                    <option value="admin">Administrator</option>
                    <option value="teknisi">Teknisi</option>
                    <option value="pelapor">Pelapor</option>
                </select>
            </div>
            <div class="col-md-6 col-xl-3">
                <label for="division" class="form-label">Divisi</label>
                <input type="text" class="form-control" id="division" name="division" placeholder="Divisi" maxlength="100">
            </div>
        </div>
        <button type="submit" class="btn btn-primary mt-3">Tambah Pengguna</button>
        <p class="mt-2 mb-0 small text-secondary">Password default: admin123</p>
    </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
    <h3 class="h6 p-3 pb-0">Daftar Pengguna</h3>
    <div class="table-responsive px-3 pb-3">
    <table id="grid-users" class="table table-hover align-middle mb-0" style="display:none;width:100%;">
        <thead class="table-dark">
            <tr>
                <th>Username</th>
                <th>Nama</th>
                <th>Role</th>
                <th>Divisi</th>
                <th>Aktif</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0" id="tbl-users-fallback">
        <thead class="table-dark">
            <tr>
                <th>Username</th>
                <th>Nama</th>
                <th>Role</th>
                <th>Divisi</th>
                <th>Aktif</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody class="table-group-divider">
            <?php while ($u = pg_fetch_assoc($usersResult)): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                    <td><?php echo htmlspecialchars($u['name']); ?></td>
                    <td><span class="status-badge status-<?php echo $u['role']; ?>"><?php echo htmlspecialchars($u['role']); ?></span></td>
                    <td><?php echo htmlspecialchars($u['division'] ?? '-'); ?></td>
                    <td><?php if ($u['is_active'] === 't' || $u['is_active'] == 1 || $u['is_active'] === true): ?><span class="badge text-bg-success">Ya</span><?php else: ?><span class="badge text-bg-secondary">Tidak</span><?php endif; ?></td>
                    <td>
                        <form method="POST" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Nonaktifkan/mengaktifkan pengguna ini?')" <?php echo ((int)$u['id'] === (int)$user['id']) ? 'disabled title="Akun sendiri"' : ''; ?>>
                                <?php echo ($u['is_active'] === 't' || $u['is_active'] == 1 || $u['is_active'] === true) ? 'Nonaktifkan' : 'Aktifkan'; ?>
                            </button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Reset password pengguna ini ke acak sementara?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="reset">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" <?php echo ((int)$u['id'] === (int)$user['id']) ? 'disabled title="Akun sendiri"' : ''; ?>>Reset PW</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table></div>
    </div>
</div>

<div class="card mt-4"><div class="card-body p-0">
    <h3 class="h6 p-3 pb-0">Log Aktivitas (20 terakhir)</h3>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-dark"><tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Detail</th><th>IP</th></tr></thead>
        <tbody>
            <?php foreach ($actLogs as $l): ?><tr><td class="text-nowrap"><?php echo date('d M Y H:i', strtotime($l['created_at'])); ?></td><td><?php echo htmlspecialchars($l['username'] ?? '-'); ?></td><td><?php echo htmlspecialchars($l['aksi']); ?></td><td><?php echo htmlspecialchars($l['detail'] ?? '-'); ?></td><td><?php echo htmlspecialchars($l['ip'] ?? '-'); ?></td></tr><?php endforeach; ?>
            <?php if (empty($actLogs)): ?><tr><td colspan="5" class="text-center text-secondary">Belum ada aktivitas.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div></div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
