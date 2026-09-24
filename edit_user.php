<?php
require_once 'config.php';
// Auth dulu SEBELUM output header (agar redirect header() tidak "headers already sent")
$admin = requireRole(['admin']);
$pageTitle = 'Edit Pengguna';

$id = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
if ($id <= 0) {
    header('Location: user_management.php');
    exit;
}
if ($id === (int)$admin['id']) {
    // Akun sendiri diubah via menu Profil
    $_SESSION['flash_error'] = 'Gunakan menu Profil untuk mengubah akun sendiri!';
    header('Location: user_management.php');
    exit;
}

require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

$cur = pg_query_params($conn, "SELECT * FROM users WHERE id = $1", [$id]);
$u = ($cur && pg_num_rows($cur) > 0) ? pg_fetch_assoc($cur) : null;
if ($cur) pg_free_result($cur);
if (!$u) {
    pg_close($conn);
    header('Location: user_management.php');
    exit;
}
$isSimrs = (($u['auth_source'] ?? 'local') === 'simrs');

// Data tertaut sebagai pelapor (tiket/CR ikut terhapus CASCADE bila user dihapus)
$linkT = 0;
$linkC = 0;
$lc = pg_query_params($conn, "SELECT (SELECT COUNT(*) FROM tickets WHERE user_id = $1) AS t, (SELECT COUNT(*) FROM change_requests WHERE user_id = $1) AS c", [$id]);
if ($lc) {
    $lr = pg_fetch_assoc($lc);
    $linkT = (int)($lr['t'] ?? 0);
    $linkC = (int)($lr['c'] ?? 0);
    pg_free_result($lc);
}

$validRoles = ['admin', 'teknisi', 'pelapor'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $role = trim($_POST['role'] ?? '');
        if (!in_array($role, $validRoles, true)) {
            $message = 'Role tidak valid.';
            $messageType = 'danger';
        } elseif ($isSimrs) {
            // Nama/divisi user SIMRS ikut DB SIMRS (disinkron tiap login): hanya role yang disimpan
            pg_query_params($conn, "UPDATE users SET role = $1 WHERE id = $2", [$role, $id]);
            logActivity($conn, $admin['id'], 'user_edit', 'Ubah role ' . $u['username'] . ' → ' . $role);
            $message = 'Role ' . $u['username'] . ' diubah menjadi ' . $role . '!';
            $messageType = 'success';
            $u['role'] = $role;
        } else {
            $name = trim($_POST['name'] ?? '');
            $division = trim($_POST['division'] ?? '');
            if ($name === '') {
                $message = 'Nama lengkap wajib diisi.';
                $messageType = 'danger';
            } elseif (mb_strlen($name) > 100 || mb_strlen($division) > 100) {
                $message = 'Nama/divisi maksimal 100 karakter.';
                $messageType = 'danger';
            } else {
                pg_query_params(
                    $conn,
                    "UPDATE users SET name = $1, division = $2, role = $3 WHERE id = $4",
                    [$name, $division !== '' ? $division : null, $role, $id]
                );
                logActivity($conn, $admin['id'], 'user_edit', 'Ubah data ' . $u['username']);
                $message = 'Data ' . $u['username'] . ' berhasil diperbarui!';
                $messageType = 'success';
                $u['name'] = $name;
                $u['division'] = $division !== '' ? $division : null;
                $u['role'] = $role;
            }
        }
    } elseif ($act === 'delete') {
        if ($isSimrs) {
            $message = 'Akun SIMRS tidak bisa dihapus dari sini (akan dibuat lagi otomatis saat login berikutnya). Nonaktifkan saja, atau hapus/nonaktifkan di SIMRS.';
            $messageType = 'danger';
        } elseif ($linkT + $linkC > 0) {
            $message = 'Tidak bisa dihapus: masih memiliki ' . $linkT . ' tiket dan ' . $linkC . ' CR (ikut terhapus bila dipaksa). Nonaktifkan saja via tombol Nonaktifkan.';
            $messageType = 'danger';
        } else {
            pg_query_params($conn, "DELETE FROM users WHERE id = $1", [$id]);
            logActivity($conn, $admin['id'], 'user_delete', 'Hapus pengguna ' . $u['username']);
            pg_close($conn);
            header('Location: user_management.php?deleted=' . urlencode($u['username']));
            exit;
        }
    }
}
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Edit Pengguna <?php echo htmlspecialchars($u['username']); ?></h2>
        <p class="text-secondary mb-0">Ubah nama, divisi, dan role. Username tidak bisa diubah (identitas login<?php echo $isSimrs ? ' + kunci JIT SIMRS' : ''; ?>).</p>
    </div>
    <a href="user_management.php" class="btn btn-outline-secondary btn-sm flex-shrink-0">← Kembali ke Daftar</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="row g-3 align-items-start">
    <div class="col-lg-8">
        <div class="card"><div class="card-body">
            <h3 class="h6">Data Pengguna</h3>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input class="form-control" value="<?php echo htmlspecialchars($u['username']); ?>" disabled>
                    <?php if ($isSimrs): ?> <span class="badge text-bg-info mt-1">SIMRS — nama & divisi disinkron dari SIMRS tiap login</span><?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="euName">Nama Lengkap</label>
                    <input class="form-control" id="euName" name="name" required maxlength="100" value="<?php echo htmlspecialchars($u['name']); ?>" <?php echo $isSimrs ? 'disabled' : ''; ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="euDiv">Divisi</label>
                    <input class="form-control" id="euDiv" name="division" maxlength="100" value="<?php echo htmlspecialchars($u['division'] ?? ''); ?>" <?php echo $isSimrs ? 'disabled' : ''; ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="euRole">Role</label>
                    <select id="euRole" name="role" class="form-select" required>
                        <?php foreach ($validRoles as $rr): ?>
                            <option value="<?php echo $rr; ?>" <?php echo $u['role'] === $rr ? 'selected' : ''; ?>><?php echo $rr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                    <a href="user_management.php" class="btn btn-danger">Batal</a>
                </div>
            </form>
        </div></div>
    </div>

    <div class="col-lg-4">
        <div class="card border-danger"><div class="card-body">
            <h3 class="h6 text-danger">Zona Berbahaya — Hapus Pengguna</h3>
            <p class="small text-secondary mb-2">Akun ini memiliki <strong><?php echo $linkT; ?> tiket</strong> dan <strong><?php echo $linkC; ?> CR</strong> sebagai pelapor.</p>
            <?php if ($isSimrs): ?>
                <div class="alert alert-info small mb-0">Akun SIMRS tidak bisa dihapus permanen dari sini. Nonaktifkan saja dari daftar pengguna.</div>
            <?php elseif ($linkT + $linkC > 0): ?>
                <div class="alert alert-warning small mb-0">Masih ada data tertaut — hapus ditolak untuk melindungi riwayat tiket/CR. Nonaktifkan saja.</div>
            <?php else: ?>
                <p class="small text-secondary">Tidak ada tiket/CR tertaut. Penghapusan permanen dan tercatat di log.</p>
                <form method="POST" onsubmit="return confirm('HAPUS PERMANEN pengguna <?php echo htmlspecialchars($u['username']); ?>? Tindakan ini tidak bisa dibatalkan!')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Hapus Permanen</button>
                </form>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
