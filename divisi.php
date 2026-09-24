<?php
require_once 'config.php';
// Auth dulu SEBELUM output header (agar redirect header() tidak "headers already sent")
$admin = requireRole(['admin']);
$pageTitle = 'Master Divisi';
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
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $message = 'Nama divisi wajib diisi.';
            $messageType = 'danger';
        } elseif (mb_strlen($name) > 100) {
            $message = 'Nama divisi maksimal 100 karakter.';
            $messageType = 'danger';
        } else {
            $ins = @pg_query_params($conn, "INSERT INTO divisions (name) VALUES ($1)", [$name]);
            if ($ins) {
                pg_free_result($ins);
                logActivity($conn, $admin['id'], 'division_add', 'Tambah divisi ' . $name);
                $message = 'Divisi ' . $name . ' berhasil ditambahkan!';
                $messageType = 'success';
            } else {
                $message = (stripos(pg_last_error($conn), 'duplicate') !== false || stripos(pg_last_error($conn), 'unique') !== false)
                    ? 'Divisi ' . $name . ' sudah ada!'
                    : 'Gagal menambahkan divisi: ' . pg_last_error($conn);
                $messageType = 'danger';
            }
        }
    } elseif ($act === 'rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '') {
            $message = 'ID dan nama baru wajib diisi.';
            $messageType = 'danger';
        } elseif (mb_strlen($name) > 100) {
            $message = 'Nama divisi maksimal 100 karakter.';
            $messageType = 'danger';
        } else {
            $cur = pg_query_params($conn, "SELECT name FROM divisions WHERE id = $1", [$id]);
            $old = ($cur && pg_num_rows($cur) > 0) ? pg_fetch_result($cur, 0, 0) : null;
            if ($cur) pg_free_result($cur);
            if ($old === null) {
                $message = 'Divisi tidak ditemukan.';
                $messageType = 'danger';
            } elseif ($old === $name) {
                $message = 'Nama tidak berubah.';
                $messageType = 'danger';
            } else {
                // Rename dipropagasi ke tiket + pengguna dalam 1 transaksi
                pg_query($conn, 'BEGIN');
                $ok = (bool)@pg_query_params($conn, "UPDATE divisions SET name = $1 WHERE id = $2", [$name, $id]);
                if ($ok) $ok = (bool)pg_query_params($conn, "UPDATE tickets SET division = $1 WHERE division = $2", [$name, $old]);
                if ($ok) $ok = (bool)pg_query_params($conn, "UPDATE users SET division = $1 WHERE division = $2", [$name, $old]);
                if ($ok) {
                    pg_query($conn, 'COMMIT');
                    logActivity($conn, $admin['id'], 'division_rename', 'Rename divisi ' . $old . ' → ' . $name);
                    $message = 'Divisi ' . $old . ' diubah menjadi ' . $name . ' (tiket & pengguna ikut diperbarui).';
                    $messageType = 'success';
                } else {
                    pg_query($conn, 'ROLLBACK');
                    $message = (stripos(pg_last_error($conn), 'duplicate') !== false || stripos(pg_last_error($conn), 'unique') !== false)
                        ? 'Nama ' . $name . ' sudah dipakai divisi lain!'
                        : 'Gagal rename: ' . pg_last_error($conn);
                    $messageType = 'danger';
                }
            }
        }
    } elseif ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $message = 'ID divisi tidak valid.';
            $messageType = 'danger';
        } else {
            pg_query_params($conn, "UPDATE divisions SET is_active = NOT is_active WHERE id = $1", [$id]);
            logActivity($conn, $admin['id'], 'division_toggle', 'Toggle aktif division_id=' . $id);
            $message = 'Status divisi diperbarui! (Nonaktif = hilang dari dropdown form, data lama tetap.)';
            $messageType = 'success';
        }
    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $message = 'ID divisi tidak valid.';
            $messageType = 'danger';
        } else {
            $cur = pg_query_params($conn, "SELECT name FROM divisions WHERE id = $1", [$id]);
            $name = ($cur && pg_num_rows($cur) > 0) ? pg_fetch_result($cur, 0, 0) : null;
            if ($cur) pg_free_result($cur);
            if ($name === null) {
                $message = 'Divisi tidak ditemukan.';
                $messageType = 'danger';
            } else {
                $cnt = pg_query_params(
                    $conn,
                    "SELECT (SELECT COUNT(*) FROM tickets WHERE division = $1) AS t, (SELECT COUNT(*) FROM change_requests WHERE unit = $1) AS c, (SELECT COUNT(*) FROM users WHERE division = $1) AS u",
                    [$name]
                );
                $row = $cnt ? pg_fetch_assoc($cnt) : ['t' => 1, 'c' => 0, 'u' => 0];
                if ($cnt) pg_free_result($cnt);
                $used = (int)$row['t'] + (int)$row['c'] + (int)$row['u'];
                if ($used > 0) {
                    $message = 'Tidak bisa dihapus: dipakai ' . $row['t'] . ' tiket, ' . $row['c'] . ' CR, ' . $row['u'] . ' pengguna. Nonaktifkan saja.';
                    $messageType = 'danger';
                } else {
                    pg_query_params($conn, "DELETE FROM divisions WHERE id = $1", [$id]);
                    logActivity($conn, $admin['id'], 'division_delete', 'Hapus divisi ' . $name);
                    $message = 'Divisi ' . $name . ' berhasil dihapus!';
                    $messageType = 'success';
                }
            }
        }
    }
}

