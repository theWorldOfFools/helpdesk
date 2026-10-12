<?php
// Helper Drive internal & foldering (mirror dev_auth.php / cr_auth.php).
// Butuh getDBConnection() dari config.php — jangan panggil sebelum config.php dimuat.

if (!function_exists('driveVisibilities')) {
    function driveVisibilities()
    {
        return ['private' => 'Pribadi', 'division' => 'Divisi', 'public' => 'Publik (SOP)'];
    }
}

if (!function_exists('driveAllowedExts')) {
    // Longgar dari tiket: tambah ppt/pptx/csv/mp4/mp3. Tetap tolak executable.
    function driveAllowedExts()
    {
        return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar', 'mp4', 'mp3'];
    }
}

if (!function_exists('driveMaxFileBytes')) {
    function driveMaxFileBytes()
    {
        return 50 * 1024 * 1024; // 50MB per file
    }
}

if (!function_exists('driveMaxFilesPerRequest')) {
    function driveMaxFilesPerRequest()
    {
        return 10;
    }
}

if (!function_exists('driveMaxBytesPerUser')) {
    function driveMaxBytesPerUser()
    {
        return 500 * 1024 * 1024; // 500MB per user
    }
}

if (!function_exists('fmtDriveSize')) {
    function fmtDriveSize($bytes)
    {
        $b = (int)$bytes;
        if ($b < 1024) return $b . ' B';
        if ($b < 1048576) return round($b / 1024, 1) . ' KB';
        if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
        return round($b / 1073741824, 2) . ' GB';
    }
}

if (!function_exists('getDriveFolder')) {
    function getDriveFolder($conn, $id)
    {
        $r = pg_query_params(
            $conn,
            "SELECT f.*, u.name AS owner_name, u.division AS owner_division_live"
            . " FROM drive_folders f LEFT JOIN users u ON u.id = f.owner_id WHERE f.id = $1",
            [(int)$id]
        );
        if (!$r || pg_num_rows($r) === 0) {
            if ($r) pg_free_result($r);
            return null;
        }
        $row = pg_fetch_assoc($r);
        pg_free_result($r);
        return $row;
    }
}

if (!function_exists('getDriveAncestors')) {
    // Rantai ancestor dari root -> parent, termasuk folder itu sendiri di akhir.
    // Guard maks 10 level untuk cegah loop bila data rusak.
    function getDriveAncestors($conn, $folder)
    {
        $chain = [];
        $seen = [];
        $cur = $folder;
        $guard = 0;
        while ($cur && $guard++ < 10) {
            $fid = (int)($cur['id'] ?? 0);
            if ($fid <= 0 || isset($seen[$fid])) break;
            $seen[$fid] = true;
            array_unshift($chain, $cur);
            if (empty($cur['parent_id'])) break;
            $cur = getDriveFolder($conn, (int)$cur['parent_id']);
        }
        return $chain;
    }
}

if (!function_exists('getDriveBreadcrumb')) {
    function getDriveBreadcrumb($conn, $folder)
    {
        if (!$folder) return [];
        return getDriveAncestors($conn, $folder);
    }
}

if (!function_exists('driveFolderDepth')) {
    function driveFolderDepth($conn, $folder)
    {
        return count(getDriveAncestors($conn, $folder));
    }
}

if (!function_exists('isDriveDescendant')) {
    // True bila $folderId adalah turunan (atau sama dengan) $ancestorId.
    function isDriveDescendant($conn, $folderId, $ancestorId)
    {
        $folderId = (int)$folderId;
        $ancestorId = (int)$ancestorId;
        if ($folderId <= 0 || $ancestorId <= 0) return false;
        if ($folderId === $ancestorId) return true;
        $cur = getDriveFolder($conn, $folderId);
        $guard = 0;
        while ($cur && !empty($cur['parent_id']) && $guard++ < 10) {
            if ((int)$cur['parent_id'] === $ancestorId) return true;
            $cur = getDriveFolder($conn, (int)$cur['parent_id']);
        }
        return false;
    }
}

