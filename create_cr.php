<?php
require_once 'config.php';
require_once 'includes/cr_auth.php';
requireLogin();

$user = getCurrentUser();
$pageTitle = 'Buat Change Request';

require_once 'includes/header.php';

$conn = getDBConnection();
$canPickUser = in_array($user['role'], ['admin', 'teknisi']);

// Dropdown pengguna aktif (reuse pola report_for di create_ticket.php)
$userOptions = [];
if ($canPickUser) {
    $ur = pg_query($conn, "SELECT id, name, username, role FROM users WHERE is_active = TRUE ORDER BY name ASC");
    while ($row = pg_fetch_assoc($ur)) $userOptions[] = $row;
    pg_free_result($ur);
}

$message = '';
$messageType = '';
$createdId = null;
$createdNumber = null;
$old = [
    'aplikasi' => '',
    'unit' => '',
    'modul' => '',
    'fitur' => '',
    'url' => '',
    'waktu' => '',
    'keterangan' => '',
    'priority' => 'medium',
    'report_for' => $user['id'],
];

// Reuse daftar divisi dari create_ticket.php untuk field Unit
$divisions = ['IT Infrastructure', 'IT Development', 'IT Support', 'IT Security', 'Network', 'System Administration'];
$priorities = ['low', 'medium', 'high', 'critical'];
// Task 5a32787a: enum jenis perubahan (mirror CHECK di migrasi 011). Tidak perlu migrasi baru.
$allowedJenis = ['Penambahan', 'Perubahan', 'Design'];
// Default 1 baris kosong untuk tabel dinamis
$oldItems = [['jenis' => '', 'uraian' => '', 'alasan' => '']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $old['aplikasi'] = trim($_POST['aplikasi'] ?? '');
    $old['unit'] = trim($_POST['unit'] ?? '');
    $old['modul'] = trim($_POST['modul'] ?? '');
    $old['fitur'] = trim($_POST['fitur'] ?? '');
    $old['url'] = trim($_POST['url'] ?? '');
    $old['waktu'] = trim($_POST['waktu'] ?? '');
    $old['keterangan'] = trim($_POST['keterangan'] ?? '');
    $old['priority'] = trim($_POST['priority'] ?? 'medium');
    $old['report_for'] = (int)($_POST['report_for'] ?? $user['id']);

    // Task 5a32787a: tabel dinamis dikirim sebagai array paralel
    $postJenis = $_POST['jenis'] ?? [];
    $postUraian = $_POST['uraian'] ?? [];
    $postAlasan = $_POST['alasan'] ?? [];
    if (!is_array($postJenis)) $postJenis = [$postJenis];
    if (!is_array($postUraian)) $postUraian = [$postUraian];
    if (!is_array($postAlasan)) $postAlasan = [$postAlasan];
    $nRows = max(count($postJenis), count($postUraian), count($postAlasan));
    $oldItems = [];
    for ($i = 0; $i < $nRows; $i++) {
        $oldItems[] = [
            'jenis' => trim((string)($postJenis[$i] ?? '')),
            'uraian' => trim((string)($postUraian[$i] ?? '')),
            'alasan' => trim((string)($postAlasan[$i] ?? '')),
        ];
    }

    $reportFor = $user['id'];
    if ($canPickUser && $old['report_for'] > 0) {
        $chk = pg_query_params($conn, "SELECT id FROM users WHERE id = $1 AND is_active = TRUE", [$old['report_for']]);
        if ($chk && pg_num_rows($chk) > 0) $reportFor = $old['report_for'];
        if ($chk) pg_free_result($chk);
    }

    // Validasi server
    $err = null;
    if ($old['aplikasi'] === '' || $old['unit'] === '' || $old['modul'] === '' || $old['fitur'] === '' || $old['keterangan'] === '' || $old['priority'] === '') {
        $err = 'Aplikasi, Unit, Modul, Fitur, Keterangan, dan Prioritas wajib diisi!';
    } elseif (mb_strlen($old['aplikasi']) > 150) {
        $err = 'Nama aplikasi maksimal 150 karakter.';
    } elseif (mb_strlen($old['modul']) > 150) {
        $err = 'Modul maksimal 150 karakter.';
    } elseif (mb_strlen($old['fitur']) > 255) {
        $err = 'Fitur maksimal 255 karakter.';
    } elseif (!in_array($old['unit'], $divisions, true)) {
        $err = 'Unit tidak valid. Pilih dari daftar yang tersedia.';
    } elseif (!in_array($old['priority'], $priorities, true)) {
        $err = 'Prioritas tidak valid.';
    } elseif (mb_strlen($old['url']) > 500) {
        $err = 'URL maksimal 500 karakter.';
    }

    // URL opsional: validasi filter_var bila diisi
    if (!$err && $old['url'] !== '') {
        if (!preg_match('#^https?://#i', $old['url']) || !filter_var($old['url'], FILTER_VALIDATE_URL)) {
            $err = 'URL tidak valid. Contoh: https://aplikasi/contoh-halaman';
        }
    }

    // Waktu dibutuhkan opsional: bila diisi harus >= hari ini (00:00 server)
    $waktuDb = null;
    if (!$err && $old['waktu'] !== '') {
        $ts = strtotime(str_replace('T', ' ', $old['waktu']));
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

    // Task 5a32787a: validasi server tabel dinamis (mirror validasi client)
    // Aturan: minimal 1 baris, maks 20 baris, jenis whitelist enum,
    // uraian wajib >= 10 karakter, alasan wajib diisi. Tolak kosong/invalid.
    $validItems = [];
    if (!$err) {
        if (count($oldItems) < 1) {
            $err = 'Minimal 1 baris Jenis Perubahan wajib diisi.';
        } elseif (count($oldItems) > 20) {
            $err = 'Maksimal 20 baris Jenis Perubahan.';
        } else {
            foreach ($oldItems as $idx => $row) {
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
    } else {
        [$upPath, $upOriginal] = handleCrUpload('attachment', $upError);
        if ($upPath === false) {
            $message = $upError ?: 'Upload lampiran gagal.';
            $messageType = 'danger';
        } else {
            $keterangan = sanitizeRichText($old['keterangan']);
            if ($keterangan === '') $keterangan = htmlspecialchars($old['keterangan']);
            // Pastikan keterangan tidak kosong setelah sanitasi
            if (trim(strip_tags($keterangan)) === '' && trim($old['keterangan']) !== '') {
                $keterangan = htmlspecialchars($old['keterangan']);
            }

            pg_query($conn, 'BEGIN');
            pg_query($conn, 'LOCK TABLE change_requests IN SHARE ROW EXCLUSIVE MODE');
            $crNumber = generateCrNumber($conn);
            $slaDue = slaDueForPriority($old['priority']);

            $ins = pg_query_params(
                $conn,
                "INSERT INTO change_requests (cr_number, aplikasi, unit, user_id, created_by, waktu_dibutuhkan, modul, fitur, url, keterangan, status, priority, sla_due_at, attachment_path, attachment_original) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,'open',$11,$12,$13,$14) RETURNING id",
                [$crNumber, $old['aplikasi'], $old['unit'], $reportFor, $user['id'], $waktuDb, $old['modul'], $old['fitur'], ($old['url'] !== '' ? $old['url'] : null), $keterangan, $old['priority'], $slaDue, $upPath, $upOriginal]
            );

            if ($ins && pg_num_rows($ins) > 0) {
                $crId = (int)pg_fetch_result($ins, 0, 0);
                pg_free_result($ins);

                // Task 5a32787a: simpan N baris tabel dinamis dalam transaksi yang sama.
                // Catatan placeholder lama: CR yang dibuat sebelum task ini menyimpan 1 baris
                // ('Perubahan', 'Pengajuan awal: ...'). Baris itu VALID terhadap CHECK migrasi 011
                // sehingga tidak perlu migrasi/cleanup — dibiarkan sebagai CR 1-item yang sah.
                // Skema 011 tetap idempoten (IF NOT EXISTS), tidak diubah task ini.
                $it = true;
                foreach ($validItems as $vrow) {
                    $it = pg_query_params(
                        $conn,
                        "INSERT INTO change_request_items (cr_id, jenis, uraian, alasan) VALUES ($1,$2,$3,$4)",
                        [$crId, $vrow['jenis'], $vrow['uraian'], $vrow['alasan']]
                    );
                    if (!$it) break;
                }

                $hi = pg_query_params(
                    $conn,
                    "INSERT INTO change_request_history (cr_id, actor_id, from_status, to_status, note) VALUES ($1,$2,NULL,'open',$3)",
                    [$crId, $user['id'], 'Change Request dibuat.']
                );

                if ($it && $hi) {
                    pg_query($conn, 'COMMIT');
                    $createdId = $crId;
                    $createdNumber = $crNumber;
                    // Notifikasi staf (admin/teknisi aktif, kecuali pembuat) — mirror create_ticket.php
                    $sr = pg_query($conn, "SELECT id FROM users WHERE role IN ('admin','teknisi') AND is_active = TRUE AND id <> " . (int)$user['id']);
                    if ($sr) {
                        while ($srow = pg_fetch_assoc($sr)) {
                            notifyUser($conn, $srow['id'], 'CR baru ' . $crNumber . ' (' . $old['priority'] . ')', $old['aplikasi'] . ' — ' . $old['fitur'], 'view_cr.php?id=' . $createdId);
                        }
                        pg_free_result($sr);
                    }
                    $message = 'Change Request ' . $crNumber . ' berhasil dibuat!' . ($upPath ? ' Lampiran ikut tersimpan.' : '');
                    $messageType = 'success';
                    $old = ['aplikasi' => '', 'unit' => '', 'modul' => '', 'fitur' => '', 'url' => '', 'waktu' => '', 'keterangan' => '', 'priority' => 'medium', 'report_for' => $user['id']];
                    $oldItems = [['jenis' => '', 'uraian' => '', 'alasan' => '']];
                } else {
                    pg_query($conn, 'ROLLBACK');
                    if ($upPath && file_exists(__DIR__ . '/' . $upPath)) @unlink(__DIR__ . '/' . $upPath);
                    $message = 'Gagal menyimpan rincian CR: ' . pg_last_error($conn);
                    $messageType = 'danger';
                }
            } else {
                pg_query($conn, 'ROLLBACK');
                if ($upPath && file_exists(__DIR__ . '/' . $upPath)) @unlink(__DIR__ . '/' . $upPath);
                $message = 'Gagal membuat Change Request: ' . pg_last_error($conn);
                $messageType = 'danger';
            }
        }
    }
}

$todayMin = date('Y-m-d') . 'T00:00';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Buat Change Request</h2>
        <p class="text-secondary mb-0">Login sebagai <strong><?php echo htmlspecialchars($user['name']); ?></strong> (<?php echo htmlspecialchars($user['role']); ?>). Isi header pengajuan dan tabel Jenis Perubahan di bawah.</p>
    </div>
    <a href="cr_list.php" class="btn btn-outline-secondary btn-sm flex-shrink-0">← Kembali ke Daftar CR</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
        <?php if ($messageType === 'success' && $createdId): ?>
            <a href="view_cr.php?id=<?php echo $createdId; ?>" class="alert-link ms-2">Lihat CR <?php echo htmlspecialchars($createdNumber ?? ''); ?> →</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="crForm" novalidate>
    <?php echo csrf_field(); ?>
    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <div class="card"><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="aplikasi" class="form-label">Aplikasi</label>
                        <input type="text" class="form-control" id="aplikasi" name="aplikasi" required maxlength="150" placeholder="cth: SIMRS / ERP / Absensi" value="<?php echo htmlspecialchars($old['aplikasi']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="modul" class="form-label">Modul</label>
                        <input type="text" class="form-control" id="modul" name="modul" required maxlength="150" placeholder="cth: Rekam Medis / Billing" value="<?php echo htmlspecialchars($old['modul']); ?>">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="fitur" class="form-label">Fitur</label>
                    <input type="text" class="form-control" id="fitur" name="fitur" required maxlength="255" placeholder="cth: Tambah tombol export PDF di laporan kunjungan" value="<?php echo htmlspecialchars($old['fitur']); ?>">
                </div>

                <div class="row g-3 mt-0">
                    <div class="col-md-6 mt-3">
                        <label for="url" class="form-label">URL <span class="text-secondary fw-normal">(opsional)</span></label>
                        <input type="url" class="form-control" id="url" name="url" maxlength="500" placeholder="https://aplikasi/contoh-halaman" value="<?php echo htmlspecialchars($old['url']); ?>">
                        <div class="form-text">Bila diisi harus URL valid diawali http(s)://</div>
                    </div>
                    <div class="col-md-6 mt-3">
                        <label for="waktu" class="form-label">Waktu Dibutuhkan <span class="text-secondary fw-normal">(opsional)</span></label>
                        <input type="datetime-local" class="form-control" id="waktu" name="waktu" min="<?php echo $todayMin; ?>" value="<?php echo htmlspecialchars($old['waktu']); ?>">
                        <div class="form-text">Minimal hari ini (<?php echo date('d M Y'); ?>).</div>
                    </div>
                </div>

                <div class="mt-3">
                    <label for="keterangan" class="form-label">Keterangan</label>
                    <textarea id="keterangan" class="form-control" name="keterangan" rows="8" placeholder="Jelaskan kebutuhan perubahan: latar belakang, dampak, harapan hasil…"><?php echo htmlspecialchars($old['keterangan']); ?></textarea>
                    <div class="form-text">Bisa format bold, list, heading, dan link. Rincian per-baris diisi di tabel Jenis Perubahan di bawah.</div>
                </div>

                <div class="mt-3">
                    <label for="attachment" class="form-label">Lampiran <span class="text-secondary fw-normal">(opsional · maks 5MB)</span></label>
                    <label class="dropzone" id="dropzone">
                        <input type="file" id="attachment" name="attachment" hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                        <span class="dz-icon" aria-hidden="true"><i class="bi bi-cloud-arrow-up fs-4"></i></span>
                        <span class="dz-text d-flex flex-column"><strong id="dzLabel">Klik atau seret file ke sini</strong><small class="text-secondary">JPG, PNG, PDF, DOCX, XLSX, TXT, ZIP · maks 5MB</small></span>
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
                        <?php foreach ($oldItems as $ri => $rit): ?>
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
                            <td><textarea name="uraian[]" class="form-control form-control-sm uraian-txt" rows="2" required minlength="10" placeholder="Uraian perubahan (min. 10 karakter)"><?php echo htmlspecialchars($rit['uraian']); ?></textarea></td>
                            <td><textarea name="alasan[]" class="form-control form-control-sm alasan-txt" rows="2" required placeholder="Alasan / manfaat"><?php echo htmlspecialchars($rit['alasan']); ?></textarea></td>
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
                <h3 class="h6 mb-3">Ringkasan Pengajuan</h3>
                <?php if ($canPickUser): ?>
                <div class="mb-3">
                    <label for="report_for" class="form-label">User (Dilaporkan Untuk)</label>
                    <select id="report_for" name="report_for" class="form-select">
                        <option value="<?php echo $user['id']; ?>">Saya sendiri (<?php echo htmlspecialchars($user['name']); ?>)</option>
                        <?php foreach ($userOptions as $uo): ?>
                            <option value="<?php echo $uo['id']; ?>" <?php echo (int)$old['report_for'] === (int)$uo['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($uo['name']); ?> · @<?php echo htmlspecialchars($uo['username']); ?> (<?php echo htmlspecialchars($uo['role']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Admin/teknisi bisa buatkan CR atas nama pengguna lain.</div>
                </div>
                <?php else: ?>
                    <input type="hidden" name="report_for" value="<?php echo $user['id']; ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label for="unit" class="form-label">Unit</label>
                    <select id="unit" name="unit" class="form-select" required>
                        <option value="">Pilih unit</option>
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?php echo $div; ?>" <?php echo $old['unit'] === $div ? 'selected' : ''; ?>><?php echo $div; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label for="priority" class="form-label">Prioritas</label>
                    <select id="priority" name="priority" class="form-select" required>
                        <option value="">Pilih prioritas</option>
                        <?php foreach ($priorities as $pr): ?>
                            <option value="<?php echo $pr; ?>" <?php echo $old['priority'] === $pr ? 'selected' : ''; ?>><?php echo ucfirst($pr); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">SLA dihitung otomatis seperti tiket (jam kerja).</div>
                </div>
            </div></div>

            <div class="card mb-3"><div class="card-body">
                <h3 class="h6">Tips pengajuan bagus</h3>
                <ul class="mb-0 ps-3 small text-secondary">
                    <li>Sebut aplikasi + modul + fitur dengan jelas.</li>
                    <li>Cantumkan URL halaman yang terdampak bila ada.</li>
                    <li>Isi waktu dibutuhkan bila ada deadline.</li>
                </ul>
            </div></div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Buat CR</button>
                <a href="cr_list.php" class="btn btn-danger">Batal</a>
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
    function notifyResult(type, msg) {
        if (!window.Notiflix) return;
        if (type === 'success') Notiflix.Notify.success(msg);
        else if (type === 'danger' || type === 'failure') Notiflix.Notify.failure(msg);
    }
    <?php if ($message): ?>
    document.addEventListener('DOMContentLoaded', function () {
        notifyResult(<?php echo json_encode($messageType); ?>, <?php echo json_encode(strip_tags($message)); ?>);
    });
    <?php endif; ?>

    var ket = document.getElementById('keterangan');
    var form = document.getElementById('crForm');
    var editorInstance = null;

    if (window.ClassicEditor && ket) {
        ClassicEditor.create(ket, {
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
            } else if (ket) {
                text = ket.value.trim();
            }
            if (!text) {
                e.preventDefault();
                if (window.Notiflix) Notiflix.Notify.warning('Keterangan wajib diisi — jelaskan kebutuhan perubahannya dulu ya.');
                if (editorInstance) editorInstance.editing.view.focus();
                else if (ket) ket.focus();
                return;
            }
            var aplikasi = document.getElementById('aplikasi');
            var modul = document.getElementById('modul');
            var fitur = document.getElementById('fitur');
            var unit = document.getElementById('unit');
            var pr = document.getElementById('priority');
            if (!aplikasi.value.trim() || !modul.value.trim() || !fitur.value.trim() || !unit.value || !pr.value) {
                e.preventDefault();
                if (window.Notiflix) Notiflix.Notify.warning('Aplikasi, Unit, Modul, Fitur, dan Prioritas wajib diisi.');
                return;
            }
            var url = document.getElementById('url');
            if (url && url.value.trim() !== '') {
                try {
                    var u = new URL(url.value.trim());
                    if (!/^https?:$/.test(u.protocol)) throw new Error('bad scheme');
                } catch (err) {
                    e.preventDefault();
                    if (window.Notiflix) Notiflix.Notify.failure('URL tidak valid. Contoh: https://aplikasi/contoh-halaman');
                    url.focus();
                    return;
                }
            }
            var waktu = document.getElementById('waktu');
            if (waktu && waktu.value) {
                var picked = new Date(waktu.value);
                var today = new Date();
                today.setHours(0, 0, 0, 0);
                if (isNaN(picked.getTime()) || picked < today) {
                    e.preventDefault();
                    if (window.Notiflix) Notiflix.Notify.failure('Waktu dibutuhkan minimal hari ini.');
                    waktu.focus();
                    return;
                }
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
                    if (js && !jv) js.focus(); else if (ur && !uv) ur.focus(); else if (al) al.focus();
                    return;
                }
                if (allowed.indexOf(jv) === -1) {
                    e.preventDefault();
                    if (window.Notiflix) Notiflix.Notify.failure('Baris ' + no + ': Jenis tidak valid.');
                    js.focus();
                    return;
                }
                if (uv.length < 10) {
                    e.preventDefault();
                    if (window.Notiflix) Notiflix.Notify.warning('Baris ' + no + ': Uraian minimal 10 karakter.');
                    ur.focus();
                    return;
                }
            }
            var f = document.getElementById('attachment');
            if (f && f.files[0] && f.files[0].size > 5 * 1024 * 1024) {
                e.preventDefault();
                if (window.Notiflix) Notiflix.Notify.failure('Ukuran lampiran maksimal 5MB.');
                return;
            }
            if (window.Notiflix) Notiflix.Loading.pulse('Membuat Change Request…');
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
    if (tbody) {
        tbody.querySelectorAll('.btn-del-row').forEach(bindDelete);
    }
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
