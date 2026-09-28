<?php
// Helper mini-todo SDLC internal (teknisi + admin).
// Dipisah dari config.php agar modul tiket/CR tidak tersentuh.
// Butuh getDBConnection() dari config.php — jangan panggil sebelum config.php dimuat.

if (!function_exists('devPhases')) {
    /**
     * Urutan fase SDLC: backlog -> siap -> development -> testing -> deploy -> done.
     */
    function devPhases()
    {
        return ['backlog', 'siap', 'development', 'testing', 'deploy', 'done'];
    }
}

if (!function_exists('generateTaskCode')) {
    /**
     * Generate nomor todo unik format DT-YYYYMM-XXXX (counter per bulan).
     * Mirror generateCrNumber(): kunci tabel saat baca MAX agar aman dari race.
     */
    function generateTaskCode($existingConn = null)
    {
        $own = $existingConn === null;
        $conn = $own ? getDBConnection() : $existingConn;
        if ($own) {
            pg_query($conn, 'BEGIN');
            pg_query($conn, 'LOCK TABLE dev_tasks IN SHARE ROW EXCLUSIVE MODE');
        }
        $prefix = 'DT-' . date('Ym');
        $result = pg_query(
            $conn,
            "SELECT '" . $prefix . "-' || LPAD(COALESCE(MAX(CAST(SUBSTRING(task_code FROM '[0-9]+$') AS INTEGER)) + 1, 1)::TEXT, 4, '0') FROM dev_tasks WHERE task_code LIKE '" . $prefix . "-%'"
        );
        $code = $result ? pg_fetch_result($result, 0, 0) : null;
        if ($result) pg_free_result($result);
        if (!$code) $code = $prefix . '-0001';
        if ($own) {
            pg_query($conn, 'COMMIT');
            pg_close($conn);
        }
        return $code;
    }
}

if (!function_exists('canViewTask')) {
    /**
     * Lihat todo: hanya staf internal (admin/teknisi). Pelapor ditolak total.
     */
    function canViewTask($task, $user)
    {
        return in_array($user['role'] ?? null, ['admin', 'teknisi'], true);
    }
}

if (!function_exists('canActOnTask')) {
    /**
     * Tindak lanjut (pindah fase + komentar): admin/teknisi.
     */
    function canActOnTask($task, $user)
    {
        return in_array($user['role'] ?? null, ['admin', 'teknisi'], true);
    }
}

if (!function_exists('canEditTask')) {
    /**
     * Edit data todo: admin, atau owner, atau pembuat.
     */
    function canEditTask($task, $user)
    {
        if (($user['role'] ?? null) === 'admin') return true;
        $uid = (int)($user['id'] ?? 0);
        return $uid > 0 && ($uid === (int)($task['owner_id'] ?? 0) || $uid === (int)($task['created_by'] ?? 0));
    }
}

if (!function_exists('canDeleteTask')) {
    /**
     * Hapus todo: admin only via POST+CSRF (mirror canDeleteTicket()).
     */
    function canDeleteTask($task, $user)
    {
        return ($user['role'] ?? null) === 'admin';
    }
}

if (!function_exists('getTaskOwnerCandidates')) {
    /**
     * Kandidat owner: user aktif role admin/teknisi, urut nama.
     */
    function getTaskOwnerCandidates($conn)
    {
        $out = [];
        $r = pg_query($conn, "SELECT id, name, username, role FROM users WHERE is_active = TRUE AND role IN ('admin','teknisi') ORDER BY name ASC");
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = $row;
            pg_free_result($r);
        }
        return $out;
    }
}

if (!function_exists('canManageAssignees')) {
    /**
     * Kelola daftar assignee multi-person: admin + pembuat todo.
     * Teknisi lain yang tidak terlibat ditolak.
     */
    function canManageAssignees($task, $user)
    {
        if (($user['role'] ?? null) === 'admin') return true;
        $uid = (int)($user['id'] ?? 0);
        return $uid > 0 && $uid === (int)($task['created_by'] ?? 0);
    }
}

