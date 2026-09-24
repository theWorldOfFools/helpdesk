<?php
require_once __DIR__ . '/includes/env.php';
loadDotEnv(__DIR__ . '/.env');

if (!defined('DB_HOST')) define('DB_HOST', env('DB_HOST', 'localhost'));
if (!defined('DB_PORT')) define('DB_PORT', env('DB_PORT', '5432'));
if (!defined('DB_NAME')) define('DB_NAME', env('DB_NAME', 'helpdesk_db'));
if (!defined('DB_USER')) define('DB_USER', env('DB_USER', 'tsany'));
if (!defined('DB_PASS')) define('DB_PASS', env('DB_PASS', 'ryzen2004'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/includes/csrf.php';

// Auto-login remember-me sentral: berlaku di SEMUA halaman (sekali per session).
function tryRememberLogin()
{
    if (!empty($_SESSION['user_id']) || empty($_COOKIE['hd_remember'])) return;
    if (!empty($_SESSION['remember_tried'])) return;
    $_SESSION['remember_tried'] = true;
    $tok = $_COOKIE['hd_remember'];
    if (!preg_match('/^[a-f0-9]{64}$/', $tok)) return;
    try {
        $conn = getDBConnection();
        $r = pg_query_params($conn, "SELECT * FROM users WHERE remember_token = $1 AND is_active = TRUE", [hash('sha256', $tok)]);
        if ($r && pg_num_rows($r) > 0) {
            $u = pg_fetch_assoc($r);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $u['id'];
            $_SESSION['role'] = $u['role'];
            $_SESSION['username'] = $u['username'];
            $_SESSION['name'] = $u['name'];
            unset($_SESSION['remember_tried']);
            logActivity($conn, $u['id'], 'login_cookie', 'Auto-login remember-me');
        }
        if ($r) pg_free_result($r);
        pg_close($conn);
    } catch (Throwable $e) {
        // abaikan: user tetap dianggap belum login
    }
}
tryRememberLogin();

function getDBConnection()
{
    // FORCE_NEW: tiap handle koneksi independen. Tanpa ini, pg_connect() dengan
    // string yang sama me-reuse koneksi lama, sehingga pg_close() di
    // getCurrentUser() ikut menutup koneksi milik halaman (fatal "already been closed").
    $conn = pg_connect(
        "host=" . DB_HOST .
        " port=" . DB_PORT .
        " dbname=" . DB_NAME .
        " user=" . DB_USER .
        " password=" . DB_PASS,
        PGSQL_CONNECT_FORCE_NEW
    );

    if (!$conn) {
        die("Koneksi database gagal: " . pg_last_error());
    }

    return $conn;
}

function generateTicketNumber($existingConn = null)
{
    // Aman dari race: kunci tabel saat baca MAX lalu generate.
    $own = $existingConn === null;
    $conn = $own ? getDBConnection() : $existingConn;
    if ($own) {
        pg_query($conn, 'BEGIN');
        pg_query($conn, 'LOCK TABLE tickets IN SHARE ROW EXCLUSIVE MODE');
    }
    $result = pg_query($conn, "SELECT TO_CHAR(NOW(), 'YYYYMM') || '-' || LPAD(COALESCE(MAX(CAST(SUBSTRING(ticket_number FROM '[0-9]+$') AS INTEGER)) + 1, 1)::TEXT, 4, '0') FROM tickets WHERE ticket_number LIKE TO_CHAR(NOW(), 'YYYYMM') || '-%'");
    $ticketNumber = $result ? pg_fetch_result($result, 0, 0) : null;
    if ($result) pg_free_result($result);
    if (!$ticketNumber) {
        $ticketNumber = date('Ym') . '-0001';
    }
    if ($own) {
        pg_query($conn, 'COMMIT');
        pg_close($conn);
    }
    return $ticketNumber;
}

function hashPassword($password)
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

function getCurrentUser()
{
    if (isset($_SESSION['user_id'])) {
        $conn = getDBConnection();
        $id = (int)$_SESSION['user_id'];
        $result = pg_query_params($conn, "SELECT * FROM users WHERE id = $1 AND is_active = TRUE", [$id]);
        if ($result && pg_num_rows($result) > 0) {
            $user = pg_fetch_assoc($result);
            pg_free_result($result);
            pg_close($conn);
            return $user;
        }
        pg_free_result($result);
        pg_close($conn);
    }
    return null;
}

function isLoggedIn()
{
    return getCurrentUser() !== null;
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function getHomeUrlByRole($role)
{
    switch ($role) {
        case 'admin':
            return 'dashboard.php';
        case 'teknisi':
            return 'index.php';
        case 'pelapor':
            return 'create_ticket.php';
        default:
            return 'index.php';
    }
}

function redirectByRole($role)
{
    header('Location: ' . getHomeUrlByRole($role));
    exit;
}

function requireRole($allowedRoles)
{
    requireLogin();
    $user = getCurrentUser();
    if (!in_array($user['role'], $allowedRoles)) {
        redirectByRole($user['role']);
    }
    return $user;
}

function canEditTicket($ticket, $user)
{
    // Keputusan: Edit data = admin only. Teknisi via tombol Tindak Lanjut.
    return ($user['role'] ?? null) === 'admin';
}

function canActOnTicket($ticket, $user)
{
    // Tindak lanjut operasional: admin + teknisi (fleksibel), pelapor hanya komentar di tiket own
    $role = $user['role'] ?? null;
    if ($role === 'admin' || $role === 'teknisi') return true;
    if ($role === 'pelapor' && (int)$ticket['user_id'] === (int)$user['id']) return true;
    return false;
}

function canCommentOnTicket($ticket, $user)
{
    return canActOnTicket($ticket, $user);
}

function canDeleteTicket($ticket, $user)
{
    return $user['role'] === 'admin';
}

function canViewTicket($ticket, $user)
{
    if ($user['role'] === 'admin' || $user['role'] === 'teknisi') return true;
    if ($user['role'] === 'pelapor' && (int)$ticket['user_id'] === (int)$user['id']) return true;
    return false;
}

// Sanitasi HTML dari rich-text editor: hanya tag aman yang dipertahankan,
// event handler (on*) dan javascript: URL dibuang.
function sanitizeRichText($html)
{
    if ($html === null || $html === '') return '';
    $allowed = '<p><br><strong><b><em><i><u><s><ul><ol><li><a><blockquote><code><pre><h2><h3><h4><hr><span><div><table><thead><tbody><tr><th><td>';
    $html = strip_tags($html, $allowed);
    // Buang event handler seperti onclick=..., onerror=...
    $html = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    // Buang javascript:/data: URL pada href/src
    $html = preg_replace('/\s*(href|src)\s*=\s*("|\')\s*(javascript|data)\s*:.*?\2/i', ' $1="#"', $html);
    return trim($html);
}

// Render deskripsi tiket: dukung HTML kaya (baru) dan teks polos (lama).
function renderTicketDescription($raw)
{
    $raw = (string)($raw ?? '');
    if ($raw === '') return '<span style="color:#999;">Tidak ada deskripsi.</span>';
    if (strip_tags($raw) !== $raw) {
        $clean = sanitizeRichText($raw);
        return $clean !== '' ? $clean : '<span style="color:#999;">Tidak ada deskripsi.</span>';
    }
    return nl2br(htmlspecialchars($raw));
}

// Upload lampiran tiket. Return [pathRelatif, namaAsli] atau [null, null] bila tidak ada file.
// $error diisi pesan kesalahan bila upload gagal.
function handleTicketUpload($field = 'attachment', &$error = null)
{
    $error = null;
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return [null, null];
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        $error = 'Upload lampiran gagal (kode ' . (int)$f['error'] . ').';
        return [false, false];
    }

    $maxBytes = 5 * 1024 * 1024; // 5MB
    if (($f['size'] ?? 0) > $maxBytes) {
        $error = 'Ukuran lampiran maksimal 5MB.';
        return [false, false];
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip', 'rar'];
    $original = basename($f['name'] ?? 'file');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        $error = 'Jenis file tidak diizinkan. Boleh: ' . implode(', ', $allowedExt) . '.';
        return [false, false];
    }

    $dir = __DIR__ . '/uploads/tickets';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        $error = 'Folder upload tidak bisa dibuat.';
        return [false, false];
    }

    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($original, PATHINFO_FILENAME));
    $safe = substr($safe !== '' ? $safe : 'lampiran', 0, 60);
    $stored = date('Ym') . '_' . bin2hex(random_bytes(6)) . '_' . $safe . '.' . $ext;
    $dest = $dir . '/' . $stored;

    // Validasi tambahan untuk gambar
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $img = @getimagesize($f['tmp_name']);
        if ($img === false) {
            $error = 'File gambar tidak valid.';
            return [false, false];
        }
    }

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        $error = 'Gagal menyimpan lampiran.';
        return [false, false];
    }
    @chmod($dest, 0644);
    return ['uploads/tickets/' . $stored, $original];
}

