<?php
// GET export.php?type=ticket|cr&format=csv|xlsx|pdf — export tiket/CR sesuai filter + scope role (terpisah).
// Kolom: nomor, judul, kategori, prioritas, status, divisi, pelapor, teknisi,
//        dibuat, respons_mnt_kerja, selesai, durasi_mnt_kerja, sla_due, status_sla.
require_once 'config.php';
requireLogin();
$user = getCurrentUser();
$role = $user['role'] ?? '';

$format = strtolower(trim($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
    http_response_code(400);
    exit('Format belum didukung (tersedia: csv, xlsx, pdf).');
}

$conn = getDBConnection();
// ---- Export Change Request terpisah (type=cr), tiket tetap default ----
$expType = strtolower(trim($_GET['type'] ?? 'ticket'));
if ($expType === 'cr') {
    $cconds = ['1=1'];
    $cparams = [];
    if ($role === 'pelapor') {
        $cparams[] = $user['id'];
        $cconds[] = 'cr.user_id = $' . count($cparams);
    }
    $fs = trim($_GET['filter_status'] ?? '');
    $fp = trim($_GET['filter_priority'] ?? '');
    $fd = trim($_GET['filter_division'] ?? '');
    if ($fs !== '') { $cparams[] = $fs; $cconds[] = 'cr.status = $' . count($cparams); }
    if ($fp !== '') { $cparams[] = $fp; $cconds[] = 'cr.priority = $' . count($cparams); }
    if ($fd !== '') { $cparams[] = $fd; $cconds[] = 'cr.unit = $' . count($cparams); }
    $cFrom = trim($_GET['date_from'] ?? '');
    $cTo = trim($_GET['date_to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cFrom)) { $cparams[] = $cFrom; $cconds[] = 'cr.created_at::date >= $' . count($cparams); }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cTo)) { $cparams[] = $cTo; $cconds[] = 'cr.created_at::date <= $' . count($cparams); }
    $cwhere = implode(' AND ', $cconds);
    $csql = "SELECT cr.cr_number, cr.aplikasi, cr.unit, cr.modul, cr.fitur, cr.priority, cr.status, u.name AS pelapor, a.name AS teknisi, cr.created_at, cr.waktu_dibutuhkan, cr.resolved_at, cr.sla_due_at, (SELECT COUNT(*) FROM change_request_items i WHERE i.cr_id = cr.id) AS item_count FROM change_requests cr LEFT JOIN users u ON u.id = cr.user_id LEFT JOIN users a ON a.id = cr.assigned_to WHERE $cwhere ORDER BY cr.created_at DESC LIMIT 5000";
    $cres = pg_query_params($conn, $csql, $cparams);
    $cheaders = ['CR_Number', 'Aplikasi', 'Unit', 'Modul', 'Fitur', 'Prioritas', 'Status', 'Pelapor', 'Teknisi', 'Dibuat', 'Waktu_Dibutuhkan', 'Selesai', 'SLA_Due', 'Status_SLA', 'Item_Count'];
    $crows = [];
    if ($cres) {
        while ($r = pg_fetch_assoc($cres)) {
            if (in_array($r['status'], ['resolved', 'closed'], true)) {
                $sla = (!empty($r['resolved_at']) && !empty($r['sla_due_at']) && strtotime($r['resolved_at']) <= strtotime($r['sla_due_at'])) ? 'tepat' : 'telat';
            } else {
                $sla = (!empty($r['sla_due_at']) && strtotime($r['sla_due_at']) < time()) ? 'overdue' : 'berjalan';
            }
            $crows[] = [$r['cr_number'], $r['aplikasi'], $r['unit'], $r['modul'], $r['fitur'], $r['priority'], $r['status'], $r['pelapor'], $r['teknisi'], $r['created_at'], $r['waktu_dibutuhkan'], $r['resolved_at'], $r['sla_due_at'], $sla, $r['item_count']];
        }
        pg_free_result($cres);
    }
    pg_close($conn);
    $cbase = 'laporan-cr-' . date('Ymd-His');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $cbase . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, $cheaders);
        foreach ($crows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) { http_response_code(500); exit('Library export belum terinstal. Jalankan: composer install'); }
    require_once __DIR__ . '/vendor/autoload.php';
    if ($format === 'xlsx') {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('CR Detail');
        $sh->fromArray($cheaders, null, 'A1');
        $sh->fromArray($crows, null, 'A2');
        $sh->getStyle('A1:O1')->getFont()->setBold(true);
        $sh->freezePane('A2');
        $sh->setAutoFilter('A1:O' . max(1, count($crows) + 1));
        foreach (range('A', 'O') as $col) $sh->getColumnDimension($col)->setAutoSize(true);
        $st = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0, 'tepat' => 0, 'telat' => 0, 'overdue' => 0];
        foreach ($crows as $row) {
            if (isset($st[$row[6]])) $st[$row[6]]++;
            if (in_array($row[13], ['tepat', 'telat', 'overdue'], true)) $st[$row[13]]++;
        }
        $rk = $ss->createSheet();
        $rk->setTitle('Rekap');
        $rk->fromArray([['Metrik', 'Nilai'], ['Total CR', count($crows)], ['Open', $st['open']], ['In Progress', $st['in_progress']], ['Resolved', $st['resolved']], ['Closed', $st['closed']], ['SLA tepat', $st['tepat']], ['SLA telat', $st['telat']], ['Overdue berjalan', $st['overdue']]], null, 'A1');
        $rk->getStyle('A1:B1')->getFont()->setBold(true);
        foreach (['A', 'B'] as $col) $rk->getColumnDimension($col)->setAutoSize(true);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $cbase . '.xlsx"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
        exit;
    }
    $pcr = array_slice($crows, 0, 300);
    $chtml = '<html><head><meta charset="utf-8"><style>body{font-family:sans-serif;font-size:9px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #999;padding:3px}th{background:#1a1a2e;color:#fff}</style></head><body>'
        . '<h2>Laporan Change Request Helpdesk IT</h2><p>Diekspor ' . date('d M Y H:i') . ' &middot; ' . count($crows) . ' CR' . (count($crows) > 300 ? ' (300 pertama ditampilkan)' : '') . '</p>'
        . '<table><tr><th>CR</th><th>Aplikasi</th><th>Modul</th><th>Pri</th><th>Status</th><th>Pelapor</th><th>Dibuat</th><th>SLA</th></tr>';
    foreach ($pcr as $row) {
        $chtml .= '<tr><td>' . htmlspecialchars($row[0]) . '</td><td>' . htmlspecialchars(mb_strimwidth($row[1], 0, 30, '…')) . '</td><td>' . htmlspecialchars(mb_strimwidth($row[3], 0, 30, '…')) . '</td><td>' . htmlspecialchars($row[5]) . '</td><td>' . htmlspecialchars($row[6]) . '</td><td>' . htmlspecialchars($row[7] ?? '-') . '</td><td>' . htmlspecialchars(substr($row[9], 0, 16)) . '</td><td>' . htmlspecialchars($row[13]) . '</td></tr>';
    }
    $chtml .= '</table></body></html>';
    $dompdf = new \Dompdf\Dompdf(['chroot' => __DIR__]);
    $dompdf->loadHtml($chtml);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $cbase . '.pdf"');
    echo $dompdf->output();
    exit;
}
$conds = ['1=1'];
$params = [];
if ($role === 'pelapor') {
    $params[] = $user['id'];
    $conds[] = 't.user_id = $' . count($params);
}
foreach (['filter_status', 'filter_priority', 'filter_division'] as $g) {
    $v = trim($_GET[$g] ?? '');
    if ($v !== '') {
        $params[] = $v;
        $col = $g === 'filter_status' ? 't.status' : ($g === 'filter_priority' ? 't.priority' : 't.division');
        $conds[] = "$col = $" . count($params);
    }
}
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $params[] = $dateFrom;
    $conds[] = 't.created_at::date >= $' . count($params);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $params[] = $dateTo;
    $conds[] = 't.created_at::date <= $' . count($params);
}
$where = implode(' AND ', $conds);