if (!function_exists('getTaskAssignees')) {
    /**
     * Daftar assignee sebuah todo beserta info user, urut nama.
     */
    function getTaskAssignees($conn, $taskId)
    {
        $out = [];
        $r = pg_query_params(
            $conn,
            "SELECT a.task_id, a.user_id AS id, u.name, u.username, u.role, a.assigned_by, a.created_at"
            . " FROM dev_task_assignees a JOIN users u ON u.id = a.user_id"
            . " WHERE a.task_id = $1 ORDER BY u.name ASC",
            [(int)$taskId]
        );
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = $row;
            pg_free_result($r);
        }
        return $out;
    }
}

if (!function_exists('setTaskAssignees')) {
    /**
     * Ganti seluruh daftar assignee + tulis history.
     * Harus dipanggil di dalam transaksi milik pemanggil.
     * Validasi: array int, dedup, maks 10, tiap id staf aktif (admin/teknisi).
     * owner_id TIDAK diubah di sini (diatur via take/edit admin).
     * Return [true, null] atau [false, 'pesan'].
     */
    function setTaskAssignees($conn, $taskId, $userIds, $actorId)
    {
        if (!is_array($userIds)) $userIds = [$userIds];
        $ids = [];
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid > 0 && !in_array($uid, $ids, true)) $ids[] = $uid;
        }
        if (count($ids) > 10) return [false, 'Maksimal 10 assignee per todo.'];
        if (!empty($ids)) {
            $place = [];
            foreach ($ids as $i => $uid) $place[] = '$' . ($i + 1);
            $chk = pg_query_params(
                $conn,
                "SELECT id FROM users WHERE id IN (" . implode(',', $place) . ") AND is_active = TRUE AND role IN ('admin','teknisi')",
                $ids
            );
            if (!$chk) return [false, 'Gagal validasi assignee.'];
            $valid = [];
            while ($row = pg_fetch_assoc($chk)) $valid[] = (int)$row['id'];
            pg_free_result($chk);
            sort($valid);
            $want = $ids;
            sort($want);
            if ($valid !== $want) return [false, 'Assignee tidak valid. Hanya staf aktif (admin/teknisi).'];
        }
        $del = pg_query_params($conn, "DELETE FROM dev_task_assignees WHERE task_id = $1", [(int)$taskId]);
        if (!$del) return [false, 'Gagal membersihkan assignee lama: ' . pg_last_error($conn)];
        foreach ($ids as $uid) {
            $ins = pg_query_params(
                $conn,
                "INSERT INTO dev_task_assignees (task_id, user_id, assigned_by) VALUES ($1,$2,$3) ON CONFLICT DO NOTHING",
                [(int)$taskId, $uid, (int)$actorId]
            );
            if (!$ins) return [false, 'Gagal menyimpan assignee: ' . pg_last_error($conn)];
        }
        return [true, null];
    }
}

if (!function_exists('canManageAttachments')) {
    /**
     * Upload/hapus lampiran: admin, atau siapa pun yang terlibat di todo
     * (pembuat, owner, atau assignee). Teknisi tak terlibat ditolak.
     */
    function canManageAttachments($task, $user, $assigneeIds = [])
    {
        if (($user['role'] ?? null) === 'admin') return true;
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) return false;
        if ($uid === (int)($task['created_by'] ?? 0)) return true;
        if ($uid === (int)($task['owner_id'] ?? 0)) return true;
        foreach ($assigneeIds as $aid) {
            if ($uid === (int)$aid) return true;
        }
        return false;
    }
}

if (!function_exists('getTaskAttachments')) {
    /**
     * Daftar lampiran sebuah todo, urut upload.
     */
    function getTaskAttachments($conn, $taskId)
    {
        $out = [];
        $r = pg_query_params(
            $conn,
            "SELECT a.*, u.name AS uploader_name FROM dev_task_attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.task_id = $1 ORDER BY a.created_at ASC",
            [(int)$taskId]
        );
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = $row;
            pg_free_result($r);
        }
        return $out;
    }
}

