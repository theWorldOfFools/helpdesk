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
