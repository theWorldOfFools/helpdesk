<?php
// Drive internal & foldering — browser ala Google Drive (semua role, requireLogin).
// Akses privat/divisi/publik + share eksplisit, mirror pola tasks.php/kb.php.
require_once 'config.php';
require_once 'includes/drive_auth.php';
requireLogin();

$user = getCurrentUser();
$pageTitle = 'Drive & Dokumen';

require_once 'includes/header.php';

$conn = getDBConnection();

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? flash_error();
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$curFolderId = isset($_GET['folder']) && (int)$_GET['folder'] > 0 ? (int)$_GET['folder'] : null;
$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'mine', 'division', 'shared', 'public'], true)) $filter = 'all';

$curFolder = null;
if ($curFolderId) {
    $curFolder = getDriveFolder($conn, $curFolderId);
    if (!$curFolder || !canViewDrive($conn, $curFolder, $user)) {
        pg_close($conn);
        $_SESSION['flash_error'] = 'Folder tidak ditemukan / tidak ada akses.';
        header('Location: drive.php');
        exit;
    }
}

$breadcrumb = $curFolder ? getDriveBreadcrumb($conn, $curFolder) : [];
$canEditCur = $curFolder ? canEditDrive($conn, $curFolder, $user) : true; // root: semua boleh buat folder
$quota = driveQuotaUsage($conn, (int)$user['id']);
$quotaMax = driveMaxBytesPerUser();
$quotaPct = $quotaMax > 0 ? min(100, round($quota['bytes'] / $quotaMax * 100, 1)) : 0;

// Peta share langsung milik user (untuk badge/filter tanpa N+1 berlebih)
$myShareMap = [];
$shr = pg_query_params($conn, "SELECT folder_id, permission FROM drive_shares WHERE shared_with_user_id = $1", [(int)$user['id']]);
if ($shr) {
    while ($row = pg_fetch_assoc($shr)) $myShareMap[(int)$row['folder_id']] = $row['permission'];
    pg_free_result($shr);
}

function driveMatchesFilter($f, $filter, $user, $myShareMap)
{
    $uid = (int)($user['id'] ?? 0);
    if ($filter === 'all') return true;
    if ($filter === 'mine') return (int)($f['owner_id'] ?? 0) === $uid;
    if ($filter === 'public') return ($f['visibility'] ?? '') === 'public';
    if ($filter === 'division') {
        return ($f['visibility'] ?? '') === 'division'
            && trim((string)($f['division'] ?? '')) !== ''
            && trim((string)($f['division'] ?? '')) === trim((string)($user['division'] ?? ''));
    }
    if ($filter === 'shared') {
        if ((int)($f['owner_id'] ?? 0) === $uid) return false;
        return isset($myShareMap[(int)$f['id']]);
    }
    return true;
}

// ---- Ambil folder & file ----
$folders = [];
$files = [];
$searchFolders = [];
$searchFiles = [];

if ($search !== '') {
    // Pencarian global di semua yang boleh dilihat (batas 100 + 50)
    $like = '%' . $search . '%';
    $fr = pg_query_params(
        $conn,
        "SELECT f.*, u.name AS owner_name FROM drive_folders f LEFT JOIN users u ON u.id = f.owner_id"
        . " WHERE f.name ILIKE $1 ORDER BY f.updated_at DESC LIMIT 100",
        [$like]
    );
    if ($fr) {
        while ($row = pg_fetch_assoc($fr)) {
            if (canViewDrive($conn, $row, $user) && driveMatchesFilter($row, $filter, $user, $myShareMap)) {
                $searchFolders[] = $row;
            }
        }
        pg_free_result($fr);
    }
    $qr = pg_query_params(
        $conn,
        "SELECT f.*, u.name AS uploader_name, d.name AS folder_name FROM drive_files f"
        . " LEFT JOIN users u ON u.id = f.uploaded_by LEFT JOIN drive_folders d ON d.id = f.folder_id"
        . " WHERE f.original_name ILIKE $1 ORDER BY f.created_at DESC LIMIT 50",
        [$like]
    );
    if ($qr) {
        while ($row = pg_fetch_assoc($qr)) {
            $fd = getDriveFolder($conn, (int)$row['folder_id']);
            if ($fd && canViewDrive($conn, $fd, $user)) $searchFiles[] = $row;
        }
        pg_free_result($qr);
    }
} else {
    if ($curFolderId) {
        $fr = pg_query_params(
            $conn,
            "SELECT f.*, u.name AS owner_name FROM drive_folders f LEFT JOIN users u ON u.id = f.owner_id"
            . " WHERE f.parent_id = $1 ORDER BY f.name ASC",
            [$curFolderId]
        );
    } else {
        $fr = pg_query(
            $conn,
            "SELECT f.*, u.name AS owner_name FROM drive_folders f LEFT JOIN users u ON u.id = f.owner_id"
            . " WHERE f.parent_id IS NULL ORDER BY f.name ASC"
        );
    }
    if ($fr) {
        while ($row = pg_fetch_assoc($fr)) {
            if (canViewDrive($conn, $row, $user) && driveMatchesFilter($row, $filter, $user, $myShareMap)) {
                $folders[] = $row;
            }
        }
        pg_free_result($fr);
    }
    if ($curFolderId) {
        $qr = pg_query_params(
            $conn,
            "SELECT f.*, u.name AS uploader_name FROM drive_files f LEFT JOIN users u ON u.id = f.uploaded_by"
            . " WHERE f.folder_id = $1 ORDER BY f.created_at DESC",
            [$curFolderId]
        );
        if ($qr) {
            while ($row = pg_fetch_assoc($qr)) $files[] = $row;
            pg_free_result($qr);
        }
    }
}

