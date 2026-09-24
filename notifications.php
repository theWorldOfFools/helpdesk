<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$pageTitle = 'Notifikasi';
require_once 'includes/header.php';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (($_POST['act'] ?? '') === 'read_all') {
        pg_query_params($conn, "UPDATE notifications SET is_read = TRUE WHERE user_id = $1", [$user['id']]);
    } elseif (($_POST['act'] ?? '') === 'read_one') {
        pg_query_params($conn, "UPDATE notifications SET is_read = TRUE WHERE id = $1 AND user_id = $2", [(int)($_POST['nid'] ?? 0), $user['id']]);
    }
}

$notifs = [];
$nr = pg_query_params($conn, "SELECT * FROM notifications WHERE user_id = $1 ORDER BY id DESC LIMIT 50", [$user['id']]);
if ($nr) {
    while ($row = pg_fetch_assoc($nr)) $notifs[] = $row;
    pg_free_result($nr);
}
?>

<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-4">
    <h2 class="h4 mb-0">Notifikasi</h2>
    <form method="POST" class="d-inline">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="read_all">
        <button class="btn btn-sm btn-outline-secondary" type="submit">Tandai semua dibaca</button>
    </form>
</div>

<div class="card"><div class="card-body p-0">
<?php if ($notifs): ?>
<ul class="list-group list-group-flush">
    <?php foreach ($notifs as $n): ?>
    <li class="list-group-item d-flex justify-content-between gap-2 align-items-start <?php echo $n['is_read'] === 't' || $n['is_read'] == 1 ? '' : 'bg-light'; ?>">
        <span>
            <?php if (!($n['is_read'] === 't' || $n['is_read'] == 1)): ?><span class="badge text-bg-danger me-1">baru</span><?php endif; ?>
            <?php $isCrNotif = !empty($n['link']) && strpos($n['link'], 'view_cr.php') !== false; ?>
            <?php if ($isCrNotif): ?><span class="badge text-bg-info me-1">CR</span><?php elseif (!empty($n['link']) && strpos($n['link'], 'view_ticket.php') !== false): ?><span class="badge text-bg-secondary me-1">Tiket</span><?php endif; ?>
            <strong><?php echo e($n['judul']); ?></strong><br>
            <small class="text-secondary"><?php echo e($n['isi'] ?? ''); ?><br><?php echo date('d M Y H:i', strtotime($n['created_at'])); ?></small>
        </span>
        <span class="d-flex gap-1 flex-shrink-0">
            <?php if (!empty($n['link']) && preg_match('#^(view_(ticket|cr)\.php\?id=\d+|cr_list\.php|index\.php)#', $n['link'])): ?><a class="btn btn-sm btn-primary" href="<?php echo e($n['link']); ?>">Buka</a><?php elseif (!empty($n['link'])): ?><a class="btn btn-sm btn-primary" href="<?php echo e($n['link']); ?>">Buka</a><?php endif; ?>
            <?php if (!($n['is_read'] === 't' || $n['is_read'] == 1)): ?>
            <form method="POST" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="act" value="read_one"><input type="hidden" name="nid" value="<?php echo $n['id']; ?>"><button class="btn btn-sm btn-outline-secondary">✓</button></form>
            <?php endif; ?>
        </span>
    </li>
    <?php endforeach; ?>
</ul>
<?php else: ?><p class="text-center text-secondary py-4 mb-0">Belum ada notifikasi.</p><?php endif; ?>
</div></div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
