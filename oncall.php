<?php
// Jadwal Oncall: 1 orang per tanggal; Minggu/tanggal merah boleh 2 orang.
// Lihat: semua role. Kelola (tambah/ubah/hapus/import/rotasi): admin only.
// Format import standar: Nama | Tanggal (NOMOR 1-31 saja, bulan dari form Bulan Target).
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$role = $user['role'] ?? '';
$isAdmin = ($role === 'admin');
$pageTitle = 'Jadwal Oncall';

$fMonth = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $fMonth)) $fMonth = date('Y-m');
$tsFirst = strtotime($fMonth . '-01');
$daysInMonth = (int)date('t', $tsFirst);
$prevMonth = date('Y-m', strtotime($fMonth . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($fMonth . '-01 +1 month'));

$conn = getDBConnection();

// Pool personel: admin/teknisi aktif
$pool = [];
$pr = pg_query($conn, "SELECT id, username, name FROM users WHERE is_active = TRUE AND role IN ('admin','teknisi') ORDER BY name ASC");
if ($pr) {
    while ($row = pg_fetch_assoc($pr)) $pool[] = $row;
    pg_free_result($pr);
}
$poolById = [];
foreach ($pool as $p) $poolById[(int)$p['id']] = $p;

// Jadwal bulan ini
$sched = [];
$sr = pg_query_params(
    $conn,
    "SELECT s.*, u.name AS user_name FROM oncall_schedules s JOIN users u ON u.id = s.user_id
     WHERE to_char(s.tanggal,'YYYY-MM') = $1 ORDER BY s.tanggal ASC, s.peran ASC, s.id ASC",
    [$fMonth]
);
if ($sr) {
    while ($row = pg_fetch_assoc($sr)) $sched[] = $row;
    pg_free_result($sr);
}
$byDate = [];
foreach ($sched as $s) $byDate[substr($s['tanggal'], 0, 10)][] = $s;

$holidays = getHolidays();
$holidayDesc = [];
$hr = pg_query_params($conn, "SELECT tanggal::text AS t, keterangan FROM holidays WHERE to_char(tanggal,'YYYY-MM') = $1", [$fMonth]);
if ($hr) {
    while ($row = pg_fetch_assoc($hr)) $holidayDesc[substr($row['t'], 0, 10)] = $row['keterangan'];
    pg_free_result($hr);
}

// Peta hari spesial (Minggu/libur → boleh 2 orang), untuk badge + validasi JS
$specialMap = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $ymd = sprintf('%s-%02d', $fMonth, $d);
    if (!isWorkingDay($ymd, $holidays)) $specialMap[$ymd] = true;
}

// ---------- Fuzzy match Nama → user (untuk preview XLS) ----------
function oncallMatchUser($input, $pool)
{
    $norm = strtolower(trim(preg_replace('/\s+/', ' ', (string)$input)));
    if ($norm === '') return null;
    foreach ($pool as $p) {
        if (strtolower(trim($p['name'])) === $norm || strtolower(trim($p['username'])) === $norm) return $p;
    }
    foreach ($pool as $p) {
        $nm = strtolower($p['name']);
        $un = strtolower($p['username']);
        if (strpos($nm, $norm) !== false || strpos($norm, $nm) !== false) return $p;
        if ($un !== '' && (strpos($un, $norm) !== false || strpos($norm, $un) !== false)) return $p;
    }
    $best = null;
    $bestDist = 3;
    foreach ($pool as $p) {
        foreach ([strtolower($p['name']), strtolower($p['username'])] as $cand) {
            $dist = levenshtein($norm, $cand);
            if ($dist <= $bestDist) {
                $bestDist = $dist;
                $best = $p;
            }
        }
    }
    return $best;
}

