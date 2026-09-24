<?php
// GET api/users.php — admin only, DataTables server-side (+ kompat lama)
require_once __DIR__ . '/_common.php';

if (($apiUser['role'] ?? '') !== 'admin') {
    jsonResponse(['error' => 'Forbidden'], 403);
}

$conn = getDBConnection();
// DataTables global search: search[value] (array) atau search string (custom/Kendo)
$searchRaw = $_GET['search'] ?? '';
if (is_array($searchRaw)) $searchRaw = $searchRaw['value'] ?? '';
$search = trim((string)$searchRaw);
$conds = ['1=1'];
$params = [];
if ($search !== '') {
    $params[] = '%' . $search . '%';
    $n = count($params);
    $conds[] = "(username ILIKE \$$n OR name ILIKE \$$n OR division ILIKE \$$n)";
}
$where = implode(' AND ', $conds);
// Sorting DataTables: izinkan username/name/role/division/created_at
$allowedUserSort = [
    'username' => 'username', 'name' => 'name', 'role' => 'role',
    'division' => 'division', 'created_at' => 'created_at',
];
$orderBy = apiSort($allowedUserSort, 'name', 'ASC');
apiPaging($take, $skip);

$totR = pg_query($conn, "SELECT COUNT(*) FROM users");
$recordsTotal = $totR ? (int)pg_fetch_result($totR, 0, 0) : 0;
if ($totR) pg_free_result($totR);

$cntR = pg_query_params($conn, "SELECT COUNT(*) FROM users WHERE $where", $params);
$total = $cntR ? (int)pg_fetch_result($cntR, 0, 0) : 0;
if ($cntR) pg_free_result($cntR);

$dataParams = array_merge($params, [$take, $skip]);
$limN = count($params) + 1;
$offN = count($params) + 2;
$res = pg_query_params($conn, "SELECT id, username, name, role, division, is_active, auth_source, created_at FROM users WHERE $where ORDER BY $orderBy LIMIT \$$limN OFFSET \$$offN", $dataParams);
$rows = [];
if ($res) {
    while ($r = pg_fetch_assoc($res)) {
        $r['is_active'] = ($r['is_active'] === 't' || $r['is_active'] == 1 || $r['is_active'] === true);
        $rows[] = $r;
    }
    pg_free_result($res);
}
pg_close($conn);
if (isDataTablesRequest()) {
    dtResponse($rows, $recordsTotal, $total);
}
jsonResponse(['data' => $rows, 'total' => $total]);
