<?php
// Helper Change Request (fondasi header, Task CR).
// Dipisah dari config.php agar tickets tidak tersentuh; dimuat via require_once dari create_cr.php.
// Butuh fungsi getDBConnection() dari config.php — jangan panggil sebelum config.php dimuat.

if (!function_exists('generateCrNumber')) {
    /**
     * Generate nomor CR unik format CR-YYYYMM-XXXX (counter per bulan).
     * Mirror generateTicketNumber(): kunci tabel saat baca MAX agar aman dari race.
     * Bila $existingConn diberikan, pakai koneksi itu tanpa BEGIN/COMMIT sendiri
     * (dipanggil di dalam transaksi create_cr.php).
     */
    function generateCrNumber($existingConn = null)
    {
        $own = $existingConn === null;
        $conn = $own ? getDBConnection() : $existingConn;
        if ($own) {
            pg_query($conn, 'BEGIN');
            pg_query($conn, 'LOCK TABLE change_requests IN SHARE ROW EXCLUSIVE MODE');
        }
        $prefix = 'CR-' . date('Ym');
        $result = pg_query(
            $conn,
            "SELECT '" . $prefix . "-' || LPAD(COALESCE(MAX(CAST(SUBSTRING(cr_number FROM '[0-9]+$') AS INTEGER)) + 1, 1)::TEXT, 4, '0') FROM change_requests WHERE cr_number LIKE '" . $prefix . "-%'"
        );
        $crNumber = $result ? pg_fetch_result($result, 0, 0) : null;
        if ($result) pg_free_result($result);
        if (!$crNumber) {
            $crNumber = $prefix . '-0001';
        }
        if ($own) {
            pg_query($conn, 'COMMIT');
            pg_close($conn);
        }
        return $crNumber;
    }
}

if (!function_exists('canViewCr')) {
    /**
     * Siapa boleh lihat CR: admin/teknisi semua; pelapor hanya milik sendiri.
     * Mirror canViewTicket().
     */
    function canViewCr($cr, $user)
    {
        $role = $user['role'] ?? null;
        if ($role === 'admin' || $role === 'teknisi') return true;
        if ($role === 'pelapor' && (int)($cr['user_id'] ?? 0) === (int)($user['id'] ?? 0)) return true;
        return false;
    }
}

if (!function_exists('canActOnCr')) {
    /**
     * Siapa boleh tindak lanjut CR: admin/teknisi; pelapor hanya komentar di CR miliknya.
     * Mirror canActOnTicket(). Edit data murni admin (dipakai view/edit Task berikutnya).
     */
    function canActOnCr($cr, $user)
    {
        $role = $user['role'] ?? null;
        if ($role === 'admin' || $role === 'teknisi') return true;
        if ($role === 'pelapor' && (int)($cr['user_id'] ?? 0) === (int)($user['id'] ?? 0)) return true;
        return false;
    }
}

if (!function_exists('canEditCr')) {
    /**
     * Edit data CR = admin only (keputusan sama seperti canEditTicket()).
     * Disediakan sekarang agar view/edit Task berikutnya tinggal pakai.
     */
    function canEditCr($cr, $user)
    {
        return ($user['role'] ?? null) === 'admin';
    }
}

if (!function_exists('canDeleteCr')) {
    /**
     * Hapus CR = admin only via POST+CSRF (mirror canDeleteTicket()).
     */
    function canDeleteCr($cr, $user)
    {
        return ($user['role'] ?? null) === 'admin';
    }
}

if (!function_exists('canManageCrPic')) {
    /**
     * Kelola PIC multi-person CR = admin only.
     * Kandidat PIC: user aktif role admin/teknisi (divalidasi di setCrPics()).
     * Melihat daftar PIC: semua yang boleh lihat CR (canViewCr).
     */
    function canManageCrPic($cr, $user)
    {
        return ($user['role'] ?? null) === 'admin';
    }
}

if (!function_exists('getCrPics')) {
    /**
     * Ambil daftar PIC sebuah CR beserta info user, urut nama.
     * Return array of ['id','name','username','role','assigned_by','created_at'].
     */
    function getCrPics($conn, $crId)
    {
        $pics = [];
        $r = pg_query_params(
            $conn,
            "SELECT p.cr_id, p.user_id AS id, u.name, u.username, u.role, p.assigned_by, p.created_at"
            . " FROM change_request_pics p JOIN users u ON u.id = p.user_id"
            . " WHERE p.cr_id = $1 ORDER BY u.name ASC",
            [(int)$crId]
        );
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $pics[] = $row;
            pg_free_result($r);
        }
        return $pics;
    }
}