// ---------- Preview XLS (admin, POST file di halaman ini; simpan via oncall_action.php) ----------
$preview = [];   // [{nama_input,user_id,user_name,day,tanggal,peran,error}]
$previewMonth = $fMonth;
$previewSumber = 'import_xls';
$previewMsg = '';
$previewMsgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview_xls') {
    if (!$isAdmin) {
        $previewMsg = 'Hanya administrator yang boleh import.';
        $previewMsgType = 'danger';
    } else {
        requireCsrf();
        $previewMonth = trim($_POST['import_month'] ?? $fMonth);
        if (!preg_match('/^\d{4}-\d{2}$/', $previewMonth)) $previewMonth = $fMonth;
        $dim = (int)date('t', strtotime($previewMonth . '-01'));
        $f = $_FILES['xls_file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $previewMsg = 'Pilih file XLS/XLSX/CSV dulu.';
            $previewMsgType = 'danger';
        } elseif (($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $previewMsg = 'Upload gagal (kode ' . (int)$f['error'] . ').';
            $previewMsgType = 'danger';
        } elseif (($f['size'] ?? 0) > 5 * 1024 * 1024) {
            $previewMsg = 'Ukuran file maksimal 5MB.';
            $previewMsgType = 'danger';
        } else {
            $ext = strtolower(pathinfo($f['name'] ?? 'file', PATHINFO_EXTENSION));
            if (!in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
                $previewMsg = 'Jenis file harus .xls / .xlsx / .csv.';
                $previewMsgType = 'danger';
            } elseif (!file_exists(__DIR__ . '/vendor/autoload.php')) {
                $previewMsg = 'Library PhpSpreadsheet belum terinstal. Jalankan: composer install';
                $previewMsgType = 'danger';
            } else {
                require_once __DIR__ . '/vendor/autoload.php';
                try {
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($f['tmp_name']);
                    $reader->setReadDataOnly(true);
                    $ss = $reader->load($f['tmp_name']);
                    $grid = $ss->getActiveSheet()->toArray(null, true, true, false);
                    $countPerDate = [];
                    $n = 0;
                    foreach ($grid as $idx => $cols) {
                        if ($idx === 0) continue; // header: Nama | Tanggal
                        $nama = trim((string)($cols[0] ?? ''));
                        $noRaw = trim((string)($cols[1] ?? ''));
                        if ($nama === '' && $noRaw === '') continue; // baris kosong
                        $n++;
                        if ($n > 500) break;
                        $row = ['nama_input' => $nama, 'user_id' => null, 'user_name' => null, 'day' => null, 'tanggal' => null, 'peran' => 'utama', 'error' => null];
                        if ($nama === '') {
                            $row['error'] = 'Nama kosong.';
                        } else {
                            $m = oncallMatchUser($nama, $pool);
                            if ($m) {
                                $row['user_id'] = (int)$m['id'];
                                $row['user_name'] = $m['name'];
                            } else {
                                $row['error'] = 'Nama "' . $nama . '" tidak cocok dengan pengguna mana pun.';
                            }
                        }
                        if (!preg_match('/\d+/', $noRaw, $mm)) {
                            $row['error'] = ($row['error'] ? $row['error'] . ' ' : '') . 'Nomor tanggal tidak terbaca.';
                        } else {
                            $day = (int)$mm[0];
                            if ($day < 1 || $day > $dim) {
                                $row['error'] = ($row['error'] ? $row['error'] . ' ' : '') . "Nomor $day di luar bulan ($dim hari).";
                            } else {
                                $row['day'] = $day;
                                $row['tanggal'] = sprintf('%s-%02d', $previewMonth, $day);
                                $c = ($countPerDate[$row['tanggal']] ?? 0);
                                $cap = isset($specialMap[$row['tanggal']]) || !isWorkingDay($row['tanggal'], $holidays) ? 2 : 1;
                                // Kapasitas preview dihitung thd bulan target bila sama dgn bulan tampil; bila beda, hitung global
                                if ($c >= $cap) {
                                    $row['error'] = ($row['error'] ? $row['error'] . ' ' : '') . "Tanggal {$row['tanggal']} kelebihan (maks $cap).";
                                } else {
                                    $row['peran'] = $c === 0 ? 'utama' : 'pendamping';
                                    $countPerDate[$row['tanggal']] = $c + 1;
                                }
                            }
                        }
                        $preview[] = $row;
                    }
                    if (empty($preview)) {
                        $previewMsg = 'File tidak berisi baris data (format: Nama | Tanggal nomor).';
                        $previewMsgType = 'danger';
                    } else {
                        $bad = 0;
                        foreach ($preview as $r) if (!empty($r['error'])) $bad++;
                        $previewMsg = 'Dibaca ' . count($preview) . ' baris' . ($bad ? ", $bad perlu dibetulkan (merah)." : '. Semua cocok, silakan simpan.');
                        $previewMsgType = $bad ? 'warning' : 'success';
                    }
                } catch (Throwable $ex) {
                    $previewMsg = 'Gagal membaca file: ' . $ex->getMessage();
                    $previewMsgType = 'danger';
                }
            }
        }
    }
}

$flashErr = flash_error();
$flashOk = null;
if (!empty($_SESSION['flash_ok'])) {
    $flashOk = $_SESSION['flash_ok'];
    unset($_SESSION['flash_ok']);
}
// Tab aktif: habis Parse XLS langsung buka tab Preview
$tabNow = !empty($preview) ? 'preview' : 'jadwal';

require_once 'includes/header.php';

$hariId = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
$namaBulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
[$yy, $mm] = array_map('intval', explode('-', $fMonth));
?>

<h1 class="h4 mb-1">Jadwal Oncall — <?php echo $namaBulan[$mm] . ' ' . $yy; ?></h1>
<p class="text-secondary small mb-3">1 orang per tanggal; <span class="badge text-bg-warning">Minggu/libur boleh 2 orang</span> (utama + pendamping). Format import: <code>Nama | Tanggal</code> (nomor 1–<?php echo $daysInMonth; ?> saja).</p>

<?php if ($flashErr): ?><div class="alert alert-danger"><?php echo htmlspecialchars($flashErr); ?></div><?php endif; ?>
<?php if ($flashOk): ?><div class="alert alert-success"><?php echo htmlspecialchars($flashOk); ?></div><?php endif; ?>

<!-- Navigasi bulan -->
<div class="card mb-3"><div class="card-body d-flex gap-2 align-items-center flex-wrap">
    <a class="btn btn-outline-secondary btn-sm" href="oncall.php?month=<?php echo $prevMonth; ?>">← <?php echo $prevMonth; ?></a>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="month" name="month" class="form-control form-control-sm" style="width:auto;" value="<?php echo $fMonth; ?>">
        <button class="btn btn-primary btn-sm" type="submit">Buka</button>
    </form>
    <a class="btn btn-outline-secondary btn-sm" href="oncall.php?month=<?php echo $nextMonth; ?>"><?php echo $nextMonth; ?> →</a>
    <span class="ms-auto small text-secondary"><?php echo count($sched); ?> penugasan bulan ini</span>
