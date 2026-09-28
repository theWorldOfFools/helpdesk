<?php
// POST api/tasks_move.php — pindah fase todo via drag-and-drop board (JSON).
// Body: {id, to_phase, csrf_token}. Khusus staf (admin/teknisi).
// Transisi bebas ke fase mana pun (keputusan), auto-note di history.
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../includes/dev_auth.php';

if (!in_array($apiUser['role'] ?? '', ['admin', 'teknisi'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Forbidden'], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Method harus POST'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$csrf = (string)($input['csrf_token'] ?? '');
if (!hash_equals(csrf_token(), $csrf)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF tidak valid. Muat ulang halaman.'], 419);
}

$id = (int)($input['id'] ?? 0);
$to = trim((string)($input['to_phase'] ?? ''));
$phases = devPhases();
if ($id <= 0 || !in_array($to, $phases, true)) {
    jsonResponse(['ok' => false, 'error' => 'Parameter tidak valid.'], 422);
}

$conn = getDBConnection();
$r = pg_query_params($conn, "SELECT * FROM dev_tasks WHERE id = $1", [$id]);
if (!$r || pg_num_rows($r) === 0) {
    if ($r) pg_free_result($r);
    pg_close($conn);
    jsonResponse(['ok' => false, 'error' => 'Todo tidak ditemukan.'], 404);
}
$task = pg_fetch_assoc($r);
pg_free_result($r);
$from = $task['phase'];

if (!canActOnTask($task, $apiUser)) {
    pg_close($conn);
    jsonResponse(['ok' => false, 'error' => 'Forbidden', 'from_phase' => $from], 403);
}
if ($to === $from) {
    pg_close($conn);
    jsonResponse(['ok' => false, 'error' => 'Sudah di fase ini.', 'from_phase' => $from], 422);
}

pg_query($conn, 'BEGIN');
try {
    $doneAt = $task['done_at'];
    if ($to === 'done') $doneAt = date('Y-m-d H:i:s');
    elseif ($from === 'done') $doneAt = null;

    $u = pg_query_params($conn, "UPDATE dev_tasks SET phase=$1, done_at=$2, updated_at=NOW() WHERE id=$3", [$to, $doneAt, $id]);
    if (!$u) throw new Exception('Gagal update fase.');
    $note = 'Dipindah via board: ' . $from . ' → ' . $to . ' oleh ' . $apiUser['name'] . '.';
    $h = pg_query_params($conn, "INSERT INTO dev_task_history (task_id, actor_id, from_phase, to_phase, note) VALUES ($1,$2,$3,$4,$5)", [$id, $apiUser['id'], $from, $to, $note]);
    if (!$h) throw new Exception('Gagal simpan riwayat.');
    pg_query($conn, 'COMMIT');
    pg_close($conn);
    jsonResponse(['ok' => true, 'id' => $id, 'from_phase' => $from, 'to_phase' => $to]);
} catch (Exception $ex) {
    pg_query($conn, 'ROLLBACK');
    pg_close($conn);
    jsonResponse(['ok' => false, 'error' => $ex->getMessage(), 'from_phase' => $from], 500);
}