// Shortcut escape HTML
function e($v)
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// Catat aktivitas ke activity_log (tabel boleh belum ada → abaikan diam-diam)
function logActivity($conn, $userId, $aksi, $detail = null)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    @pg_query_params($conn, "INSERT INTO activity_log (user_id, aksi, detail, ip) VALUES ($1,$2,$3,$4)", [$userId, $aksi, $detail, $ip]);
}

// Tolak method selain POST (untuk aksi destruktif)
function requirePost()
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Location: index.php');
        exit;
    }
}

function jsonResponse($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Pengaturan (cache per-request) — target SLA bisa diubah admin tanpa coding.
function appSetting($key, $default = null, $conn = null)
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $val = $default;
    try {
        $own = $conn === null;
        $c = $own ? getDBConnection() : $conn;
        $r = @pg_query_params($c, "SELECT nilai FROM settings WHERE kunci = $1", [$key]);
        if ($r && pg_num_rows($r) > 0) $val = pg_fetch_result($r, 0, 0);
        if ($r) pg_free_result($r);
        if ($own) pg_close($c);
    } catch (Throwable $e) {
        // abaikan: pakai default
    }
    $cache[$key] = $val;
    return $val;
}

// SLA jam kerja: Senin-Sabtu 08:00-17:00, Minggu + tabel holidays libur.
// Target per prioritas dibaca dari settings (jam kerja).
function slaTargetHours($priority)
{
    $map = ['critical' => 'sla_critical_hours', 'high' => 'sla_high_hours', 'medium' => 'sla_medium_hours', 'low' => 'sla_low_hours'];
    $k = $map[strtolower((string)$priority)] ?? 'sla_medium_hours';
    $v = (int)appSetting($k, 72);
    return $v > 0 ? $v : 72;
}

