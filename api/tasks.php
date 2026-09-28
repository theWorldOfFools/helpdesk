<?php
// GET api/tasks.php — DataSource server-side mini-todo SDLC (DataTables + kompat Kendo).
// Mirror api/crs.php untuk tabel dev_tasks; khusus staf (admin/teknisi), pelapor 403.
require_once __DIR__ . '/_common.php';

if (!in_array($apiUser['role'] ?? '', ['admin', 'teknisi'], true)) {
    jsonResponse(['error' => 'Forbidden'], 403);
}

$conn = getDBConnection();
$conds = ['1=1'];
$params = [];

$searchRaw = $_GET['search'] ?? '';
if (is_array($searchRaw)) $searchRaw = $searchRaw['value'] ?? '';
$search = trim((string)$searchRaw);
$filterPhase = trim($_GET['phase'] ?? '');
$filterPriority = trim($_GET['priority'] ?? '');
$filterOwner = trim($_GET['owner'] ?? '');
$filterTicket = (int)($_GET['ticket_id'] ?? 0);
$filterCr = (int)($_GET['cr_id'] ?? 0);
$overdue = ($_GET['overdue'] ?? '') === '1';

$validPhases = ['backlog', 'siap', 'development', 'testing', 'deploy', 'done'];
$validPriority = ['low', 'medium', 'high', 'critical'];
if (!in_array($filterPhase, $validPhases, true)) $filterPhase = '';
if (!in_array($filterPriority, $validPriority, true)) $filterPriority = '';
if (!in_array($filterOwner, ['mine', 'unassigned'], true)) $filterOwner = '';

// Filter Kendo: filter[filters][i][field/value]
if (isset($_GET['filter']['filters']) && is_array($_GET['filter']['filters'])) {
    foreach ($_GET['filter']['filters'] as $f) {
        if (!is_array($f)) continue;
        $ff = $f['field'] ?? '';
        $fv = trim((string)($f['value'] ?? ''));
        if ($fv === '') continue;
        if ($ff === 'phase' && in_array($fv, $validPhases, true) && $filterPhase === '') {
            $filterPhase = $fv;
        } elseif ($ff === 'priority' && in_array($fv, $validPriority, true) && $filterPriority === '') {
            $filterPriority = $fv;
        } elseif (in_array($ff, ['task_code', 'title', 'q'], true) && $search === '') {
            $search = $fv;
        }
    }
}

if ($search !== '') {
    $params[] = '%' . $search . '%';
    $n = count($params);
    $conds[] = "(d.task_code ILIKE \$$n OR d.title ILIKE \$$n OR d.description ILIKE \$$n)";
}
if ($filterPhase !== '') {
    $params[] = $filterPhase;
    $conds[] = 'd.phase = $' . count($params);
}
if ($filterPriority !== '') {
    $params[] = $filterPriority;
    $conds[] = 'd.priority = $' . count($params);
}
if ($filterOwner === 'mine') {
    $params[] = $apiUser['id'];
    $n = count($params);
    $conds[] = '(d.owner_id = $' . $n . ' OR EXISTS (SELECT 1 FROM dev_task_assignees a WHERE a.task_id = d.id AND a.user_id = $' . $n . '))';
} elseif ($filterOwner === 'unassigned') {
    $conds[] = 'd.owner_id IS NULL AND NOT EXISTS (SELECT 1 FROM dev_task_assignees a WHERE a.task_id = d.id)';
}
if ($filterTicket > 0) {
    $params[] = $filterTicket;
    $conds[] = 'd.ticket_id = $' . count($params);
}
if ($filterCr > 0) {
    $params[] = $filterCr;
    $conds[] = 'd.cr_id = $' . count($params);
}
if ($overdue) {
    $conds[] = "d.due_date IS NOT NULL AND d.due_date < CURRENT_DATE AND d.phase <> 'done'";
}

$where = implode(' AND ', $conds);
$allowedSort = [
    'task_code' => 'd.task_code',
    'title' => 'd.title',
    'priority' => "CASE d.priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END",
    'phase' => "CASE d.phase WHEN 'backlog' THEN 1 WHEN 'siap' THEN 2 WHEN 'development' THEN 3 WHEN 'testing' THEN 4 WHEN 'deploy' THEN 5 ELSE 6 END",
    'due_date' => 'd.due_date',
    'created_at' => 'd.created_at',
    'updated_at' => 'd.updated_at',
];
$orderBy = apiSort($allowedSort, 'created_at', 'DESC');
apiPaging($take, $skip);

$cntR = pg_query_params($conn, "SELECT COUNT(*) FROM dev_tasks d WHERE $where", $params);
$total = $cntR ? (int)pg_fetch_result($cntR, 0, 0) : 0;
if ($cntR) pg_free_result($cntR);

$dataParams = array_merge($params, [$take, $skip]);
$limN = count($params) + 1;
$offN = count($params) + 2;
$sql = "SELECT d.id, d.task_code, d.title, d.phase, d.priority, d.due_date, d.estimate_hours,
        d.created_at, d.updated_at, d.ticket_id, d.cr_id, d.owner_id,
        u.name AS owner_name, t.ticket_number, c.cr_number,
        (SELECT string_agg(u2.name, ', ' ORDER BY u2.name) FROM dev_task_assignees a JOIN users u2 ON u2.id = a.user_id WHERE a.task_id = d.id) AS assignee_names,
        (SELECT COUNT(*) FROM dev_task_assignees a2 WHERE a2.task_id = d.id) AS assignee_count,
        (SELECT COUNT(*) FROM dev_task_attachments at WHERE at.task_id = d.id) AS attachment_count
        FROM dev_tasks d
        LEFT JOIN users u ON u.id = d.owner_id
        LEFT JOIN tickets t ON t.id = d.ticket_id
        LEFT JOIN change_requests c ON c.id = d.cr_id
        WHERE $where ORDER BY $orderBy LIMIT \$$limN OFFSET \$$offN";
$res = pg_query_params($conn, $sql, $dataParams);
$rows = [];
if ($res) {
    while ($r = pg_fetch_assoc($res)) {
        $r['is_overdue'] = !empty($r['due_date']) && $r['due_date'] < date('Y-m-d') && $r['phase'] !== 'done';
        $r['assignee_count'] = (int)($r['assignee_count'] ?? 0);
        $r['attachment_count'] = (int)($r['attachment_count'] ?? 0);
        $r['has_attachment'] = $r['attachment_count'] > 0;
        $rows[] = $r;
    }
    pg_free_result($res);
}
pg_close($conn);
if (isDataTablesRequest()) {
    dtResponse($rows, $total, $total);
}
jsonResponse(['data' => $rows, 'total' => $total]);
