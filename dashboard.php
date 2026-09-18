<?php
require_once 'includes/header.php';
require_once 'config.php';
requireLogin();

$pageTitle = 'Dashboard Monitoring';
$conn = getDBConnection();
$user = getCurrentUser();

$totalTicketsQuery = "SELECT COUNT(*) FROM tickets";
$params = [];
if ($user['role'] === 'pelapor') {
    $totalTicketsQuery = "SELECT COUNT(*) FROM tickets WHERE user_id = $1";
    $params[] = $user['id'];
}
$totalTickets = pg_fetch_result(pg_query_params($conn, $totalTicketsQuery, $params), 0, 0);
$openTickets = pg_fetch_result(pg_query_params($conn, str_replace("FROM tickets", "FROM tickets WHERE status = 'open'", $totalTicketsQuery), $params), 0, 0);
$inProgress = pg_fetch_result(pg_query_params($conn, str_replace("FROM tickets", "FROM tickets WHERE status = 'in_progress'", $totalTicketsQuery), $params), 0, 0);
$resolved = pg_fetch_result(pg_query_params($conn, str_replace("FROM tickets", "FROM tickets WHERE status = 'resolved'", $totalTicketsQuery), $params), 0, 0);
$closed = pg_fetch_result(pg_query_params($conn, str_replace("FROM tickets", "FROM tickets WHERE status = 'closed'", $totalTicketsQuery), $params), 0, 0);

$statusResult = pg_query_params($conn, "SELECT status, COUNT(*) as count FROM tickets WHERE 1=1" . ($user['role'] === 'pelapor' ? " AND user_id = $1" : "") . " GROUP BY status ORDER BY status", array_merge($params, []));
$statusData = [];
while ($row = pg_fetch_assoc($statusResult)) {
    $statusData[] = $row;
}
pg_free_result($statusResult);

$divisionQuery = "SELECT division, COUNT(*) as count FROM tickets WHERE 1=1";
$divParams = [];
if ($user['role'] === 'pelapor') {
    $divisionQuery .= " AND user_id = $1";
    $divParams[] = $user['id'];
}
$divisionQuery .= " GROUP BY division ORDER BY division";
$divisionResult = pg_query_params($conn, $divisionQuery, $divParams);
$divisionData = [];
while ($row = pg_fetch_assoc($divisionResult)) {
    $divisionData[] = $row;
}
pg_free_result($divisionResult);

$priorityResult = pg_query_params($conn, "SELECT priority, COUNT(*) as count FROM tickets WHERE 1=1" . ($user['role'] === 'pelapor' ? " AND user_id = $1" : "") . " GROUP BY priority ORDER BY priority", $divParams);
$priorityData = [];
while ($row = pg_fetch_assoc($priorityResult)) {
    $priorityData[] = $row;
}
pg_free_result($priorityResult);

$recentQuery = "SELECT * FROM tickets WHERE 1=1";
if ($user['role'] === 'pelapor') {
    $recentQuery .= " AND user_id = $1";
}
$recentQuery .= " ORDER BY created_at DESC LIMIT 5";
$recentResult = pg_query_params($conn, $recentQuery, $divParams);
$recentTickets = [];
while ($row = pg_fetch_assoc($recentResult)) {
    $recentTickets[] = $row;
}
pg_free_result($recentResult);

if ($user['role'] === 'admin' || $user['role'] === 'teknisi') {
    $allDivisionResult = pg_query($conn, "SELECT division, COUNT(*) as count FROM tickets GROUP BY division ORDER BY division");
    $allDivisionData = [];
    while ($row = pg_fetch_assoc($allDivisionResult)) {
        $allDivisionData[] = $row;
    }
    pg_free_result($allDivisionResult);
}
?>

<h1>Dashboard Monitoring</h1>
<p style="color:#666;margin-bottom:20px;">Login sebagai: <strong><?php echo htmlspecialchars($user['name']); ?></strong> (<?php echo htmlspecialchars($user['role']); ?>)</p>