function slaResponseLimit()
{
    $v = (int)appSetting('sla_response_minutes', 60);
    return $v > 0 ? $v : 60;
}

function slaResponseTarget()
{
    $v = (float)appSetting('sla_response_target', 100);
    return ($v >= 0 && $v <= 100) ? $v : 100;
}

// Notifikasi in-app (abaikan diam-diam bila tabel belum ada)
function notifyUser($conn, $userId, $judul, $isi = null, $link = null)
{
    if ((int)$userId <= 0) return;
    @pg_query_params($conn, "INSERT INTO notifications (user_id, judul, isi, link) VALUES ($1,$2,$3,$4)", [(int)$userId, $judul, $isi, $link]);
}

function unreadNotifCount($conn, $userId)
{
    $r = @pg_query_params($conn, "SELECT COUNT(*) FROM notifications WHERE user_id = $1 AND is_read = FALSE", [(int)$userId]);
    $n = $r ? (int)pg_fetch_result($r, 0, 0) : 0;
    if ($r) pg_free_result($r);
    return $n;
}

function getHolidays($conn = null)
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $own = $conn === null;
        $c = $own ? getDBConnection() : $conn;
        $r = @pg_query($c, "SELECT tanggal::text FROM holidays");
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $cache[substr($row['tanggal'], 0, 10)] = true;
            pg_free_result($r);
        }
        if ($own) pg_close($c);
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

