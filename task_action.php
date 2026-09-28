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

// ---- Kelola assignee multi-person: admin + pembuat, tanpa wajib catatan ----
if ($action === 'assignee_add' || $action === 'assignee_remove') {
    if (!canManageAssignees($task, $user)) {
        pg_close($conn);
        $_SESSION['flash_error'] = 'Hanya admin atau pembuat todo yang boleh mengatur assignee.';
        header('Location: view_task.php?id=' . $id);
        exit;
    }
    $assigneeId = (int)($_POST['assignee_id'] ?? 0);
    if ($assigneeId <= 0) {
        pg_close($conn);
        $_SESSION['flash_error'] = 'Pilih user assignee terlebih dahulu.';
        header('Location: view_task.php?id=' . $id);
        exit;
    }
    $cand = pg_query_params($conn, "SELECT id, name FROM users WHERE id = $1 AND is_active = TRUE AND role IN ('admin','teknisi')", [$assigneeId]);
    $candName = ($cand && pg_num_rows($cand) > 0) ? pg_fetch_result($cand, 0, 1) : null;
    if ($cand) pg_free_result($cand);
    if ($candName === null) {
        pg_close($conn);
        $_SESSION['flash_error'] = 'Assignee tidak valid. Hanya staf aktif (admin/teknisi).';
        header('Location: view_task.php?id=' . $id);
        exit;
    }

    pg_query($conn, 'BEGIN');
    try {
        $cur = pg_query_params($conn, "SELECT user_id FROM dev_task_assignees WHERE task_id = $1", [$id]);
        $ids = [];
        if ($cur) {
            while ($row = pg_fetch_assoc($cur)) $ids[] = (int)$row['user_id'];
            pg_free_result($cur);
        }
        if ($action === 'assignee_add') {
            if (!in_array($assigneeId, $ids, true)) $ids[] = $assigneeId;
            if (count($ids) > 10) throw new Exception('Maksimal 10 assignee per todo.');
            $noteHist = 'Assignee ditambahkan oleh ' . $user['name'] . ': ' . $candName . '.';
        } else {
            if ($assigneeId === (int)$task['owner_id']) throw new Exception('Owner utama tidak bisa dihapus dari assignee. Ganti owner dulu via Edit (admin).');
            $ids = array_values(array_filter($ids, fn($x) => $x !== $assigneeId));
            $noteHist = 'Assignee dihapus oleh ' . $user['name'] . ': ' . $candName . '.';
        }
        [$ok, $setErr] = setTaskAssignees($conn, $id, $ids, $user['id']);
        if (!$ok) throw new Exception($setErr ?: 'Gagal menyimpan assignee.');
        $h = pg_query_params($conn, "INSERT INTO dev_task_history (task_id, actor_id, from_phase, to_phase, note) VALUES ($1,$2,$3,$4,$5)", [$id, $user['id'], $from, $from, $noteHist]);
        if (!$h) throw new Exception('Gagal simpan riwayat.');
        pg_query($conn, 'COMMIT');
        if ($action === 'assignee_add' && $assigneeId !== (int)$user['id']) {
            notifyUser($conn, $assigneeId, 'Todo ' . $task['task_code'] . ' menugaskan Anda', $user['name'] . ': ' . $task['title'], 'view_task.php?id=' . $id);
        }
        $_SESSION['flash_ok'] = $action === 'assignee_add' ? ('Assignee ' . $candName . ' ditambahkan.') : ('Assignee ' . $candName . ' dihapus.');
    } catch (Exception $ex) {
        pg_query($conn, 'ROLLBACK');
        $_SESSION['flash_error'] = $ex->getMessage();
    }
    pg_close($conn);
    header('Location: view_task.php?id=' . $id);
    exit;
}

// ---- Kelola lampiran: admin atau yang terlibat di todo ----
if ($action === 'attach_add' || $action === 'attach_remove') {
    $curA = pg_query_params($conn, "SELECT user_id FROM dev_task_assignees WHERE task_id = $1", [$id]);
    $aIds = [];
    if ($curA) {
        while ($row = pg_fetch_assoc($curA)) $aIds[] = (int)$row['user_id'];
        pg_free_result($curA);
    }
    if (!canManageAttachments($task, $user, $aIds)) {
        pg_close($conn);
        $_SESSION['flash_error'] = 'Hanya admin atau yang terlibat di todo yang boleh mengelola lampiran.';
        header('Location: view_task.php?id=' . $id);
        exit;
    }

    pg_query($conn, 'BEGIN');
    $savedPaths = [];
    try {
        if ($action === 'attach_add') {
            [$ok, $upErr] = saveTaskUploads($conn, $id, $user['id'], $savedPaths, 'attachments');
            if (!$ok) throw new Exception($upErr ?: 'Upload gagal.');
            if (empty($savedPaths)) throw new Exception('Pilih minimal 1 file untuk diupload.');
            $noteHist = 'Lampiran ditambahkan oleh ' . $user['name'] . ' (' . count($savedPaths) . ' file).';
        } else {
            $attId = (int)($_POST['attachment_id'] ?? 0);
            $ar = pg_query_params($conn, "SELECT * FROM dev_task_attachments WHERE id = $1 AND task_id = $2", [$attId, $id]);
            if (!$ar || pg_num_rows($ar) === 0) {
                if ($ar) pg_free_result($ar);
                throw new Exception('Lampiran tidak ditemukan.');
            }
            $att = pg_fetch_assoc($ar);
            pg_free_result($ar);
            $del = pg_query_params($conn, "DELETE FROM dev_task_attachments WHERE id = $1", [$attId]);
            if (!$del) throw new Exception('Gagal hapus lampiran.');
            if (!empty($att['file_path']) && file_exists(__DIR__ . '/' . $att['file_path'])) @unlink(__DIR__ . '/' . $att['file_path']);
            $noteHist = 'Lampiran dihapus oleh ' . $user['name'] . ': ' . ($att['original_name'] ?? '');
        }
        $h = pg_query_params($conn, "INSERT INTO dev_task_history (task_id, actor_id, from_phase, to_phase, note) VALUES ($1,$2,$3,$4,$5)", [$id, $user['id'], $from, $from, $noteHist]);
        if (!$h) throw new Exception('Gagal simpan riwayat.');
        pg_query_params($conn, "UPDATE dev_tasks SET updated_at=NOW() WHERE id=$1", [$id]);
        pg_query($conn, 'COMMIT');
        $_SESSION['flash_ok'] = $action === 'attach_add' ? (count($savedPaths) . ' file lampiran ditambahkan.') : 'Lampiran dihapus.';
    } catch (Exception $ex) {
        pg_query($conn, 'ROLLBACK');
        foreach ($savedPaths as $sp) {
            if (file_exists(__DIR__ . '/' . $sp)) @unlink(__DIR__ . '/' . $sp);
        }
        $_SESSION['flash_error'] = $ex->getMessage();
    }
    pg_close($conn);
    header('Location: view_task.php?id=' . $id);
    exit;
}

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
        if ($assignSelf) {
            $newOwner = $user['id'];
            // Handover: yang mengambil otomatis masuk daftar assignee + jadi owner utama
            $ap = pg_query_params($conn, "INSERT INTO dev_task_assignees (task_id, user_id, assigned_by) VALUES ($1,$2,$3) ON CONFLICT DO NOTHING", [$id, $user['id'], $user['id']]);
            if (!$ap) throw new Exception('Gagal sinkron assignee: ' . pg_last_error($conn));
        }

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
