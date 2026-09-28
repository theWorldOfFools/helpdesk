<?php
// Buat mini-todo SDLC internal — khusus staf (admin/teknisi). Pelapor ditolak.
// Mirip create_ticket.php tapi tanpa SLA/divisi: fase awal selalu backlog.
require_once 'config.php';
require_once 'includes/dev_auth.php';
requireLogin();

$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['admin', 'teknisi'], true)) {
    header('Location: dashboard.php');
    exit;
}
$pageTitle = 'Buat Todo SDLC';

require_once 'includes/header.php';

$conn = getDBConnection();
$staffOptions = getTaskOwnerCandidates($conn);

// Relasi opsional: tiket yang belum selesai + CR yang belum closed (batas 200 terbaru)
$ticketOptions = [];
$tr = pg_query($conn, "SELECT id, ticket_number, title FROM tickets WHERE status NOT IN ('resolved','closed') ORDER BY created_at DESC LIMIT 200");
if ($tr) {
    while ($row = pg_fetch_assoc($tr)) $ticketOptions[] = $row;
    pg_free_result($tr);
}
$crOptions = [];
$cr = pg_query($conn, "SELECT id, cr_number, aplikasi, fitur FROM change_requests WHERE status <> 'closed' ORDER BY created_at DESC LIMIT 200");
if ($cr) {
    while ($row = pg_fetch_assoc($cr)) $crOptions[] = $row;
    pg_free_result($cr);
}