// Daftar + hitungan pakai (tiket, CR via unit, pengguna)
$divs = [];
$dr = pg_query($conn, "SELECT d.*, (SELECT COUNT(*) FROM tickets t WHERE t.division = d.name) AS n_tiket, (SELECT COUNT(*) FROM tickets t WHERE t.division = d.name AND t.status NOT IN ('resolved','closed')) AS n_open, (SELECT COUNT(*) FROM change_requests c WHERE c.unit = d.name) AS n_cr, (SELECT COUNT(*) FROM users u WHERE u.division = d.name) AS n_user FROM divisions d ORDER BY d.name ASC");
if ($dr) {
    while ($row = pg_fetch_assoc($dr)) $divs[] = $row;
    pg_free_result($dr);
}
?>

<h1 class="h4 mb-3">Master Divisi</h1>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
    <h3 class="h6">Tambah Divisi</h3>
    <form method="POST" class="row g-2 align-items-end">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add">
        <div class="col-12 col-md-6">
            <label for="divName" class="form-label">Nama Divisi</label>
            <input type="text" class="form-control" id="divName" name="name" required maxlength="100" placeholder="cth: IT Support">
        </div>
        <div class="col-12 col-md-auto">
            <button type="submit" class="btn btn-primary">Tambah Divisi</button>
        </div>
    </form>
    <p class="mt-2 mb-0 small text-secondary">Daftar ini dipakai dropdown Divisi/Unit di form tiket & CR. Nonaktif = hilang dari dropdown, data lama tidak berubah.</p>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
    <h3 class="h6 p-3 pb-0">Daftar Divisi (<?php echo count($divs); ?>)</h3>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
            <tr>
                <th>Divisi</th>
                <th class="text-center">Tiket</th>
                <th class="text-center">Open</th>
                <th class="text-center">CR</th>
                <th class="text-center">Pengguna</th>
                <th class="text-center">Aktif</th>
                <th style="min-width:280px;">Aksi</th>
            </tr>
        </thead>
        <tbody class="table-group-divider">
            <?php foreach ($divs as $d): ?>
                <?php $active = ($d['is_active'] === 't' || $d['is_active'] == 1 || $d['is_active'] === true); ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($d['name']); ?></strong></td>
                    <td class="text-center"><?php echo (int)$d['n_tiket']; ?></td>
                    <td class="text-center"><?php echo (int)$d['n_open']; ?></td>
                    <td class="text-center"><?php echo (int)$d['n_cr']; ?></td>
                    <td class="text-center"><?php echo (int)$d['n_user']; ?></td>
                    <td class="text-center"><?php if ($active): ?><span class="badge text-bg-success">Ya</span><?php else: ?><span class="badge text-bg-secondary">Tidak</span><?php endif; ?></td>
                    <td>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Rename divisi ini? Tiket & pengguna ikut diperbarui.')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" name="name" required maxlength="100" value="<?php echo htmlspecialchars($d['name']); ?>" aria-label="Nama baru">
                                <button type="submit" class="btn btn-outline-primary">Rename</button>
                            </div>
                        </form>
                        <div class="d-flex gap-1 flex-wrap mt-1">
                        <form method="POST" class="d-inline" onsubmit="return confirm('Nonaktifkan/mengaktifkan divisi ini?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-warning"><?php echo $active ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Hapus divisi ini? Ditolak bila masih dipakai.')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                        </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($divs)): ?><tr><td colspan="7" class="text-center text-secondary">Belum ada divisi.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    </div>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
