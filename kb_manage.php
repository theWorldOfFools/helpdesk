<?php
// Kelola artikel & template jawaban — admin + teknisi.
require_once 'config.php';
$user = requireRole(['admin', 'teknisi']);
$pageTitle = 'Kelola Basis Solusi';
require_once 'includes/header.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['act'] ?? '';
    if ($act === 'art_add' || $act === 'art_edit') {
        $judul = trim($_POST['judul'] ?? '');
        $kat = trim($_POST['kategori'] ?? 'Lainnya');
        $isi = trim($_POST['isi'] ?? '');
        if ($judul === '' || $isi === '') {
            $message = 'Judul dan isi wajib diisi.';
            $messageType = 'danger';
        } else {
            $isiClean = sanitizeRichText($isi);
            if ($isiClean === '') $isiClean = e($isi);
            if ($act === 'art_add') {
                $r = pg_query_params($conn, "INSERT INTO kb_articles (judul, kategori, isi, created_by) VALUES ($1,$2,$3,$4)", [$judul, $kat, $isiClean, $user['id']]);
                $message = $r ? 'Artikel ditambahkan.' : 'Gagal: ' . pg_last_error($conn);
                $messageType = $r ? 'success' : 'danger';
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $r = pg_query_params($conn, "UPDATE kb_articles SET judul=$1, kategori=$2, isi=$3, updated_at=NOW() WHERE id=$4", [$judul, $kat, $isiClean, $id]);
                $message = $r ? 'Artikel diperbarui.' : 'Gagal: ' . pg_last_error($conn);
                $messageType = $r ? 'success' : 'danger';
            }
        }
    } elseif ($act === 'art_del') {
        pg_query_params($conn, "DELETE FROM kb_articles WHERE id = $1", [(int)($_POST['id'] ?? 0)]);
        $message = 'Artikel dihapus.';
        $messageType = 'success';
    } elseif ($act === 'tpl_add') {
        $judul = trim($_POST['judul'] ?? '');
        $isi = trim($_POST['isi'] ?? '');
        if ($judul === '' || $isi === '') {
            $message = 'Judul dan isi template wajib diisi.';
            $messageType = 'danger';
        } else {
            $r = pg_query_params($conn, "INSERT INTO kb_templates (judul, isi, created_by) VALUES ($1,$2,$3)", [$judul, $isi, $user['id']]);
            $message = $r ? 'Template ditambahkan.' : 'Gagal: ' . pg_last_error($conn);
            $messageType = $r ? 'success' : 'danger';
        }
    } elseif ($act === 'tpl_del') {
        pg_query_params($conn, "DELETE FROM kb_templates WHERE id = $1", [(int)($_POST['id'] ?? 0)]);
        $message = 'Template dihapus.';
        $messageType = 'success';
    }
}

$arts = [];
$ar = pg_query($conn, "SELECT * FROM kb_articles ORDER BY updated_at DESC");
while ($row = pg_fetch_assoc($ar)) $arts[] = $row;
pg_free_result($ar);
$tpls = [];
$tr = pg_query($conn, "SELECT t.*, u.name AS author FROM kb_templates t LEFT JOIN users u ON u.id = t.created_by ORDER BY t.id");
while ($row = pg_fetch_assoc($tr)) $tpls[] = $row;
pg_free_result($tr);
$edit = null;
if (!empty($_GET['edit'])) {
    $er = pg_query_params($conn, "SELECT * FROM kb_articles WHERE id = $1", [(int)$_GET['edit']]);
    if ($er && pg_num_rows($er) > 0) $edit = pg_fetch_assoc($er);
    if ($er) pg_free_result($er);
}
?>

<h1 class="h4 mb-3">Kelola Basis Solusi</h1>
<?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div><?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <h3 class="h6"><?php echo $edit ? 'Edit Artikel' : 'Tulis Artikel Baru'; ?></h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="<?php echo $edit ? 'art_edit' : 'art_add'; ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo $edit['id']; ?>"><?php endif; ?>
        <div class="row g-2">
            <div class="col-lg-8"><input type="text" name="judul" class="form-control" required maxlength="255" placeholder="Judul solusi" value="<?php echo e($edit['judul'] ?? ''); ?>"></div>
            <div class="col-lg-4"><input type="text" name="kategori" class="form-control" required maxlength="100" placeholder="Kategori" value="<?php echo e($edit['kategori'] ?? ''); ?>"></div>
        </div>
        <textarea name="isi" class="form-control mt-2" rows="5" required placeholder="Langkah solusi… (mendukung format HTML sederhana)"><?php echo e($edit ? strip_tags($edit['isi']) : ''); ?></textarea>
        <div class="mt-2 d-flex gap-2">
            <button class="btn btn-primary btn-sm" type="submit"><?php echo $edit ? 'Simpan' : 'Tambah'; ?></button>
            <?php if ($edit): ?><a href="kb_manage.php" class="btn btn-sm btn-secondary">Batal</a><?php endif; ?>
        </div>
    </form>
</div></div>

<div class="card mb-4"><div class="card-body p-0">
    <h3 class="h6 p-3 pb-0">Daftar Artikel (<?php echo count($arts); ?>)</h3>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead class="table-dark"><tr><th>Judul</th><th>Kategori</th><th>Update</th><th>Aksi</th></tr></thead>
        <tbody>
            <?php foreach ($arts as $a): ?><tr><td><strong><?php echo e($a['judul']); ?></strong></td><td><?php echo e($a['kategori']); ?></td><td class="text-nowrap"><?php echo date('d M Y', strtotime($a['updated_at'])); ?></td>
            <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="kb.php?id=<?php echo $a['id']; ?>">Lihat</a> <a class="btn btn-sm btn-warning" href="kb_manage.php?edit=<?php echo $a['id']; ?>">Edit</a>
            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus artikel ini?')"><?php echo csrf_field(); ?><input type="hidden" name="act" value="art_del"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button class="btn btn-sm btn-outline-danger">Hapus</button></form></td></tr><?php endforeach; ?>
        </tbody>
    </table></div>
</div></div>

<div class="card mb-4"><div class="card-body">
    <h3 class="h6">Template Jawaban Cepat (dipakai di modal Tindak Lanjut)</h3>
    <form method="POST" class="row g-2 mb-3">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="tpl_add">
        <div class="col-lg-4"><input type="text" name="judul" class="form-control" required maxlength="255" placeholder="Judul template"></div>
        <div class="col-lg-6"><input type="text" name="isi" class="form-control" required placeholder="Isi pesan…"></div>
        <div class="col-lg-2"><button class="btn btn-primary w-100" type="submit">Tambah</button></div>
    </form>
    <ul class="list-group list-group-flush">
        <?php foreach ($tpls as $t): ?><li class="list-group-item px-0 d-flex justify-content-between gap-2 align-items-start">
            <span><strong><?php echo e($t['judul']); ?></strong><br><small class="text-secondary"><?php echo e(mb_strimwidth($t['isi'], 0, 160, '…')); ?></small></span>
            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus template ini?')"><?php echo csrf_field(); ?><input type="hidden" name="act" value="tpl_del"><input type="hidden" name="id" value="<?php echo $t['id']; ?>"><button class="btn btn-sm btn-outline-danger">Hapus</button></form>
        </li><?php endforeach; ?>
    </ul>
</div></div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
