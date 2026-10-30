<?php
// Helper modul Undangan Rapat Digital.
// Butuh config.php (getDBConnection, env, e, rich text, sanitize) sudah dimuat.
// Tidak ada output di file ini.

if (!function_exists('meetingStatuses')) {
    function meetingStatuses()
    {
        return ['scheduled', 'completed', 'cancelled'];
    }
}

if (!function_exists('meetingBool')) {
    function meetingBool($value)
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}

if (!function_exists('generateMeetingCode')) {
    /**
     * Nomor rapat unik format MTG-YYYYMM-XXXX (counter per bulan).
     * Mirror generateTaskCode()/generateCrNumber(): kunci tabel saat baca MAX
     * agar aman dari race. Pemanggil boleh mengirim koneksi yang sudah di dalam
     * transaksi; fungsi ini tidak COMMIT/ROLLBACK sendiri saat koneksi dikirim.
     */
    function generateMeetingCode($existingConn = null)
    {
        $own = $existingConn === null;
        $conn = $own ? getDBConnection() : $existingConn;
        if ($own) {
            pg_query($conn, 'BEGIN');
            pg_query($conn, 'LOCK TABLE meetings IN SHARE ROW EXCLUSIVE MODE');
        }
        $prefix = 'MTG-' . date('Ym');
        $result = pg_query(
            $conn,
            "SELECT '" . $prefix . "-' || LPAD(COALESCE(MAX(CAST(SUBSTRING(meeting_code FROM '[0-9]+$') AS INTEGER)) + 1, 1)::TEXT, 4, '0') FROM meetings WHERE meeting_code LIKE '" . $prefix . "-%'"
        );
        $code = $result ? pg_fetch_result($result, 0, 0) : null;
        if ($result) pg_free_result($result);
        if (!$code) $code = $prefix . '-0001';
        if ($own) {
            pg_query($conn, 'COMMIT');
            pg_close($conn);
        }
        return $code;
    }
}

if (!function_exists('meetingBaseUrl')) {
    function meetingBaseUrl()
    {
        $app = rtrim((string)env('APP_URL', ''), '/');
        $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwarded === 'https') ? 'https' : 'http';

        if ($requestHost === '') return $app;
        $requestOrigin = $scheme . '://' . $requestHost;
        if ($app === '') return $requestOrigin;

        $parts = parse_url($app);
        $appHost = strtolower((string)($parts['host'] ?? ''));
        $reqHost = strtolower((string)preg_replace('/:\d+$/', '', $requestHost));
        $isLocal = function ($host) {
            return in_array(strtolower((string)$host), ['localhost', '127.0.0.1', '::1'], true);
        };

        $appPath = rtrim((string)($parts['path'] ?? ''), '/');
        if ($appPath === '') {
            $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
            if ($dir !== '' && $dir !== '.' && $dir !== '/') $appPath = $dir;
        }
        $appBase = rtrim($app, '/');
        if ($appPath !== '' && rtrim((string)($parts['path'] ?? ''), '/') === '') $appBase .= $appPath;
        $reqPort = null;
        if (preg_match('/:(\d+)$/', $requestHost, $m)) $reqPort = (int)$m[1];
        if ($reqPort === null) $reqPort = ($scheme === 'https') ? 443 : 80;
        $appScheme = strtolower((string)($parts['scheme'] ?? 'http'));
        $appPort = isset($parts['port']) ? (int)$parts['port'] : (($appScheme === 'https') ? 443 : 80);

        if ($appHost !== '' && $appHost === $reqHost) {
            // Host sama: untuk domain produksi percaya APP_URL (skema/port),
            // untuk localhost pastikan port juga sama agar QR tidak menunjuk port lain.
            if (!$isLocal($appHost) || $appPort === $reqPort) return $appBase;
            return $requestOrigin . $appPath;
        }

        // Host berbeda: jika salah satu localhost (dev/LAN), ikuti host request
        // agar QR tidak menunjuk ke localhost dari perangkat lain.
        if ($isLocal($reqHost) || $isLocal($appHost)) return $requestOrigin . $appPath;

        // Dua-duanya host non-local (mis. APP_URL publik vs Host internal proxy):
        // APP_URL dianggap sumber kebenaran.
        return $appBase;
    }
}

if (!function_exists('meetingShareUrl')) {
    function meetingShareUrl($meeting)
    {
        $token = trim((string)($meeting['share_token'] ?? ''));
        if ($token === '') return '';
        $base = meetingBaseUrl();
        return ($base !== '' ? $base : '') . '/meeting_invite.php?t=' . rawurlencode($token);
    }
}

if (!function_exists('meetingAttendeeUrl')) {
    function meetingAttendeeUrl($accessToken)
    {
        $token = trim((string)$accessToken);
        if ($token === '') return '';
        $base = meetingBaseUrl();
        return ($base !== '' ? $base : '') . '/meeting_room.php?p=' . rawurlencode($token);
    }
}

