<?php
require_once 'includes/header.php';
require_once 'config.php';
requireLogin();

$pageTitle = 'Daftar Tiket';
$conn = getDBConnection();
$user = getCurrentUser();

$search = isset($_GET['search']) ? pg_escape_string($conn, $_GET['search']) : '';
$filterStatus = isset($_GET['filter_status']) ? pg_escape_string($conn, $_GET['filter_status']) : '';
$filterDivision = isset($_GET['filter_division']) ? pg_escape_string($conn, $_GET['filter_division']) : '';

$query = "SELECT t.*, u.name AS created_by_name FROM tickets t LEFT JOIN users u ON t.user_id = u.id WHERE 1=1";

if ($user['role'] === 'pelapor') {
    $query .= " AND t.user_id = " . $user['id'];
}

if (!empty($search)) {
    $query .= " AND (t.title ILIKE '%" . $search . "%' OR t.ticket_number ILIKE '%" . $search . "%' OR t.description ILIKE '%" . $search . "%')";
}
if (!empty($filterStatus)) {
    $query .= " AND t.status = '" . $filterStatus . "'";
}
if (!empty($filterDivision)) {
    $query .= " AND t.division = '" . $filterDivision . "'";
}

$query .= " ORDER BY t.created_at DESC";
$result = pg_query($conn, $query);

$divisionsQuery = "SELECT DISTINCT division FROM tickets ORDER BY division";
if ($user['role'] === 'pelapor') {
    $divisionsQuery = "SELECT DISTINCT division FROM tickets WHERE user_id = " . $user['id'] . " ORDER BY division";
}
$divisionsResult = pg_query($conn, $divisionsQuery);

$statusCounts = [];
$countQuery = "SELECT status, COUNT(*) as count FROM tickets WHERE 1=1";
if ($user['role'] === 'pelapor') {
    $countQuery .= " AND user_id = " . $user['id'];
}
$allResult = pg_query($conn, $countQuery);
while ($row = pg_fetch_assoc($allResult)) {
    $statusCounts[$row['status']] = (int)$row['count'];
}
pg_free_result($allResult);
?>

<div class="flex-between mb-20">
    <h1>Daftar Tiket</h1>
    <a href="create_ticket.php" class="btn btn-primary">+ Tiket Baru</a>
</div>

<div class="stats-grid mb-20">
    <div class="stat-card open">
        <div class="stat-number"><?php echo $statusCounts['open'] ?? 0; ?></div>
        <div class="stat-label">Open</div>
    </div>
    <div class="stat-card in_progress">
        <div class="stat-number"><?php echo $statusCounts['in_progress'] ?? 0; ?></div>
        <div class="stat-label">In Progress</div>
    </div>
    <div class="stat-card resolved">
        <div class="stat-number"><?php echo $statusCounts['resolved'] ?? 0; ?></div>
        <div class="stat-label">Resolved</div>
    </div>
    <div class="stat-card closed">
        <div class="stat-number"><?php echo $statusCounts['closed'] ?? 0; ?></div>
        <div class="stat-label">Closed</div>
    </div>
</div>

<div class="card mb-20">
    <form method="GET" class="flex-between">
        <div style="display:flex;gap:10px;flex:1;">
            <input type="text" name="search" placeholder="Cari tiket..." value="<?php echo htmlspecialchars($search); ?>" style="flex:1;padding:10px;border:1px solid #ddd;border-radius:5px;">
            <select name="filter_status" style="padding:10px;border:1px solid #ddd;border-radius:5px;">
                <option value="">Semua Status</option>
                <option value="open" <?php echo $filterStatus === 'open' ? 'selected' : ''; ?>>Open</option>
                <option value="in_progress" <?php echo $filterStatus === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="resolved" <?php echo $filterStatus === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                <option value="closed" <?php echo $filterStatus === 'closed' ? 'selected' : ''; ?>>Closed</option>
            </select>
            <select name="filter_division" style="padding:10px;border:1px solid #ddd;border-radius:5px;">
                <option value="">Semua Divisi</option>
                <?php while ($div = pg_fetch_assoc($divisionsResult)): ?>
                    <option value="<?php echo htmlspecialchars($div['division']); ?>" <?php echo $filterDivision === $div['division'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($div['division']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
        </div>
    </form>
</div>

<div class="card">
    <?php if ($result && pg_num_rows($result) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Ticket Number</th>
                    <th>Judul</th>
                    <th>Kategori</th>
                    <th>Prioritas</th>
                    <th>Status</th>
                    <th>Divisi</th>
                    <th>Dibuat Oleh</th>
                    <th>Tanggal</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = pg_fetch_assoc($result)): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['ticket_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                        <td><?php echo htmlspecialchars($row['category']); ?></td>
                        <td><span class="priority-badge priority-<?php echo strtolower($row['priority']); ?>"><?php echo htmlspecialchars($row['priority']); ?></span></td>
                        <td><span class="status-badge status-<?php echo $row['status']; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($row['division']); ?></td>
                        <td><?php echo htmlspecialchars($row['created_by_name'] ?? '-'); ?></td>
                        <td><?php echo date('d M Y H:i', strtotime($row['created_at'])); ?></td>
                        <td>
                            <a href="view_ticket.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">Detail</a>
                            <?php if (in_array($user['role'], ['admin', 'teknisi'])): ?>
                                <a href="edit_ticket.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="text-align:center;padding:40px;color:#999;">Tidak ada tiket ditemukan.</p>
    <?php endif; ?>
    <?php if ($result) pg_free_result($result); ?>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
