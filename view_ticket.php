<?php
require_once 'includes/header.php';
require_once 'config.php';
requireLogin();

$pageTitle = 'Detail Tiket';
$conn = getDBConnection();
$user = getCurrentUser();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$query = "SELECT t.*, u.name AS created_by_name, u.username AS created_by_username FROM tickets t LEFT JOIN users u ON t.user_id = u.id WHERE t.id = $1";
$result = pg_query_params($conn, $query, [$id]);

if (!$result || pg_num_rows($result) === 0) {
    header('Location: index.php');
    exit;
}

$ticket = pg_fetch_assoc($result);

if (!canViewTicket($ticket, $user)) {
    pg_free_result($result);
    pg_close($conn);
    ?>
    <div class="denied-message">
        <h2>Akses Ditolak</h2>
        <p>Anda tidak memiliki izin untuk melihat tiket ini.</p>
        <a href="index.php" class="btn btn-primary">Kembali</a>
    </div>
    <?php
    exit;
}
pg_free_result($result);
?>

<h1>Detail Tiket</h1>

<div class="card mb-20">
    <div style="display:flex;justify-content:space-between;align-items:start;">
        <div>
            <h2><?php echo htmlspecialchars($ticket['title']); ?></h2>
            <p><strong>Ticket Number:</strong> <?php echo htmlspecialchars($ticket['ticket_number']); ?></p>
        </div>
        <div>
            <span class="status-badge status-<?php echo $ticket['status']; ?>"><?php echo htmlspecialchars($ticket['status']); ?></span>
            <span class="priority-badge priority-<?php echo strtolower($ticket['priority']); ?>" style="margin-left:8px;"><?php echo htmlspecialchars($ticket['priority']); ?></span>
        </div>
    </div>

    <table style="margin-top:20px;">
        <tr>
            <td style="width:180px;font-weight:600;">Deskripsi</td>
            <td><?php echo nl2br(htmlspecialchars($ticket['description'])); ?></td>
        </tr>
        <tr>
            <td style="width:180px;font-weight:600;">Kategori</td>
            <td><?php echo htmlspecialchars($ticket['category']); ?></td>
        </tr>
        <tr>
            <td style="width:180px;font-weight:600;">Divisi</td>
            <td><?php echo htmlspecialchars($ticket['division']); ?></td>
        </tr>
        <tr>
            <td style="width:180px;font-weight:600;">Dibuat Oleh</td>
            <td><?php echo htmlspecialchars($ticket['created_by_name'] ?? '-'); ?></td>
        </tr>
        <tr>
            <td style="width:180px;font-weight:600;">Tanggal Dibuat</td>
            <td><?php echo date('d M Y H:i:s', strtotime($ticket['created_at'])); ?></td>
        </tr>
        <tr>
            <td style="width:180px;font-weight:60px;">Terakhir Diupdate</td>
            <td><?php echo date('d M Y H:i:s', strtotime($ticket['updated_at'])); ?></td>
        </tr>
    </table>
</div>

<div style="display:flex;gap:10px;">
    <?php if (canEditTicket($ticket, $user)): ?>
        <a href="edit_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn btn-warning">Edit</a>
    <?php endif; ?>
    <?php if (canDeleteTicket($ticket, $user)): ?>
        <a href="delete_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn btn-danger" onclick="return confirm('Hapus tiket ini?')">Hapus</a>
    <?php endif; ?>
    <a href="index.php" class="btn btn-primary">Kembali</a>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
