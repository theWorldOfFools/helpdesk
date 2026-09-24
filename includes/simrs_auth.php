<?php
// Helper Auth via SIMRS (PostgreSQL server sama, DB SIMRS_DB_NAME).
// Sumber diverifikasi: rehashPassword() + disableNTBin() + actionLogin() SIMRS (Yii).
// Prinsip: helpdesk READ-ONLY ke DB SIMRS — hanya SELECT loginpemakai_k/pegawai_m,
// tidak pernah menulis (tanpa login_token/lastlogin/statuslogin seperti SIMRS).
// Butuh konstanta DB_* + fungsi env() dari config.php — jangan panggil sebelum config.php dimuat.

if (!function_exists('simrsAuthEnabled')) {
    /**
     * Flag pemutus: SIMRS dipakai hanya bila SIMRS_AUTH_ENABLED=true di .env.
     * Default off agar perilaku login lama tidak berubah sebelum verifikasi end-to-end.
     */
    function simrsAuthEnabled()
    {
        $v = strtolower(trim((string)env('SIMRS_AUTH_ENABLED', 'false')));
        return in_array($v, ['1', 'true', 'ya', 'on'], true);
    }
}

if (!function_exists('simrsDbConfig')) {
    /**
     * Konfigurasi koneksi SIMRS. Nilai kosong = ikut DB_* helpdesk (satu cluster).
     */
    function simrsDbConfig()
    {
        $str = function ($v) {
            $v = trim((string)$v);
            return $v === '' ? null : $v;
        };
        $host = $str(env('SIMRS_DB_HOST', '')) ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
        $port = $str(env('SIMRS_DB_PORT', '')) ?: (defined('DB_PORT') ? DB_PORT : '5432');
        $name = $str(env('SIMRS_DB_NAME', ''));
        $user = $str(env('SIMRS_DB_USER', '')) ?: (defined('DB_USER') ? DB_USER : null);
        $pass = env('SIMRS_DB_PASS', '');
        if ($pass === null || trim((string)$pass) === '') {
            $pass = (defined('DB_PASS') ? DB_PASS : '');
        }
        return ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass];
    }
}

if (!function_exists('getSimrsConnection')) {
    /**
     * Koneksi independen ke DB SIMRS (read-only secara konvensi: query di file ini SELECT saja).
     * Return resource koneksi atau null bila config belum lengkap / koneksi gagal.
     */
    function getSimrsConnection()
    {
        $c = simrsDbConfig();
        if (empty($c['name']) || empty($c['user'])) return null;
        $conn = @pg_connect(
            "host=" . $c['host'] .
            " port=" . $c['port'] .
            " dbname=" . $c['name'] .
            " user=" . $c['user'] .
            " password=" . $c['pass'] .
            " connect_timeout=5",
            PGSQL_CONNECT_FORCE_NEW
        );
        return $conn ?: null;
    }
}

if (!function_exists('simrsDisableNTBin')) {
    /**
     * Replika 1:1 disableNTBin() SIMRS: byte 0x00 pada hasil hash_hmac diganti 0x88.
     * Tanpa ini password_hash/verify bisa gagal untuk sebagian password.
     */
    function simrsDisableNTBin($binary)
    {
        $hex = bin2hex($binary);
        $hex = str_replace("00", "88", $hex);
        $r = hex2bin($hex);
        return $r === false ? $binary : $r;
    }
}

if (!function_exists('simrsBuildPass')) {
    /**
     * Replika transformasi pre-hash SIMRS: HMAC-SHA256(password & nama_pemakai, seckey) + disableNTBin.
     * $seckey = Yii::app()->params->seckey SIMRS (via SIMRS_SECKEY di .env, tidak di-commit).
     */
    function simrsBuildPass($password, $namaPemakai, $seckey)
    {
        $raw = hash_hmac("sha256", $password . "&" . $namaPemakai, $seckey, true);
        return simrsDisableNTBin($raw);
    }
}

