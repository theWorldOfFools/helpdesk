<?php
require_once 'config.php';
require_once 'includes/cr_auth.php';
requireLogin();

$user = getCurrentUser();
$pageTitle = 'Edit Change Request';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: cr_list.php');
    exit;
}

require_once 'includes/header.php';

$conn = getDBConnection();

$result = pg_query_params($conn, "SELECT * FROM change_requests WHERE id = $1", [$id]);
if (!$result || pg_num_rows($result) === 0) {
    if ($result) pg_free_result($result);
    pg_close($conn);
    header('Location: cr_list.php');
    exit;
}
$cr = pg_fetch_assoc($result);
pg_free_result($result);

if (!canEditCr($cr, $user)) {
    http_response_code(403);
    pg_close($conn);
    ?>
    <div class="alert alert-danger text-center py-5">
        <h2 class="h5 text-danger">Akses Ditolak (403)</h2>
        <p>Hanya admin yang boleh mengedit Change Request.</p>
        <a href="view_cr.php?id=<?php echo (int)$id; ?>" class="btn btn-primary">Kembali</a>
    </div>
    <?php
    require_once 'includes/footer.php';
    exit;
}

// Preload baris rincian existing untuk tabel dinamis
$existingItems = [];
$ir = pg_query_params($conn, "SELECT jenis, uraian, alasan FROM change_request_items WHERE cr_id = $1 ORDER BY id ASC", [$id]);
if ($ir) {
    while ($row = pg_fetch_assoc($ir)) $existingItems[] = $row;
    pg_free_result($ir);
}
if (empty($existingItems)) $existingItems = [['jenis' => '', 'uraian' => '', 'alasan' => '']];

$userOptions = [];
$ur = pg_query($conn, "SELECT id, name, username, role FROM users WHERE is_active = TRUE ORDER BY name ASC");
if ($ur) {
    while ($row = pg_fetch_assoc($ur)) $userOptions[] = $row;
    pg_free_result($ur);
}

$message = '';
$messageType = '';