if (!function_exists('getDriveShareForUser')) {
    // Share langsung ATAU warisan dari ancestor (hak tertinggi menang: edit > view).
    function getDriveShareForUser($conn, $folder, $userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0 || !$folder) return null;
        $chain = getDriveAncestors($conn, $folder);
        $ids = array_map(fn($f) => (int)$f['id'], $chain);
        if (empty($ids)) return null;
        $place = [];
        foreach ($ids as $i => $v) $place[] = '$' . ($i + 2);
        $r = pg_query_params(
            $conn,
            "SELECT * FROM drive_shares WHERE folder_id IN (" . implode(',', $place) . ") AND shared_with_user_id = $1 ORDER BY CASE permission WHEN 'edit' THEN 0 ELSE 1 END LIMIT 1",
            array_merge([$userId], $ids)
        );
        if (!$r || pg_num_rows($r) === 0) {
            if ($r) pg_free_result($r);
            return null;
        }
        $row = pg_fetch_assoc($r);
        pg_free_result($r);
        return $row;
    }
}

if (!function_exists('canViewDrive')) {
    // Lihat: admin semua; owner; publik (self/ancestor); divisi cocok; share langsung/warisan.
    function canViewDrive($conn, $folder, $user)
    {
        if (!$folder) return true; // root listing selalu boleh dibuka, isinya difilter
        $role = $user['role'] ?? null;
        if ($role === 'admin') return true;
        $uid = (int)($user['id'] ?? 0);
        if ($uid > 0 && $uid === (int)($folder['owner_id'] ?? 0)) return true;
        $chain = getDriveAncestors($conn, $folder);
        $userDiv = trim((string)($user['division'] ?? ''));
        foreach ($chain as $node) {
            $vis = $node['visibility'] ?? 'private';
            if ($vis === 'public') return true;
            if ($vis === 'division' && $userDiv !== '' && $userDiv === trim((string)($node['division'] ?? ''))) return true;
        }
        return getDriveShareForUser($conn, $folder, $uid) !== null;
    }
}

if (!function_exists('canEditDrive')) {
    // Kelola (rename/hapus/upload/share/visibility): admin, owner, atau share 'edit' (langsung/warisan).
    // Share 'view', publik, dan divisi = baca saja.
    function canEditDrive($conn, $folder, $user)
    {
        if (!$folder) return false;
        $role = $user['role'] ?? null;
        if ($role === 'admin') return true;
        $uid = (int)($user['id'] ?? 0);
        if ($uid > 0 && $uid === (int)($folder['owner_id'] ?? 0)) return true;
        $sh = getDriveShareForUser($conn, $folder, $uid);
        return $sh !== null && ($sh['permission'] ?? '') === 'edit';
    }
}

if (!function_exists('canCreatePublicDrive')) {
    // Folder publik (SOP): admin + teknisi. Pelapor ditolak (boleh private/division).
    function canCreatePublicDrive($user)
    {
        return in_array($user['role'] ?? null, ['admin', 'teknisi'], true);
    }
}

if (!function_exists('getDriveShares')) {
    function getDriveShares($conn, $folderId)
    {
        $out = [];
        $r = pg_query_params(
            $conn,
            "SELECT s.*, u.name AS shared_name, u.username, u.division FROM drive_shares s"
            . " JOIN users u ON u.id = s.shared_with_user_id WHERE s.folder_id = $1 ORDER BY u.name ASC",
            [(int)$folderId]
        );
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = $row;
            pg_free_result($r);
        }
        return $out;
    }
}

if (!function_exists('driveQuotaUsage')) {
    function driveQuotaUsage($conn, $userId)
    {
        $r = pg_query_params($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(file_size),0) AS b FROM drive_files WHERE uploaded_by = $1", [(int)$userId]);
        if (!$r) return ['count' => 0, 'bytes' => 0];
        $row = pg_fetch_assoc($r);
        pg_free_result($r);
        return ['count' => (int)$row['c'], 'bytes' => (int)$row['b']];
    }
}

if (!function_exists('driveSubtreeFilePaths')) {
    // Semua path file dalam subtree (untuk hapus fisik cascade). Return array rel path.
    function driveSubtreeFilePaths($conn, $folderId)
    {
        $out = [];
        $r = pg_query_params(
            $conn,
            "WITH RECURSIVE sub AS (SELECT id FROM drive_folders WHERE id = $1"
            . " UNION ALL SELECT f.id FROM drive_folders f JOIN sub s ON f.parent_id = s.id)"
            . " SELECT stored_path AS file_path FROM drive_files WHERE folder_id IN (SELECT id FROM sub)",
            [(int)$folderId]
        );
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = $row['file_path'];
            pg_free_result($r);
        }
        return $out;
    }
}