<div class="stats-grid mb-20">
    <div class="stat-card">
        <div class="stat-number"><?php echo $totalTickets; ?></div>
        <div class="stat-label">Total Tiket</div>
    </div>
    <div class="stat-card open">
        <div class="stat-number"><?php echo $openTickets; ?></div>
        <div class="stat-label">Open</div>
    </div>
    <div class="stat-card in_progress">
        <div class="stat-number"><?php echo $inProgress; ?></div>
        <div class="stat-label">In Progress</div>
    </div>
    <div class="stat-card resolved">
        <div class="stat-number"><?php echo $resolved; ?></div>
        <div class="stat-label">Resolved</div>
    </div>
    <div class="stat-card closed">
        <div class="stat-number"><?php echo $closed; ?></div>
        <div class="stat-label">Closed</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:20px;margin-bottom:20px;">
    <div class="card">
        <h3>Status Tiket</h3>
        <?php foreach ($statusData as $item): ?>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;">
                <span><?php echo htmlspecialchars($item['status']); ?></span>
                <strong><?php echo $item['count']; ?></strong>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($divisionData)): ?>
    <div class="card">
        <h3>Berdasarkan Divisi</h3>
        <?php foreach ($divisionData as $item): ?>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;">
                <span><?php echo htmlspecialchars($item['division']); ?></span>
                <strong><?php echo $item['count']; ?></strong>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:20px;margin-bottom:20px;">
    <div class="card">
        <h3>Prioritas Tiket</h3>
        <?php foreach ($priorityData as $item): ?>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;">
                <span class="priority-badge priority-<?php echo strtolower($item['priority']); ?>"><?php echo htmlspecialchars($item['priority']); ?></span>
                <strong><?php echo $item['count']; ?></strong>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card">
        <h3>Tiket Terakhir</h3>
        <?php if (!empty($recentTickets)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Judul</th>
                        <th>Status</th>
                        <th>Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTickets as $rt): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($rt['ticket_number']); ?></td>
                            <td><?php echo htmlspecialchars($rt['title']); ?></td>
                            <td><span class="status-badge status-<?php echo $rt['status']; ?>"><?php echo $rt['status']; ?></span></td>
                            <td><?php echo date('d M Y', strtotime($rt['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align:center;color:#999;padding:20px;">Belum ada tiket.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($user['role'] === 'admin' && !empty($allDivisionData)): ?>
<div class="card">
    <h3>Ringkasan Semua Divisi</h3>
    <table>
        <thead>
            <tr>
                <th>Divisi</th>
                <th>Total</th>
                <th>Open</th>
                <th>In Progress</th>
                <th>Resolved</th>
                <th>Closed</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allDivisionData as $div): ?>
                <?php
                $divTotal = $div['count'];
                $divOpen = pg_fetch_result(pg_query_params($conn, "SELECT COUNT(*) FROM tickets WHERE division = $1 AND status = 'open'", [$div['division']]), 0, 0);
                $divInProgress = pg_fetch_result(pg_query_params($conn, "SELECT COUNT(*) FROM tickets WHERE division = $1 AND status = 'in_progress'", [$div['division']]), 0, 0);
                $divResolved = pg_fetch_result(pg_query_params($conn, "SELECT COUNT(*) FROM tickets WHERE division = $1 AND status = 'resolved'", [$div['division']]), 0, 0);
                $divClosed = pg_fetch_result(pg_query_params($conn, "SELECT COUNT(*) FROM tickets WHERE division = $1 AND status = 'closed'", [$div['division']]), 0, 0);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($div['division']); ?></td>
                    <td><?php echo $divTotal; ?></td>
                    <td><?php echo $divOpen; ?></td>
                    <td><?php echo $divInProgress; ?></td>
                    <td><?php echo $divResolved; ?></td>
                    <td><?php echo $divClosed; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