if (!function_exists('simrsVerifyPassword')) {
    /**
     * Verifikasi password SIMRS: password_verify(preHash, base64_decode(katakunci_pemakai)).
     * katakunci_pemakai = base64_encode(password_hash(preHash, PASSWORD_DEFAULT, cost 12)).
     */
    function simrsVerifyPassword($password, $namaPemakai, $katakunciPemakai)
    {
        $seckey = env('SIMRS_SECKEY', '');
        if ($seckey === null || trim((string)$seckey) === '') return false;
        if (!is_string($katakunciPemakai) || $katakunciPemakai === '') return false;
        $stored = base64_decode($katakunciPemakai, true);
        if ($stored === false || $stored === '') return false;
        $pass = simrsBuildPass((string)$password, (string)$namaPemakai, $seckey);
        return password_verify($pass, $stored);
    }
}

if (!function_exists('simrsFindUser')) {
    /**
     * Ambil user SIMRS by nama_pemakai + profil pegawai (nama, NIP, jabatan, ruangan).
     * Aktif = loginpemakai_aktif TRUE (dan pegawai_aktif TRUE bila terikat pegawai).
     * Return array assoc atau null bila tidak ketemu.
     */
    function simrsFindUser($simrsConn, $username)
    {
        $username = trim((string)$username);
        if ($username === '' || !$simrsConn) return null;
        $sql = "SELECT l.loginpemakai_id, l.nama_pemakai, l.katakunci_pemakai, l.loginpemakai_aktif,
                       p.pegawai_id, p.nama_pegawai, p.nomorindukpegawai, p.pegawai_aktif,
                       j.jabatan_nama,
                       (SELECT r.ruangan_nama FROM ruanganpegawai_m rp
                         JOIN ruangan_m r ON r.ruangan_id = rp.ruangan_id
                        WHERE rp.pegawai_id = p.pegawai_id
                        ORDER BY r.ruangan_nama ASC LIMIT 1) AS ruangan_nama
                  FROM loginpemakai_k l
             LEFT JOIN pegawai_m p ON p.pegawai_id = l.pegawai_id
             LEFT JOIN jabatan_m j ON j.jabatan_id = p.jabatan_id
                 WHERE l.nama_pemakai = $1 LIMIT 1";
        $r = @pg_query_params($simrsConn, $sql, [$username]);
        if (!$r || pg_num_rows($r) === 0) {
            if ($r) pg_free_result($r);
            return null;
        }
        $row = pg_fetch_assoc($r);
        pg_free_result($r);
        return $row;
    }
}

if (!function_exists('simrsIsActive')) {
    /**
     * User SIMRS dianggap aktif bila loginpemakai_aktif TRUE
     * dan (tidak terikat pegawai ATAU pegawai_aktif TRUE).
     */
    function simrsIsActive($simrsRow)
    {
        if (!$simrsRow) return false;
        $loginAktif = ($simrsRow['loginpemakai_aktif'] === 't' || $simrsRow['loginpemakai_aktif'] == 1 || $simrsRow['loginpemakai_aktif'] === true);
        if (!$loginAktif) return false;
        if (array_key_exists('pegawai_id', $simrsRow) && $simrsRow['pegawai_id'] !== null && $simrsRow['pegawai_id'] !== '') {
            $pAktif = $simrsRow['pegawai_aktif'] ?? null;
            if (!($pAktif === 't' || $pAktif == 1 || $pAktif === true)) return false;
        }
        return true;
    }
}

if (!function_exists('simrsDisplayName')) {
    /**
     * Nama tampilan: nama_pegawai bila ada, fallback ke nama_pemakai.
     */
    function simrsDisplayName($simrsRow)
    {
        $nama = trim((string)($simrsRow['nama_pegawai'] ?? ''));
        if ($nama !== '') return $nama;
        return trim((string)($simrsRow['nama_pemakai'] ?? ''));
    }
}