if (!function_exists('saveDriveUploads')) {
    // Multi-upload ke satu folder (maks 10 file/request, 50MB/file, 500MB/user).
    // Harus dipanggil di dalam transaksi; gagal di file mana pun -> [false, pesan]
    // agar pemanggil ROLLBACK + hapus file yang sudah ter-copy ($savedPaths).
    function saveDriveUploads($conn, $folderId, $userId, &$savedPaths = [], $field = 'attachments')
    {
        $savedPaths = [];
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || !isset($_FILES[$field]['name'])) {
            return [true, null];
        }
        $names = $_FILES[$field]['name'];
        if (!is_array($names)) {
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) $_FILES[$field][$k] = [$_FILES[$field][$k]];
            $names = $_FILES[$field]['name'];
        }
        $files = [];
        foreach ($names as $i => $nm) {
            if ((int)($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $files[] = [
                'name' => $nm,
                'tmp_name' => $_FILES[$field]['tmp_name'][$i] ?? '',
                'error' => (int)($_FILES[$field]['error'][$i] ?? 0),
                'size' => (int)($_FILES[$field]['size'][$i] ?? 0),
            ];
        }
        if (empty($files)) return [true, null];

        $maxFiles = driveMaxFilesPerRequest();
        $maxPerFile = driveMaxFileBytes();
        $maxPerUser = driveMaxBytesPerUser();
        $allowedExt = driveAllowedExts();

        if (count($files) > $maxFiles) {
            return [false, 'Maksimal ' . $maxFiles . ' file per upload.'];
        }
        foreach ($files as $f) {
            if ($f['size'] > $maxPerFile) {
                return [false, '"' . basename($f['name']) . '": maksimal ' . fmtDriveSize($maxPerFile) . ' per file.'];
            }
        }
        $use = driveQuotaUsage($conn, $userId);
        $newBytes = array_sum(array_column($files, 'size'));
        if ($use['bytes'] + $newBytes > $maxPerUser) {
            return [false, 'Kuota Drive Anda penuh (' . fmtDriveSize($use['bytes']) . ' dari ' . fmtDriveSize($maxPerUser) . '). Hapus file lama dulu.'];
        }

        $dir = __DIR__ . '/../uploads/drive';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return [false, 'Folder upload tidak bisa dibuat.'];
        }

        foreach ($files as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK) {
                return [false, 'Upload "' . basename($f['name']) . '" gagal (kode ' . $f['error'] . ').'];
            }
            if (!is_uploaded_file($f['tmp_name'])) {
                return [false, 'Upload "' . basename($f['name']) . '" tidak valid.'];
            }
            $original = basename($f['name'] !== '' ? $f['name'] : 'file');
            if (mb_strlen($original) > 255) {
                return [false, '"' . mb_strimwidth($original, 0, 60, '…') . '": nama file maksimal 255 karakter.'];
            }
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                return [false, '"' . $original . '": jenis file tidak diizinkan. Boleh: ' . implode(', ', $allowedExt) . '.'];
            }
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                if (@getimagesize($f['tmp_name']) === false) {
                    return [false, '"' . $original . '": file gambar tidak valid.'];
                }
            }
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($original, PATHINFO_FILENAME));
            $safe = substr($safe !== '' ? $safe : 'file', 0, 60);
            $stored = date('Ym') . '_' . bin2hex(random_bytes(6)) . '_' . $safe . '.' . $ext;
            $dest = $dir . '/' . $stored;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                return [false, 'Gagal menyimpan "' . $original . '".'];
            }
            @chmod($dest, 0644);
            $rel = 'uploads/drive/' . $stored;
            $savedPaths[] = $rel;
            $mime = null;
            if (function_exists('mime_content_type')) {
                $mime = @mime_content_type($dest) ?: null;
            }
            $ins = pg_query_params(
                $conn,
                "INSERT INTO drive_files (folder_id, stored_path, stored_name, original_name, mime, file_size, uploaded_by) VALUES ($1,$2,$3,$4,$5,$6,$7)",
                [(int)$folderId, $rel, $stored, $original, $mime, $f['size'], (int)$userId]
            );
            if (!$ins) return [false, 'Gagal mencatat "' . $original . '": ' . pg_last_error($conn)];
        }
        return [true, null];
    }
}