</div></div>

<?php if ($isAdmin): ?>
<!-- Tab admin: Jadwal | Import | Preview | Rotasi (tanpa scroll panjang) -->
<ul class="nav nav-tabs mb-3" id="oncallTabs" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link<?php echo $tabNow === 'jadwal' ? ' active' : ''; ?>" id="tabbtn-jadwal" data-bs-toggle="tab" data-bs-target="#tab-jadwal" type="button" role="tab">📅 Jadwal (<?php echo count($sched); ?>)</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link<?php echo $tabNow === 'import' ? ' active' : ''; ?>" id="tabbtn-import" data-bs-toggle="tab" data-bs-target="#tab-import" type="button" role="tab">📥 Import</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link<?php echo $tabNow === 'preview' ? ' active' : ''; ?>" id="tabbtn-preview" data-bs-toggle="tab" data-bs-target="#tab-preview" type="button" role="tab">📝 Preview <span class="badge text-bg-secondary" id="pvCount"><?php echo count($preview); ?></span></button></li>
    <li class="nav-item" role="presentation"><button class="nav-link<?php echo $tabNow === 'rotasi' ? ' active' : ''; ?>" id="tabbtn-rotasi" data-bs-toggle="tab" data-bs-target="#tab-rotasi" type="button" role="tab">🔁 Rotasi</button></li>
