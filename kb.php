<?php
// Basis Solusi — baca untuk semua role login.
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$pageTitle = 'Basis Solusi';
require_once 'includes/header.php';

$conn = getDBConnection();
$search = trim($_GET['search'] ?? '');
$cats = [];
$cr = pg_query($conn, "SELECT DISTINCT kategori FROM kb_articles ORDER BY kategori");
while ($row = pg_fetch_assoc($cr)) $cats[] = $row['kategori'];
pg_free_result($cr);
$fCat = trim($_GET['kategori'] ?? '');

$conds = ['1=1'];
$params = [];
if ($search !== '') {
    $params[] = '%' . $search . '%';
    $conds[] = '(judul ILIKE $' . count($params) . ' OR isi ILIKE $' . count($params) . ')';
}
if ($fCat !== '') {
    $params[] = $fCat;
    $conds[] = 'kategori = $' . count($params);
}
$where = implode(' AND ', $conds);
$r = pg_query_params($conn, "SELECT k.*, u.name AS author FROM kb_articles k LEFT JOIN users u ON u.id = k.created_by WHERE $where ORDER BY k.updated_at DESC", $params);
$arts = [];
if ($r) {
    while ($row = pg_fetch_assoc($r)) $arts[] = $row;
    pg_free_result($r);
}
$view = null;
if (!empty($_GET['id'])) {
    $vr = pg_query_params($conn, "SELECT k.*, u.name AS author FROM kb_articles k LEFT JOIN users u ON u.id = k.created_by WHERE k.id = $1", [(int)$_GET['id']]);
    if ($vr && pg_num_rows($vr) > 0) $view = pg_fetch_assoc($vr);
    if ($vr) pg_free_result($vr);
}
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Basis Solusi</h2>
        <p class="text-secondary mb-0">Kumpulan solusi kendala berulang. Cari dulu sebelum buat/kerjakan tiket.</p>
    </div>
    <?php if (in_array($user['role'], ['admin', 'teknisi'], true)): ?>
    <a href="kb_manage.php" class="btn btn-primary flex-shrink-0">Kelola Artikel</a>
    <?php endif; ?>
</div>

<?php if ($view): ?>
<div class="card mb-4"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
        <div><span class="badge text-bg-info"><?php echo e($view['kategori']); ?></span>
        <h3 class="h5 mt-2 mb-1"><?php echo e($view['judul']); ?></h3>
        <small class="text-secondary">Oleh <?php echo e($view['author'] ?? '-'); ?> · <?php echo date('d M Y H:i', strtotime($view['updated_at'])); ?></small></div>
        <a href="kb.php" class="btn btn-sm btn-outline-secondary">← Kembali</a>
    </div>
    <div class="rich-content"><?php echo renderTicketDescription($view['isi']); ?></div>
</div></div>
<?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <form method="GET" class="row g-2">
        <div class="col-12 col-lg-6"><input type="text" name="search" class="form-control" placeholder="Cari solusi…" value="<?php echo e($search); ?>"></div>
        <div class="col-6 col-lg-4"><select name="kategori" class="form-select"><option value="">Semua Kategori</option><?php foreach ($cats as $c): ?><option value="<?php echo e($c); ?>" <?php echo $fCat === $c ? 'selected' : ''; ?>><?php echo e($c); ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-lg-2"><button class="btn btn-primary w-100" type="submit">Cari</button></div>
    </form>
</div></div>

<div class="row g-3">
<?php foreach ($arts as $a): ?>
    <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body d-flex flex-column">
        <span class="badge text-bg-info align-self-start mb-2"><?php echo e($a['kategori']); ?></span>
        <h3 class="h6"><?php echo e($a['judul']); ?></h3>
        <p class="small text-secondary flex-grow-1"><?php echo e(mb_strimwidth(strip_tags($a['isi']), 0, 140, '…')); ?></p>
        <a href="kb.php?id=<?php echo $a['id']; ?>" class="btn btn-sm btn-outline-primary align-self-start">Baca solusi →</a>
    </div></div></div>
<?php endforeach; ?>
<?php if (empty($arts)): ?><div class="col-12"><div class="alert alert-light border text-center text-secondary">Belum ada artikel. <?php if (in_array($user['role'], ['admin', 'teknisi'], true)): ?><a href="kb_manage.php">Tulis yang pertama →</a><?php endif; ?></div></div><?php endif; ?>
</div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