// Kandidat user untuk share (aktif, bukan diri sendiri)
$shareCandidates = [];
$ur = pg_query_params($conn, "SELECT id, name, username, division, role FROM users WHERE is_active = TRUE AND id <> $1 ORDER BY name ASC LIMIT 200", [(int)$user['id']]);
if ($ur) {
    while ($row = pg_fetch_assoc($ur)) $shareCandidates[] = $row;
    pg_free_result($ur);
}

// Kandidat folder tujuan pindah (yang boleh diedit, batas 200)
$moveTargets = [];
$mr = pg_query($conn, "SELECT id, name, parent_id FROM drive_folders ORDER BY name ASC LIMIT 200");
if ($mr) {
    while ($row = pg_fetch_assoc($mr)) {
        $full = getDriveFolder($conn, (int)$row['id']);
        if ($full && canEditDrive($conn, $full, $user)) $moveTargets[] = $full;
    }
    pg_free_result($mr);
}

$shares = $curFolder ? getDriveShares($conn, (int)$curFolder['id']) : [];
$visLabels = driveVisibilities();
$visBadge = ['private' => 'text-bg-secondary', 'division' => 'text-bg-info', 'public' => 'text-bg-success'];

function driveKeepQS($over = [])
{
    $q = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = $v;
    }
    return http_build_query($q);
}

function driveFileIcon($name)
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return ['bi-image', 'text-primary'];
    if ($ext === 'pdf') return ['bi-file-earmark-pdf', 'text-danger'];
    if (in_array($ext, ['doc', 'docx'], true)) return ['bi-file-earmark-word', 'text-primary'];
    if (in_array($ext, ['xls', 'xlsx', 'csv'], true)) return ['bi-file-earmark-excel', 'text-success'];
    if (in_array($ext, ['ppt', 'pptx'], true)) return ['bi-file-earmark-slides', 'text-warning'];
    if (in_array($ext, ['zip', 'rar'], true)) return ['bi-file-earmark-zip', 'text-secondary'];
    if (in_array($ext, ['mp4'], true)) return ['bi-file-earmark-play', 'text-dark'];
    if (in_array($ext, ['mp3'], true)) return ['bi-file-earmark-music', 'text-dark'];
    return ['bi-file-earmark-text', 'text-secondary'];
}
?>

<?php if ($flashOk): ?><div class="alert alert-success"><?php echo e($flashOk); ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="alert alert-danger"><?php echo e($flashErr); ?></div><?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h2 class="h4 mb-1"><i class="bi bi-hdd"></i> Drive & Dokumen</h2>
        <p class="text-secondary mb-0 small">Folder pribadi + divisi + SOP publik. Klik folder untuk masuk, bagikan bila perlu.</p>
    </div>
    <div class="d-flex gap-2 align-items-center flex-shrink-0">
        <span class="small text-secondary" title="Kuota pribadi"><?php echo fmtDriveSize($quota['bytes']); ?> / <?php echo fmtDriveSize($quotaMax); ?></span>
        <div class="progress" style="width:110px;height:8px;" title="<?php echo $quotaPct; ?>%">
            <div class="progress-bar<?php echo $quotaPct >= 90 ? ' bg-danger' : ''; ?>" style="width:<?php echo $quotaPct; ?>%"></div>
        </div>
    </div>
