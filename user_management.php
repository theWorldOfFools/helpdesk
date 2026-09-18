<?php
require_once 'includes/header.php';
require_once 'config.php';
requireRole(['admin']);

$pageTitle = 'Manajemen Pengguna';
$conn = getDBConnection();
$user = getCurrentUser();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $username = pg_escape_string($conn, $_POST['username']);
        $name = pg_escape_string($conn, $_POST['name']);
        $role = pg_escape_string($conn, $_POST['role']);
        $division = pg_escape_string($conn, $_POST['division']);
        $password = password_hash('admin123', PASSWORD_BCRYPT);

        $checkQuery = "SELECT id FROM users WHERE username = '$username'";
        $checkResult = pg_query($conn, $checkQuery);
        if ($checkResult && pg_num_rows($checkResult) > 0) {
            $message = 'Username sudah digunakan!';
            $messageType = 'danger';
        } else {
            $insertQuery = "INSERT INTO users (username, password, name, role, division) 
                            VALUES ('$username', '$password', '$name', '$role', '$division')";
            if (pg_query($conn, $insertQuery)) {
                $message = 'Pengguna berhasil ditambahkan!';
                $messageType = 'success';
            } else {
                $message = 'Gagal menambahkan pengguna: ' . pg_last_error($conn);
                $messageType = 'danger';
            }
        }
        pg_free_result($checkResult);
    } elseif (isset($_POST['action']) && $_POST['action'] === 'toggle') {
        $id = (int)$_POST['user_id'];
        $toggleQuery = "UPDATE users SET is_active = NOT is_active WHERE id = $1";
        pg_query_params($conn, $toggleQuery, [$id]);
        $message = 'Status pengguna diperbarui!';
        $messageType = 'success';
    }
}

$usersResult = pg_query($conn, "SELECT * FROM users ORDER BY role, name");
?>

<h1>Manajemen Pengguna</h1>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card mb-20">
    <h3>Tambah Pengguna</h3>
    <form method="POST">
        <input type="hidden" name="action" value="add">
        <div style="display:flex;gap:15px;flex-wrap:wrap;">
            <div class="form-group" style="flex:1;min-width:200px;">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required placeholder="Username">
            </div>
            <div class="form-group" style="flex:1;min-width:200px;">
                <label for="name">Nama Lengkap</label>
                <input type="text" id="name" name="name" required placeholder="Nama lengkap">
            </div>
            <div class="form-group" style="flex:1;min-width:150px;">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="admin">Administrator</option>
                    <option value="teknisi">Teknisi</option>
                    <option value="pelapor">Pelapor</option>
                </select>
            </div>
            <div class="form-group" style="flex:1;min-width:150px;">
                <label for="division">Divisi</label>
                <input type="text" id="division" name="division" placeholder="Divisi">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Tambah Pengguna</button>
        <p style="margin-top:10px;font-size:0.85rem;color:#999;">Password default: admin123</p>
    </form>
</div>

<div class="card">
    <h3>Daftar Pengguna</h3>
    <table>
        <thead>
            <tr>
                <th>Username</th>
                <th>Nama</th>
                <th>Role</th>
                <th>Divisi</th>
                <th>Aktif</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($u = pg_fetch_assoc($usersResult)): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                    <td><?php echo htmlspecialchars($u['name']); ?></td>
                    <td><span class="status-badge status-<?php echo $u['role']; ?>"><?php echo htmlspecialchars($u['role']); ?></span></td>
                    <td><?php echo htmlspecialchars($u['division'] ?? '-'); ?></td>
                    <td><?php echo $u['is_active'] ? '✅ Ya' : '❌ Tidak'; ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Nonaktifkan/mengaktifkan pengguna ini?')">
                                <?php echo $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
