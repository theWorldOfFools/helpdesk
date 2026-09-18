<?php
require_once 'includes/header.php';
require_once 'config.php';
requireLogin();

$pageTitle = 'Buat Tiket Baru';
$conn = getDBConnection();
$user = getCurrentUser();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = pg_escape_string($conn, $_POST['title']);
    $description = pg_escape_string($conn, $_POST['description']);
    $category = pg_escape_string($conn, $_POST['category']);
    $priority = pg_escape_string($conn, $_POST['priority']);
    $division = pg_escape_string($conn, $_POST['division']);
    $userId = $user['id'];
    $status = 'open';

    $ticketNumberQuery = "SELECT TO_CHAR(NOW(), 'YYYYMM') || '-' || LPAD(COALESCE(MAX(CAST(SUBSTRING(ticket_number FROM '[0-9]+$') AS INTEGER)) + 1, 1), 4, '0') FROM tickets";
    $ticketNumberResult = pg_query($conn, $ticketNumberQuery);
    $ticketNumber = pg_fetch_result($ticketNumberResult, 0, 0);
    pg_free_result($ticketNumberResult);

    $query = "INSERT INTO tickets (ticket_number, title, description, category, priority, status, division, user_id, created_by) 
              VALUES ('$ticketNumber', '$title', '$description', '$category', '$priority', '$status', '$division', $userId, $userId)";

    if (pg_query($conn, $query)) {
        $message = 'Tiket ' . $ticketNumber . ' berhasil dibuat!';
        $messageType = 'success';
    } else {
        $message = 'Gagal membuat tiket: ' . pg_last_error($conn);
        $messageType = 'danger';
    }
}

$divisions = ['IT Infrastructure', 'IT Development', 'IT Support', 'IT Security', 'Network', 'System Administration'];
$categories = ['Hardware', 'Software', 'Jaringan', 'Lainnya'];
$priorities = ['low', 'medium', 'high', 'critical'];
?>

<h1>Buat Tiket Baru</h1>
<p style="color:#666;margin-bottom:20px;">Login sebagai: <strong><?php echo htmlspecialchars($user['name']); ?></strong> (<?php echo htmlspecialchars($user['role']); ?>)</p>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST">
        <div class="form-group">
            <label for="title">Judul</label>
            <input type="text" id="title" name="title" required placeholder="Masukkan judul tiket">
        </div>
        <div class="form-group">
            <label for="description">Deskripsi</label>
            <textarea id="description" name="description" required placeholder="Jelaskan masalah secara detail"></textarea>
        </div>
        <div style="display:flex;gap:15px;">
            <div class="form-group" style="flex:1;">
                <label for="category">Kategori</label>
                <select id="category" name="category" required>
                    <option value="">Pilih kategori</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;">
                <label for="priority">Prioritas</label>
                <select id="priority" name="priority" required>
                    <option value="">Pilih prioritas</option>
                    <?php foreach ($priorities as $pr): ?>
                        <option value="<?php echo $pr; ?>"><?php echo ucfirst($pr); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;">
                <label for="division">Divisi</label>
                <select id="division" name="division" required>
                    <option value="">Pilih divisi</option>
                    <?php foreach ($divisions as $div): ?>
                        <option value="<?php echo $div; ?>"><?php echo $div; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Buat Tiket</button>
        <a href="index.php" class="btn btn-danger">Batal</a>
    </form>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
