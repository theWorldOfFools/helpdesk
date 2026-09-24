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
