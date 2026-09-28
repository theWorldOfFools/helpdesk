<?php
// Aksi Tindak Lanjut mini-todo SDLC — POST only + CSRF + catatan wajib untuk pindah fase.
// Mirror cr_action.php untuk tabel dev_tasks/* (file tiket/CR tidak diubah).
// Khusus staf (admin/teknisi); pelapor ditolak di semua aksi.
require_once 'config.php';
require_once 'includes/dev_auth.php';
requireLogin();
requirePost();
requireCsrf();

$user = getCurrentUser();

$id = (int)($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$noteRaw = trim($_POST['note'] ?? '');

if ($id <= 0) {
    header('Location: tasks.php');
    exit;
}

$conn = getDBConnection();
$r = pg_query_params($conn, "SELECT * FROM dev_tasks WHERE id = $1", [$id]);
if (!$r || pg_num_rows($r) === 0) {
    if ($r) pg_free_result($r);
    pg_close($conn);
    header('Location: tasks.php');
    exit;
}
$task = pg_fetch_assoc($r);
pg_free_result($r);

if (!canActOnTask($task, $user)) {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Hanya admin/teknisi yang boleh menindaklanjuti todo.';
    header('Location: tasks.php');
    exit;
}

$from = $task['phase'];
$phases = devPhases();
$fromIdx = array_search($from, $phases, true);

// Peta aksi fase SDLC
$to = null;
$assignSelf = false;
switch ($action) {
    case 'advance':
        if ($fromIdx === false || $fromIdx >= count($phases) - 1) {
            pg_close($conn);
            $_SESSION['flash_error'] = 'Todo sudah di fase akhir (done).';
            header('Location: view_task.php?id=' . $id);
            exit;
        }
        $to = $phases[$fromIdx + 1];
        break;
    case 'back':
        if ($fromIdx === false || $fromIdx <= 0) {
            pg_close($conn);
            $_SESSION['flash_error'] = 'Todo sudah di fase awal (backlog).';
            header('Location: view_task.php?id=' . $id);
            exit;
        }
        $to = $phases[$fromIdx - 1];
        break;
    case 'to_done':
        if ($from === 'done') {
            pg_close($conn);
            $_SESSION['flash_error'] = 'Todo sudah done.';
            header('Location: view_task.php?id=' . $id);
            exit;
        }
        $to = 'done';
        break;
    case 'to_backlog':
        if ($from === 'backlog') {
            pg_close($conn);
            $_SESSION['flash_error'] = 'Todo sudah di backlog.';
            header('Location: view_task.php?id=' . $id);
            exit;
        }
        $to = 'backlog';
        break;
    case 'take':
        $to = $from; // tanpa ganti fase, hanya assign ke saya
        $assignSelf = true;
        break;
    case 'comment':
        $to = null; // tanpa ganti fase
        break;
    default:
        pg_close($conn);
        header('Location: view_task.php?id=' . $id);
        exit;
}

// Wajib catatan >= 10 karakter untuk semua aksi (konsisten tiket/CR)
$note = sanitizeRichText($noteRaw);
$plainLen = mb_strlen(trim(strip_tags($noteRaw)));
if ($plainLen < 10) {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Catatan wajib diisi minimal 10 karakter untuk setiap tindak lanjut.';
    header('Location: view_task.php?id=' . $id);
    exit;
}
if ($note === '') $note = e($noteRaw);

pg_query($conn, 'BEGIN');

$newOwner = $task['owner_id'];
try {
    if ($action === 'comment') {
        $c = pg_query_params($conn, "INSERT INTO dev_task_comments (task_id, user_id, body) VALUES ($1,$2,$3)", [$id, $user['id'], $note]);
        if (!$c) throw new Exception('Gagal simpan komentar: ' . pg_last_error($conn));
        $h = pg_query_params($conn, "INSERT INTO dev_task_history (task_id, actor_id, from_phase, to_phase, note) VALUES ($1,$2,$3,$4,$5)", [$id, $user['id'], $from, $from, $note]);
        if (!$h) throw new Exception('Gagal simpan riwayat.');
        pg_query_params($conn, "UPDATE dev_tasks SET updated_at=NOW() WHERE id=$1", [$id]);
    } else {
        if ($assignSelf) $newOwner = $user['id'];

        // done_at diisi saat masuk done, dikosongkan saat keluar dari done
        $doneAt = $task['done_at'];
        if ($to === 'done') $doneAt = date('Y-m-d H:i:s');
        elseif ($from === 'done' && $to !== 'done') $doneAt = null;

        $u = pg_query_params($conn, "UPDATE dev_tasks SET phase=$1, owner_id=$2, done_at=$3, updated_at=NOW() WHERE id=$4", [$to, $newOwner, $doneAt, $id]);
        if (!$u) throw new Exception('Gagal update fase: ' . pg_last_error($conn));

        $c = pg_query_params($conn, "INSERT INTO dev_task_comments (task_id, user_id, body) VALUES ($1,$2,$3)", [$id, $user['id'], $note]);
        if (!$c) throw new Exception('Gagal simpan catatan.');
        $h = pg_query_params($conn, "INSERT INTO dev_task_history (task_id, actor_id, from_phase, to_phase, note) VALUES ($1,$2,$3,$4,$5)", [$id, $user['id'], $from, $to, $note]);
        if (!$h) throw new Exception('Gagal simpan riwayat.');
    }

    pg_query($conn, 'COMMIT');
    // Notifikasi owner (bila aktor bukan owner)
    $plain = trim(mb_substr(strip_tags($noteRaw), 0, 120));
    $link = 'view_task.php?id=' . $id;
    if (!empty($newOwner) && (int)$newOwner !== (int)$user['id']) {
        $judul = $action === 'comment'
            ? 'Komentar baru di ' . $task['task_code']
            : 'Todo ' . $task['task_code'] . ': ' . $from . ' → ' . ($to ?? $from);
        notifyUser($conn, $newOwner, $judul, $user['name'] . ': ' . $plain, $link);
    }
    $_SESSION['flash_ok'] = $action === 'comment' ? 'Komentar ditambahkan.' : ('Fase: ' . $from . ' → ' . ($to ?? $from) . ' tersimpan.');
} catch (Exception $ex) {
    pg_query($conn, 'ROLLBACK');
    $_SESSION['flash_error'] = $ex->getMessage();
}

pg_close($conn);
header('Location: view_task.php?id=' . $id);
exit;
