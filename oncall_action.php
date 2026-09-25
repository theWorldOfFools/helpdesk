<?php
// Backend Jadwal Oncall (admin only). Semua aksi via POST + CSRF.
// Aksi: add, update, delete, save_import, rotasi. Sukses/gagal via flash + redirect ke oncall.php.
require_once 'config.php';
requireLogin();
requirePost();
requireCsrf();

$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    $_SESSION['flash_error'] = 'Hanya administrator yang boleh mengelola jadwal oncall.';
    header('Location: oncall.php');
    exit;
}

function oncallRedirect($msg, $type = 'success')
{
    if ($type === 'success') {
        $_SESSION['flash_ok'] = $msg;
    } else {
        $_SESSION['flash_error'] = $msg;
    }
    $month = trim($_POST['month'] ?? $_GET['month'] ?? '');
    $to = 'oncall.php' . (preg_match('/^\d{4}-\d{2}$/', $month) ? '?month=' . $month : '');
    header('Location: ' . $to);
    exit;
}

// Pool personel yang sah: admin/teknisi aktif.
function oncallPoolIds($conn)
{
    $ids = [];
    $r = pg_query($conn, "SELECT id FROM users WHERE is_active = TRUE AND role IN ('admin','teknisi')");
    if ($r) {
        while ($row = pg_fetch_assoc($r)) $ids[(int)$row['id']] = true;
        pg_free_result($r);
    }
    return $ids;
}

// True bila tanggal boleh diisi 2 orang (Minggu / libur). Kapasitas: spesial=2, biasa=1.
function oncallIsSpecial($ymd)
{
    return !isWorkingDay($ymd);
}

function oncallCap($ymd)
{
    return oncallIsSpecial($ymd) ? 2 : 1;
}

$conn = getDBConnection();
$action = trim($_POST['action'] ?? '');
$month = trim($_POST['month'] ?? '');
$monthOk = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');

// ---------- TAMBAH manual: 1 baris per submit, peran otomatis (kosong=utama, terisi=pendamping) ----------
if ($action === 'add') {
    $tgl = trim($_POST['tanggal'] ?? '');
    $uid = (int)($_POST['user_id'] ?? 0);
    $cat = trim($_POST['catatan'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || $uid <= 0) {
        oncallRedirect('Tanggal dan personel wajib diisi.', 'danger');
    }
    $pool = oncallPoolIds($conn);
    if (!isset($pool[$uid])) {
        oncallRedirect('Personel tidak valid (harus admin/teknisi aktif).', 'danger');
    }
    $cnt = (int)pg_fetch_result(pg_query_params($conn, "SELECT COUNT(*) FROM oncall_schedules WHERE tanggal = $1", [$tgl]), 0, 0);
    if ($cnt >= oncallCap($tgl)) {
        oncallRedirect("Tanggal $tgl sudah penuh (" . ($cnt) . " orang). Hari biasa maks 1, Minggu/libur maks 2.", 'danger');
    }
    $peran = $cnt === 0 ? 'utama' : 'pendamping';
    $ins = @pg_query_params(
        $conn,
        "INSERT INTO oncall_schedules (tanggal, user_id, peran, sumber, catatan, created_by) VALUES ($1,$2,$3,'manual',$4,$5)",
        [$tgl, $uid, $peran, ($cat !== '' ? $cat : null), $user['id']]
    );
    if ($ins) {
        pg_free_result($ins);
        logActivity($conn, $user['id'], 'oncall_add', "Tambah oncall $tgl user_id=$uid ($peran)");
        pg_close($conn);
        oncallRedirect("Jadwal $tgl berhasil ditambahkan ($peran).");
    }
    $err = pg_last_error($conn);
    pg_close($conn);
    oncallRedirect(stripos($err, 'duplicate') !== false || stripos($err, 'unique') !== false
        ? 'Personel tersebut sudah terjadwal di tanggal itu.'
        : 'Gagal menambah jadwal: ' . $err, 'danger');
}

// ---------- GANTI personel satu baris ----------
if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($id <= 0 || $uid <= 0) {
        oncallRedirect('Data tidak lengkap.', 'danger');
    }
    $pool = oncallPoolIds($conn);
    if (!isset($pool[$uid])) {
        oncallRedirect('Personel tidak valid (harus admin/teknisi aktif).', 'danger');
    }
    $cur = pg_query_params($conn, "SELECT tanggal FROM oncall_schedules WHERE id = $1", [$id]);
    if (!$cur || pg_num_rows($cur) === 0) {
        if ($cur) pg_free_result($cur);
        pg_close($conn);
        oncallRedirect('Jadwal tidak ditemukan.', 'danger');
    }
    $tgl = pg_fetch_result($cur, 0, 0);
    pg_free_result($cur);
    $up = @pg_query_params(
        $conn,
        "UPDATE oncall_schedules SET user_id = $1, updated_at = NOW() WHERE id = $2",
        [$uid, $id]
    );
    if ($up) {
        pg_free_result($up);
        logActivity($conn, $user['id'], 'oncall_update', "Ubah oncall id=$id ($tgl) user_id=$uid");
        pg_close($conn);
        oncallRedirect("Jadwal $tgl berhasil diperbarui.");
    }
    $err = pg_last_error($conn);
    pg_close($conn);
    oncallRedirect(stripos($err, 'duplicate') !== false || stripos($err, 'unique') !== false
        ? 'Personel tersebut sudah terjadwal di tanggal itu.'
        : 'Gagal memperbarui jadwal: ' . $err, 'danger');
}

