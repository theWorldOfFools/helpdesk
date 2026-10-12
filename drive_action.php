<?php
// Backend Drive internal — POST only + CSRF. Mirror task_action.php / cr_action.php.
// Aksi: folder_create/rename/move/visibility/delete, file_upload/rename/move/delete,
// share_add/share_remove. Semua redirect kembali ke drive.php dengan flash.
require_once 'config.php';
require_once 'includes/drive_auth.php';
requireLogin();
requirePost();
requireCsrf();

$user = getCurrentUser();
$conn = getDBConnection();

function driveBack($folderId = null, $extra = '')
{
    $url = 'drive.php';
    $q = [];
    if ($folderId) $q['folder'] = (int)$folderId;
    if ($extra !== '') {
        // extra sudah dalam bentuk query tambahan yang aman (dibangun internal)
        $url .= (!empty($q) ? '?' . http_build_query($q) . '&' . $extra : '?' . $extra);
    } elseif (!empty($q)) {
        $url .= '?' . http_build_query($q);
    }
    return $url;
}

function driveFolderNameError($name)
{
    $name = trim((string)$name);
    if ($name === '') return 'Nama folder wajib diisi.';
    if (mb_strlen($name) > 120) return 'Nama folder maksimal 120 karakter.';
    if (strpos($name, '/') !== false || strpos($name, '\\') !== false) return 'Nama folder tidak boleh mengandung / atau \\.';
    if ($name === '.' || $name === '..') return 'Nama folder tidak valid.';
    return null;
}

// Cek duplikat nama (case-insensitive) di parent yang sama. $excludeId untuk rename/move.
function driveNameTaken($conn, $name, $parentId, $excludeId = 0)
{
    $parentId = $parentId ? (int)$parentId : null;
    if ($parentId) {
        $r = pg_query_params(
            $conn,
            "SELECT id FROM drive_folders WHERE parent_id = $1 AND LOWER(name) = LOWER($2) AND id <> $3 LIMIT 1",
            [$parentId, $name, (int)$excludeId]
        );
    } else {
        $r = pg_query_params(
            $conn,
            "SELECT id FROM drive_folders WHERE parent_id IS NULL AND LOWER(name) = LOWER($1) AND id <> $2 LIMIT 1",
            [$name, (int)$excludeId]
        );
    }
    $taken = $r && pg_num_rows($r) > 0;
    if ($r) pg_free_result($r);
    return $taken;
}

$action = trim($_POST['action'] ?? '');
$backFolder = isset($_POST['back_folder']) && (int)$_POST['back_folder'] > 0 ? (int)$_POST['back_folder'] : null;
$curFolderId = isset($_POST['folder_id']) && (int)$_POST['folder_id'] > 0 ? (int)$_POST['folder_id'] : $backFolder;

