<?php
// Preview publik artikel Basis Solusi — TANPA login, hanya-lihat, akses via token bagikan.
require_once 'config.php';

header('X-Robots-Tag: noindex, nofollow');

$token = trim($_GET['token'] ?? '');
$art = null;
if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
    $conn = getDBConnection();
    $r = pg_query_params($conn, "SELECT k.id, k.judul, k.kategori, k.isi, k.updated_at, u.name AS author FROM kb_articles k LEFT JOIN users u ON u.id = k.created_by WHERE k.share_token = $1", [$token]);
    if ($r && pg_num_rows($r) > 0) $art = pg_fetch_assoc($r);
    if ($r) pg_free_result($r);
    pg_close($conn);
}
if (!$art) http_response_code(404);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $art ? e($art['judul']) . ' · Basis Solusi' : 'Artikel tidak ditemukan'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <meta name="theme-color" content="#1a1a2e">
</head>
<body class="bg-light">
<main class="container py-4" style="max-width: 860px;">
    <div class="text-center mb-3">
        <span class="fw-bold" style="color:#1a1a2e;">Helpdesk IT</span><span class="text-secondary small"> · Basis Solusi</span>
    </div>

    <?php if ($art): ?>
    <div class="card border-0 shadow-sm"><div class="card-body p-4">
        <span class="badge text-bg-info"><?php echo e($art['kategori']); ?></span>
        <h1 class="h4 mt-2 mb-1"><?php echo e($art['judul']); ?></h1>
        <p class="text-secondary small mb-3">Oleh <?php echo e($art['author'] ?? '-'); ?> · Diperbarui <?php echo date('d M Y H:i', strtotime($art['updated_at'])); ?></p>
        <div class="rich-content"><?php echo renderTicketDescription($art['isi']); ?></div>
    </div></div>
    <?php else: ?>
    <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center">
        <i class="bi bi-link-45deg fs-1 text-secondary"></i>
        <h1 class="h5 mt-2 mb-1">Artikel tidak ditemukan</h1>
        <p class="text-secondary mb-0">Link bagikan tidak valid atau sudah dicabut oleh staf IT.</p>
    </div></div>
    <?php endif; ?>

    <p class="text-center text-secondary small mt-3 mb-0">Dibagikan dari sistem Helpdesk IT · <a href="login.php">Masuk Helpdesk</a></p>
</main>
</body>
</html>