</ul>
<div class="tab-content">
<div class="tab-pane fade<?php echo $tabNow === 'jadwal' ? ' show active' : ''; ?>" id="tab-jadwal" role="tabpanel">
<?php endif; ?>
<!-- Tabel jadwal sebulan -->
<div class="card mb-4"><div class="card-body p-0">
<div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
        <th style="width:90px;">Tanggal</th><th style="width:70px;">Hari</th><th>Petugas Utama</th><th>Petugas Pendamping</th>
        <?php if ($isAdmin): ?><th style="min-width:220px;">Kelola (admin)</th><?php endif; ?>
    </tr></thead>
    <tbody class="table-group-divider">
    <?php for ($d = 1; $d <= $daysInMonth; $d++):
        $ymd = sprintf('%s-%02d', $fMonth, $d);
        $dow = (int)date('w', strtotime($ymd));
        $isSpecial = isset($specialMap[$ymd]);
        $rows = $byDate[$ymd] ?? [];
        $utama = null;
        $pend = null;
        foreach ($rows as $r) {
            if ($r['peran'] === 'utama' && $utama === null) $utama = $r;
            elseif ($pend === null) $pend = $r;
        }
        $isToday = ($ymd === date('Y-m-d'));
    ?>
    <tr class="<?php echo $isToday ? 'table-primary' : ''; ?>">
        <td class="text-nowrap"><strong><?php echo $d; ?></strong> <small class="text-secondary"><?php echo $namaBulan[$mm]; ?></small><?php if ($isToday): ?> <span class="badge text-bg-primary">hari ini</span><?php endif; ?></td>
        <td>
            <?php echo $hariId[$dow]; ?>
            <?php if ($isSpecial): ?><br><span class="badge text-bg-warning mt-1"><?php echo $dow === 0 ? 'Minggu' : 'Libur'; ?></span><?php endif; ?>
            <?php if (isset($holidayDesc[$ymd])): ?><br><small class="text-secondary"><?php echo htmlspecialchars($holidayDesc[$ymd]); ?></small><?php endif; ?>
        </td>
        <td><?php echo $utama ? htmlspecialchars($utama['user_name']) : '<span class="text-secondary">— kosong —</span>'; ?></td>
        <td><?php echo $pend ? htmlspecialchars($pend['user_name']) : '<span class="text-secondary">—</span>'; ?></td>
        <?php if ($isAdmin): ?>
        <td>
            <div class="d-flex gap-1 flex-wrap">
            <?php if ($utama): ?>
                <form method="POST" action="oncall_action.php" class="d-inline-flex gap-1" title="Ganti petugas utama">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="month" value="<?php echo $fMonth; ?>">
                    <input type="hidden" name="id" value="<?php echo $utama['id']; ?>">
                    <select name="user_id" class="form-select form-select-sm" style="width:auto;" required>
                        <?php foreach ($pool as $p): ?><option value="<?php echo $p['id']; ?>"<?php echo ((int)$p['id'] === (int)$utama['user_id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Ganti</button>
                </form>
                <form method="POST" action="oncall_action.php" class="d-inline" onsubmit="return confirm('Hapus <?php echo htmlspecialchars($utama['user_name'], ENT_QUOTES); ?> dari <?php echo $ymd; ?>?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="month" value="<?php echo $fMonth; ?>">
                    <input type="hidden" name="id" value="<?php echo $utama['id']; ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Hapus utama">✕</button>
                </form>
            <?php endif; ?>
            <?php if ($pend): ?>
                <form method="POST" action="oncall_action.php" class="d-inline-flex gap-1" title="Ganti pendamping">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="month" value="<?php echo $fMonth; ?>">
                    <input type="hidden" name="id" value="<?php echo $pend['id']; ?>">
                    <select name="user_id" class="form-select form-select-sm" style="width:auto;" required>
                        <?php foreach ($pool as $p): ?><option value="<?php echo $p['id']; ?>"<?php echo ((int)$p['id'] === (int)$pend['user_id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Ganti</button>
                </form>
                <form method="POST" action="oncall_action.php" class="d-inline" onsubmit="return confirm('Hapus pendamping <?php echo $ymd; ?>?')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="month" value="<?php echo $fMonth; ?>">
                    <input type="hidden" name="id" value="<?php echo $pend['id']; ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Hapus pendamping">✕</button>
                </form>
            <?php endif; ?>
            <?php if (count($rows) < ($isSpecial ? 2 : 1)): ?>
                <form method="POST" action="oncall_action.php" class="d-inline-flex gap-1" title="Tambah petugas tanggal ini">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="month" value="<?php echo $fMonth; ?>">
                    <input type="hidden" name="tanggal" value="<?php echo $ymd; ?>">
                    <select name="user_id" class="form-select form-select-sm" style="width:auto;" required>
                        <option value="">+ Pilih…</option>
                        <?php foreach ($pool as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-success" type="submit">+</button>
                </form>
            <?php endif; ?>
            </div>
        </td>
        <?php endif; ?>
    </tr>
    <?php endfor; ?>
    </tbody>
</table></div>
</div></div>
<?php if ($isAdmin): ?>
</div><!-- /tab-jadwal -->
<!-- ============ TAB IMPORT ============ -->
<div class="tab-pane fade<?php echo $tabNow === 'import' ? ' show active' : ''; ?>" id="tab-import" role="tabpanel">
<div class="row g-3 mb-4">
    <!-- Import XLS -->
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h3 class="h6">📥 Import XLS <small class="text-secondary">(Nama | Nomor tanggal)</small></h3>
        <p class="small text-secondary mb-2">Pilih <strong>Bulan Target</strong> dulu (kolom tanggal hanya nomor 1–31), lalu upload. <a href="oncall_template.php">Download template</a>.</p>
        <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-end">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="preview_xls">
            <div><label class="form-label small mb-1">Bulan Target</label><input type="month" name="import_month" class="form-control form-control-sm" value="<?php echo $fMonth; ?>" required></div>
            <div><label class="form-label small mb-1">File (.xls/.xlsx/.csv, maks 5MB)</label><input type="file" name="xls_file" class="form-control form-control-sm" accept=".xls,.xlsx,.csv" required></div>
            <div><button class="btn btn-primary btn-sm" type="submit">Parse & Preview</button></div>
        </form>
        <?php if ($previewMsg): ?><div class="alert alert-<?php echo $previewMsgType; ?> mt-2 mb-0 py-2 small"><?php echo htmlspecialchars($previewMsg); ?></div><?php endif; ?>
    </div></div>
    </div><!-- /col XLS -->
    <!-- Import PNG -->
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h3 class="h6">🖼 Import PNG <small class="text-secondary">(OCR, perlu koreksi)</small></h3>
        <p class="small text-secondary mb-2">Upload foto jadwal → <em>Baca dengan OCR</em> → betulkan di tabel preview → simpan. Akurasi OCR tidak 100%.</p>
        <div class="d-flex gap-2 flex-wrap align-items-end">
            <div><label class="form-label small mb-1">Bulan Target</label><input type="month" id="pngMonth" class="form-control form-control-sm" value="<?php echo $fMonth; ?>"></div>
            <div><label class="form-label small mb-1">File (.png/.jpg)</label><input type="file" id="pngFile" class="form-control form-control-sm" accept=".png,.jpg,.jpeg"></div>
            <div><button class="btn btn-primary btn-sm" type="button" id="btnOcr">Baca dengan OCR</button></div>
        </div>
        <div id="ocrStatus" class="small text-secondary mt-2">Tesseract.js dimuat dari CDN saat tombol ditekan (butuh internet). Tips: foto tegak, fokus, dan cukup cahaya.</div>
        <div class="progress mt-2 d-none" id="ocrProg" style="height:8px;"><div class="progress-bar progress-bar-striped progress-bar-animated" id="ocrProgBar" style="width:0%"></div></div>
        <button class="btn btn-sm btn-outline-secondary mt-2 d-none" type="button" id="btnOcrRaw">Masukkan semua baris mentah ke preview (betulkan manual)</button>
        <img id="pngPreview" class="img-fluid border rounded mt-2 d-none" alt="Pratinjau">
        <details class="mt-2"><summary class="small">Lihat teks mentah OCR</summary><pre id="ocrRaw" class="small bg-light p-2 mt-1" style="max-height:150px;overflow:auto;"></pre></details>
    </div></div>
    </div><!-- /col PNG -->
</div><!-- /row import -->
</div><!-- /tab-import -->
<!-- TAB PREVIEW: WAJIB dikoreksi sebelum simpan -->
<div class="tab-pane fade<?php echo $tabNow === 'preview' ? ' show active' : ''; ?>" id="tab-preview" role="tabpanel">
<div class="card mb-4" id="previewCard">
<div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h3 class="h6 mb-0">📝 Preview Import <small class="text-secondary">(betulkan Nama / Nomor bila perlu, lalu Simpan)</small></h3>
        <span class="d-flex gap-2 align-items-center">
            <label class="small mb-0">Bulan <input type="month" id="pvMonth" class="form-control form-control-sm d-inline-block" style="width:auto;" value="<?php echo htmlspecialchars($previewMonth); ?>"></label>
            <label class="small mb-0">Sumber
                <select id="pvSumber" class="form-select form-select-sm d-inline-block" style="width:auto;">
                    <option value="import_xls"<?php echo $previewSumber === 'import_xls' ? ' selected' : ''; ?>>XLS</option>
                    <option value="import_png">PNG/OCR</option>
                </select>
            </label>
            <button class="btn btn-sm btn-outline-secondary" type="button" id="btnPvAdd">+ Baris</button>
        </span>
    </div>
    <style>#pvTable thead th{position:sticky;top:0;z-index:2;}</style>
    <div class="table-responsive" style="max-height:420px;overflow:auto;"><table class="table table-sm table-hover align-middle mb-0" id="pvTable">
        <thead class="table-dark"><tr><th>Nama (personel)</th><th style="width:110px;">No (1–31)</th><th style="width:130px;">→ Tanggal</th><th style="width:120px;">Peran</th><th>Status</th><th style="width:60px;"></th></tr></thead>
        <tbody id="pvBody">
            <?php foreach ($preview as $r): ?>
            <tr>
                <td><select class="form-select form-select-sm pv-user">
                    <option value="">— pilih —</option>
                    <?php foreach ($pool as $p): ?><option value="<?php echo $p['id']; ?>"<?php echo ((int)($r['user_id'] ?? 0) === (int)$p['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?>
                </select><small class="text-secondary">file: <?php echo htmlspecialchars($r['nama_input']); ?></small></td>
                <td><input type="number" class="form-control form-control-sm pv-day" min="1" max="31" value="<?php echo $r['day'] ?? ''; ?>"></td>
                <td class="pv-date small text-nowrap"><?php echo $r['tanggal'] ?? '-'; ?></td>
                <td class="pv-peran small"><?php echo $r['peran']; ?></td>
                <td class="pv-status small"><?php echo $r['error'] ? '<span class="badge text-bg-danger">perlu betulkan</span> ' . htmlspecialchars($r['error']) : '<span class="badge text-bg-success">ok</span>'; ?></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger pv-del" title="Buang baris">✕</button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <p id="pvEmpty" class="text-secondary small mt-2 mb-0"<?php echo empty($preview) ? '' : ' style="display:none;"'; ?>>Belum ada baris preview. Hasil Parse XLS muncul di sini; hasil OCR dari foto juga masuk ke tabel ini.</p>
    <form method="POST" action="oncall_action.php" id="pvForm" class="mt-2">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_import">
        <input type="hidden" name="month" id="pvMonthHidden" value="<?php echo htmlspecialchars($previewMonth); ?>">
        <input type="hidden" name="sumber" id="pvSumberHidden" value="import_xls">
        <input type="hidden" name="rows" id="pvRows">
        <button class="btn btn-success btn-sm" type="submit" id="btnPvSave">💾 Simpan hasil import (timpa tanggal yang diimport)</button>
        <span class="small text-secondary ms-2">Strategi: replace-per-tanggal — hanya tanggal yang muncul di preview yang ditimpa.</span>
    </form>
</div></div>
</div><!-- /tab-preview -->
<!-- TAB ROTASI -->
<div class="tab-pane fade<?php echo $tabNow === 'rotasi' ? ' show active' : ''; ?>" id="tab-rotasi" role="tabpanel">
<div class="card mb-4"><div class="card-body">
    <h3 class="h6">🔁 Rotasi Otomatis Sebulan</h3>
    <p class="small text-secondary">Gilir personel melingkar sesuai urutan checklist. Hari biasa = 1 berikutnya; Minggu/libur = 2 berikutnya. Tanggal yang sudah terisi dilewati (giliran tidak maju), kecuali centang Timpa.</p>
    <form method="POST" action="oncall_action.php" class="row g-2 align-items-end">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="rotasi">
        <div class="col-12 col-md-2"><label class="form-label small">Bulan Target</label><input type="month" name="month" class="form-control form-control-sm" value="<?php echo date('Y-m', strtotime($fMonth . '-01 +1 month')); ?>" required></div>
        <div class="col-12 col-md-3"><label class="form-label small">Mulai dari</label>
            <select name="start_id" class="form-select form-select-sm"><option value="0">Awal urutan</option>
            <?php foreach ($pool as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-12 col-md-auto"><label class="form-check small"><input type="checkbox" name="overwrite" value="1" class="form-check-input"> Timpa seluruh bulan</label></div>
        <div class="col-12"><label class="form-label small">Urutan gilir (centang sesuai urutan tampil)</label>
            <div class="d-flex gap-2 flex-wrap">
            <?php foreach ($pool as $i => $p): ?>
                <label class="form-check border rounded px-2 py-1 small"><input type="checkbox" name="order[]" value="<?php echo $p['id']; ?>" class="form-check-input" checked> <?php echo ($i + 1) . '. ' . htmlspecialchars($p['name']); ?></label>
            <?php endforeach; ?>
            <?php if (empty($pool)): ?><span class="text-secondary small">Tidak ada personel aktif.</span><?php endif; ?>
            </div></div>
        <div class="col-12"><button class="btn btn-warning btn-sm" type="submit" onclick="return confirm('Buat rotasi untuk bulan target?')">Buat Rotasi</button></div>
    </form>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
(function () {
  var pool = <?php echo json_encode(array_map(function ($p) {
      return ['id' => (int)$p['id'], 'name' => $p['name'], 'username' => $p['username']];
  }, $pool), JSON_UNESCAPED_UNICODE); ?>;
  var special = <?php echo json_encode($specialMap, JSON_UNESCAPED_UNICODE); ?>;
  var holidays = <?php echo json_encode($holidays, JSON_UNESCAPED_UNICODE); ?>;
  var csrf = document.querySelector('#pvForm input[name=csrf_token]').value;

  function isSpecial(ymd) {
    if (special[ymd]) return true;
    var d = new Date(ymd + 'T00:00:00');
    if (d.getDay() === 0) return true;
    return !!holidays[ymd];
  }
  function daysIn(month) {
    var p = month.split('-');
    return new Date(+p[0], +p[1], 0).getDate();
  }
  function userOptions(sel) {
    var html = '<option value="">— pilih —</option>';
    pool.forEach(function (p) {
      html += '<option value="' + p.id + '"' + (String(sel) === String(p.id) ? ' selected' : '') + '>' + p.name.replace(/</g, '&lt;') + '</option>';
    });
    return html;
  }
  window.pvAddRow = function (nama, day, userId) {
    document.getElementById('pvEmpty').style.display = 'none';
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><select class="form-select form-select-sm pv-user">' + userOptions(userId || '') + '</select>' +
      (nama ? '<small class="text-secondary">ocr: ' + String(nama).replace(/</g, '&lt;') + '</small>' : '') + '</td>' +
      '<td><input type="number" class="form-control form-control-sm pv-day" min="1" max="31" value="' + (day || '') + '"></td>' +
      '<td class="pv-date small text-nowrap">-</td><td class="pv-peran small"></td><td class="pv-status small"></td>' +
      '<td><button type="button" class="btn btn-sm btn-outline-danger pv-del">✕</button></td>';
    document.getElementById('pvBody').appendChild(tr);
    refreshPreview();
  };
  document.getElementById('btnPvAdd').addEventListener('click', function () { pvAddRow('', '', ''); });
  document.getElementById('pvBody').addEventListener('click', function (ev) {
    if (ev.target.classList.contains('pv-del')) {
      ev.target.closest('tr').remove();
      if (!document.querySelectorAll('#pvBody tr').length) document.getElementById('pvEmpty').style.display = '';
      refreshPreview();
    }
  });
  document.getElementById('pvBody').addEventListener('input', refreshPreview);
  document.getElementById('pvBody').addEventListener('change', refreshPreview);
  document.getElementById('pvMonth').addEventListener('change', function () {
    document.getElementById('pvMonthHidden').value = this.value;
    refreshPreview();
  });
  document.getElementById('pvSumber').addEventListener('change', function () {
    document.getElementById('pvSumberHidden').value = this.value;
  });

  function refreshPreview() {
    var month = document.getElementById('pvMonth').value;
    var dim = daysIn(month);
    var perDate = {};
    var rows = document.querySelectorAll('#pvBody tr');
    rows.forEach(function (tr) {
      var uid = tr.querySelector('.pv-user').value;
      var day = parseInt(tr.querySelector('.pv-day').value, 10);
      var dateCell = tr.querySelector('.pv-date');
      var peranCell = tr.querySelector('.pv-peran');
      var stCell = tr.querySelector('.pv-status');
      var errs = [];
      if (!uid) errs.push('personel belum dipilih');
      if (!day || day < 1 || day > dim) errs.push('nomor 1–' + dim);
      var ymd = (!errs.length || (uid && day >= 1 && day <= dim))
        ? month + '-' + String(day).padStart(2, '0') : null;
      if (day >= 1 && day <= dim) {
        ymd = month + '-' + String(day).padStart(2, '0');
        dateCell.textContent = ymd;
        var c = perDate[ymd] || 0;
        var cap = isSpecial(ymd) ? 2 : 1;
        if (c >= cap) errs.push(ymd + ' penuh (maks ' + cap + ')');
        else { peranCell.textContent = c === 0 ? 'utama' : 'pendamping'; perDate[ymd] = c + 1; }
      } else { dateCell.textContent = '-'; peranCell.textContent = ''; }
      // duplikat tanggal+personel
      stCell.innerHTML = errs.length
        ? '<span class="badge text-bg-danger">perlu betulkan</span> ' + errs.join('; ')
        : '<span class="badge text-bg-success">ok</span>';
    });
    // cek duplikat
    var seen = {};
    rows.forEach(function (tr) {
      var uid = tr.querySelector('.pv-user').value;
      var dt = tr.querySelector('.pv-date').textContent;
      if (uid && dt && dt !== '-') {
        var k = dt + '#' + uid;
        if (seen[k]) {
          var st = tr.querySelector('.pv-status');
          st.innerHTML = '<span class="badge text-bg-danger">perlu betulkan</span> duplikat tanggal+personel';
        }
        seen[k] = true;
      }
    });
    var badge = document.getElementById('pvCount');
    if (badge) badge.textContent = document.querySelectorAll('#pvBody tr').length;
  }

  document.getElementById('pvForm').addEventListener('submit', function (ev) {
    refreshPreview();
    var bad = document.querySelectorAll('#pvBody .pv-status .text-bg-danger').length;
    var n = document.querySelectorAll('#pvBody tr').length;
    if (!n) { alert('Preview kosong.'); ev.preventDefault(); return; }
    if (bad) { alert('Masih ada ' + bad + ' baris perlu dibetulkan (merah).'); ev.preventDefault(); return; }
    var month = document.getElementById('pvMonth').value;
    var perDate = {};
    var out = [];
    var ok = true;
    document.querySelectorAll('#pvBody tr').forEach(function (tr) {
      var uid = parseInt(tr.querySelector('.pv-user').value, 10);
      var day = parseInt(tr.querySelector('.pv-day').value, 10);
      var ymd = month + '-' + String(day).padStart(2, '0');
      var c = perDate[ymd] || 0;
      out.push({ user_id: uid, tanggal: ymd, peran: c === 0 ? 'utama' : 'pendamping' });
      perDate[ymd] = c + 1;
    });
    if (!ok) { ev.preventDefault(); return; }
    if (!confirm('Simpan ' + out.length + ' jadwal bulan ' + month + '? Tanggal yang diimport akan ditimpa.')) { ev.preventDefault(); return; }
    document.getElementById('pvMonthHidden').value = month;
    document.getElementById('pvSumberHidden').value = document.getElementById('pvSumber').value;
    document.getElementById('pvRows').value = JSON.stringify(out);
  });

  // ---------- OCR PNG ----------
  var pngFile = document.getElementById('pngFile');
  pngFile.addEventListener('change', function () {
    var f = pngFile.files[0];
    if (!f) return;
    var img = document.getElementById('pngPreview');
    img.src = URL.createObjectURL(f);
    img.classList.remove('d-none');
  });
  function cleanTok(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim(); }
  function findUser(text) {
    var n = cleanTok(text);
    if (!n) return null;
    var hit = null;
    pool.forEach(function (p) {
      if (cleanTok(p.name) === n || cleanTok(p.username) === n) hit = p;
    });
    if (hit) return hit;
    pool.forEach(function (p) {
      if (hit) return;
      var cn = cleanTok(p.name);
      if (cn && (cn.indexOf(n) !== -1 || n.indexOf(cn) !== -1)) hit = p;
    });
    if (hit) return hit;
    // cocok berdasar kata: butuh minimal 2 kata nama yang terbaca (hindari salah pasang)
    var best = null, bestScore = 0;
    pool.forEach(function (p) {
      var words = cleanTok(p.name).split(' ').filter(function (w) { return w.length > 2; });
      var sc = 0;
      words.forEach(function (w) { if (n.indexOf(w) !== -1) sc++; });
      if (sc > bestScore) { bestScore = sc; best = p; }
    });
    return bestScore >= 2 ? best : null;
  }
  // Perbesar + pertajam foto sebelum OCR (teks kecil jauh lebih mudah terbaca)
  function preprocessImage(file) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        try {
          var scale = Math.max(1, 2000 / img.width);
          var cv = document.createElement('canvas');
          cv.width = Math.round(img.width * scale);
          cv.height = Math.round(img.height * scale);
          var ctx = cv.getContext('2d');
          ctx.fillStyle = '#fff';
          ctx.fillRect(0, 0, cv.width, cv.height);
          ctx.drawImage(img, 0, 0, cv.width, cv.height);
          var id = ctx.getImageData(0, 0, cv.width, cv.height), d = id.data;
          for (var i = 0; i < d.length; i += 4) {
            var g = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
            var v = g > 150 ? 255 : (g < 110 ? 0 : Math.round((g - 110) / 40 * 255));
            d[i] = d[i + 1] = d[i + 2] = v;
          }
          ctx.putImageData(id, 0, 0);
          resolve(cv);
        } catch (e) { reject(e); }
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('File gambar tidak bisa dibaca.')); };
      img.src = url;
    });
  }
  // Parser fleksibel: angka boleh di depan/belakang nama ("5 Budi", "Budi 5", "05/10 Budi"),
  // nama tanpa angka digabung dengan angka di baris sebelahnya (hasil OCR kolom terpisah).
  var skipPat = /^(tanggal|tgl|nama|jadwal|oncall|hari|bulan|daftar|shift|petugas|keterangan|unit|divisi|no\.?|minggu|senin|selasa|rabu|kamis|jumat|sabtu)$/i;
  function parseOcrLines(text) {
    var rows = [];
    var pending = null;
    text.split('\n').forEach(function (raw) {
      var ln = String(raw || '').replace(/[|_—–]/g, ' ').replace(/\s+/g, ' ').trim();
      if (!ln || ln.length < 2) return;
      var day = null, namePart = ln;
      var md = ln.match(/\b(\d{1,2})[\/\-.](\d{1,2})(?:[\/\-.]\d{2,4})?\b/); // 05/10, 5-10-2026
      if (md && +md[1] >= 1 && +md[1] <= 31) {
        day = +md[1];
        namePart = ln.replace(md[0], ' ');
      } else {
        var nums = ln.match(/\b\d{1,2}\b/g) || [];
        if (nums.length === 1) {
          var n = +nums[0];
          if (n >= 1 && n <= 31) {
            day = n;
            namePart = ln.replace(/\b\d{1,2}\b/, ' ');
          } else return; // angka di luar 1-31 (mis. tahun) → abaikan baris
        } else if (nums.length > 1) return; // deretan angka tak jelas → abaikan
      }
      namePart = namePart.replace(/[\s:;.,\-()]+/g, ' ').trim();
      var letters = namePart.replace(/[^a-zA-Z]/g, '');
      if (day !== null) {
        if (letters.length >= 2 && !skipPat.test(namePart)) {
          rows.push({ nama: namePart, day: day });
          pending = null;
        } else if (pending) {
          rows.push({ nama: pending, day: day });
          pending = null;
        }
        // angka tanpa nama → abaikan
      } else if (letters.length >= 3 && !skipPat.test(namePart)) {
        pending = namePart; // nama tanpa angka: tunggu angka di baris berikut
      }
    });
    return rows;
  }
  function gotoPreviewTab() {
    var tabBtn = document.getElementById('tabbtn-preview');
    if (tabBtn && window.bootstrap && bootstrap.Tab) bootstrap.Tab.getOrCreateInstance(tabBtn).show();
  }
  var lastOcrText = '';
  document.getElementById('btnOcr').addEventListener('click', async function () {
    var btn = this;
    var f = pngFile.files[0];
    var st = document.getElementById('ocrStatus');
    var prog = document.getElementById('ocrProg');
    var bar = document.getElementById('ocrProgBar');
    var rawBtn = document.getElementById('btnOcrRaw');
    if (!f) { alert('Pilih file PNG/JPG dulu.'); return; }
    if (typeof Tesseract === 'undefined') { st.textContent = 'Gagal memuat Tesseract.js — periksa koneksi internet lalu muat ulang halaman.'; return; }
    // Samakan bulan preview dengan Bulan Target di panel PNG
    var pm = document.getElementById('pngMonth').value;
    if (/^\d{4}-\d{2}$/.test(pm)) {
      document.getElementById('pvMonth').value = pm;
      document.getElementById('pvMonth').dispatchEvent(new Event('change'));
    }
    btn.disabled = true;
    rawBtn.classList.add('d-none');
    prog.classList.remove('d-none');
    bar.style.width = '2%';
    st.textContent = 'Menyiapkan gambar…';
    try {
      var cv = await preprocessImage(f);
      st.textContent = 'Membaca gambar… (pertama kali unduh model bahasa, bisa 30–60 detik)';
      var res = null, lastErr = null;
      var langs = ['ind+eng', 'eng']; // fallback ke English bila model Indonesia gagal diunduh
      for (var li = 0; li < langs.length && !res; li++) {
        try {
          res = await Tesseract.recognize(cv, langs[li], {
            logger: function (m) {
              if (m.status === 'recognizing text') {
                bar.style.width = Math.round((m.progress || 0) * 100) + '%';
              } else {
                st.textContent = 'OCR: ' + m.status + '…';
              }
            }
          });
        } catch (e) { lastErr = e; }
      }
      if (!res) throw lastErr || new Error('OCR gagal.');
      bar.style.width = '100%';
      var text = (res.data && res.data.text) || '';
      lastOcrText = text;
      document.getElementById('ocrRaw').textContent = text || '(tidak ada teks terbaca)';
      document.getElementById('pvSumber').value = 'import_png';
      document.getElementById('pvSumber').dispatchEvent(new Event('change'));
      var parsed = parseOcrLines(text);
      parsed.forEach(function (r) {
        var u = findUser(r.nama);
        pvAddRow(r.nama, r.day, u ? u.id : '');
      });
      var totalLines = text.split('\n').filter(function (l) { return l.trim().length >= 2; }).length;
      if (parsed.length) {
        st.textContent = 'OCR selesai: ' + parsed.length + ' dari ~' + totalLines + ' baris terbaca otomatis. WAJIB periksa & betulkan di tab Preview sebelum Simpan.';
      } else {
        st.textContent = 'OCR selesai (' + totalLines + ' baris teks) tapi pola Nama+nomor tidak dikenali. Klik tombol di bawah untuk memasukkan semua baris mentah lalu betulkan manual.';
      }
      if (parsed.length < totalLines) rawBtn.classList.remove('d-none');
      gotoPreviewTab();
    } catch (e) {
      st.textContent = 'OCR gagal: ' + (e.message || e) + ' — pastikan internet aktif (model bahasa diunduh dari CDN), lalu coba lagi.';
    } finally {
      btn.disabled = false;
      setTimeout(function () { prog.classList.add('d-none'); }, 800);
    }
  });
  // Fallback: tiap baris mentah jadi 1 baris preview (tebak nomor pertama 1-31 sebagai tanggal)
  document.getElementById('btnOcrRaw').addEventListener('click', function () {
    if (!lastOcrText) return;
    document.getElementById('pvSumber').value = 'import_png';
    document.getElementById('pvSumber').dispatchEvent(new Event('change'));
    var n = 0;
    lastOcrText.split('\n').forEach(function (raw) {
      var ln = String(raw || '').replace(/[|_—–]/g, ' ').replace(/\s+/g, ' ').trim();
      if (!ln || ln.length < 2 || skipPat.test(ln)) return;
      var m = ln.match(/\b(\d{1,2})\b/);
      var day = (m && +m[1] >= 1 && +m[1] <= 31) ? +m[1] : '';
      var nama = ln.replace(/\b\d{1,2}\b/g, ' ').replace(/[\s:;.,\-()]+/g, ' ').trim() || ln;
      var u = findUser(nama);
      pvAddRow(nama, day, u ? u.id : '');
      n++;
    });
    document.getElementById('ocrStatus').textContent = n + ' baris mentah dimasukkan ke Preview — pilih personel & betulkan nomornya, lalu Simpan.';
    gotoPreviewTab();
  });

  refreshPreview();
})();
</script>
</div><!-- /tab-rotasi -->
</div><!-- /tab-content -->
<?php endif; ?>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
