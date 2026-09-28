<?php
// Edit mini-todo SDLC — admin, owner, atau pembuat (mirror canEditTask).
require_once 'config.php';
require_once 'includes/dev_auth.php';
requireLogin();

$user = getCurrentUser();
$pageTitle = 'Edit Todo SDLC';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: tasks.php');
    exit;
}

require_once 'includes/header.php';

$conn = getDBConnection();

$result = pg_query_params($conn, "SELECT * FROM dev_tasks WHERE id = $1", [$id]);
if (!$result || pg_num_rows($result) === 0) {
    if ($result) pg_free_result($result);
    pg_close($conn);
    header('Location: tasks.php');
    exit;
}
$task = pg_fetch_assoc($result);
pg_free_result($result);

if (!canEditTask($task, $user)) {
    http_response_code(403);
    pg_close($conn);
    ?>
    <div class="alert alert-danger text-center py-5">
        <h2 class="h5 text-danger">Akses Ditolak (403)</h2>
        <p>Hanya admin, owner, atau pembuat todo yang boleh mengedit.</p>
        <a href="view_task.php?id=<?php echo (int)$id; ?>" class="btn btn-primary">Kembali</a>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

$phases = devPhases();
$priorities = ['low', 'medium', 'high', 'critical'];
$ownerOptions = getTaskOwnerCandidates($conn);
$ticketOptions = [];
$tr = pg_query($conn, "SELECT id, ticket_number, title FROM tickets WHERE status NOT IN ('resolved','closed') OR id = " . (int)($task['ticket_id'] ?? 0) . " ORDER BY created_at DESC LIMIT 200");
if ($tr) {
    while ($row = pg_fetch_assoc($tr)) $ticketOptions[] = $row;
    pg_free_result($tr);
}
$crOptions = [];
$crq = pg_query($conn, "SELECT id, cr_number, aplikasi, fitur FROM change_requests WHERE status <> 'closed' OR id = " . (int)($task['cr_id'] ?? 0) . " ORDER BY created_at DESC LIMIT 200");
if ($crq) {
    while ($row = pg_fetch_assoc($crq)) $crOptions[] = $row;
    pg_free_result($crq);
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $title = trim($_POST['title'] ?? '');
    $descRaw = trim($_POST['description'] ?? '');
    $priority = trim($_POST['priority'] ?? '');
    $phase = trim($_POST['phase'] ?? '');
    $ownerPost = (int)($_POST['owner_id'] ?? 0);
    $ticketPost = trim($_POST['ticket_id'] ?? '');
    $crPost = trim($_POST['cr_id'] ?? '');
    $duePost = trim($_POST['due_date'] ?? '');
    $estPost = trim($_POST['estimate'] ?? '');

    $err = null;
    if ($title === '' || $descRaw === '' || $priority === '' || $phase === '') {
        $err = 'Judul, deskripsi, prioritas, dan fase wajib diisi!';
    } elseif (mb_strlen($title) > 255) {
        $err = 'Judul maksimal 255 karakter.';
    } elseif (!in_array($priority, $priorities, true)) {
        $err = 'Prioritas tidak valid.';
    } elseif (!in_array($phase, $phases, true)) {
        $err = 'Fase tidak valid.';
    }

    $ownerId = null;
    if (!$err && $ownerPost > 0) {
        $chk = pg_query_params($conn, "SELECT id FROM users WHERE id = $1 AND is_active = TRUE AND role IN ('admin','teknisi')", [$ownerPost]);
        if (!$chk || pg_num_rows($chk) === 0) $err = 'Owner tidak valid.';
        else $ownerId = $ownerPost;
        if ($chk) pg_free_result($chk);
    }
    $ticketId = null;
    $crId = null;
    if (!$err && $ticketPost !== '' && $crPost !== '') {
        $err = 'Pilih salah satu relasi: tiket ATAU CR.';
    }
    if (!$err && $ticketPost !== '') {
        $chk = pg_query_params($conn, "SELECT id FROM tickets WHERE id = $1", [(int)$ticketPost]);
        if (!$chk || pg_num_rows($chk) === 0) $err = 'Tiket relasi tidak ditemukan.';
        else $ticketId = (int)$ticketPost;
        if ($chk) pg_free_result($chk);
    }
    if (!$err && $crPost !== '') {
        $chk = pg_query_params($conn, "SELECT id FROM change_requests WHERE id = $1", [(int)$crPost]);
        if (!$chk || pg_num_rows($chk) === 0) $err = 'CR relasi tidak ditemukan.';
        else $crId = (int)$crPost;
        if ($chk) pg_free_result($chk);
    }
    $dueDate = null;
    if (!$err && $duePost !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $duePost)) $err = 'Format due date tidak valid.';
        elseif ($duePost < date('Y-m-d')) $err = 'Due date minimal hari ini.';
        else $dueDate = $duePost;
    }
    $estimate = null;
    if (!$err && $estPost !== '') {
        if (!ctype_digit($estPost) || (int)$estPost < 1 || (int)$estPost > 1000) $err = 'Estimasi jam harus angka 1-1000.';
        else $estimate = (int)$estPost;
    }

    if ($err) {
        $message = $err;
        $messageType = 'danger';
        $task['title'] = $title;
        $task['description'] = $descRaw;
        $task['priority'] = $priority;
        $task['phase'] = $phase;
        $task['owner_id'] = $ownerId;
        $task['ticket_id'] = $ticketId;
        $task['cr_id'] = $crId;
        $task['due_date'] = $dueDate;
        $task['estimate_hours'] = $estimate;
    } else {
        $description = sanitizeRichText($descRaw);
        if ($description === '') $description = htmlspecialchars($descRaw);
        $doneAt = $task['done_at'];
        if ($phase === 'done' && $task['phase'] !== 'done') $doneAt = date('Y-m-d H:i:s');
        elseif ($phase !== 'done' && $task['phase'] === 'done') $doneAt = null;

        pg_query($conn, 'BEGIN');
        $upd = pg_query_params(
            $conn,
            "UPDATE dev_tasks SET title=$1, description=$2, priority=$3, phase=$4, owner_id=$5, ticket_id=$6, cr_id=$7, due_date=$8, estimate_hours=$9, done_at=$10, updated_at=NOW() WHERE id=$11",
            [$title, $description, $priority, $phase, $ownerId, $ticketId, $crId, $dueDate, $estimate, $doneAt, $id]
        );
        $ok = (bool)$upd;
        if ($ok && $phase !== $task['phase']) {
            $hi = pg_query_params(
                $conn,
                "INSERT INTO dev_task_history (task_id, actor_id, from_phase, to_phase, note) VALUES ($1,$2,$3,$4,$5)",
                [$id, $user['id'], $task['phase'], $phase, 'Fase diubah via Edit.']
            );
            $ok = (bool)$hi;
        }
        if ($ok) {
            pg_query($conn, 'COMMIT');
            if ($ownerId && $ownerId !== (int)$user['id'] && $ownerId !== (int)$task['owner_id']) {
                notifyUser($conn, $ownerId, 'Todo ' . $task['task_code'] . ' diassign ke Anda', $user['name'] . ' mengubah todo: ' . $title, 'view_task.php?id=' . $id);
            }
            $message = 'Todo berhasil diperbarui!';
            $messageType = 'success';
            $r2 = pg_query_params($conn, "SELECT * FROM dev_tasks WHERE id = $1", [$id]);
            $task = pg_fetch_assoc($r2);
            pg_free_result($r2);
        } else {
            pg_query($conn, 'ROLLBACK');
            $message = 'Gagal memperbarui todo: ' . pg_last_error($conn);
            $messageType = 'danger';
        }
    }
}
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Edit Todo <?php echo htmlspecialchars($task['task_code']); ?></h2>
        <p class="text-secondary mb-0">Perbarui data, fase, owner, dan relasi todo.</p>
    </div>
    <a href="view_task.php?id=<?php echo $task['id']; ?>" class="btn btn-outline-secondary btn-sm flex-shrink-0">← Kembali ke Detail</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<form method="POST" id="taskForm" novalidate>
    <?php echo csrf_field(); ?>
    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
                <div class="mb-3">
                    <label for="title" class="form-label">Judul</label>
                    <input type="text" class="form-control" id="title" name="title" required maxlength="255" value="<?php echo htmlspecialchars($task['title']); ?>">
                </div>
                <div class="mb-0">
                    <label for="description" class="form-label">Deskripsi</label>
                    <textarea id="description" class="form-control" name="description" rows="8"><?php echo htmlspecialchars($task['description']); ?></textarea>
                </div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card mb-3"><div class="card-body">
                <div class="mb-3">
                    <label for="phase" class="form-label">Fase</label>
                    <select id="phase" name="phase" class="form-select" required>
                        <?php foreach ($phases as $ph): ?>
                            <option value="<?php echo $ph; ?>" <?php echo $task['phase'] === $ph ? 'selected' : ''; ?>><?php echo $ph; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="priority" class="form-label">Prioritas</label>
                    <select id="priority" name="priority" class="form-select" required>
                        <?php foreach ($priorities as $pr): ?>
                            <option value="<?php echo $pr; ?>" <?php echo $task['priority'] === $pr ? 'selected' : ''; ?>><?php echo ucfirst($pr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="owner_id" class="form-label">Owner</label>
                    <select id="owner_id" name="owner_id" class="form-select">
                        <option value="0">— Belum ada owner —</option>
                        <?php foreach ($ownerOptions as $uo): ?>
                            <option value="<?php echo $uo['id']; ?>" <?php echo (int)$task['owner_id'] === (int)$uo['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($uo['name']); ?> (<?php echo htmlspecialchars($uo['role']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label for="due_date" class="form-label">Due Date</label>
                        <input type="date" class="form-control" id="due_date" name="due_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($task['due_date'] ?? ''); ?>">
                    </div>
                    <div class="col-6">
                        <label for="estimate" class="form-label">Estimasi (jam)</label>
                        <input type="number" class="form-control" id="estimate" name="estimate" min="1" max="1000" value="<?php echo htmlspecialchars($task['estimate_hours'] ?? ''); ?>">
                    </div>
                </div>
            </div></div>
            <div class="card mb-3"><div class="card-body">
                <h3 class="h6 mb-3">Relasi <span class="text-secondary fw-normal">(salah satu)</span></h3>
                <div class="mb-3">
                    <label for="ticket_id" class="form-label">Tiket terkait</label>
                    <select id="ticket_id" name="ticket_id" class="form-select">
                        <option value="">— Tanpa tiket —</option>
                        <?php foreach ($ticketOptions as $to): ?>
                            <option value="<?php echo $to['id']; ?>" <?php echo (string)($task['ticket_id'] ?? '') === (string)$to['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($to['ticket_number']); ?> — <?php echo htmlspecialchars(mb_strimwidth($to['title'], 0, 40, '…')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label for="cr_id" class="form-label">CR terkait</label>
                    <select id="cr_id" name="cr_id" class="form-select">
                        <option value="">— Tanpa CR —</option>
                        <?php foreach ($crOptions as $co): ?>
                            <option value="<?php echo $co['id']; ?>" <?php echo (string)($task['cr_id'] ?? '') === (string)$co['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($co['cr_number']); ?> — <?php echo htmlspecialchars($co['aplikasi']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                <a href="view_task.php?id=<?php echo $task['id']; ?>" class="btn btn-danger">Batal</a>
            </div>
        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@39.0.2/build/ckeditor.js"></script>
<script>
(function () {
    var desc = document.getElementById('description');
    if (window.ClassicEditor && desc) {
        ClassicEditor.create(desc, {
            toolbar: ['heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'code', '|', 'undo', 'redo']
        }).catch(function () {});
    }
})();
</script>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
