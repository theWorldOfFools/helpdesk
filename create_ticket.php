<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$pageTitle = 'Buat Tiket Baru';

require_once 'includes/header.php';

$conn = getDBConnection();
$canPickUser = in_array($user['role'], ['admin', 'teknisi']);

// Daftar pengguna aktif untuk dropdown + tombol sisip @user
$userOptions = [];
if ($canPickUser) {
    $ur = pg_query($conn, "SELECT id, name, username, role FROM users WHERE is_active = TRUE ORDER BY name ASC");
    while ($row = pg_fetch_assoc($ur)) $userOptions[] = $row;
    pg_free_result($ur);
}

$message = '';
$messageType = '';
$createdId = null;
$old = ['title' => '', 'description' => '', 'category' => '', 'priority' => '', 'division' => '', 'report_for' => $user['id']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $old['title'] = trim($_POST['title'] ?? '');
    $old['description'] = trim($_POST['description'] ?? '');
    $old['category'] = trim($_POST['category'] ?? '');
    $old['priority'] = trim($_POST['priority'] ?? '');
    $old['division'] = trim($_POST['division'] ?? '');
    $old['report_for'] = (int)($_POST['report_for'] ?? $user['id']);

    $reportFor = $user['id'];
    if ($canPickUser && $old['report_for'] > 0) {
        $chk = pg_query_params($conn, "SELECT id FROM users WHERE id = $1 AND is_active = TRUE", [$old['report_for']]);
        if ($chk && pg_num_rows($chk) > 0) $reportFor = $old['report_for'];
        if ($chk) pg_free_result($chk);
    }

    if ($old['title'] === '' || $old['description'] === '' || $old['category'] === '' || $old['priority'] === '' || $old['division'] === '') {
        $message = 'Judul, deskripsi, kategori, prioritas, dan divisi wajib diisi!';
        $messageType = 'danger';
    } else {
        [$upPath, $upOriginal] = handleTicketUpload('attachment', $upError);
        if ($upPath === false) {
            $message = $upError ?: 'Upload lampiran gagal.';
            $messageType = 'danger';
        } else {
            $description = sanitizeRichText($old['description']);
            if ($description === '') $description = htmlspecialchars($old['description']);

            pg_query($conn, 'BEGIN');
            pg_query($conn, 'LOCK TABLE tickets IN SHARE ROW EXCLUSIVE MODE');
            $nr = pg_query($conn, "SELECT TO_CHAR(NOW(), 'YYYYMM') || '-' || LPAD(COALESCE(MAX(CAST(SUBSTRING(ticket_number FROM '[0-9]+$') AS INTEGER)) + 1, 1)::TEXT, 4, '0') FROM tickets WHERE ticket_number LIKE TO_CHAR(NOW(), 'YYYYMM') || '-%'");
            $ticketNumber = $nr ? pg_fetch_result($nr, 0, 0) : null;
            if (!$ticketNumber) $ticketNumber = date('Ym') . '-0001';
            if ($nr) pg_free_result($nr);
            $slaDue = slaDueForPriority($old['priority']);

            $ins = pg_query_params(
                $conn,
                "INSERT INTO tickets (ticket_number, title, description, category, priority, status, division, user_id, created_by, attachment_path, attachment_original, sla_due_at) VALUES ($1,$2,$3,$4,$5,'open',$6,$7,$8,$9,$10,$11) RETURNING id",
                [$ticketNumber, $old['title'], $description, $old['category'], $old['priority'], $old['division'], $reportFor, $user['id'], $upPath, $upOriginal, $slaDue]
            );

            if ($ins && pg_num_rows($ins) > 0) {
                pg_query($conn, 'COMMIT');
                $createdId = (int)pg_fetch_result($ins, 0, 0);
                pg_free_result($ins);
                // Notifikasi staf (admin/teknisi aktif, kecuali pembuat)
                $sr = pg_query($conn, "SELECT id FROM users WHERE role IN ('admin','teknisi') AND is_active = TRUE AND id <> " . (int)$user['id']);
                if ($sr) {
                    while ($srow = pg_fetch_assoc($sr)) {
                        notifyUser($conn, $srow['id'], 'Tiket baru ' . $ticketNumber . ' (' . $old['priority'] . ')', $old['title'], 'view_ticket.php?id=' . $createdId);
                    }
                    pg_free_result($sr);
                }
                $message = 'Tiket ' . $ticketNumber . ' berhasil dibuat!' . ($upPath ? ' Lampiran ikut tersimpan.' : '');
                $messageType = 'success';
                $old = ['title' => '', 'description' => '', 'category' => '', 'priority' => '', 'division' => '', 'report_for' => $user['id']];
            } else {
                pg_query($conn, 'ROLLBACK');
                if ($upPath && file_exists(__DIR__ . '/' . $upPath)) @unlink(__DIR__ . '/' . $upPath);
                $message = 'Gagal membuat tiket: ' . pg_last_error($conn);
                $messageType = 'danger';
            }
        }
    }
}