</div>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb">
    <ol class="breadcrumb bg-white border rounded-3 px-3 py-2">
        <li class="breadcrumb-item"><a href="drive.php" class="text-decoration-none"><i class="bi bi-house"></i> Root</a></li>
        <?php foreach ($breadcrumb as $b): ?>
        <li class="breadcrumb-item<?php echo (int)$b['id'] === (int)($curFolder['id'] ?? 0) ? ' active' : ''; ?>">
            <?php if ((int)$b['id'] === (int)($curFolder['id'] ?? 0)): ?><?php echo e($b['name']); ?>
            <?php else: ?><a href="drive.php?folder=<?php echo (int)$b['id']; ?>" class="text-decoration-none"><?php echo e($b['name']); ?></a><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</nav>

<!-- Search + filter -->
<div class="card mb-3"><div class="card-body">
    <form method="GET" class="row g-2 align-items-center">
        <?php if ($curFolderId): ?><input type="hidden" name="folder" value="<?php echo (int)$curFolderId; ?>"><?php endif; ?>
        <div class="col-12 col-lg-5">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" name="search" placeholder="Cari folder / nama file…" value="<?php echo e($search); ?>">
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <select name="filter" class="form-select" onchange="this.form.submit()">
                <option value="all"<?php echo $filter === 'all' ? ' selected' : ''; ?>>Semua yang bisa saya lihat</option>
                <option value="mine"<?php echo $filter === 'mine' ? ' selected' : ''; ?>>Milik saya</option>
                <option value="division"<?php echo $filter === 'division' ? ' selected' : ''; ?>>Divisi saya (<?php echo e($user['division'] ?? '-'); ?>)</option>
                <option value="shared"<?php echo $filter === 'shared' ? ' selected' : ''; ?>>Dibagikan ke saya</option>
                <option value="public"<?php echo $filter === 'public' ? ' selected' : ''; ?>>Publik (SOP)</option>
            </select>
        </div>
        <div class="col-12 col-lg-auto d-flex gap-2">
            <button class="btn btn-primary btn-sm" type="submit">Cari</button>
            <?php if ($search !== '' || $filter !== 'all'): ?><a href="drive.php<?php echo $curFolderId ? '?folder=' . (int)$curFolderId : ''; ?>" class="btn btn-outline-secondary btn-sm">Reset</a><?php endif; ?>
        </div>
    </form>
    <?php if ($search !== ''): ?><p class="small text-secondary mb-0 mt-2">Hasil pencarian global untuk “<?php echo e($search); ?>” (folder: <?php echo count($searchFolders); ?>, file: <?php echo count($searchFiles); ?>).</p><?php endif; ?>
</div>

<?php if ($search !== ''): ?>
<!-- ===== MODE PENCARIAN ===== -->
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-folder"></i> Folder (<?php echo count($searchFolders); ?>)</div>
            <div class="list-group list-group-flush">
                <?php if (empty($searchFolders)): ?><p class="text-secondary small p-3 mb-0">Tidak ada folder cocok.</p><?php endif; ?>
                <?php foreach ($searchFolders as $sf): ?>
                <a href="drive.php?folder=<?php echo (int)$sf['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-folder text-warning"></i> <?php echo e($sf['name']); ?><br><small class="text-secondary"><?php echo e($sf['owner_name'] ?? '-'); ?></small></span>
                    <span class="badge <?php echo $visBadge[$sf['visibility']] ?? 'text-bg-secondary'; ?>"><?php echo e($visLabels[$sf['visibility']] ?? $sf['visibility']); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-file-earmark"></i> File (<?php echo count($searchFiles); ?>)</div>
            <div class="list-group list-group-flush">
                <?php if (empty($searchFiles)): ?><p class="text-secondary small p-3 mb-0">Tidak ada file cocok.</p><?php endif; ?>
                <?php foreach ($searchFiles as $sf): ?>
                <?php [$ic, $cc] = driveFileIcon($sf['original_name']); ?>
                <div class="list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <span><i class="bi <?php echo $ic; ?> <?php echo $cc; ?>"></i> <?php echo e($sf['original_name']); ?><br><small class="text-secondary">di <?php echo e($sf['folder_name'] ?? '-'); ?> · <?php echo fmtDriveSize($sf['file_size']); ?></small></span>
                    <span class="d-flex gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="drive_download.php?id=<?php echo (int)$sf['id']; ?>&preview=1" target="_blank" rel="noopener">Lihat</a>
                        <a class="btn btn-sm btn-primary" href="drive_download.php?id=<?php echo (int)$sf['id']; ?>">Unduh</a>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ===== MODE BROWSE ===== -->