$divisions = ['IT Infrastructure', 'IT Development', 'IT Support', 'IT Security', 'Network', 'System Administration'];
$priorities = ['low', 'medium', 'high', 'critical'];
$statuses = ['open', 'in_progress', 'resolved', 'closed'];
$allowedJenis = ['Penambahan', 'Perubahan', 'Design'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $aplikasi = trim($_POST['aplikasi'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $modul = trim($_POST['modul'] ?? '');
    $fitur = trim($_POST['fitur'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $waktuRaw = trim($_POST['waktu'] ?? '');
    $keteranganRaw = trim($_POST['keterangan'] ?? '');
    $priority = trim($_POST['priority'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $reportFor = (int)$cr['user_id'];
    $cand = (int)($_POST['report_for'] ?? 0);
    if ($cand > 0) {
        $chk = pg_query_params($conn, "SELECT id FROM users WHERE id = $1 AND is_active = TRUE", [$cand]);
        if ($chk && pg_num_rows($chk) > 0) $reportFor = $cand;
        if ($chk) pg_free_result($chk);
    }

    // Tabel dinamis paralel (mirror create_cr.php)
    $postJenis = $_POST['jenis'] ?? [];
    $postUraian = $_POST['uraian'] ?? [];
    $postAlasan = $_POST['alasan'] ?? [];
    if (!is_array($postJenis)) $postJenis = [$postJenis];
    if (!is_array($postUraian)) $postUraian = [$postUraian];
    if (!is_array($postAlasan)) $postAlasan = [$postAlasan];
    $nRows = max(count($postJenis), count($postUraian), count($postAlasan));
    $postedItems = [];
    for ($i = 0; $i < $nRows; $i++) {
        $postedItems[] = [
            'jenis' => trim((string)($postJenis[$i] ?? '')),
            'uraian' => trim((string)($postUraian[$i] ?? '')),
            'alasan' => trim((string)($postAlasan[$i] ?? '')),
        ];
    }

    // Validasi identik create_cr.php
    $err = null;
    if ($aplikasi === '' || $unit === '' || $modul === '' || $fitur === '' || $keteranganRaw === '' || $priority === '' || $status === '') {
        $err = 'Aplikasi, Unit, Modul, Fitur, Keterangan, Prioritas, dan Status wajib diisi!';
    } elseif (mb_strlen($aplikasi) > 150) {
        $err = 'Nama aplikasi maksimal 150 karakter.';
    } elseif (mb_strlen($modul) > 150) {
        $err = 'Modul maksimal 150 karakter.';
    } elseif (mb_strlen($fitur) > 255) {
        $err = 'Fitur maksimal 255 karakter.';
    } elseif (!in_array($unit, $divisions, true)) {
        $err = 'Unit tidak valid. Pilih dari daftar yang tersedia.';
    } elseif (!in_array($priority, $priorities, true)) {
        $err = 'Prioritas tidak valid.';
    } elseif (!in_array($status, $statuses, true)) {
        $err = 'Status tidak valid.';
    } elseif (mb_strlen($url) > 500) {
        $err = 'URL maksimal 500 karakter.';
    }

    if (!$err && $url !== '') {
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $err = 'URL tidak valid. Contoh: https://aplikasi/contoh-halaman';
        }
    }

    $waktuDb = null;
    if (!$err && $waktuRaw !== '') {
        $ts = strtotime(str_replace('T', ' ', $waktuRaw));
        if ($ts === false) {
            $err = 'Format waktu dibutuhkan tidak valid.';
        } else {
            $todayStart = strtotime(date('Y-m-d 00:00:00'));
            if ($ts < $todayStart) {
                $err = 'Waktu dibutuhkan minimal hari ini.';
            } else {
                $waktuDb = date('Y-m-d H:i:s', $ts);
            }
        }
    }

    $validItems = [];
    if (!$err) {
        if (count($postedItems) < 1) {
            $err = 'Minimal 1 baris Jenis Perubahan wajib diisi.';
        } elseif (count($postedItems) > 20) {
            $err = 'Maksimal 20 baris Jenis Perubahan.';
        } else {
            foreach ($postedItems as $idx => $row) {
                $no = $idx + 1;
                if ($row['jenis'] === '' || $row['uraian'] === '' || $row['alasan'] === '') {
                    $err = 'Baris ' . $no . ': Jenis, Uraian, dan Alasan/Manfaat wajib diisi.';
                    break;
                }
                if (!in_array($row['jenis'], $allowedJenis, true)) {
                    $err = 'Baris ' . $no . ': Jenis tidak valid. Pilih Penambahan, Perubahan, atau Design.';
                    break;
                }
                if (mb_strlen($row['uraian']) < 10) {
                    $err = 'Baris ' . $no . ': Uraian minimal 10 karakter.';
                    break;
                }
                if (mb_strlen($row['jenis']) > 20 || mb_strlen($row['uraian']) > 5000 || mb_strlen($row['alasan']) > 5000) {
                    $err = 'Baris ' . $no . ': isian terlalu panjang.';
                    break;
                }
                $validItems[] = $row;
            }
            if (!$err && count($validItems) < 1) {
                $err = 'Minimal 1 baris Jenis Perubahan wajib diisi.';
            }
        }
    }

    if ($err) {
        $message = $err;
        $messageType = 'danger';
        // Tampilkan kembali isian user
        $cr['aplikasi'] = $aplikasi;
        $cr['unit'] = $unit;
        $cr['modul'] = $modul;
        $cr['fitur'] = $fitur;
        $cr['url'] = $url;
        $cr['priority'] = $priority;
        $cr['status'] = $status;
        $cr['user_id'] = $reportFor;
        $cr['keterangan'] = $keteranganRaw;
        $existingItems = $postedItems;
    } else {
        $newPath = $cr['attachment_path'];
        $newOriginal = $cr['attachment_original'];
        $removeFile = isset($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1';

        if ($removeFile && $newPath) {
            if (file_exists(__DIR__ . '/' . $newPath)) @unlink(__DIR__ . '/' . $newPath);
            $newPath = null;
            $newOriginal = null;
        }

        [$upPath, $upOriginal] = handleCrUpload('attachment', $upError);
        if ($upPath === false) {
            $message = $upError ?: 'Upload lampiran gagal.';
            $messageType = 'danger';
        } else {
            if ($upPath) {
                if (!empty($cr['attachment_path']) && file_exists(__DIR__ . '/' . $cr['attachment_path'])) {
                    @unlink(__DIR__ . '/' . $cr['attachment_path']);
                }
                $newPath = $upPath;
                $newOriginal = $upOriginal;
            }

            $keterangan = sanitizeRichText($keteranganRaw);
            if ($keterangan === '') $keterangan = htmlspecialchars($keteranganRaw);

            pg_query($conn, 'BEGIN');
            $upd = pg_query_params(
                $conn,
                "UPDATE change_requests SET aplikasi=$1, unit=$2, modul=$3, fitur=$4, url=$5, waktu_dibutuhkan=$6, keterangan=$7, priority=$8, status=$9, user_id=$10, attachment_path=$11, attachment_original=$12, updated_at=NOW() WHERE id=$13",
                [$aplikasi, $unit, $modul, $fitur, ($url !== '' ? $url : null), $waktuDb, $keterangan, $priority, $status, $reportFor, $newPath, $newOriginal, $id]
            );
            $ok = (bool)$upd;
            if ($ok) {
                $del = pg_query_params($conn, "DELETE FROM change_request_items WHERE cr_id = $1", [$id]);
                $ok = (bool)$del;
            }
            if ($ok) {
                foreach ($validItems as $vrow) {
                    $ins = pg_query_params(
                        $conn,
                        "INSERT INTO change_request_items (cr_id, jenis, uraian, alasan) VALUES ($1,$2,$3,$4)",
                        [$id, $vrow['jenis'], $vrow['uraian'], $vrow['alasan']]
                    );
                    if (!$ins) { $ok = false; break; }
                }
            }
            if ($ok) {
                $hi = pg_query_params(
                    $conn,
                    "INSERT INTO change_request_history (cr_id, actor_id, from_status, to_status, note) VALUES ($1,$2,$3,$4,$5)",
                    [$id, $user['id'], $cr['status'], $status, 'CR diubah oleh admin.']
                );
                $ok = (bool)$hi;
            }

            if ($ok) {
                pg_query($conn, 'COMMIT');
                $message = 'Change Request berhasil diperbarui!';
                $messageType = 'success';
                $r2 = pg_query_params($conn, "SELECT * FROM change_requests WHERE id = $1", [$id]);
                $cr = pg_fetch_assoc($r2);
                pg_free_result($r2);
                $existingItems = $validItems;
            } else {
                pg_query($conn, 'ROLLBACK');
                if ($upPath && file_exists(__DIR__ . '/' . $upPath)) @unlink(__DIR__ . '/' . $upPath);
                $message = 'Gagal memperbarui CR: ' . pg_last_error($conn);
                $messageType = 'danger';
            }
        }
    }
}

$waktuVal = !empty($cr['waktu_dibutuhkan']) ? date('Y-m-d\TH:i', strtotime($cr['waktu_dibutuhkan'])) : '';
$todayMin = date('Y-m-d') . 'T00:00';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Edit CR <?php echo htmlspecialchars($cr['cr_number']); ?></h2>
        <p class="text-secondary mb-0">Perbarui keterangan rich-text, lampiran, status, dan rincian perubahan.</p>
    </div>
    <a href="view_cr.php?id=<?php echo $cr['id']; ?>" class="btn btn-outline-secondary btn-sm flex-shrink-0">← Kembali ke Detail</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="crForm" novalidate>
    <?php echo csrf_field(); ?>
    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="aplikasi" class="form-label">Aplikasi</label>
                        <input type="text" class="form-control" id="aplikasi" name="aplikasi" required maxlength="150" value="<?php echo htmlspecialchars($cr['aplikasi']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="modul" class="form-label">Modul</label>
                        <input type="text" class="form-control" id="modul" name="modul" required maxlength="150" value="<?php echo htmlspecialchars($cr['modul']); ?>">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="fitur" class="form-label">Fitur</label>
                    <input type="text" class="form-control" id="fitur" name="fitur" required maxlength="255" value="<?php echo htmlspecialchars($cr['fitur']); ?>">
                </div>

                <div class="row g-3 mt-0">
                    <div class="col-md-6 mt-3">
                        <label for="url" class="form-label">URL <span class="text-secondary fw-normal">(opsional)</span></label>
                        <input type="url" class="form-control" id="url" name="url" maxlength="500" value="<?php echo htmlspecialchars($cr['url'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mt-3">
                        <label for="waktu" class="form-label">Waktu Dibutuhkan <span class="text-secondary fw-normal">(opsional)</span></label>
                        <input type="datetime-local" class="form-control" id="waktu" name="waktu" min="<?php echo $todayMin; ?>" value="<?php echo htmlspecialchars($waktuVal); ?>">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="keterangan" class="form-label">Keterangan</label>
                    <textarea id="keterangan" class="form-control" name="keterangan" rows="8"><?php echo htmlspecialchars($cr['keterangan']); ?></textarea>
                </div>

                <div class="mt-3">
                    <label for="attachment" class="form-label">Lampiran <span class="text-secondary fw-normal">(kosongkan bila tidak diganti · maks 5MB)</span></label>
                    <?php if (!empty($cr['attachment_path'])): ?>
                        <div class="d-flex justify-content-between align-items-center gap-2 bg-body-tertiary border rounded-3 p-2 px-3 mb-2 small">
                            <span>📎 <?php echo htmlspecialchars($cr['attachment_original'] ?: basename($cr['attachment_path'])); ?></span>
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

            <div class="card mt-3"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h3 class="h6 mb-0">Jenis Perubahan</h3>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnAddRow">[+ Tambah Baris]</button>
                </div>
                <p class="text-secondary small mb-2">Minimal 1 baris. Uraian minimal 10 karakter. Jenis: Penambahan / Perubahan / Design.</p>
                <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0" id="jenisTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:48px;" class="text-center">No</th>
                            <th style="width:170px;">Jenis</th>
                            <th>Uraian</th>
                            <th>Alasan / Manfaat</th>
                            <th style="width:64px;" class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="jenisBody">
                        <?php foreach ($existingItems as $ri => $rit): ?>
                        <tr class="jenis-row">
                            <td class="row-no text-center"><?php echo $ri + 1; ?></td>
                            <td>
                                <select name="jenis[]" class="form-select form-select-sm jenis-sel" required>
                                    <option value="">Pilih</option>
                                    <?php foreach ($allowedJenis as $aj): ?>
                                        <option value="<?php echo $aj; ?>" <?php echo ($rit['jenis'] === $aj) ? 'selected' : ''; ?>><?php echo $aj; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><textarea name="uraian[]" class="form-control form-control-sm uraian-txt" rows="2" required minlength="10"><?php echo htmlspecialchars($rit['uraian']); ?></textarea></td>
                            <td><textarea name="alasan[]" class="form-control form-control-sm alasan-txt" rows="2" required><?php echo htmlspecialchars($rit['alasan'] ?? ''); ?></textarea></td>
                            <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm btn-del-row" title="Hapus baris" aria-label="Hapus baris"><i class="bi bi-trash"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div></div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3"><div class="card-body">
                <div class="mb-3">
                    <label for="report_for" class="form-label">Dilaporkan Untuk (Pengguna)</label>
                    <select id="report_for" name="report_for" class="form-select">
                        <?php foreach ($userOptions as $uo): ?>
                            <option value="<?php echo $uo['id']; ?>" <?php echo (int)$cr['user_id'] === (int)$uo['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($uo['name']); ?> · @<?php echo htmlspecialchars($uo['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="unit" class="form-label">Unit</label>
                    <select id="unit" name="unit" class="form-select" required>
                        <option value="">Pilih unit</option>
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?php echo $div; ?>" <?php echo $cr['unit'] === $div ? 'selected' : ''; ?>><?php echo $div; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="priority" class="form-label">Prioritas</label>
                    <select id="priority" name="priority" class="form-select" required>
                        <?php foreach ($priorities as $pr): ?>
                            <option value="<?php echo $pr; ?>" <?php echo $cr['priority'] === $pr ? 'selected' : ''; ?>><?php echo ucfirst($pr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select" required>
                        <?php foreach ($statuses as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo $cr['status'] === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                <a href="view_cr.php?id=<?php echo $cr['id']; ?>" class="btn btn-danger">Batal</a>
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

    var ket = document.getElementById('keterangan');
    var form = document.getElementById('crForm');
    var editorInstance = null;
    if (window.ClassicEditor && ket) {
        ClassicEditor.create(ket, { toolbar: ['heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'code', '|', 'undo', 'redo'] })
            .then(function (editor) { editorInstance = editor; }).catch(function () {});
    }
    if (form) {
        form.addEventListener('submit', function (e) {
            var text = '';
            if (editorInstance) {
                text = editorInstance.getData().replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').trim();
            } else if (ket) {
                text = ket.value.trim();
            }
            if (!text) {
                e.preventDefault();
                if (window.Notiflix) Notiflix.Notify.warning('Keterangan wajib diisi.');
                if (editorInstance) editorInstance.editing.view.focus();
                else if (ket) ket.focus();
                return;
            }
            var allowed = ['Penambahan', 'Perubahan', 'Design'];
            var rows = form.querySelectorAll('#jenisBody tr.jenis-row');
            if (rows.length < 1) {
                e.preventDefault();
                if (window.Notiflix) Notiflix.Notify.warning('Minimal 1 baris Jenis Perubahan wajib diisi.');
                return;
            }
            if (rows.length > 20) {
                e.preventDefault();
                if (window.Notiflix) Notiflix.Notify.failure('Maksimal 20 baris Jenis Perubahan.');
                return;
            }
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                var js = r.querySelector('.jenis-sel');
                var ur = r.querySelector('.uraian-txt');
                var al = r.querySelector('.alasan-txt');
                var no = i + 1;
                var jv = js ? js.value.trim() : '';
                var uv = ur ? ur.value.trim() : '';
                var av = al ? al.value.trim() : '';
                if (!jv || !uv || !av) {
                    e.preventDefault();
                    if (window.Notiflix) Notiflix.Notify.warning('Baris ' + no + ': Jenis, Uraian, dan Alasan/Manfaat wajib diisi.');
                    return;
                }
                if (allowed.indexOf(jv) === -1) {
                    e.preventDefault();
                    if (window.Notiflix) Notiflix.Notify.failure('Baris ' + no + ': Jenis tidak valid.');
                    return;
                }
                if (uv.length < 10) {
                    e.preventDefault();
                    if (window.Notiflix) Notiflix.Notify.warning('Baris ' + no + ': Uraian minimal 10 karakter.');
                    return;
                }
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
    var tbody = document.getElementById('jenisBody');
    var btnAdd = document.getElementById('btnAddRow');
    function renumber() {
        if (!tbody) return;
        var nos = tbody.querySelectorAll('tr.jenis-row .row-no');
        for (var i = 0; i < nos.length; i++) nos[i].textContent = (i + 1);
    }
    function bindDelete(btn) {
        btn.addEventListener('click', function () {
            var rows = tbody.querySelectorAll('tr.jenis-row');
            if (rows.length <= 1) {
                if (window.Notiflix) Notiflix.Notify.warning('Minimal 1 baris harus tersisa.');
                return;
            }
            btn.closest('tr.jenis-row').remove();
            renumber();
        });
    }
    if (tbody) tbody.querySelectorAll('.btn-del-row').forEach(bindDelete);
    if (btnAdd && tbody) {
        btnAdd.addEventListener('click', function () {
            var rows = tbody.querySelectorAll('tr.jenis-row');
            if (rows.length >= 20) {
                if (window.Notiflix) Notiflix.Notify.warning('Maksimal 20 baris.');
                return;
            }
            var first = tbody.querySelector('tr.jenis-row');
            var clone = first.cloneNode(true);
            clone.querySelector('.jenis-sel').value = '';
            clone.querySelector('.uraian-txt').value = '';
            clone.querySelector('.alasan-txt').value = '';
            tbody.appendChild(clone);
            bindDelete(clone.querySelector('.btn-del-row'));
            renumber();
            clone.querySelector('.jenis-sel').focus();
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