$message = '';
$messageType = '';
$createdId = null;
$createdCode = null;
$old = ['title' => '', 'description' => '', 'priority' => 'medium', 'ticket_id' => '', 'cr_id' => '', 'due_date' => '', 'estimate' => ''];
$postedAssignees = [];
$priorities = ['low', 'medium', 'high', 'critical'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $old['title'] = trim($_POST['title'] ?? '');
    $old['description'] = trim($_POST['description'] ?? '');
    $old['priority'] = trim($_POST['priority'] ?? 'medium');
    $old['ticket_id'] = trim($_POST['ticket_id'] ?? '');
    $old['cr_id'] = trim($_POST['cr_id'] ?? '');
    $old['due_date'] = trim($_POST['due_date'] ?? '');
    $old['estimate'] = trim($_POST['estimate'] ?? '');

    // Assignee tambahan (opsional): pembuat otomatis masuk sebagai owner + assignee #1
    $postedAssignees = $_POST['assignee_ids'] ?? [];
    if (!is_array($postedAssignees)) $postedAssignees = [$postedAssignees];
    $postedAssignees = array_values(array_unique(array_filter(array_map('intval', $postedAssignees), fn($x) => $x > 0 && $x !== (int)$user['id'])));

    $err = null;
    if ($old['title'] === '' || $old['description'] === '' || $old['priority'] === '') {
        $err = 'Judul, deskripsi, dan prioritas wajib diisi!';
    } elseif (mb_strlen($old['title']) > 255) {
        $err = 'Judul maksimal 255 karakter.';
    } elseif (!in_array($old['priority'], $priorities, true)) {
        $err = 'Prioritas tidak valid.';
    }

    // Owner otomatis = pembuat (yang login). Halaman ini khusus staf aktif,
    // jadi pembuat selalu valid sebagai owner.
    $ownerId = (int)$user['id'];

    // Relasi opsional: maksimal salah satu (tiket ATAU CR), harus exist
    $ticketId = null;
    $crId = null;
    if (!$err && $old['ticket_id'] !== '' && $old['cr_id'] !== '') {
        $err = 'Pilih salah satu relasi: tiket ATAU CR (tidak bisa keduanya).';
    }
    if (!$err && $old['ticket_id'] !== '') {
        $chk = pg_query_params($conn, "SELECT id FROM tickets WHERE id = $1", [(int)$old['ticket_id']]);
        if (!$chk || pg_num_rows($chk) === 0) $err = 'Tiket relasi tidak ditemukan.';
        else $ticketId = (int)$old['ticket_id'];
        if ($chk) pg_free_result($chk);
    }
    if (!$err && $old['cr_id'] !== '') {
        $chk = pg_query_params($conn, "SELECT id FROM change_requests WHERE id = $1", [(int)$old['cr_id']]);
        if (!$chk || pg_num_rows($chk) === 0) $err = 'CR relasi tidak ditemukan.';
        else $crId = (int)$old['cr_id'];
        if ($chk) pg_free_result($chk);
    }

    // Due date opsional: minimal hari ini
    $dueDate = null;
    if (!$err && $old['due_date'] !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['due_date'])) {
            $err = 'Format due date tidak valid.';
        } elseif ($old['due_date'] < date('Y-m-d')) {
            $err = 'Due date minimal hari ini.';
        } else {
            $dueDate = $old['due_date'];
        }
    }

    // Estimasi jam opsional: 1-1000
    $estimate = null;
    if (!$err && $old['estimate'] !== '') {
        if (!ctype_digit($old['estimate']) || (int)$old['estimate'] < 1 || (int)$old['estimate'] > 1000) {
            $err = 'Estimasi jam harus angka 1-1000.';
        } else {
            $estimate = (int)$old['estimate'];
        }
    }

    // Validasi assignee tambahan: staf aktif, total (termasuk pembuat) maks 10
    $allAssignees = array_merge([(int)$user['id']], $postedAssignees);
    if (!$err && count($allAssignees) > 10) {
        $err = 'Maksimal 10 assignee per todo.';
    }
    if (!$err && !empty($postedAssignees)) {
        $place = [];
        foreach ($postedAssignees as $i => $uid) $place[] = '$' . ($i + 1);
        $achk = pg_query_params($conn, "SELECT id FROM users WHERE id IN (" . implode(',', $place) . ") AND is_active = TRUE AND role IN ('admin','teknisi')", $postedAssignees);
        if (!$achk) {
            $err = 'Gagal validasi assignee.';
        } else {
            $av = [];
            while ($arow = pg_fetch_assoc($achk)) $av[] = (int)$arow['id'];
            pg_free_result($achk);
            sort($av);
            $pw = $postedAssignees;
            sort($pw);
            if ($av !== $pw) $err = 'Assignee tidak valid. Hanya staf aktif (admin/teknisi).';
        }
    }

    if ($err) {
        $message = $err;
        $messageType = 'danger';
    } else {
        $description = sanitizeRichText($old['description']);
        if ($description === '') $description = htmlspecialchars($old['description']);
        if (trim(strip_tags($description)) === '' && trim($old['description']) !== '') {
            $description = htmlspecialchars($old['description']);
        }

        pg_query($conn, 'BEGIN');
        pg_query($conn, 'LOCK TABLE dev_tasks IN SHARE ROW EXCLUSIVE MODE');
        $taskCode = generateTaskCode($conn);

        $ins = pg_query_params(
            $conn,
            "INSERT INTO dev_tasks (task_code, title, description, phase, priority, owner_id, created_by, ticket_id, cr_id, due_date, estimate_hours) VALUES ($1,$2,$3,'backlog',$4,$5,$6,$7,$8,$9,$10) RETURNING id",
            [$taskCode, $old['title'], $description, $old['priority'], $ownerId, $user['id'], $ticketId, $crId, $dueDate, $estimate]
        );

        if ($ins && pg_num_rows($ins) > 0) {
            $taskId = (int)pg_fetch_result($ins, 0, 0);
            pg_free_result($ins);
            $hi = pg_query_params(
                $conn,
                "INSERT INTO dev_task_history (task_id, actor_id, from_phase, to_phase, note) VALUES ($1,$2,NULL,'backlog',$3)",
                [$taskId, $user['id'], 'Todo dibuat.']
            );
            if ($hi) {
                [$asOk, $asErr] = setTaskAssignees($conn, $taskId, $allAssignees, $user['id']);
                $savedPaths = [];
                if ($asOk) {
                    [$upOk, $upErr] = saveTaskUploads($conn, $taskId, $user['id'], $savedPaths, 'attachments');
                    if (!$upOk) {
                        $asOk = false;
                        $asErr = $upErr;
                    }
                }
                if ($asOk) {
                    pg_query($conn, 'COMMIT');
                    $createdId = $taskId;
                    $createdCode = $taskCode;
                    foreach ($postedAssignees as $aid) {
                        notifyUser($conn, $aid, 'Todo baru ' . $taskCode . ' menugaskan Anda', $user['name'] . ': ' . $old['title'], 'view_task.php?id=' . $createdId);
                    }
                    $message = 'Todo ' . $taskCode . ' berhasil dibuat! Kamu otomatis jadi owner-nya.'
                        . (!empty($postedAssignees) ? ' (' . count($postedAssignees) . ' assignee lain ditugaskan)' : '')
                        . (!empty($savedPaths) ? ' (' . count($savedPaths) . ' lampiran tersimpan)' : '');
                    $messageType = 'success';
                    $old = ['title' => '', 'description' => '', 'priority' => 'medium', 'ticket_id' => '', 'cr_id' => '', 'due_date' => '', 'estimate' => ''];
                } else {
                    pg_query($conn, 'ROLLBACK');
                    foreach ($savedPaths as $sp) {
                        if (file_exists(__DIR__ . '/' . $sp)) @unlink(__DIR__ . '/' . $sp);
                    }
                    $message = $asErr ?: 'Gagal menyimpan assignee/lampiran.';
                    $messageType = 'danger';
                }
            } else {
                pg_query($conn, 'ROLLBACK');
                $message = 'Gagal menyimpan riwayat todo.';
                $messageType = 'danger';
            }
        } else {
            pg_query($conn, 'ROLLBACK');
            $message = 'Gagal membuat todo: ' . pg_last_error($conn);
            $messageType = 'danger';
        }
    }
}
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Buat Todo SDLC</h2>
        <p class="text-secondary mb-0">Login sebagai <strong><?php echo htmlspecialchars($user['name']); ?></strong> (<?php echo htmlspecialchars($user['role']); ?>). Todo baru masuk fase <span class="badge text-bg-secondary">backlog</span>.</p>
    </div>
    <a href="tasks.php" class="btn btn-outline-secondary btn-sm flex-shrink-0">← Kembali ke Board</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
        <?php if ($messageType === 'success' && $createdId): ?>
            <a href="view_task.php?id=<?php echo $createdId; ?>" class="alert-link ms-2">Lihat todo <?php echo htmlspecialchars($createdCode ?? ''); ?> →</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="taskForm" novalidate>
    <?php echo csrf_field(); ?>
    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
                <div class="mb-3">
                    <label for="title" class="form-label">Judul</label>
                    <input type="text" class="form-control" id="title" name="title" required maxlength="255" placeholder="cth: Implementasi export PDF laporan kunjungan" value="<?php echo htmlspecialchars($old['title']); ?>">
                </div>
                <div class="mb-0">
                    <label for="description" class="form-label">Deskripsi</label>
                    <textarea id="description" class="form-control" name="description" rows="8" placeholder="Lingkup pengerjaan, acceptance criteria, catatan teknis…"><?php echo htmlspecialchars($old['description']); ?></textarea>
                    <div class="form-text">Bisa format bold, list, heading, dan link.</div>
                </div>
            </div></div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3"><div class="card-body">
                <h3 class="h6 mb-3">Pengaturan Todo</h3>
                <div class="mb-3">
                    <label class="form-label">Owner (Assigned)</label>
                    <div class="alert alert-info py-2 mb-2 small">👤 <strong><?php echo htmlspecialchars($user['name']); ?></strong> — otomatis kamu sebagai pembuat.</div>
                    <?php $staffOthers = array_values(array_filter($staffOptions, fn($s) => (int)$s['id'] !== (int)$user['id'])); ?>
                    <?php if (!empty($staffOthers)): ?>
                    <label class="form-label small">Tugaskan juga ke <span class="text-secondary fw-normal">(opsional, maks 9 tambahan)</span></label>
                    <div class="border rounded-3 p-2" style="max-height:180px;overflow-y:auto;">
                        <?php foreach ($staffOthers as $so): ?>
                        <label class="form-check mb-1 small">
                            <input type="checkbox" class="form-check-input" name="assignee_ids[]" value="<?php echo (int)$so['id']; ?>" <?php echo in_array((int)$so['id'], $postedAssignees, true) ? 'checked' : ''; ?>>
                            <span class="form-check-label"><?php echo htmlspecialchars($so['name']); ?> <span class="text-secondary">(<?php echo htmlspecialchars($so['role']); ?>)</span></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label for="attachments" class="form-label">Lampiran <span class="text-secondary fw-normal">(opsional · maks 10 file, total 20MB)</span></label>
                    <input type="file" class="form-control" id="attachments" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                    <div class="form-text" id="attHelp">JPG, PNG, PDF, DOCX, XLSX, TXT, ZIP · total maksimal 20MB.</div>
                </div>
                <div class="mb-3">
                    <label for="priority" class="form-label">Prioritas</label>
                    <select id="priority" name="priority" class="form-select" required>
                        <?php foreach ($priorities as $pr): ?>
                            <option value="<?php echo $pr; ?>" <?php echo $old['priority'] === $pr ? 'selected' : ''; ?>><?php echo ucfirst($pr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label for="due_date" class="form-label">Due Date <span class="text-secondary fw-normal">(opsional)</span></label>
                        <input type="date" class="form-control" id="due_date" name="due_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($old['due_date']); ?>">
                    </div>
                    <div class="col-6">
                        <label for="estimate" class="form-label">Estimasi (jam)</label>
                        <input type="number" class="form-control" id="estimate" name="estimate" min="1" max="1000" placeholder="cth: 8" value="<?php echo htmlspecialchars($old['estimate']); ?>">
                    </div>
                </div>
            </div></div>

            <div class="card mb-3"><div class="card-body">
                <h3 class="h6 mb-3">Relasi <span class="text-secondary fw-normal">(opsional, salah satu)</span></h3>
                <div class="mb-3">
                    <label for="ticket_id" class="form-label">Tiket terkait</label>
                    <select id="ticket_id" name="ticket_id" class="form-select">
                        <option value="">— Tanpa tiket —</option>
                        <?php foreach ($ticketOptions as $to): ?>
                            <option value="<?php echo $to['id']; ?>" <?php echo (string)$old['ticket_id'] === (string)$to['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($to['ticket_number']); ?> — <?php echo htmlspecialchars(mb_strimwidth($to['title'], 0, 40, '…')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label for="cr_id" class="form-label">CR terkait</label>
                    <select id="cr_id" name="cr_id" class="form-select">
                        <option value="">— Tanpa CR —</option>
                        <?php foreach ($crOptions as $co): ?>
                            <option value="<?php echo $co['id']; ?>" <?php echo (string)$old['cr_id'] === (string)$co['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($co['cr_number']); ?> — <?php echo htmlspecialchars($co['aplikasi']); ?> / <?php echo htmlspecialchars(mb_strimwidth($co['fitur'], 0, 30, '…')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div></div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Buat Todo</button>
                <a href="tasks.php" class="btn btn-danger">Batal</a>
            </div>
        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@39.0.2/build/ckeditor.js"></script>
<script>
(function () {
    var desc = document.getElementById('description');
    var form = document.getElementById('taskForm');
    var editorInstance = null;
    if (window.ClassicEditor && desc) {
        ClassicEditor.create(desc, {
            toolbar: ['heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'code', '|', 'undo', 'redo']
        }).then(function (editor) { editorInstance = editor; }).catch(function () {});
    }
    if (form) {
        form.addEventListener('submit', function (e) {
            var text = editorInstance
                ? editorInstance.getData().replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').trim()
                : desc.value.trim();
            if (!text || !document.getElementById('title').value.trim()) {
                e.preventDefault();
                alert('Judul dan deskripsi wajib diisi.');
                return;
            }
            var tk = document.getElementById('ticket_id');
            var cr = document.getElementById('cr_id');
            if (tk && cr && tk.value !== '' && cr.value !== '') {
                e.preventDefault();
                alert('Pilih salah satu relasi: tiket ATAU CR.');
                return;
            }
            var att = document.getElementById('attachments');
            if (att && att.files.length > 0) {
                if (att.files.length > 10) {
                    e.preventDefault();
                    alert('Maksimal 10 file lampiran.');
                    return;
                }
                var total = 0;
                for (var i = 0; i < att.files.length; i++) total += att.files[i].size;
                if (total > 20 * 1024 * 1024) {
                    e.preventDefault();
                    alert('Total lampiran maksimal 20MB (terpilih ' + (total / 1048576).toFixed(1) + 'MB).');
                    return;
                }
            }
        });
    }
})();
</script>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