// Daftar divisi dari master (divisi.php); fallback bawaan bila tabel belum ada
$divisions = getActiveDivisions($conn);
$categories = ['Hardware', 'Software', 'Jaringan', 'Lainnya'];
$priorities = ['low', 'medium', 'high', 'critical'];
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Buat Tiket Baru</h2>
        <p class="text-secondary mb-0">Login sebagai <strong><?php echo htmlspecialchars($user['name']); ?></strong> (<?php echo htmlspecialchars($user['role']); ?>). Deskripsi mendukung format kaya, sebut pengguna, dan lampiran.</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary btn-sm flex-shrink-0">← Kembali ke Daftar</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
        <?php if ($messageType === 'success' && $createdId): ?>
            <a href="view_ticket.php?id=<?php echo $createdId; ?>" class="alert-link ms-2">Lihat tiket →</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="ticketForm">
    <?php echo csrf_field(); ?>
    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
            <div class="mb-3">
                <label for="title" class="form-label">Judul Tiket</label>
                <input type="text" class="form-control" id="title" name="title" required maxlength="255" placeholder="cth: Komputer tidak bisa booting" value="<?php echo htmlspecialchars($old['title']); ?>">
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                    <label for="description" class="form-label mb-0">Deskripsi Lengkap</label>
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
                <textarea id="description" class="form-control" name="description" rows="10" placeholder="Jelaskan masalah secara detail: kronologi, pesan error, langkah yang sudah dicoba…"><?php echo htmlspecialchars($old['description']); ?></textarea>
                <div class="form-text">Bisa format bold, list, heading, dan link. Ketik rapi supaya teknisi cepat paham.</div>
            </div>

            <div class="mb-0">
                <label for="attachment" class="form-label">Lampiran <span class="text-secondary fw-normal">(opsional · maks 5MB)</span></label>
                <label class="dropzone" id="dropzone">
                    <input type="file" id="attachment" name="attachment" hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                    <span class="dz-icon" aria-hidden="true"><i class="bi bi-cloud-arrow-up fs-4"></i></span>
                    <span class="dz-text d-flex flex-column"><strong id="dzLabel">Klik atau seret file ke sini</strong><small class="text-secondary">JPG, PNG, PDF, DOCX, XLSX, TXT, ZIP · maks 5MB</small></span>
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
                        <option value="<?php echo $user['id']; ?>">Saya sendiri (<?php echo htmlspecialchars($user['name']); ?>)</option>
                        <?php foreach ($userOptions as $uo): ?>
                            <option value="<?php echo $uo['id']; ?>" <?php echo (int)$old['report_for'] === (int)$uo['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($uo['name']); ?> · @<?php echo htmlspecialchars($uo['username']); ?> (<?php echo htmlspecialchars($uo['role']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Admin/teknisi bisa buatkan tiket atas nama pengguna lain.</div>
                </div>
                <?php else: ?>
                    <input type="hidden" name="report_for" value="<?php echo $user['id']; ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label for="category" class="form-label">Kategori</label>
                    <select id="category" name="category" class="form-select" required>
                        <option value="">Pilih kategori</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo $old['category'] === $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="priority" class="form-label">Prioritas</label>
                    <select id="priority" name="priority" class="form-select" required>
                        <option value="">Pilih prioritas</option>
                        <?php foreach ($priorities as $pr): ?>
                            <option value="<?php echo $pr; ?>" <?php echo $old['priority'] === $pr ? 'selected' : ''; ?>><?php echo ucfirst($pr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label for="division" class="form-label">Divisi Tujuan</label>
                    <select id="division" name="division" class="form-select" required>
                        <option value="">Pilih divisi</option>
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?php echo $div; ?>" <?php echo $old['division'] === $div ? 'selected' : ''; ?>><?php echo $div; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div></div>

            <div class="card mb-3"><div class="card-body">
                <h3 class="h6">Tips laporan bagus</h3>
                <ul class="mb-0 ps-3 small text-secondary">
                    <li>Tulis kronologi + pesan error persis.</li>
                    <li>Sebut perangkat / aplikasi yang dipakai.</li>
                    <li>Lampirkan screenshot atau file pendukung.</li>
                </ul>
            </div></div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Buat Tiket</button>
                <a href="index.php" class="btn btn-danger">Batal</a>
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
    function notifyResult(type, msg, link) {
        if (!window.Notiflix) return;
        if (type === 'success') {
            Notiflix.Notify.success(msg);
            if (link) setTimeout(function () { window.location.href = link; }, 1600);
        } else if (type === 'danger' || type === 'failure') {
            Notiflix.Notify.failure(msg);
        }
    }
    <?php if ($message): ?>
    document.addEventListener('DOMContentLoaded', function () {
        notifyResult(<?php echo json_encode($messageType); ?>, <?php echo json_encode(strip_tags($message)); ?>, <?php echo ($messageType === 'success' && $createdId) ? json_encode('view_ticket.php?id=' . $createdId) : 'null'; ?>);
    });
    <?php endif; ?>

    var desc = document.getElementById('description');
    var form = document.getElementById('ticketForm');
    var editorInstance = null;

    function insertAtCursor(text) {
        if (editorInstance) {
            var model = editorInstance.model;
            model.change(function (writer) {
                var insertPos = model.document.selection.getFirstPosition();
                writer.insertText(text + ' ', insertPos);
            });
            editorInstance.editing.view.focus();
        } else if (desc) {
            var s = desc.selectionStart || desc.value.length;
            var e = desc.selectionEnd || s;
            desc.value = desc.value.slice(0, s) + text + ' ' + desc.value.slice(e);
            desc.focus();
        }
    }

    var mentionBtn = document.getElementById('mentionBtn');
    var mentionSel = document.getElementById('mentionUser');
    if (mentionBtn && mentionSel) {
        mentionBtn.addEventListener('click', function () {
            if (mentionSel.value) insertAtCursor(mentionSel.value);
        });
    }

    if (window.ClassicEditor && desc) {
        ClassicEditor.create(desc, {
            toolbar: ['heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'code', '|', 'undo', 'redo']
        }).then(function (editor) {
            editorInstance = editor;
        }).catch(function () { /* fallback ke textarea biasa */ });
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
                if (window.Notiflix) Notiflix.Notify.warning('Deskripsi wajib diisi — jelaskan masalahnya dulu ya.');
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
            if (window.Notiflix) Notiflix.Loading.pulse('Membuat tiket…');
        });
    }

    var dz = document.getElementById('dropzone');
    var input = document.getElementById('attachment');
    var label = document.getElementById('dzLabel');
    function showFile(f) { if (f && label) label.textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB)'; }
    if (input) input.addEventListener('change', function () { if (input.files[0]) showFile(input.files[0]); });
    if (dz) {
        ['dragover', 'dragenter'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('drag'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('drag'); }); });
        dz.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files[0] && input) {
                input.files = e.dataTransfer.files;
                showFile(input.files[0]);
            }
        });
    }
})();
</script>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