$sql = "SELECT t.ticket_number, t.title, t.category, t.priority, t.status, t.division,
            u.name AS pelapor, a.name AS teknisi, t.created_at, t.resolved_at, t.sla_due_at,
            (SELECT MIN(h.created_at) FROM ticket_history h JOIN users uu ON uu.id = h.actor_id
              WHERE h.ticket_id = t.id AND uu.role IN ('admin','teknisi')) AS first_resp
        FROM tickets t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN users a ON a.id = t.assigned_to
        WHERE $where ORDER BY t.created_at DESC LIMIT 5000";
$res = pg_query_params($conn, $sql, $params);

$headers = ['Nomor', 'Judul', 'Kategori', 'Prioritas', 'Status', 'Divisi', 'Pelapor', 'Teknisi', 'Dibuat', 'Respons_MntKerja', 'Selesai', 'Durasi_MntKerja', 'SLA_Due', 'Status_SLA'];
$rows = [];
if ($res) {
    while ($r = pg_fetch_assoc($res)) {
        $respMin = null;
        if (!empty($r['first_resp'])) {
            $respMin = workingMinutesBetween(nextWorkingStart(strtotime($r['created_at'])), strtotime($r['first_resp']));
        }
        $durMin = null;
        if (!empty($r['resolved_at'])) {
            $durMin = workingMinutesBetween(nextWorkingStart(strtotime($r['created_at'])), strtotime($r['resolved_at']));
        }
        if (in_array($r['status'], ['resolved', 'closed'], true)) {
            $sla = (!empty($r['resolved_at']) && !empty($r['sla_due_at']) && strtotime($r['resolved_at']) <= strtotime($r['sla_due_at'])) ? 'tepat' : 'telat';
        } else {
            $sla = (!empty($r['sla_due_at']) && strtotime($r['sla_due_at']) < time()) ? 'overdue' : 'berjalan';
        }
        $rows[] = [$r['ticket_number'], $r['title'], $r['category'], $r['priority'], $r['status'], $r['division'], $r['pelapor'], $r['teknisi'], $r['created_at'], $respMin, $r['resolved_at'], $durMin, $r['sla_due_at'], $sla];
    }
    pg_free_result($res);
}
pg_close($conn);