<div class="row g-3 align-items-start">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold">
                    <i class="bi bi-folder2-open text-warning"></i>
                    <?php echo $curFolder ? e($curFolder['name']) : 'Root'; ?>
                    <?php if ($curFolder): ?><span class="badge <?php echo $visBadge[$curFolder['visibility']] ?? 'text-bg-secondary'; ?> ms-1"><?php echo e($visLabels[$curFolder['visibility']] ?? ''); ?></span><?php endif; ?>
                </span>
                <small class="text-secondary"><?php echo count($folders); ?> folder · <?php echo count($files); ?> file</small>
            </div>
            <div class="list-group list-group-flush">
                <?php if ($curFolderId): ?>
                <?php $upId = $curFolder['parent_id'] ? (int)$curFolder['parent_id'] : null; ?>
                <a href="drive.php<?php echo $upId ? '?folder=' . $upId : ''; ?>" class="list-group-item list-group-item-action text-secondary"><i class="bi bi-arrow-90deg-up"></i> .. ke atas</a>
                <?php endif; ?>
                <?php if (empty($folders) && empty($files)): ?>
                <p class="text-secondary small text-center p-4 mb-0">📁 Folder ini kosong. Buat subfolder atau upload file di panel kanan.</p>
                <?php endif; ?>
                <?php foreach ($folders as $fd): ?>
                <?php $canEdit = canEditDrive($conn, $fd, $user); ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <a href="drive.php?folder=<?php echo (int)$fd['id']; ?>" class="text-decoration-none fw-semibold"><i class="bi bi-folder-fill text-warning"></i> <?php echo e($fd['name']); ?></a>
                        <span class="d-flex gap-1 align-items-center flex-wrap">
                            <span class="badge <?php echo $visBadge[$fd['visibility']] ?? 'text-bg-secondary'; ?>"><?php echo e($visLabels[$fd['visibility']] ?? ''); ?></span>
                            <?php if (isset($myShareMap[(int)$fd['id']])): ?><span class="badge text-bg-primary">shared:<?php echo e($myShareMap[(int)$fd['id']]); ?></span><?php endif; ?>
                            <small class="text-secondary"><?php echo e($fd['owner_name'] ?? '-'); ?></small>
                            <?php if ($canEdit): ?>
                            <form method="POST" action="drive_action.php" class="d-inline" onsubmit="return confirm('Hapus folder & seluruh isinya?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="folder_delete">
                                <input type="hidden" name="id" value="<?php echo (int)$fd['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Hapus folder"><i class="bi bi-trash"></i></button>
                            </form>
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#frn-<?php echo (int)$fd['id']; ?>" title="Rename/pindah"><i class="bi bi-pencil"></i></button>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($canEdit): ?>
                    <div class="collapse mt-2" id="frn-<?php echo (int)$fd['id']; ?>">
                        <div class="d-flex gap-2 flex-wrap">
                            <form method="POST" action="drive_action.php" class="d-flex gap-1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="folder_rename">
                                <input type="hidden" name="id" value="<?php echo (int)$fd['id']; ?>">
                                <input type="text" name="name" class="form-control form-control-sm" value="<?php echo e($fd['name']); ?>" maxlength="120" required style="max-width:200px;">
                                <button class="btn btn-sm btn-primary">Rename</button>
                            </form>
                            <form method="POST" action="drive_action.php" class="d-flex gap-1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="folder_move">
                                <input type="hidden" name="id" value="<?php echo (int)$fd['id']; ?>">
                                <select name="new_parent_id" class="form-select form-select-sm" style="max-width:200px;">
                                    <option value="">→ Root</option>
                                    <?php foreach ($moveTargets as $mt): ?>
                                    <?php if ((int)$mt['id'] === (int)$fd['id']) continue; ?>
                                    <option value="<?php echo (int)$mt['id']; ?>"<?php echo ((int)($fd['parent_id'] ?? 0) === (int)$mt['id']) ? ' selected' : ''; ?>><?php echo e($mt['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-primary">Pindah</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($files)): ?>
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-files"></i> File dalam folder ini (<?php echo count($files); ?>)</div>
            <div class="list-group list-group-flush">
                <?php foreach ($files as $fl): ?>
                <?php [$ic, $cc] = driveFileIcon($fl['original_name']); $ext = strtolower(pathinfo($fl['original_name'], PATHINFO_EXTENSION)); $previewable = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'mp4', 'mp3'], true); ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <span><i class="bi <?php echo $ic; ?> <?php echo $cc; ?>"></i> <strong><?php echo e($fl['original_name']); ?></strong><br>
                        <small class="text-secondary"><?php echo fmtDriveSize($fl['file_size']); ?> · <?php echo e($fl['uploader_name'] ?? '-'); ?> · <?php echo date('d M Y H:i', strtotime($fl['created_at'])); ?></small></span>
                        <span class="d-flex gap-1 flex-wrap">
                            <?php if ($previewable): ?><a class="btn btn-sm btn-outline-secondary" href="drive_download.php?id=<?php echo (int)$fl['id']; ?>&preview=1" target="_blank" rel="noopener">Lihat</a><?php endif; ?>
                            <a class="btn btn-sm btn-primary" href="drive_download.php?id=<?php echo (int)$fl['id']; ?>">Unduh</a>
                            <?php if ($canEditCur): ?>
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#flm-<?php echo (int)$fl['id']; ?>" title="Rename/pindah"><i class="bi bi-pencil"></i></button>
                            <form method="POST" action="drive_action.php" class="d-inline" onsubmit="return confirm('Hapus file ini?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="file_delete">
                                <input type="hidden" name="file_id" value="<?php echo (int)$fl['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Hapus file"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($previewable && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)): ?>
                    <a href="drive_download.php?id=<?php echo (int)$fl['id']; ?>&preview=1" target="_blank" rel="noopener">
                        <img src="drive_download.php?id=<?php echo (int)$fl['id']; ?>&preview=1" alt="Preview" class="attach-preview mt-2" loading="lazy">
                    </a>
                    <?php endif; ?>
                    <?php if ($canEditCur): ?>
                    <div class="collapse mt-2" id="flm-<?php echo (int)$fl['id']; ?>">
                        <div class="d-flex gap-2 flex-wrap">
                            <form method="POST" action="drive_action.php" class="d-flex gap-1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="file_rename">
                                <input type="hidden" name="file_id" value="<?php echo (int)$fl['id']; ?>">
                                <input type="text" name="name" class="form-control form-control-sm" value="<?php echo e($fl['original_name']); ?>" maxlength="255" required style="max-width:220px;">
                                <button class="btn btn-sm btn-primary">Rename</button>
                            </form>
                            <form method="POST" action="drive_action.php" class="d-flex gap-1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="file_move">
                                <input type="hidden" name="file_id" value="<?php echo (int)$fl['id']; ?>">
                                <select name="new_folder_id" class="form-select form-select-sm" required style="max-width:200px;">
                                    <option value="">→ pilih folder…</option>
                                    <?php foreach ($moveTargets as $mt): ?>
                                    <option value="<?php echo (int)$mt['id']; ?>"<?php echo ((int)$fl['folder_id'] === (int)$mt['id']) ? ' selected' : ''; ?>><?php echo e($mt['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-primary">Pindah</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4 d-flex flex-column gap-3">
        <!-- Buat folder -->
        <div class="card"><div class="card-body">
            <h3 class="h6"><i class="bi bi-folder-plus"></i> Folder baru <?php echo $curFolder ? 'di “' . e($curFolder['name']) . '”' : 'di Root'; ?></h3>
            <form method="POST" action="drive_action.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="folder_create">
                <?php if ($curFolderId): ?><input type="hidden" name="parent_id" value="<?php echo (int)$curFolderId; ?>"><?php endif; ?>
                <input type="text" name="name" class="form-control form-control-sm mb-2" required maxlength="120" placeholder="Nama folder…">
                <select name="visibility" class="form-select form-select-sm mb-2">
                    <option value="private">🔒 Pribadi (hanya saya + yang di-share)</option>
                    <option value="division">🏢 Divisi (<?php echo e($user['division'] ?? '-'); ?>)</option>
                    <?php if (canCreatePublicDrive($user)): ?><option value="public">🌍 Publik / SOP (semua user)</option><?php endif; ?>
                </select>
                <button class="btn btn-primary btn-sm w-100" type="submit">+ Buat Folder</button>
            </form>
        </div></div>

        <!-- Upload -->
        <?php if ($curFolderId && $canEditCur): ?>
        <div class="card"><div class="card-body">
            <h3 class="h6"><i class="bi bi-cloud-upload"></i> Upload ke folder ini</h3>
            <form method="POST" action="drive_action.php" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="file_upload">
                <input type="hidden" name="folder_id" value="<?php echo (int)$curFolderId; ?>">
                <input type="file" name="attachments[]" class="form-control form-control-sm mb-2" multiple required>
                <button class="btn btn-success btn-sm w-100" type="submit">Upload (maks 10 file, 50MB/file)</button>
            </form>
            <p class="small text-secondary mb-0 mt-2">Boleh: <?php echo implode(', ', driveAllowedExts()); ?>.</p>
        </div></div>
        <?php elseif (!$curFolderId): ?>
        <div class="alert alert-info small mb-0">Masuk ke sebuah folder untuk upload file. Upload hanya di dalam folder (bukan di Root).</div>
        <?php endif; ?>

        <!-- Sharing & visibilitas folder aktif -->
        <?php if ($curFolder): ?>
        <div class="card"><div class="card-body">
            <h3 class="h6"><i class="bi bi-share"></i> Sharing “<?php echo e($curFolder['name']); ?>”</h3>
            <p class="small mb-2">Owner: <strong><?php echo e($curFolder['owner_name'] ?? '-'); ?></strong> · <span class="badge <?php echo $visBadge[$curFolder['visibility']] ?? 'text-bg-secondary'; ?>"><?php echo e($visLabels[$curFolder['visibility']] ?? ''); ?></span><?php if (($curFolder['visibility'] ?? '') === 'division'): ?> <small class="text-secondary">(<?php echo e($curFolder['division'] ?? '-'); ?>)</small><?php endif; ?></p>
            <?php if ($canEditCur): ?>
            <form method="POST" action="drive_action.php" class="d-flex gap-1 mb-3">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="folder_visibility">
                <input type="hidden" name="id" value="<?php echo (int)$curFolder['id']; ?>">
                <select name="visibility" class="form-select form-select-sm">
                    <?php foreach ($visLabels as $vk => $vl): ?>
                    <?php if ($vk === 'public' && !canCreatePublicDrive($user)) continue; ?>
                    <option value="<?php echo $vk; ?>"<?php echo ($curFolder['visibility'] ?? '') === $vk ? ' selected' : ''; ?>><?php echo e($vl); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-primary">Ubah</button>
            </form>
            <?php endif; ?>
            <?php if (empty($shares)): ?>
            <p class="small text-secondary mb-2">Belum dibagikan ke user spesifik.</p>
            <?php else: ?>
            <ul class="list-group list-group-flush mb-2">
                <?php foreach ($shares as $sh): ?>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                    <span class="small"><strong><?php echo e($sh['shared_name']); ?></strong> <span class="text-secondary">(<?php echo e($sh['division'] ?? '-'); ?>)</span><br><span class="badge text-bg-primary"><?php echo e($sh['permission']); ?></span></span>
                    <?php if ($canEditCur): ?>
                    <form method="POST" action="drive_action.php" class="d-inline" onsubmit="return confirm('Cabut akses user ini?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="share_remove">
                        <input type="hidden" name="folder_id" value="<?php echo (int)$curFolder['id']; ?>">
                        <input type="hidden" name="user_id" value="<?php echo (int)$sh['shared_with_user_id']; ?>">
                        <button class="btn btn-sm btn-outline-danger">Cabut</button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($canEditCur): ?>
            <form method="POST" action="drive_action.php" class="row g-1">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="share_add">
                <input type="hidden" name="folder_id" value="<?php echo (int)$curFolder['id']; ?>">
                <div class="col-12"><select name="user_id" class="form-select form-select-sm" required>
                    <option value="">→ pilih user…</option>
                    <?php foreach ($shareCandidates as $sc): ?><option value="<?php echo (int)$sc['id']; ?>"><?php echo e($sc['name']); ?> (<?php echo e($sc['division'] ?? '-'); ?> · <?php echo e($sc['role']); ?>)</option><?php endforeach; ?>
                </select></div>
                <div class="col-6"><select name="permission" class="form-select form-select-sm">
                    <option value="view">view (baca)</option>
                    <option value="edit">edit (kelola)</option>
                </select></div>
                <div class="col-6"><button class="btn btn-sm btn-primary w-100" type="submit">Bagikan</button></div>
            </form>
            <p class="small text-secondary mb-0 mt-1">Share berlaku untuk folder ini + seluruh subfolder di dalamnya.</p>
            <?php endif; ?>
        </div></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
