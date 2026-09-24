<?php
// Aksi Tindak Lanjut Change Request — POST only + CSRF + wajib catatan.
// Mirror ticket_action.php untuk tabel change_requests/* (file tiket tidak diubah).
require_once 'config.php';
require_once 'includes/cr_auth.php';
requireLogin();
requirePost();
requireCsrf();

$user = getCurrentUser();
$role = $user['role'] ?? '';

$id = (int)($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$noteRaw = trim($_POST['note'] ?? '');

if ($id <= 0) {
    header('Location: cr_list.php');
    exit;
}

$conn = getDBConnection();
$r = pg_query_params($conn, "SELECT * FROM change_requests WHERE id = $1", [$id]);
if (!$r || pg_num_rows($r) === 0) {
    if ($r) pg_free_result($r);
    pg_close($conn);
    header('Location: cr_list.php');
    exit;
}
$cr = pg_fetch_assoc($r);
pg_free_result($r);

if (!canActOnCr($cr, $user)) {
    pg_close($conn);
    header('Location: cr_list.php');
    exit;
}

$from = $cr['status'];

// Peta aksi fleksibel (mirror tiket: staf boleh loncat open->resolved + reopen sendiri)
$map = [
    // action => [from_allowed, to, assign_self?, set_resolved?, set_closed?, clear_dates?]
    'take'            => [['open'], 'in_progress', true, false, false, false],
    'resolve_direct'  => [['open'], 'resolved', true, true, false, false],
    'resolve'         => [['in_progress', 'open'], 'resolved', false, true, false, false],
    'close'           => [['resolved'], 'closed', false, false, true, false],
    'reopen_open'     => [['resolved', 'closed', 'in_progress'], 'open', false, false, false, true],
    'reopen_progress' => [['resolved', 'closed', 'open'], 'in_progress', true, false, false, true],
    'unassign'        => [['in_progress'], 'open', false, false, false, true],
    'comment'         => [null, null, false, false, false, false], // tanpa ganti status
];

if (!isset($map[$action])) {
    pg_close($conn);
    header('Location: view_cr.php?id=' . $id);
    exit;
}

// Pelapor selain komentar ditolak
if ($role === 'pelapor' && $action !== 'comment') {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Pelapor hanya boleh menambah komentar. Perubahan status oleh staf.';
    header('Location: view_cr.php?id=' . $id);
    exit;
}

[$allowedFrom, $to, $assignSelf, $setResolved, $setClosed, $clearDates] = $map[$action];

if ($allowedFrom !== null && !in_array($from, $allowedFrom, true)) {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Transisi tidak valid: ' . $from . ' tidak bisa via aksi ini.';
    header('Location: view_cr.php?id=' . $id);
    exit;
}

// Wajib catatan >= 10 karakter untuk semua aksi
$note = sanitizeRichText($noteRaw);
$plainLen = mb_strlen(trim(strip_tags($noteRaw)));
if ($plainLen < 10) {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Catatan wajib diisi minimal 10 karakter untuk setiap tindak lanjut.';
    header('Location: view_cr.php?id=' . $id);
    exit;
}
if ($note === '') $note = e($noteRaw);

pg_query($conn, 'BEGIN');

$newAssigned = $cr['assigned_to'];
try {
    if ($action === 'comment') {
        $c = pg_query_params($conn, "INSERT INTO change_request_comments (cr_id, user_id, body) VALUES ($1,$2,$3)", [$id, $user['id'], $note]);
        if (!$c) throw new Exception('Gagal simpan komentar: ' . pg_last_error($conn));
        $h = pg_query_params($conn, "INSERT INTO change_request_history (cr_id, actor_id, from_status, to_status, note) VALUES ($1,$2,$3,$4,$5)", [$id, $user['id'], $from, $from, $note]);
        if (!$h) throw new Exception('Gagal simpan riwayat.');
        pg_query_params($conn, "UPDATE change_requests SET updated_at=NOW() WHERE id=$1", [$id]);
    } else {
        if ($assignSelf) {
            $newAssigned = $user['id'];
        }
        if ($action === 'unassign') {
            $newAssigned = null;
        }

        $resolvedAt = $cr['resolved_at'];
        $closedAt = $cr['closed_at'];
        if ($setResolved) $resolvedAt = date('Y-m-d H:i:s');
        if ($setClosed) $closedAt = date('Y-m-d H:i:s');
        if ($clearDates) {
            if ($to === 'open') {
                $resolvedAt = null;
                $closedAt = null;
            } elseif ($to === 'in_progress') {
                $closedAt = null;
                if (in_array($from, ['resolved', 'closed', 'open'], true)) $resolvedAt = null;
            }
        }

        $u = pg_query_params($conn, "UPDATE change_requests SET status=$1, assigned_to=$2, resolved_at=$3, closed_at=$4, updated_at=NOW() WHERE id=$5", [$to, $newAssigned, $resolvedAt, $closedAt, $id]);
        if (!$u) throw new Exception('Gagal update status: ' . pg_last_error($conn));

        $c = pg_query_params($conn, "INSERT INTO change_request_comments (cr_id, user_id, body) VALUES ($1,$2,$3)", [$id, $user['id'], $note]);
        if (!$c) throw new Exception('Gagal simpan catatan.');
        $h = pg_query_params($conn, "INSERT INTO change_request_history (cr_id, actor_id, from_status, to_status, note) VALUES ($1,$2,$3,$4,$5)", [$id, $user['id'], $from, $to, $note]);
        if (!$h) throw new Exception('Gagal simpan riwayat.');
    }

    pg_query($conn, 'COMMIT');
    // Notifikasi: pelapor (bila aktor bukan pelapor) + assignee baru
    $plain = trim(mb_substr(strip_tags($noteRaw), 0, 120));
    $link = 'view_cr.php?id=' . $id;
    if ((int)$cr['user_id'] !== (int)$user['id']) {
        $judul = $action === 'comment'
            ? 'Komentar baru di ' . $cr['cr_number']
            : 'CR ' . $cr['cr_number'] . ': ' . $from . ' → ' . ($to ?? $from);
        notifyUser($conn, $cr['user_id'], $judul, $user['name'] . ': ' . $plain, $link);
    }
    if (!empty($newAssigned) && (int)$newAssigned !== (int)$user['id'] && (int)$newAssigned !== (int)$cr['user_id']) {
        notifyUser($conn, $newAssigned, 'CR ' . $cr['cr_number'] . ' diassign ke Anda', $user['name'] . ': ' . $plain, $link);
    }
    $_SESSION['flash_ok'] = $action === 'comment' ? 'Komentar ditambahkan.' : ('Status: ' . $from . ' → ' . ($to ?? $from) . ' tersimpan.');
} catch (Exception $ex) {
    pg_query($conn, 'ROLLBACK');
    $_SESSION['flash_error'] = $ex->getMessage();
}

pg_close($conn);
header('Location: view_cr.php?id=' . $id);
exit;