if (!function_exists('getCrPicCandidates')) {
    /**
     * Kandidat PIC: user aktif role admin/teknisi, urut nama.
     * Mirror dropdown report_for di create_cr.php (khusus staf).
     */
    function getCrPicCandidates($conn)
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

if (!function_exists('setCrPics')) {
    /**
     * Ganti seluruh daftar PIC sebuah CR + sinkron assigned_to + tulis history.
     * Harus dipanggil di dalam transaksi milik pemanggil (BEGIN/COMMIT di luar).
     * Validasi: array int, dedup, maks 10, tiap id aktif + role admin/teknisi.
     * assigned_to disinkron = PIC dengan created_at tertua (PIC pertama),
     * atau NULL bila daftar kosong — agar laporan/API lama tetap jalan.
     * Return [true, null] sukses atau [false, 'pesan error'].
     */
    function setCrPics($conn, $crId, $userIds, $actorId)
    {
        if (!is_array($userIds)) $userIds = [$userIds];
        $ids = [];
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid > 0 && !in_array($uid, $ids, true)) $ids[] = $uid;
        }
        if (count($ids) > 10) return [false, 'Maksimal 10 PIC per CR.'];
        if (!empty($ids)) {
            $place = [];
            foreach ($ids as $i => $uid) $place[] = '$' . ($i + 1);
            $chk = pg_query_params(
                $conn,
                "SELECT id FROM users WHERE id IN (" . implode(',', $place) . ") AND is_active = TRUE AND role IN ('admin','teknisi')",
                $ids
            );
            if (!$chk) return [false, 'Gagal validasi kandidat PIC.'];
            $valid = [];
            while ($row = pg_fetch_assoc($chk)) $valid[] = (int)$row['id'];
            pg_free_result($chk);
            sort($valid);
            $want = $ids;
            sort($want);
            if ($valid !== $want) return [false, 'Kandidat PIC tidak valid. Hanya user aktif role admin/teknisi.'];
        }
        $del = pg_query_params($conn, "DELETE FROM change_request_pics WHERE cr_id = $1", [(int)$crId]);
        if (!$del) return [false, 'Gagal membersihkan PIC lama: ' . pg_last_error($conn)];
        foreach ($ids as $uid) {
            $ins = pg_query_params(
                $conn,
                "INSERT INTO change_request_pics (cr_id, user_id, assigned_by) VALUES ($1,$2,$3) ON CONFLICT DO NOTHING",
                [(int)$crId, $uid, (int)$actorId]
            );
            if (!$ins) return [false, 'Gagal menyimpan PIC: ' . pg_last_error($conn)];
        }
        // Sinkron assigned_to = PIC tertua agar kode lama (take/unassign/laporan/API) tetap valid.
        $sync = pg_query_params(
            $conn,
            "UPDATE change_requests SET assigned_to = (SELECT user_id FROM change_request_pics WHERE cr_id = $1 ORDER BY created_at ASC, user_id ASC LIMIT 1), updated_at = NOW() WHERE id = $1",
            [(int)$crId]
        );
        if (!$sync) return [false, 'Gagal sinkron assigned_to: ' . pg_last_error($conn)];
        return [true, null];
    }
}

if (!function_exists('handleCrUpload')) {
    /**
     * Upload lampiran CR ke uploads/change_requests/. Mirror handleTicketUpload().
     * Return [pathRelatif, namaAsli] atau [null, null] bila tidak ada file.
     * $error diisi pesan kesalahan bila upload gagal (dan return [false, false]).
     * Batas 5MB, ekstensi sama seperti tiket.
     */
    function handleCrUpload($field = 'attachment', &$error = null)
    {
        $error = null;
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return [null, null];
        $f = $_FILES[$field];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
        if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $error = 'Upload lampiran gagal (kode ' . (int)$f['error'] . ').';
            return [false, false];
        }

        $maxBytes = 5 * 1024 * 1024; // 5MB
        if (($f['size'] ?? 0) > $maxBytes) {
            $error = 'Ukuran lampiran maksimal 5MB.';
            return [false, false];
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip', 'rar'];
        $original = basename($f['name'] ?? 'file');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $error = 'Jenis file tidak diizinkan. Boleh: ' . implode(', ', $allowedExt) . '.';
            return [false, false];
        }

        $dir = __DIR__ . '/../uploads/change_requests';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $error = 'Folder upload tidak bisa dibuat.';
            return [false, false];
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($original, PATHINFO_FILENAME));
        $safe = substr($safe !== '' ? $safe : 'lampiran', 0, 60);
        $stored = date('Ym') . '_' . bin2hex(random_bytes(6)) . '_' . $safe . '.' . $ext;
        $dest = $dir . '/' . $stored;

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $img = @getimagesize($f['tmp_name']);
            if ($img === false) {
                $error = 'File gambar tidak valid.';
                return [false, false];
            }
        }

        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            $error = 'Gagal menyimpan lampiran.';
            return [false, false];
        }
        @chmod($dest, 0644);
        return ['uploads/change_requests/' . $stored, $original];
    }
}