if (!function_exists('meetingEffectiveState')) {
    /**
     * Status tampilan: cancelled/completed/upcoming/ongoing/ended.
     * Status tersimpan tetap scheduled sampai host menandai selesai.
     */
    function meetingEffectiveState($meeting)
    {
        $status = (string)($meeting['status'] ?? 'scheduled');
        if ($status === 'cancelled') return 'cancelled';
        if ($status === 'completed') return 'completed';

        $now = time();
        $start = strtotime((string)($meeting['start_at'] ?? '')) ?: $now;
        $end = strtotime((string)($meeting['end_at'] ?? '')) ?: $start;
        if ($now < $start) return 'upcoming';
        if ($now <= $end) return 'ongoing';
        return 'ended';
    }
}

if (!function_exists('meetingStateMeta')) {
    function meetingStateMeta($meeting)
    {
        $state = meetingEffectiveState($meeting);
        $map = [
            'upcoming' => ['label' => 'Akan Datang', 'badge' => 'text-bg-primary', 'icon' => 'bi-calendar-event'],
            'ongoing' => ['label' => 'Berlangsung', 'badge' => 'text-bg-warning', 'icon' => 'bi-broadcast'],
            'ended' => ['label' => 'Lewat Jadwal', 'badge' => 'text-bg-secondary', 'icon' => 'bi-clock-history'],
            'completed' => ['label' => 'Selesai', 'badge' => 'text-bg-success', 'icon' => 'bi-check2-circle'],
            'cancelled' => ['label' => 'Dibatalkan', 'badge' => 'text-bg-danger', 'icon' => 'bi-x-circle'],
        ];
        $meta = $map[$state] ?? $map['upcoming'];
        $meta['state'] = $state;
        return $meta;
    }
}

if (!function_exists('meetingDurationMinutes')) {
    function meetingDurationMinutes($meeting)
    {
        $start = strtotime((string)($meeting['start_at'] ?? ''));
        $end = strtotime((string)($meeting['end_at'] ?? ''));
        if (!$start || !$end || $end <= $start) return 0;
        return (int)round(($end - $start) / 60);
    }
}

if (!function_exists('meetingFormatDuration')) {
    function meetingFormatDuration($minutes)
    {
        $m = max(0, (int)$minutes);
        if ($m < 60) return $m . ' menit';
        $h = intdiv($m, 60);
        $r = $m % 60;
        return $h . ' jam' . ($r > 0 ? ' ' . $r . ' menit' : '');
    }
}

if (!function_exists('meetingCanViewInternal')) {
    function meetingCanViewInternal($user)
    {
        return in_array($user['role'] ?? null, ['admin', 'teknisi'], true);
    }
}

if (!function_exists('meetingCanManage')) {
    function meetingCanManage($meeting, $user)
    {
        if (!$meeting || !$user) return false;
        if (($user['role'] ?? null) === 'admin') return true;
        $uid = (int)($user['id'] ?? 0);
        return $uid > 0 && $uid === (int)($meeting['host_id'] ?? 0);
    }
}

if (!function_exists('meetingIsRegistrationOpen')) {
    function meetingIsRegistrationOpen($meeting)
    {
        return ($meeting['status'] ?? '') === 'scheduled'
            && meetingBool($meeting['registration_open'] ?? false);
    }
}

if (!function_exists('meetingNotesWritable')) {
    function meetingNotesWritable($meeting)
    {
        return ($meeting['status'] ?? '') !== 'cancelled'
            && !meetingBool($meeting['notes_locked'] ?? false);
    }
}

if (!function_exists('meetingCleanText')) {
    /**
     * Bersihkan input teks satu baris: buang karakter kontrol, rapikan spasi,
     * lalu potong sesuai batas kolom.
     */
    function meetingCleanText($value, $max = 255)
    {
        $value = (string)$value;
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', (string)$value);
        return mb_substr(trim((string)$value), 0, max(1, (int)$max), 'UTF-8');
    }
}

if (!function_exists('meetingValidateHttpUrl')) {
    function meetingValidateHttpUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') return '';
        if (mb_strlen($url, 'UTF-8') > 500) return null;
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
        return $url;
    }
}

if (!function_exists('meetingAttendeeStatuses')) {
    function meetingAttendeeStatuses()
    {
        return ['registered', 'present', 'absent'];
    }
}

if (!function_exists('meetingAttendeeStatusLabel')) {
    function meetingAttendeeStatusLabel($status)
    {
        $map = [
            'registered' => ['label' => 'Terdaftar', 'badge' => 'text-bg-info'],
            'present' => ['label' => 'Hadir', 'badge' => 'text-bg-success'],
            'absent' => ['label' => 'Tidak Hadir', 'badge' => 'text-bg-secondary'],
        ];
        return $map[$status] ?? $map['registered'];
    }
}