if (!function_exists('taskAttachmentUsage')) {
    /**
     * Pemakaian kuota lampiran todo: ['count' => n, 'bytes' => total].
     */
    function taskAttachmentUsage($conn, $taskId)
    {
        $r = pg_query_params($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(file_size),0) AS b FROM dev_task_attachments WHERE task_id = $1", [(int)$taskId]);
        if (!$r) return ['count' => 0, 'bytes' => 0];
        $row = pg_fetch_assoc($r);
        pg_free_result($r);
        return ['count' => (int)$row['c'], 'bytes' => (int)$row['b']];
    }
}

if (!function_exists('saveTaskUploads')) {
    /**
     * Simpan multi-upload lampiran todo (maks 10 file, total 20MB per todo).
     * Mirror handleTicketUpload() per file: whitelist ekstensi + cek gambar.
     * Harus dipanggil di dalam transaksi milik pemanggil; gagal di file mana
     * pun → kembalikan [false, pesan] agar pemanggil ROLLBACK + hapus file
     * yang sudah ter-copy (daftar di $savedPaths).
     * Return [true, null] atau [false, 'pesan'].
     */
    function saveTaskUploads($conn, $taskId, $userId, &$savedPaths = [], $field = 'attachments')
    {
        $savedPaths = [];
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || !isset($_FILES[$field]['name'])) {
            return [true, null]; // tidak ada file = bukan error
        }
        $names = $_FILES[$field]['name'];
        if (!is_array($names)) {
            // Single file dikirim tanpa [] — normalisasi ke multi
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

        $maxFiles = 10;
        $maxBytes = 20 * 1024 * 1024; // 20MB total per todo
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip', 'rar'];

        $use = taskAttachmentUsage($conn, $taskId);
        if ($use['count'] + count($files) > $maxFiles) {
            return [false, 'Maksimal ' . $maxFiles . ' file lampiran per todo (saat ini ' . $use['count'] . ').'];
        }
        $newBytes = array_sum(array_column($files, 'size'));
        if ($use['bytes'] + $newBytes > $maxBytes) {
            return [false, 'Total lampiran maksimal 20MB per todo (sisa kuota ' . round(($maxBytes - $use['bytes']) / 1048576, 1) . 'MB).'];
        }

        $dir = __DIR__ . '/../uploads/dev_tasks';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return [false, 'Folder upload tidak bisa dibuat.'];
        }

        foreach ($files as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK) {
                return [false, 'Upload "' . basename($f['name']) . '" gagal (kode ' . $f['error'] . ').'];
            }
            $original = basename($f['name'] !== '' ? $f['name'] : 'file');
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
            $safe = substr($safe !== '' ? $safe : 'lampiran', 0, 60);
            $stored = date('Ym') . '_' . bin2hex(random_bytes(6)) . '_' . $safe . '.' . $ext;
            $dest = $dir . '/' . $stored;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                return [false, 'Gagal menyimpan "' . $original . '".'];
            }
            @chmod($dest, 0644);
            $rel = 'uploads/dev_tasks/' . $stored;
            $savedPaths[] = $rel;
            $ins = pg_query_params(
                $conn,
                "INSERT INTO dev_task_attachments (task_id, file_path, original_name, file_size, uploaded_by) VALUES ($1,$2,$3,$4,$5)",
                [(int)$taskId, $rel, $original, $f['size'], (int)$userId]
            );
            if (!$ins) return [false, 'Gagal mencatat "' . $original . '": ' . pg_last_error($conn)];
        }
        return [true, null];
    }
}

if (!function_exists('isTaskOverdue')) {
    /**
     * Todo overdue: ada due_date lewat hari ini dan fase belum done.
     */
    function isTaskOverdue($task)
    {
        if (($task['phase'] ?? '') === 'done') return false;
        if (empty($task['due_date'])) return false;
        return strtotime($task['due_date']) < strtotime(date('Y-m-d'));
    }
}