try {
    // ---------- FOLDER CREATE ----------
    if ($action === 'folder_create') {
        $parentId = isset($_POST['parent_id']) && (int)$_POST['parent_id'] > 0 ? (int)$_POST['parent_id'] : null;
        $name = trim($_POST['name'] ?? '');
        $visibility = trim($_POST['visibility'] ?? 'private');
        if (!in_array($visibility, ['private', 'division', 'public'], true)) $visibility = 'private';

        if ($err = driveFolderNameError($name)) throw new Exception($err);
        if ($visibility === 'public' && !canCreatePublicDrive($user)) {
            throw new Exception('Hanya admin/teknisi yang boleh membuat folder publik.');
        }
        $parent = null;
        if ($parentId) {
            $parent = getDriveFolder($conn, $parentId);
            if (!$parent) throw new Exception('Folder induk tidak ditemukan.');
            if (!canViewDrive($conn, $parent, $user) || !canEditDrive($conn, $parent, $user)) {
                throw new Exception('Anda tidak punya akses ke folder induk.');
            }
            if (driveFolderDepth($conn, $parent) >= 5) {
                throw new Exception('Maksimal kedalaman 5 level.');
            }
        }
        if (driveNameTaken($conn, $name, $parentId)) {
            throw new Exception('Sudah ada folder bernama "' . $name . '" di lokasi ini.');
        }
        $division = $user['division'] ?? null;
        $r = pg_query_params(
            $conn,
            "INSERT INTO drive_folders (parent_id, name, owner_id, division, visibility) VALUES ($1,$2,$3,$4,$5) RETURNING id",
            [$parentId, $name, (int)$user['id'], $division, $visibility]
        );
        if (!$r) throw new Exception('Gagal membuat folder: ' . pg_last_error($conn));
        $newId = (int)pg_fetch_result($r, 0, 0);
        pg_free_result($r);
        logActivity($conn, $user['id'], 'drive_folder_create', 'Folder #' . $newId . ' "' . $name . '" (' . $visibility . ')');
        $_SESSION['flash_ok'] = 'Folder "' . $name . '" dibuat.';
        pg_close($conn);
        header('Location: ' . driveBack($parentId));
        exit;
    }

    // ---------- FOLDER RENAME ----------
    if ($action === 'folder_rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0) throw new Exception('Folder tidak valid.');
        if ($err = driveFolderNameError($name)) throw new Exception($err);
        $f = getDriveFolder($conn, $id);
        if (!$f) throw new Exception('Folder tidak ditemukan.');
        if (!canEditDrive($conn, $f, $user)) throw new Exception('Anda tidak boleh mengubah folder ini.');
        if (driveNameTaken($conn, $name, $f['parent_id'] ? (int)$f['parent_id'] : null, $id)) {
            throw new Exception('Sudah ada folder bernama "' . $name . '" di lokasi ini.');
        }
        $u = pg_query_params($conn, "UPDATE drive_folders SET name = $1, updated_at = NOW() WHERE id = $2", [$name, $id]);
        if (!$u) throw new Exception('Gagal rename: ' . pg_last_error($conn));
        logActivity($conn, $user['id'], 'drive_folder_rename', 'Folder #' . $id . ' -> "' . $name . '"');
        $_SESSION['flash_ok'] = 'Folder diubah menjadi "' . $name . '".';
        pg_close($conn);
        header('Location: ' . driveBack($f['parent_id'] ? (int)$f['parent_id'] : null));
        exit;
    }

    // ---------- FOLDER VISIBILITY ----------
    if ($action === 'folder_visibility') {
        $id = (int)($_POST['id'] ?? 0);
        $visibility = trim($_POST['visibility'] ?? '');
        if ($id <= 0) throw new Exception('Folder tidak valid.');
        if (!in_array($visibility, ['private', 'division', 'public'], true)) throw new Exception('Visibilitas tidak valid.');
        $f = getDriveFolder($conn, $id);
        if (!$f) throw new Exception('Folder tidak ditemukan.');
        if (!canEditDrive($conn, $f, $user)) throw new Exception('Anda tidak boleh mengubah folder ini.');
        if ($visibility === 'public' && !canCreatePublicDrive($user)) {
            throw new Exception('Hanya admin/teknisi yang boleh menjadikan folder publik.');
        }
        $u = pg_query_params(
            $conn,
            "UPDATE drive_folders SET visibility = $1, division = $2, updated_at = NOW() WHERE id = $3",
            [$visibility, $user['division'] ?? null, $id]
        );
        if (!$u) throw new Exception('Gagal ubah visibilitas: ' . pg_last_error($conn));
        $labels = driveVisibilities();
        logActivity($conn, $user['id'], 'drive_folder_visibility', 'Folder #' . $id . ' -> ' . $visibility);
        $_SESSION['flash_ok'] = 'Visibilitas folder menjadi "' . ($labels[$visibility] ?? $visibility) . '".';
        pg_close($conn);
        header('Location: ' . driveBack($id));
        exit;
    }

    // ---------- FOLDER MOVE ----------
    if ($action === 'folder_move') {
        $id = (int)($_POST['id'] ?? 0);
        $newParent = isset($_POST['new_parent_id']) && (int)$_POST['new_parent_id'] > 0 ? (int)$_POST['new_parent_id'] : null;
        if ($id <= 0) throw new Exception('Folder tidak valid.');
        $f = getDriveFolder($conn, $id);
        if (!$f) throw new Exception('Folder tidak ditemukan.');
        if (!canEditDrive($conn, $f, $user)) throw new Exception('Anda tidak boleh memindahkan folder ini.');
        if ($newParent === $id) throw new Exception('Tidak bisa memindahkan folder ke dirinya sendiri.');
        $dest = null;
        if ($newParent) {
            $dest = getDriveFolder($conn, $newParent);
            if (!$dest) throw new Exception('Folder tujuan tidak ditemukan.');
            if (!canEditDrive($conn, $dest, $user)) throw new Exception('Anda tidak punya akses tulis ke folder tujuan.');
            if (isDriveDescendant($conn, $newParent, $id)) {
                throw new Exception('Tidak bisa memindahkan folder ke dalam turunannya sendiri.');
            }
            if (driveFolderDepth($conn, $dest) >= 5) throw new Exception('Maksimal kedalaman 5 level.');
        }
        if (driveNameTaken($conn, $f['name'], $newParent, $id)) {
            throw new Exception('Sudah ada folder bernama "' . $f['name'] . '" di tujuan.');
        }
        $u = pg_query_params($conn, "UPDATE drive_folders SET parent_id = $1, updated_at = NOW() WHERE id = $2", [$newParent, $id]);
        if (!$u) throw new Exception('Gagal memindahkan: ' . pg_last_error($conn));
        logActivity($conn, $user['id'], 'drive_folder_move', 'Folder #' . $id . ' -> parent ' . ($newParent ?: 'root'));
        $_SESSION['flash_ok'] = 'Folder dipindahkan.';
        pg_close($conn);
        header('Location: ' . driveBack($newParent));
        exit;
    }

    // ---------- FOLDER DELETE ----------
    if ($action === 'folder_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('Folder tidak valid.');
        $f = getDriveFolder($conn, $id);
        if (!$f) throw new Exception('Folder tidak ditemukan.');
        if (!canEditDrive($conn, $f, $user)) throw new Exception('Anda tidak boleh menghapus folder ini.');
        $parentId = $f['parent_id'] ? (int)$f['parent_id'] : null;
        $paths = driveSubtreeFilePaths($conn, $id);
        pg_query($conn, 'BEGIN');
        $del = pg_query_params($conn, "DELETE FROM drive_folders WHERE id = $1", [$id]);
        if (!$del) {
            pg_query($conn, 'ROLLBACK');
            throw new Exception('Gagal menghapus: ' . pg_last_error($conn));
        }
        pg_query($conn, 'COMMIT');
        foreach ($paths as $p) {
            if ($p && file_exists(__DIR__ . '/' . $p)) @unlink(__DIR__ . '/' . $p);
        }
        logActivity($conn, $user['id'], 'drive_folder_delete', 'Folder #' . $id . ' "' . ($f['name'] ?? '') . '" + ' . count($paths) . ' file');
        $_SESSION['flash_ok'] = 'Folder "' . ($f['name'] ?? '') . '" dan isinya dihapus.';
        pg_close($conn);
        header('Location: ' . driveBack($parentId));
        exit;
    }

    // ---------- FILE UPLOAD ----------
    if ($action === 'file_upload') {
        $folderId = (int)($_POST['folder_id'] ?? 0);
        if ($folderId <= 0) throw new Exception('Pilih folder tujuan dulu.');
        $f = getDriveFolder($conn, $folderId);
        if (!$f) throw new Exception('Folder tidak ditemukan.');
        if (!canEditDrive($conn, $f, $user)) throw new Exception('Anda tidak boleh upload ke folder ini.');
        pg_query($conn, 'BEGIN');
        $savedPaths = [];
        [$ok, $upErr] = saveDriveUploads($conn, $folderId, (int)$user['id'], $savedPaths, 'attachments');
        if (!$ok) {
            pg_query($conn, 'ROLLBACK');
            foreach ($savedPaths as $sp) {
                if (file_exists(__DIR__ . '/' . $sp)) @unlink(__DIR__ . '/' . $sp);
            }
            throw new Exception($upErr ?: 'Upload gagal.');
        }
        if (empty($savedPaths)) {
            pg_query($conn, 'ROLLBACK');
            throw new Exception('Pilih minimal 1 file untuk diupload.');
        }
        pg_query($conn, 'COMMIT');
        logActivity($conn, $user['id'], 'drive_upload', count($savedPaths) . ' file ke folder #' . $folderId);
        $_SESSION['flash_ok'] = count($savedPaths) . ' file berhasil diupload.';
        pg_close($conn);
        header('Location: ' . driveBack($folderId));
        exit;
    }

    // ---------- FILE RENAME ----------
    if ($action === 'file_rename') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        $newName = trim($_POST['name'] ?? '');
        if ($fileId <= 0) throw new Exception('File tidak valid.');
        if ($newName === '' || mb_strlen($newName) > 255) throw new Exception('Nama file wajib 1–255 karakter.');
        if (strpos($newName, '/') !== false || strpos($newName, '\\') !== false) throw new Exception('Nama file tidak boleh mengandung / atau \\.');
        $fr = pg_query_params($conn, "SELECT * FROM drive_files WHERE id = $1", [$fileId]);
        if (!$fr || pg_num_rows($fr) === 0) {
            if ($fr) pg_free_result($fr);
            throw new Exception('File tidak ditemukan.');
        }
        $file = pg_fetch_assoc($fr);
        pg_free_result($fr);
        $folder = getDriveFolder($conn, (int)$file['folder_id']);
        if (!$folder || !canEditDrive($conn, $folder, $user)) throw new Exception('Anda tidak boleh mengubah file ini.');
        $u = pg_query_params($conn, "UPDATE drive_files SET original_name = $1 WHERE id = $2", [$newName, $fileId]);
        if (!$u) throw new Exception('Gagal rename file: ' . pg_last_error($conn));
        $_SESSION['flash_ok'] = 'Nama file diubah.';
        pg_close($conn);
        header('Location: ' . driveBack((int)$file['folder_id']));
        exit;
    }

    // ---------- FILE MOVE ----------
    if ($action === 'file_move') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        $destId = (int)($_POST['new_folder_id'] ?? 0);
        if ($fileId <= 0 || $destId <= 0) throw new Exception('File / folder tujuan tidak valid.');
        $fr = pg_query_params($conn, "SELECT * FROM drive_files WHERE id = $1", [$fileId]);
        if (!$fr || pg_num_rows($fr) === 0) {
            if ($fr) pg_free_result($fr);
            throw new Exception('File tidak ditemukan.');
        }
        $file = pg_fetch_assoc($fr);
        pg_free_result($fr);
        $src = getDriveFolder($conn, (int)$file['folder_id']);
        $dest = getDriveFolder($conn, $destId);
        if (!$src || !$dest) throw new Exception('Folder tidak ditemukan.');
        if (!canEditDrive($conn, $src, $user)) throw new Exception('Anda tidak boleh memindahkan file ini.');
        if (!canEditDrive($conn, $dest, $user)) throw new Exception('Anda tidak punya akses tulis ke folder tujuan.');
        $u = pg_query_params($conn, "UPDATE drive_files SET folder_id = $1 WHERE id = $2", [$destId, $fileId]);
        if (!$u) throw new Exception('Gagal memindahkan file: ' . pg_last_error($conn));
        $_SESSION['flash_ok'] = 'File dipindahkan ke "' . $dest['name'] . '".';
        pg_close($conn);
        header('Location: ' . driveBack($destId));
        exit;
    }

    // ---------- FILE DELETE ----------
    if ($action === 'file_delete') {
        $fileId = (int)($_POST['file_id'] ?? 0);
        if ($fileId <= 0) throw new Exception('File tidak valid.');
        $fr = pg_query_params($conn, "SELECT * FROM drive_files WHERE id = $1", [$fileId]);
        if (!$fr || pg_num_rows($fr) === 0) {
            if ($fr) pg_free_result($fr);
            throw new Exception('File tidak ditemukan.');
        }
        $file = pg_fetch_assoc($fr);
        pg_free_result($fr);
        $folder = getDriveFolder($conn, (int)$file['folder_id']);
        if (!$folder || !canEditDrive($conn, $folder, $user)) throw new Exception('Anda tidak boleh menghapus file ini.');
        $del = pg_query_params($conn, "DELETE FROM drive_files WHERE id = $1", [$fileId]);
        if (!$del) throw new Exception('Gagal menghapus file: ' . pg_last_error($conn));
        if (!empty($file['stored_path']) && file_exists(__DIR__ . '/' . $file['stored_path'])) @unlink(__DIR__ . '/' . $file['stored_path']);
        logActivity($conn, $user['id'], 'drive_file_delete', 'File #' . $fileId . ' "' . ($file['original_name'] ?? '') . '"');
        $_SESSION['flash_ok'] = 'File "' . ($file['original_name'] ?? '') . '" dihapus.';
        pg_close($conn);
        header('Location: ' . driveBack((int)$file['folder_id']));
        exit;
    }

    // ---------- SHARE ADD ----------
    if ($action === 'share_add') {
        $folderId = (int)($_POST['folder_id'] ?? 0);
        $targetId = (int)($_POST['user_id'] ?? 0);
        $perm = trim($_POST['permission'] ?? 'view');
        if (!in_array($perm, ['view', 'edit'], true)) $perm = 'view';
        if ($folderId <= 0 || $targetId <= 0) throw new Exception('Folder / user tidak valid.');
        $folder = getDriveFolder($conn, $folderId);
        if (!$folder) throw new Exception('Folder tidak ditemukan.');
        if (!canEditDrive($conn, $folder, $user)) throw new Exception('Anda tidak boleh mengatur sharing folder ini.');
        if ($targetId === (int)$user['id']) throw new Exception('Tidak perlu share ke diri sendiri.');
        $isOwnerOrAdmin = ($user['role'] ?? '') === 'admin' || (int)($folder['owner_id'] ?? 0) === (int)$user['id'];
        if ($perm === 'edit' && !$isOwnerOrAdmin) {
            throw new Exception('Hanya owner/admin yang boleh memberi hak edit.');
        }
        $chk = pg_query_params($conn, "SELECT id, name FROM users WHERE id = $1 AND is_active = TRUE", [$targetId]);
        if (!$chk || pg_num_rows($chk) === 0) {
            if ($chk) pg_free_result($chk);
            throw new Exception('User tujuan tidak aktif / tidak ditemukan.');
        }
        $targetName = pg_fetch_result($chk, 0, 1);
        pg_free_result($chk);
        $ins = pg_query_params(
            $conn,
            "INSERT INTO drive_shares (folder_id, shared_with_user_id, permission, created_by) VALUES ($1,$2,$3,$4)"
            . " ON CONFLICT (folder_id, shared_with_user_id) DO UPDATE SET permission = EXCLUDED.permission",
            [$folderId, $targetId, $perm, (int)$user['id']]
        );
        if (!$ins) throw new Exception('Gagal menyimpan share: ' . pg_last_error($conn));
        logActivity($conn, $user['id'], 'drive_share', 'Folder #' . $folderId . ' -> user #' . $targetId . ' (' . $perm . ')');
        notifyUser($conn, $targetId, 'Folder "' . $folder['name'] . '" dibagikan ke Anda', $user['name'] . ' memberi akses ' . $perm, 'drive.php?folder=' . $folderId);
        $_SESSION['flash_ok'] = 'Folder dibagikan ke ' . $targetName . ' (' . $perm . ').';
        pg_close($conn);
        header('Location: ' . driveBack($folderId));
        exit;
    }

    // ---------- SHARE REMOVE ----------
    if ($action === 'share_remove') {
        $folderId = (int)($_POST['folder_id'] ?? 0);
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($folderId <= 0 || $targetId <= 0) throw new Exception('Data share tidak valid.');
        $folder = getDriveFolder($conn, $folderId);
        if (!$folder) throw new Exception('Folder tidak ditemukan.');
        if (!canEditDrive($conn, $folder, $user)) throw new Exception('Anda tidak boleh mengatur sharing folder ini.');
        pg_query_params($conn, "DELETE FROM drive_shares WHERE folder_id = $1 AND shared_with_user_id = $2", [$folderId, $targetId]);
        logActivity($conn, $user['id'], 'drive_unshare', 'Folder #' . $folderId . ' x user #' . $targetId);
        $_SESSION['flash_ok'] = 'Akses sharing dicabut.';
        pg_close($conn);
        header('Location: ' . driveBack($folderId));
        exit;
    }

    throw new Exception('Aksi tidak dikenal.');
} catch (Exception $ex) {
    if (isset($conn) && $conn) {
        @pg_query($conn, 'ROLLBACK');
        @pg_close($conn);
    }
    $_SESSION['flash_error'] = $ex->getMessage();
    $fallback = $curFolderId ? driveBack($curFolderId) : 'drive.php';
    header('Location: ' . $fallback);
    exit;
}