if (!function_exists('meetingFetchMeetingByToken')) {
    function meetingFetchMeetingByToken($conn, $token, $requireEnabled = true)
    {
        $token = trim((string)$token);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $sql = "SELECT m.*, h.name AS host_name, h.username AS host_username, h.division AS host_division
                FROM meetings m LEFT JOIN users h ON h.id = m.host_id
                WHERE m.share_token = $1";
        if ($requireEnabled) $sql .= " AND m.share_enabled = TRUE";
        $r = pg_query_params($conn, $sql, [$token]);
        $row = ($r && pg_num_rows($r) > 0) ? pg_fetch_assoc($r) : null;
        if ($r) pg_free_result($r);
        return $row;
    }
}

if (!function_exists('meetingFetchAttendeeByToken')) {
    function meetingFetchAttendeeByToken($conn, $token)
    {
        $token = trim((string)$token);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $r = pg_query_params(
            $conn,
            "SELECT a.*, m.meeting_code, m.title, m.agenda, m.start_at, m.end_at, m.location, m.online_url,
                    m.status, m.notes_locked, m.share_enabled, m.host_id, h.name AS host_name
             FROM meeting_attendees a
             JOIN meetings m ON m.id = a.meeting_id
             LEFT JOIN users h ON h.id = m.host_id
             WHERE a.access_token = $1",
            [$token]
        );
        $row = ($r && pg_num_rows($r) > 0) ? pg_fetch_assoc($r) : null;
        if ($r) pg_free_result($r);
        return $row;
    }
}

if (!function_exists('meetingGetNotes')) {
    function meetingGetNotes($conn, $meetingId, $limit = 500)
    {
        $limit = max(1, min(1000, (int)$limit));
        $out = [];
        $r = pg_query_params(
            $conn,
            "SELECT n.*, a.name AS attendee_name, u.name AS user_name
             FROM meeting_notes n
             LEFT JOIN meeting_attendees a ON a.id = n.attendee_id
             LEFT JOIN users u ON u.id = n.author_user_id
             WHERE n.meeting_id = $1
             ORDER BY n.created_at ASC, n.id ASC
             LIMIT $limit",
            [(int)$meetingId]
        );
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = $row;
            pg_free_result($r);
        }
        return $out;
    }
}

if (!function_exists('meetingGetAttendees')) {
    function meetingGetAttendees($conn, $meetingId, $search = '', $limit = 500)
    {
        $limit = max(1, min(1000, (int)$limit));
        $params = [(int)$meetingId];
        $conds = ['meeting_id = $1'];
        $search = trim((string)$search);
        if ($search !== '') {
            $params[] = '%' . $search . '%';
            $n = count($params);
            $conds[] = "(name ILIKE $n OR installation ILIKE $n OR department ILIKE $n OR room ILIKE $n OR phone ILIKE $n)";
        }
        $sql = "SELECT * FROM meeting_attendees WHERE " . implode(' AND ', $conds) . " ORDER BY registered_at ASC, id ASC LIMIT $limit";
        $out = [];
        $r = pg_query_params($conn, $sql, $params);
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = $row;
            pg_free_result($r);
        }
        return $out;
    }
}

if (!function_exists('meetingAttendanceStats')) {
    function meetingAttendanceStats($conn, $meetingId)
    {
        $stats = ['total' => 0, 'registered' => 0, 'present' => 0, 'absent' => 0];
        $r = pg_query_params(
            $conn,
            "SELECT attendance_status, COUNT(*) AS c FROM meeting_attendees WHERE meeting_id = $1 GROUP BY attendance_status",
            [(int)$meetingId]
        );
        if ($r) {
            while ($row = pg_fetch_assoc($r)) {
                $k = (string)$row['attendance_status'];
                if (!isset($stats[$k])) $stats[$k] = 0;
                $stats[$k] = (int)$row['c'];
                $stats['total'] += (int)$row['c'];
            }
            pg_free_result($r);
        }
        return $stats;
    }
}

if (!function_exists('meetingNoteAuthorBadge')) {
    function meetingNoteAuthorBadge($type)
    {
        $map = [
            'host' => ['label' => 'Host', 'badge' => 'text-bg-danger'],
            'admin' => ['label' => 'Admin', 'badge' => 'text-bg-dark'],
            'participant' => ['label' => 'Peserta', 'badge' => 'text-bg-info'],
        ];
        return $map[$type] ?? $map['participant'];
    }
}

if (!function_exists('meetingStripImages')) {
    /** Peserta publik tidak boleh menyisipkan gambar, termasuk via HTML mentah. */
    function meetingStripImages($html)
    {
        return trim((string)preg_replace('#<img\b[^>]*>#i', '', (string)$html));
    }
}
