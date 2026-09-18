<?php
require_once 'includes/header.php';
require_once 'config.php';
requireLogin();

$pageTitle = 'Edit Tiket';
$conn = getDBConnection();
$user = getCurrentUser();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$query = "SELECT * FROM tickets WHERE id = $1";
$result = pg_query_params($conn, $query, [$id]);

if (!$result || pg_num_rows($result) === 0) {
    header('Location: index.php');
    exit;
}

$ticket = pg_fetch_assoc($result);
pg_free_result($result);

if (!canEditTicket($ticket, $user)) {
    ?>
    <div class="denied-message">
        <h2>Akses Ditolak</h2>
        <p>Anda tidak memiliki izin untuk mengedit tiket ini.</p>
        <a href="view_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn btn-primary">Kembali</a>
    </div>
    <?php
    pg_close($conn);
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = pg_escape_string($conn, $_POST['title']);
    $description = pg_escape_string($conn, $_POST['description']);
    $category = pg_escape_string($conn, $_POST['category']);
    $priority = pg_escape_string($conn, $_POST['priority']);
    $division = pg_escape_string($conn, $_POST['division']);
    $status = pg_escape_string($conn, $_POST['status']);

    $updateQuery = "UPDATE tickets SET 
        title = '$title', 
        description = '$description', 
        category = '$category', 
        priority = '$priority', 
        division = '$division', 
        status = '$status', 
        updated_at = NOW() 
        WHERE id = $id";

    if (pg_query($conn, $updateQuery)) {
        $message = 'Tiket berhasil diperbarui!';
        $messageType = 'success';
        $ticket['title'] = $title;
        $ticket['description'] = $description;
        $ticket['category'] = $category;
        $ticket['priority'] = $priority;
        $ticket['division'] = $division;
        $ticket['status'] = $status;
    } else {
        $message = 'Gagal memperbarui tiket: ' . pg_last_error($conn);
        $messageType = 'danger';
    }
}

$divisions = ['IT Infrastructure', 'IT Development', 'IT Support', 'IT Security', 'Network', 'System Administration'];
$categories = ['Hardware', 'Software', 'Jaringan', 'Lainnya'];
$priorities = ['low', 'medium', 'high', 'critical'];
$statuses = ['open', 'in_progress', 'resolved', 'closed'];
?>

<h1>Edit Tiket</h1>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST">
        <div class="form-group">
            <label for="title">Judul</label>
            <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($ticket['title']); ?>" required>
        </div>
        <div class="form-group">
            <label for="description">Deskripsi</label>
            <textarea id="description" name="description" required><?php echo htmlspecialchars($ticket['description']); ?></textarea>
        </div>
        <div style="display:flex;gap:15px;">
            <div class="form-group" style="flex:1;">
                <label for="category">Kategori</label>
                <select id="category" name="category" required>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat; ?>" <?php echo $ticket['category'] === $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;">
                <label for="priority">Prioritas</label>
                <select id="priority" name="priority" required>
                    <?php foreach ($priorities as $pr): ?>
                        <option value="<?php echo $pr; ?>" <?php echo $ticket['priority'] === $pr ? 'selected' : ''; ?>><?php echo ucfirst($pr); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;">
                <label for="division">Divisi</label>
                <select id="division" name="division" required>
                    <?php foreach ($divisions as $div): ?>
                        <option value="<?php echo $div; ?>" <?php echo $ticket['division'] === $div ? 'selected' : ''; ?>><?php echo $div; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status" required>
                <?php foreach ($statuses as $st): ?>
                    <option value="<?php echo $st; ?>" <?php echo $ticket['status'] === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        <a href="view_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn btn-danger">Batal</a>
    </form>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