function isWorkingDay($dateYmd, $holidays = null)
{
    if ($holidays === null) $holidays = getHolidays();
    $dow = (int)date('w', strtotime($dateYmd)); // 0 = Minggu
    if ($dow === 0) return false;
    return !isset($holidays[$dateYmd]);
}

// Geser ke awal jam kerja berikutnya bila di luar jam/libur (mirror next_working_start()).
function nextWorkingStart($ts, $holidays = null)
{
    if (!is_int($ts)) $ts = strtotime((string)$ts);
    if ($holidays === null) $holidays = getHolidays();
    $guard = 0;
    while ($guard++ < 30) {
        $ymd = date('Y-m-d', $ts);
        $hm = date('H:i', $ts);
        if (!isWorkingDay($ymd, $holidays)) {
            $ts = strtotime($ymd . ' +1 day 08:00');
            continue;
        }
        if ($hm < '08:00') {
            $ts = strtotime($ymd . ' 08:00');
            break;
        }
        if ($hm >= '17:00') {
            $ts = strtotime($ymd . ' +1 day 08:00');
            continue;
        }
        break;
    }
    return $ts;
}

// Tambah N jam kerja ke timestamp (mirror logika working_minutes).
function workingAddHours($from, $hours, $holidays = null)
{
    if (!is_int($from)) $from = strtotime((string)$from);
    if ($holidays === null) $holidays = getHolidays();
    $ts = nextWorkingStart($from, $holidays);
    $remain = (float)$hours * 3600;
    $guard = 0;
    while ($remain > 0 && $guard++ < 500) {
        $ymd = date('Y-m-d', $ts);
        if (!isWorkingDay($ymd, $holidays)) {
            $ts = strtotime($ymd . ' +1 day 08:00');
            continue;
        }
        $endOfDay = strtotime($ymd . ' 17:00');
        $avail = $endOfDay - $ts;
        if ($avail <= 0) {
            $ts = strtotime($ymd . ' +1 day 08:00');
            continue;
        }
        if ($remain <= $avail) {
            $ts += (int)$remain;
            $remain = 0;
        } else {
            $remain -= $avail;
            $ts = strtotime($ymd . ' +1 day 08:00');
        }
    }
    return date('Y-m-d H:i:s', $ts);
}

// Menit kerja antara dua waktu (mirror working_minutes()).
function workingMinutesBetween($from, $to, $holidays = null)
{
    $f = is_int($from) ? $from : strtotime((string)$from);
    $t = is_int($to) ? $to : strtotime((string)$to);
    if ($t <= $f) return 0;
    if ($holidays === null) $holidays = getHolidays();
    $total = 0;
    $day = strtotime(date('Y-m-d 00:00', $f));
    $guard = 0;
    while ($day <= $t && $guard++ < 500) {
        $ymd = date('Y-m-d', $day);
        if (isWorkingDay($ymd, $holidays)) {
            $ws = strtotime($ymd . ' 08:00');
            $we = strtotime($ymd . ' 17:00');
            if ($f < $we && $t > $ws) {
                $total += min($t, $we) - max($f, $ws);
            }
        }
        $day = strtotime($ymd . ' +1 day 00:00');
    }
    return (int)round($total / 60);
}

function fmtWorkingDuration($minutes)
{
    $m = (int)$minutes;
    if ($m < 60) return $m . ' mnt';
    $h = intdiv($m, 60);
    $r = $m % 60;
    if ($h < 24) return $h . ' jam' . ($r ? ' ' . $r . ' mnt' : '');
    $d = intdiv($h, 9); // hari kerja @9 jam, pembulatan info
    return $d . ' hk (' . $h . ' jam)';
}

// SLA resolusi kini dalam JAM KERJA (nama fungsi dipertahankan agar create_ticket.php tak berubah).
function slaDueForPriority($priority, $from = null)
{
    $h = slaTargetHours($priority);
    $ts = $from ? strtotime($from) : time();
    return workingAddHours($ts, $h);
}
