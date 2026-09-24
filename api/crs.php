<?php
// GET api/crs.php — DataSource server-side Change Request (DataTables + kompat Kendo).
// Mirror api/tickets.php untuk tabel change_requests; data tiket tidak tersentuh.
// Param DataTables: draw/start/length/search[value]/order[0][column]/columns[i][data]
// Param custom: search/filter_status/filter_priority/overdue/assignee(mine/unassigned)/
//        date_from/date_to/user_id(admin/teknisi)/aplikasi
require_once __DIR__ . '/_common.php';

$conn = getDBConnection();
$conds = ['1=1'];
$params = [];
// Scope role: pelapor hanya milik sendiri (mirror canViewCr)
if (($apiUser['role'] ?? '') === 'pelapor') {
    $params[] = $apiUser['id'];
    $conds[] = 'cr.user_id = $' . count($params);
}

$search = trim($_GET['search'] ?? '');
if ($search === '' && isset($_GET['search']['value'])) {
    $search = trim((string)$_GET['search']['value']);
}
$filterStatus = trim($_GET['filter_status'] ?? '');
$filterPriority = trim($_GET['filter_priority'] ?? '');
$filterAplikasi = trim($_GET['aplikasi'] ?? '');
$assignee = trim($_GET['assignee'] ?? '');
$overdue = ($_GET['overdue'] ?? '') === '1';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$userFilter = (int)($_GET['user_id'] ?? 0);

$validStatus = ['open', 'in_progress', 'resolved', 'closed'];
$validPriority = ['low', 'medium', 'high', 'critical'];
if (!in_array($filterStatus, $validStatus, true)) $filterStatus = '';
if (!in_array($filterPriority, $validPriority, true)) $filterPriority = '';

// Filter Kendo: filter[filters][i][field/value]
if (isset($_GET['filter']['filters']) && is_array($_GET['filter']['filters'])) {
    foreach ($_GET['filter']['filters'] as $f) {
        if (!is_array($f)) continue;
        $ff = $f['field'] ?? '';
        $fv = trim((string)($f['value'] ?? ''));
        if ($fv === '') continue;
        if ($ff === 'status' && in_array($fv, $validStatus, true) && $filterStatus === '') {
            $filterStatus = $fv;
        } elseif ($ff === 'priority' && in_array($fv, $validPriority, true) && $filterPriority === '') {
            $filterPriority = $fv;
        } elseif (in_array($ff, ['cr_number', 'aplikasi', 'modul', 'fitur', 'q'], true) && $search === '') {
            $search = $fv;
        }
    }
}

if ($search !== '') {
    $params[] = '%' . $search . '%';
    $n = count($params);
    $conds[] = "(cr.cr_number ILIKE \$$n OR cr.aplikasi ILIKE \$$n OR cr.modul ILIKE \$$n OR cr.fitur ILIKE \$$n OR cr.keterangan ILIKE \$$n)";
}
if ($filterStatus !== '') {
    $params[] = $filterStatus;
    $conds[] = 'cr.status = $' . count($params);
}
if ($filterPriority !== '') {
    $params[] = $filterPriority;
    $conds[] = 'cr.priority = $' . count($params);
}
if ($filterAplikasi !== '') {
    $params[] = '%' . $filterAplikasi . '%';
    $conds[] = 'cr.aplikasi ILIKE $' . count($params);
}
if ($assignee === 'mine') {
    $params[] = $apiUser['id'];
    $conds[] = 'cr.assigned_to = $' . count($params);
} elseif ($assignee === 'unassigned') {
    $conds[] = 'cr.assigned_to IS NULL';
}
if ($overdue) {
    $conds[] = "cr.sla_due_at IS NOT NULL AND cr.sla_due_at < NOW() AND cr.status NOT IN ('resolved','closed')";
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $params[] = $dateFrom;
    $conds[] = 'cr.created_at::date >= $' . count($params);
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $params[] = $dateTo;
    $conds[] = 'cr.created_at::date <= $' . count($params);
}
if ($userFilter > 0 && in_array($apiUser['role'], ['admin', 'teknisi'], true)) {
    $params[] = $userFilter;
    $conds[] = 'cr.user_id = $' . count($params);
}

$where = implode(' AND ', $conds);
$allowedSort = [
    'cr_number' => 'cr.cr_number',
    'aplikasi' => 'cr.aplikasi',
    'modul' => 'cr.modul',
    'fitur' => 'cr.fitur',
    'priority' => "CASE cr.priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END",
    'status' => 'cr.status',
    'waktu_dibutuhkan' => 'cr.waktu_dibutuhkan',
    'created_at' => 'cr.created_at',
    'updated_at' => 'cr.updated_at',
];
$orderBy = apiSort($allowedSort, 'created_at', 'DESC');
apiPaging($take, $skip);

$cntR = pg_query_params($conn, "SELECT COUNT(*) FROM change_requests cr WHERE $where", $params);
$total = $cntR ? (int)pg_fetch_result($cntR, 0, 0) : 0;
if ($cntR) pg_free_result($cntR);

// recordsTotal = total tanpa filter tapi tetap scope role
$scopeConds = ['1=1'];
$scopeP = [];
if (($apiUser['role'] ?? '') === 'pelapor') {
    $scopeP[] = $apiUser['id'];
    $scopeConds[] = 'cr.user_id = $' . count($scopeP);
}
$scopeWhere = implode(' AND ', $scopeConds);
$totR = pg_query_params($conn, "SELECT COUNT(*) FROM change_requests cr WHERE $scopeWhere", $scopeP);
$recordsTotal = $totR ? (int)pg_fetch_result($totR, 0, 0) : $total;
if ($totR) pg_free_result($totR);

$dataParams = array_merge($params, [$take, $skip]);
$limN = count($params) + 1;
$offN = count($params) + 2;
$sql = "SELECT cr.id, cr.cr_number, cr.aplikasi, cr.unit, cr.modul, cr.fitur, cr.priority, cr.status,
        cr.created_at, cr.updated_at, cr.waktu_dibutuhkan, cr.attachment_path, cr.attachment_original,
        cr.assigned_to, cr.sla_due_at, u.name AS reporter_name, a.name AS assignee_name,
        (SELECT COUNT(*) FROM change_request_items i WHERE i.cr_id = cr.id) AS item_count
        FROM change_requests cr
        LEFT JOIN users u ON cr.user_id = u.id
        LEFT JOIN users a ON a.id = cr.assigned_to
        WHERE $where ORDER BY $orderBy LIMIT \$$limN OFFSET \$$offN";
$res = pg_query_params($conn, $sql, $dataParams);
$rows = [];
if ($res) {
    while ($r = pg_fetch_assoc($res)) {
        $r['has_attachment'] = !empty($r['attachment_path']);
        $r['is_overdue'] = !empty($r['sla_due_at']) && strtotime($r['sla_due_at']) < time() && !in_array($r['status'], ['resolved', 'closed'], true);
        $r['item_count'] = (int)($r['item_count'] ?? 0);
        $rows[] = $r;
    }
    pg_free_result($res);
}
pg_close($conn);
if (isDataTablesRequest()) {
    dtResponse($rows, $recordsTotal, $total);
}
jsonResponse(['data' => $rows, 'total' => $total]);
