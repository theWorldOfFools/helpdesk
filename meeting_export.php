<?php
// Export CSV absensi peserta rapat — host/admin.
require_once 'config.php';
require_once 'includes/meeting_auth.php';
$user = requireRole(['admin', 'teknisi']);

$id = (int)($_GET['id'] ?? 0);
$conn = getDBConnection();
$r = pg_query_params($conn, "SELECT * FROM meetings WHERE id = $1", [$id]);
$meeting = ($r && pg_num_rows($r) > 0) ? pg_fetch_assoc($r) : null;
if ($r) pg_free_result($r);
if (!$meeting || !meetingCanManage($meeting, $user)) {
    pg_close($conn);
    http_response_code(403);
    echo 'Anda tidak berhak mengekspor absensi rapat ini.';
    exit;
}

$attendees = meetingGetAttendees($conn, $id, '', 1000);
logActivity($conn, $user['id'], 'meeting_export_attendance', 'Absensi rapat ' . $meeting['meeting_code'] . ' diekspor (' . count($attendees) . ' peserta)');
pg_close($conn);

$filename = 'absensi-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $meeting['meeting_code']) . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');
if ($out === false) exit;
fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
fputcsv($out, ['No', 'Nama', 'Instalasi', 'Bagian/Unit', 'Ruangan', 'Jabatan', 'No. HP', 'Status Kehadiran', 'Waktu Daftar']);
$no = 0;
foreach ($attendees as $a) {
    $no++;
    $st = meetingAttendeeStatusLabel($a['attendance_status']);
    fputcsv($out, [
        $no,
        $a['name'],
        $a['installation'],
        $a['department'],
        $a['room'],
        (string)($a['position'] ?? ''),
        (string)($a['phone'] ?? ''),
        $st['label'],
        date('Y-m-d H:i', strtotime($a['registered_at'])),
    ]);
}
fclose($out);
exit;