// ---------- HAPUS satu baris ----------
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        oncallRedirect('ID tidak valid.', 'danger');
    }
    $cur = pg_query_params($conn, "SELECT tanggal FROM oncall_schedules WHERE id = $1", [$id]);
    $tgl = ($cur && pg_num_rows($cur) > 0) ? pg_fetch_result($cur, 0, 0) : null;
    if ($cur) pg_free_result($cur);
    if ($tgl === null) {
        pg_close($conn);
        oncallRedirect('Jadwal tidak ditemukan.', 'danger');
    }
    pg_query_params($conn, "DELETE FROM oncall_schedules WHERE id = $1", [$id]);
    logActivity($conn, $user['id'], 'oncall_delete', "Hapus oncall id=$id ($tgl)");
    pg_close($conn);
    oncallRedirect("Jadwal $tgl berhasil dihapus.");
}

// ---------- SIMPAN hasil import (JSON dari tabel preview, sudah dikoreksi user) ----------
// rows: [{user_id, tanggal Y-m-d, peran}]  sumber: import_xls | import_png
if ($action === 'save_import') {
    $sumber = trim($_POST['sumber'] ?? 'import_xls');
    if (!in_array($sumber, ['import_xls', 'import_png'], true)) $sumber = 'import_xls';
    $rows = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($rows) || empty($rows)) {
        oncallRedirect('Tidak ada baris import untuk disimpan.', 'danger');
    }
    if (count($rows) > 500) {
        oncallRedirect('Maksimal 500 baris per import.', 'danger');
    }
    $pool = oncallPoolIds($conn);
    $clean = [];
    $seen = [];
    foreach ($rows as $i => $r) {
        $no = $i + 1;
        $uid = (int)($r['user_id'] ?? 0);
        $tgl = trim($r['tanggal'] ?? '');
        $peran = trim($r['peran'] ?? 'utama');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || strtotime($tgl) === false) {
            oncallRedirect("Baris $no: tanggal tidak valid.", 'danger');
        }
        if (substr($tgl, 0, 7) !== $monthOk) {
            oncallRedirect("Baris $no: tanggal $tgl di luar bulan target $monthOk.", 'danger');
        }
        if (!isset($pool[$uid])) {
            oncallRedirect("Baris $no: personel belum dipetakan ke pengguna aktif.", 'danger');
        }
        if (!in_array($peran, ['utama', 'pendamping'], true)) $peran = 'utama';
        $key = $tgl . '#' . $uid;
        if (isset($seen[$key])) {
            oncallRedirect("Baris $no: duplikat (tanggal + personel sama).", 'danger');
        }
        $seen[$key] = true;
        $clean[] = ['tanggal' => $tgl, 'user_id' => $uid, 'peran' => $peran];
    }
    // Validasi kapasitas per tanggal (hitung per tanggal di batch)
    $perDate = [];
    foreach ($clean as $c) $perDate[$c['tanggal']][] = $c;
    foreach ($perDate as $tgl => $list) {
        if (count($list) > oncallCap($tgl)) {
            oncallRedirect("Tanggal $tgl kelebihan (" . count($list) . " orang). Hari biasa maks 1, Minggu/libur maks 2.", 'danger');
        }
    }
    $dates = array_keys($perDate);
    pg_query($conn, 'BEGIN');
    $ok = true;
    // Replace-per-tanggal: hapus isi lama tanggal yang muncul di import
    $in = implode(',', array_map(function ($i) {
        return '$' . ($i + 1);
    }, array_keys($dates)));
    if (!$ok || !pg_query_params($conn, "DELETE FROM oncall_schedules WHERE tanggal IN ($in)", $dates)) $ok = false;
    if ($ok) {
        foreach ($clean as $c) {
            $ins = @pg_query_params(
                $conn,
                "INSERT INTO oncall_schedules (tanggal, user_id, peran, sumber, created_by) VALUES ($1,$2,$3,$4,$5)",
                [$c['tanggal'], $c['user_id'], $c['peran'], $sumber, $user['id']]
            );
            if (!$ins) {
                $ok = false;
                break;
            }
            pg_free_result($ins);
        }
    }
    if ($ok) {
        pg_query($conn, 'COMMIT');
        logActivity($conn, $user['id'], 'oncall_import', "Import $sumber bulan $monthOk: " . count($clean) . " baris");
        pg_close($conn);
        oncallRedirect("Import berhasil: " . count($clean) . " jadwal bulan $monthOk tersimpan.");
    }
    pg_query($conn, 'ROLLBACK');
    $err = pg_last_error($conn);
    pg_close($conn);
    oncallRedirect('Gagal menyimpan import: ' . $err, 'danger');
}