$base = 'laporan-tiket-' . date('Ymd-His');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('Library export belum terinstal. Jalankan: composer install');
}
require_once __DIR__ . '/vendor/autoload.php';

if ($format === 'xlsx') {
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Detail');
    $sh->fromArray($headers, null, 'A1');
    $sh->fromArray($rows, null, 'A2');
    $sh->getStyle('A1:N1')->getFont()->setBold(true);
    $sh->freezePane('A2');
    $sh->setAutoFilter('A1:N' . max(1, count($rows) + 1));
    foreach (range('A', 'N') as $col) $sh->getColumnDimension($col)->setAutoSize(true);
    // Sheet rekap
    $st = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0, 'tepat' => 0, 'telat' => 0, 'overdue' => 0];
    foreach ($rows as $row) {
        if (isset($st[$row[4]])) $st[$row[4]]++;
        if (in_array($row[13], ['tepat', 'telat', 'overdue'], true)) $st[$row[13]]++;
    }
    $rk = $ss->createSheet();
    $rk->setTitle('Rekap');
    $rk->fromArray([['Metrik', 'Nilai'], ['Total tiket', count($rows)], ['Open', $st['open']], ['In Progress', $st['in_progress']], ['Resolved', $st['resolved']], ['Closed', $st['closed']], ['SLA tepat', $st['tepat']], ['SLA telat', $st['telat']], ['Overdue berjalan', $st['overdue']]], null, 'A1');
    $rk->getStyle('A1:B1')->getFont()->setBold(true);
    foreach (['A', 'B'] as $col) $rk->getColumnDimension($col)->setAutoSize(true);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}

// PDF: tabel ringkas (maks 300 baris agar ringan)
$prows = array_slice($rows, 0, 300);
$html = '<html><head><meta charset="utf-8"><style>body{font-family:sans-serif;font-size:9px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #999;padding:3px}th{background:#1a1a2e;color:#fff}</style></head><body>'
    . '<h2>Laporan Tiket Helpdesk IT</h2><p>Diekspor ' . date('d M Y H:i') . ' · ' . count($rows) . ' tiket' . (count($rows) > 300 ? ' (300 pertama ditampilkan)' : '') . '</p>'
    . '<table><tr><th>Nomor</th><th>Judul</th><th>Pri</th><th>Status</th><th>Divisi</th><th>Teknisi</th><th>Dibuat</th><th>SLA</th></tr>';
foreach ($prows as $row) {
    $html .= '<tr><td>' . htmlspecialchars($row[0]) . '</td><td>' . htmlspecialchars(mb_strimwidth($row[1], 0, 50, '…')) . '</td><td>' . htmlspecialchars($row[3]) . '</td><td>' . htmlspecialchars($row[4]) . '</td><td>' . htmlspecialchars($row[5]) . '</td><td>' . htmlspecialchars($row[7] ?? '-') . '</td><td>' . htmlspecialchars(substr($row[8], 0, 16)) . '</td><td>' . htmlspecialchars($row[13]) . '</td></tr>';
}
$html .= '</table></body></html>';
$dompdf = new \Dompdf\Dompdf(['chroot' => __DIR__]);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $base . '.pdf"');
echo $dompdf->output();
exit;
