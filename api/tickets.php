<?php
// GET api/tickets.php — DataSource server-side (DataTables + kompat Kendo lama).
// Param DataTables: draw/start/length/search[value]/order[0][column]/columns[i][data]
// Param custom: search/filter_status/filter_priority/filter_division/
//        has_attachment/assignee(mine/unassigned)/overdue(1)/date_from/date_to/user_id(admin)
require_once __DIR__ . '/_common.php';

$conn = getDBConnection();
$conds = ['1=1'];
$params = [];
apiTicketScope($conds, $params, $apiUser, 't');

$search = trim($_GET['search'] ?? '');
// DataTables global search: search[value]
if ($search === '' && isset($_GET['search']['value'])) {
    $search = trim((string)$_GET['search']['value']);
}
$filterStatus = trim($_GET['filter_status'] ?? '');
$filterPriority = trim($_GET['filter_priority'] ?? '');
$filterDivision = trim($_GET['filter_division'] ?? '');
$onlyAttach = ($_GET['has_attachment'] ?? '') === '1';
$assignee = trim($_GET['assignee'] ?? '');
$overdue = ($_GET['overdue'] ?? '') === '1';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$userFilter = (int)($_GET['user_id'] ?? 0);

$validStatus = ['open', 'in_progress', 'resolved', 'closed'];
$validPriority = ['low', 'medium', 'high', 'critical'];
if (!in_array($filterStatus, $validStatus, true)) $filterStatus = '';
if (!in_array($filterPriority, $validPriority, true)) $filterPriority = '';

// Filter Kendo bawaan: filter[filters][i][field/value/operator] — petakan secukupnya
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
        } elseif (in_array($ff, ['title', 'ticket_number', 'q'], true) && $search === '') {
            $search = $fv;
        }
    }
}

if ($search !== '') {
    $params[] = '%' . $search . '%';
    $n = count($params);
    $conds[] = "(t.title ILIKE \$$n OR t.ticket_number ILIKE \$$n OR t.description ILIKE \$$n)";
}
if ($filterStatus !== '') {
    $params[] = $filterStatus;
    $conds[] = 't.status = $' . count($params);
}
if ($filterPriority !== '') {
    $params[] = $filterPriority;
    $conds[] = 't.priority = $' . count($params);
}
if ($filterDivision !== '') {
    $params[] = $filterDivision;
    $conds[] = 't.division = $' . count($params);
}
if ($onlyAttach) {
    $conds[] = "t.attachment_path IS NOT NULL AND t.attachment_path <> ''";
}
if ($assignee === 'mine') {
    $params[] = $apiUser['id'];
    $conds[] = 't.assigned_to = $' . count($params);
} elseif ($assignee === 'unassigned') {
    $conds[] = 't.assigned_to IS NULL';
}
if ($overdue) {
    $conds[] = "t.sla_due_at IS NOT NULL AND t.sla_due_at < NOW() AND t.status NOT IN ('resolved','closed')";
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $params[] = $dateFrom;
    $conds[] = 't.created_at::date >= $' . count($params);
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $params[] = $dateTo;
    $conds[] = 't.created_at::date <= $' . count($params);
}
if ($userFilter > 0 && in_array($apiUser['role'], ['admin', 'teknisi'], true)) {
    $params[] = $userFilter;
    $conds[] = 't.user_id = $' . count($params);
}

$where = implode(' AND ', $conds);
$allowedSort = [
    'ticket_number' => 't.ticket_number', 'title' => 't.title',
    'priority' => "CASE t.priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END",
    'status' => 't.status', 'division' => 't.division',
    'created_at' => 't.created_at', 'updated_at' => 't.updated_at',
];
$orderBy = apiSort($allowedSort, 'created_at', 'DESC');
apiPaging($take, $skip);

$cntR = pg_query_params($conn, "SELECT COUNT(*) FROM tickets t WHERE $where", $params);
$total = $cntR ? (int)pg_fetch_result($cntR, 0, 0) : 0;
if ($cntR) pg_free_result($cntR);

// recordsTotal DataTables = total tanpa filter (tapi tetap scope role)
$scopeConds = ['1=1'];
$scopeP = [];
apiTicketScope($scopeConds, $scopeP, $apiUser, 't');
$scopeWhere = implode(' AND ', $scopeConds);
$totR = pg_query_params($conn, "SELECT COUNT(*) FROM tickets t WHERE $scopeWhere", $scopeP);
$recordsTotal = $totR ? (int)pg_fetch_result($totR, 0, 0) : $total;
if ($totR) pg_free_result($totR);

$dataParams = array_merge($params, [$take, $skip]);
$limN = count($params) + 1;
$offN = count($params) + 2;
$sql = "SELECT t.id, t.ticket_number, t.title, t.category, t.priority, t.status, t.division,
        t.created_at, t.updated_at, t.attachment_path, t.attachment_original, t.assigned_to,
        t.sla_due_at, u.name AS created_by_name, a.name AS assignee_name
        FROM tickets t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN users a ON a.id = t.assigned_to
        WHERE $where ORDER BY $orderBy LIMIT \$$limN OFFSET \$$offN";
$res = pg_query_params($conn, $sql, $dataParams);
$rows = [];
if ($res) {
    while ($r = pg_fetch_assoc($res)) {
        $r['has_attachment'] = !empty($r['attachment_path']);
        $r['is_overdue'] = !empty($r['sla_due_at']) && strtotime($r['sla_due_at']) < time() && !in_array($r['status'], ['resolved', 'closed'], true);
        $rows[] = $r;
    }
    pg_free_result($res);
}
pg_close($conn);
if (isDataTablesRequest()) {
    dtResponse($rows, $recordsTotal, $total);
}
jsonResponse(['data' => $rows, 'total' => $total]);