// ---------- ROTASI otomatis sebulan ----------
// order[] = id personel berurutan; start_id opsional; overwrite opsional.
// Hari biasa = 1 berikutnya; Minggu/libur = 2 berikutnya. Tanggal terisi dilewati (pointer tidak maju).
if ($action === 'rotasi') {
    $order = $_POST['order'] ?? [];
    if (!is_array($order)) $order = [$order];
    $order = array_values(array_unique(array_map('intval', $order)));
    $order = array_filter($order, function ($v) {
        return $v > 0;
    });
    $order = array_values($order);
    if (empty($order)) {
        oncallRedirect('Pilih minimal 1 personel untuk rotasi.', 'danger');
    }
    $pool = oncallPoolIds($conn);
    foreach ($order as $oid) {
        if (!isset($pool[$oid])) {
            oncallRedirect('Daftar personel rotasi mengandung pengguna tidak valid.', 'danger');
        }
    }
    $startId = (int)($_POST['start_id'] ?? 0);
    $p = 0;
    if ($startId > 0) {
        $found = array_search($startId, $order, true);
        if ($found !== false) $p = $found;
    }
    $overwrite = isset($_POST['overwrite']);
    $n = ($x = strtotime($monthOk . '-01')) ? (int)date('t', $x) : 0;
    if ($n <= 0) {
        oncallRedirect('Bulan target tidak valid.', 'danger');
    }
    // Peta tanggal terisi bulan ini
    $filled = [];
    $r = pg_query_params(
        $conn,
        "SELECT tanggal, COUNT(*) AS c FROM oncall_schedules WHERE to_char(tanggal,'YYYY-MM') = $1 GROUP BY tanggal",
        [$monthOk]
    );
    if ($r) {
        while ($row = pg_fetch_assoc($r)) $filled[$row['tanggal']] = (int)$row['c'];
        pg_free_result($r);
    }
    $m = count($order);
    $toIns = [];
    for ($d = 1; $d <= $n; $d++) {
        $tgl = sprintf('%s-%02d', $monthOk, $d);
        if (!empty($filled[$tgl]) && !$overwrite) continue; // lewati, pointer tetap
        $need = oncallCap($tgl);
        if ($m === 1) $need = min($need, 1); // 1 orang saja: hindari duplikat unik
        $pick = [];
        for ($k = 0; $k < $need; $k++) {
            $pick[] = $order[($p + $k) % $m];
        }
        // Bila pool < need (mis. 1 orang), pick bisa duplikat → unikkan
        $pick = array_values(array_unique($pick));
        foreach ($pick as $idx => $uid) {
            $toIns[] = ['tanggal' => $tgl, 'user_id' => $uid, 'peran' => $idx === 0 ? 'utama' : 'pendamping'];
        }
        $p = ($p + count($pick)) % $m;
    }
    if (empty($toIns)) {
        pg_close($conn);
        oncallRedirect("Bulan $monthOk sudah terisi semua (centang Timpa bila ingin tulis ulang).", 'danger');
    }
    pg_query($conn, 'BEGIN');
    $ok = true;
    if ($overwrite) {
        $ok = (bool)pg_query_params(
            $conn,
            "DELETE FROM oncall_schedules WHERE to_char(tanggal,'YYYY-MM') = $1",
            [$monthOk]
        );
    } else {
        // Hapus tanggal target yang kebetulan terisi sebagian? Tidak — tanggal terisi dilewati,
        // jadi hanya tanggal kosong yang diisi; tidak perlu DELETE.
    }
    if ($ok) {
        foreach ($toIns as $c) {
            $ins = @pg_query_params(
                $conn,
                "INSERT INTO oncall_schedules (tanggal, user_id, peran, sumber, created_by) VALUES ($1,$2,$3,'rotasi',$4)
                 ON CONFLICT (tanggal, user_id) DO NOTHING",
                [$c['tanggal'], $c['user_id'], $c['peran'], $user['id']]
            );
            if (!$ins) {
                $ok = false;
                break;
            }
            pg_free_result($ins);
        }
    }
    if ($ok) {
        pg_query($conn, 'COMMIT');
        logActivity($conn, $user['id'], 'oncall_rotasi', "Rotasi bulan $monthOk: " . count($toIns) . " baris" . ($overwrite ? ' (timpa)' : ''));
        pg_close($conn);
        oncallRedirect("Rotasi bulan $monthOk berhasil: " . count($toIns) . " penugasan dibuat.");
    }
    pg_query($conn, 'ROLLBACK');
    $err = pg_last_error($conn);
    pg_close($conn);
    oncallRedirect('Gagal membuat rotasi: ' . $err, 'danger');
}

pg_close($conn);
oncallRedirect('Aksi tidak dikenal.', 'danger');
