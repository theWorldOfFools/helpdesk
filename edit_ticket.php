<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$pageTitle = 'Edit Tiket';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

require_once 'includes/header.php';

$conn = getDBConnection();

$result = pg_query_params($conn, "SELECT * FROM tickets WHERE id = $1", [$id]);
if (!$result || pg_num_rows($result) === 0) {
    pg_close($conn);
    header('Location: index.php');
    exit;
}
$ticket = pg_fetch_assoc($result);
pg_free_result($result);

if (!canEditTicket($ticket, $user)) {
    pg_close($conn);
    ?>
    <div class="alert alert-danger text-center py-5">
        <h2 class="h5 text-danger">Akses Ditolak</h2>
        <p>Anda tidak memiliki izin untuk mengedit tiket ini.</p>
        <a href="view_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn btn-primary">Kembali</a>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

$canPickUser = ($user['role'] === 'admin');
$userOptions = [];
if ($canPickUser) {
    $ur = pg_query($conn, "SELECT id, name, username, role FROM users WHERE is_active = TRUE ORDER BY name ASC");
    while ($row = pg_fetch_assoc($ur)) $userOptions[] = $row;
    pg_free_result($ur);
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $title = trim($_POST['title'] ?? '');
    $descriptionRaw = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $priority = trim($_POST['priority'] ?? '');
    $division = trim($_POST['division'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $reportFor = (int)($ticket['user_id'] ?? 0);

    if ($canPickUser && isset($_POST['report_for'])) {
        $cand = (int)$_POST['report_for'];
        $chk = pg_query_params($conn, "SELECT id FROM users WHERE id = $1 AND is_active = TRUE", [$cand]);
        if ($chk && pg_num_rows($chk) > 0) $reportFor = $cand;
        if ($chk) pg_free_result($chk);
    }

    $validStatus = ['open', 'in_progress', 'resolved', 'closed'];
    if ($title === '' || $descriptionRaw === '' || $category === '' || $priority === '' || $division === '' || !in_array($status, $validStatus, true)) {
        $message = 'Semua field wajib diisi dengan benar!';
        $messageType = 'danger';
    } else {
        $newPath = $ticket['attachment_path'];
        $newOriginal = $ticket['attachment_original'];
        $removeFile = isset($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1';

        if ($removeFile && $newPath) {
            if (file_exists(__DIR__ . '/' . $newPath)) @unlink(__DIR__ . '/' . $newPath);
            $newPath = null;
            $newOriginal = null;
        }

        [$upPath, $upOriginal] = handleTicketUpload('attachment', $upError);
        if ($upPath === false) {
            $message = $upError ?: 'Upload lampiran gagal.';
            $messageType = 'danger';
        } else {
            if ($upPath) {
                if (!empty($ticket['attachment_path']) && file_exists(__DIR__ . '/' . $ticket['attachment_path'])) {
                    @unlink(__DIR__ . '/' . $ticket['attachment_path']);
                }
                $newPath = $upPath;
                $newOriginal = $upOriginal;
            }

            $description = sanitizeRichText($descriptionRaw);
            if ($description === '') $description = htmlspecialchars($descriptionRaw);

            $upd = pg_query_params(
                $conn,
                "UPDATE tickets SET title=$1, description=$2, category=$3, priority=$4, division=$5, status=$6, user_id=$7, attachment_path=$8, attachment_original=$9, updated_at=NOW() WHERE id=$10",
                [$title, $description, $category, $priority, $division, $status, $reportFor, $newPath, $newOriginal, $id]
            );

            if ($upd) {
                $message = 'Tiket berhasil diperbarui!';
                $messageType = 'success';
                $r2 = pg_query_params($conn, "SELECT * FROM tickets WHERE id = $1", [$id]);
                $ticket = pg_fetch_assoc($r2);
                pg_free_result($r2);
            } else {
                if ($upPath && file_exists(__DIR__ . '/' . $upPath)) @unlink(__DIR__ . '/' . $upPath);
                $message = 'Gagal memperbarui tiket: ' . pg_last_error($conn);
                $messageType = 'danger';
            }
        }
    }
}

$divisions = ['IT Infrastructure', 'IT Development', 'IT Support', 'IT Security', 'Network', 'System Administration'];
$categories = ['Hardware', 'Software', 'Jaringan', 'Lainnya'];
$priorities = ['low', 'medium', 'high', 'critical'];
$statuses = ['open', 'in_progress', 'resolved', 'closed'];
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Edit Tiket <?php echo htmlspecialchars($ticket['ticket_number']); ?></h2>
        <p class="text-secondary mb-0">Perbarui deskripsi rich-text, lampiran, status, dan data tiket lainnya.</p>
    </div>
    <a href="view_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn btn-outline-secondary btn-sm flex-shrink-0">← Kembali ke Detail</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="ticketForm">
    <?php echo csrf_field(); ?>
    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
            <div class="mb-3">
                <label for="title" class="form-label">Judul</label>
                <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($ticket['title']); ?>" required maxlength="255">
            </div>
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                    <label for="description" class="form-label mb-0">Deskripsi</label>
                    <?php if ($canPickUser && !empty($userOptions)): ?>
                    <span class="d-flex gap-2 align-items-center">
                        <select id="mentionUser" class="form-select form-select-sm" style="max-width:230px;" aria-label="Sisipkan pengguna ke deskripsi">
                            <option value="">Sisip @pengguna…</option>
                            <?php foreach ($userOptions as $uo): ?>
                                <option value="@<?php echo htmlspecialchars($uo['username']); ?>">@<?php echo htmlspecialchars($uo['username']); ?> — <?php echo htmlspecialchars($uo['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="mentionBtn">Sisipkan</button>
                    </span>
                    <?php endif; ?>
                </div>
                <textarea id="description" class="form-control" name="description" rows="10"><?php echo htmlspecialchars($ticket['description']); ?></textarea>
            </div>
            <div class="mb-0">
                <label for="attachment" class="form-label">Lampiran <span class="text-secondary fw-normal">(kosongkan bila tidak diganti · maks 5MB)</span></label>
                <?php if (!empty($ticket['attachment_path'])): ?>
                    <div class="d-flex justify-content-between align-items-center gap-2 bg-body-tertiary border rounded-3 p-2 px-3 mb-2 small">
                        <span>📎 <?php echo htmlspecialchars($ticket['attachment_original'] ?: basename($ticket['attachment_path'])); ?></span>
                        <label class="form-check mb-0 text-danger text-nowrap"><input type="checkbox" class="form-check-input" name="remove_attachment" value="1"> Hapus lampiran ini</label>
                    </div>
                <?php endif; ?>
                <label class="dropzone" id="dropzone">
                    <input type="file" id="attachment" name="attachment" hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                    <span class="dz-icon" aria-hidden="true"><i class="bi bi-cloud-arrow-up fs-4"></i></span>
                    <span class="dz-text d-flex flex-column"><strong id="dzLabel">Klik atau seret file baru ke sini</strong><small class="text-secondary">JPG, PNG, PDF, DOCX, XLSX, TXT, ZIP · maks 5MB</small></span>
                </label>
            </div>
            </div></div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3"><div class="card-body">
                <?php if ($canPickUser): ?>
                <div class="mb-3">
                    <label for="report_for" class="form-label">Dilaporkan Untuk (Pengguna)</label>
                    <select id="report_for" name="report_for" class="form-select">
                        <?php foreach ($userOptions as $uo): ?>
                            <option value="<?php echo $uo['id']; ?>" <?php echo (int)$ticket['user_id'] === (int)$uo['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($uo['name']); ?> · @<?php echo htmlspecialchars($uo['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label for="category" class="form-label">Kategori</label>
                    <select id="category" name="category" class="form-select" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo $ticket['category'] === $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="priority" class="form-label">Prioritas</label>
                    <select id="priority" name="priority" class="form-select" required>
                        <?php foreach ($priorities as $pr): ?>
                            <option value="<?php echo $pr; ?>" <?php echo $ticket['priority'] === $pr ? 'selected' : ''; ?>><?php echo ucfirst($pr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="division" class="form-label">Divisi</label>
                    <select id="division" name="division" class="form-select" required>
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?php echo $div; ?>" <?php echo $ticket['division'] === $div ? 'selected' : ''; ?>><?php echo $div; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select" required>
                        <?php foreach ($statuses as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo $ticket['status'] === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                <a href="view_ticket.php?id=<?php echo $ticket['id']; ?>" class="btn btn-danger">Batal</a>
            </div>
        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@39.0.2/build/ckeditor.js"></script>
<script src="https://cdn.jsdelivr.net/npm/notiflix@3.2.7/dist/notiflix-aio-3.2.7.min.js"></script>
<script>
(function () {
    if (window.Notiflix) {
        Notiflix.Notify.init({ position: 'right-top', timeout: 4200, showOnlyTheLastOne: true });
        Notiflix.Loading.init({ svgColor: '#e94560' });
    }
    <?php if ($message): ?>
    document.addEventListener('DOMContentLoaded', function () {
        if (!window.Notiflix) return;
        <?php if ($messageType === 'success'): ?>
        Notiflix.Notify.success(<?php echo json_encode(strip_tags($message)); ?>);
        <?php else: ?>
        Notiflix.Notify.failure(<?php echo json_encode(strip_tags($message)); ?>);
        <?php endif; ?>
    });
    <?php endif; ?>

    var desc = document.getElementById('description');
    var form = document.getElementById('ticketForm');
    var editorInstance = null;
    var mentionBtn = document.getElementById('mentionBtn');
    var mentionSel = document.getElementById('mentionUser');
    function insertAtCursor(text) {
        if (editorInstance) {
            var model = editorInstance.model;
            model.change(function (writer) { writer.insertText(text + ' ', model.document.selection.getFirstPosition()); });
            editorInstance.editing.view.focus();
        } else if (desc) {
            var s = desc.selectionStart || desc.value.length, e = desc.selectionEnd || s;
            desc.value = desc.value.slice(0, s) + text + ' ' + desc.value.slice(e);
            desc.focus();
        }
    }
    if (mentionBtn && mentionSel) mentionBtn.addEventListener('click', function () { if (mentionSel.value) insertAtCursor(mentionSel.value); });
    if (window.ClassicEditor && desc) {
        ClassicEditor.create(desc, { toolbar: ['heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'code', '|', 'undo', 'redo'] })
            .then(function (editor) { editorInstance = editor; }).catch(function () {});
    }
    if (form) {
        form.addEventListener('submit', function (e) {
            var text = '';
            if (editorInstance) {
                text = editorInstance.getData().replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').trim();
            } else if (desc) {
                text = desc.value.trim();
            }
            if (!text) {
                e.preventDefault();
                if (window.Notiflix) Notiflix.Notify.warning('Deskripsi wajib diisi.');
                if (editorInstance) editorInstance.editing.view.focus();
                else if (desc) desc.focus();
                return;
            }
            var f = document.getElementById('attachment');
            if (f && f.files[0] && f.files[0].size > 5 * 1024 * 1024) {
                e.preventDefault();
                if (window.Notiflix) Notiflix.Notify.failure('Ukuran lampiran maksimal 5MB.');
                return;
            }
            if (window.Notiflix) Notiflix.Loading.pulse('Menyimpan…');
        });
    }
    var dz = document.getElementById('dropzone'), input = document.getElementById('attachment'), label = document.getElementById('dzLabel');
    if (input) input.addEventListener('change', function () { if (input.files[0] && label) label.textContent = input.files[0].name; });
    if (dz) {
        ['dragover', 'dragenter'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('drag'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('drag'); }); });
        dz.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files[0] && input) { input.files = e.dataTransfer.files; if (label) label.textContent = input.files[0].name; } });
    }
})();
</script>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
