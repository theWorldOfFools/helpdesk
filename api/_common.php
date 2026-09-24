<?php
// Common untuk semua API JSON: auth + helper scope role.
require_once __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}
$apiUser = getCurrentUser();
if (!$apiUser) jsonResponse(['error' => 'Unauthorized'], 401);

function apiTicketScope(&$conds, &$params, $user, $prefix = 't')
{
    if (($user['role'] ?? '') === 'pelapor') {
        $params[] = $user['id'];
        $conds[] = "$prefix.user_id = $" . count($params);
    }
}

function apiSort($allowed, $defaultField = 'created_at', $defaultDir = 'DESC')
{
    $field = $_GET['sortField'] ?? $_GET['sort_field'] ?? $defaultField;
    $dir = strtoupper($_GET['sortDir'] ?? $_GET['sort_dir'] ?? $defaultDir);
    // Dukung format Kendo: sort[0][field], sort[0][dir]
    if (isset($_GET['sort']) && is_array($_GET['sort'])) {
        $s0 = $_GET['sort'][0] ?? null;
        if (is_array($s0)) {
            $field = $s0['field'] ?? $field;
            $dir = strtoupper($s0['dir'] ?? $dir);
        }
    }
    // Dukung format DataTables: order[0][column] + columns[idx][data], order[0][dir]
    if (isset($_GET['order']) && is_array($_GET['order'])) {
        $o0 = $_GET['order'][0] ?? null;
        if (is_array($o0) && isset($o0['column'])) {
            $colIdx = (int)$o0['column'];
            $cols = $_GET['columns'] ?? [];
            $colData = is_array($cols) && isset($cols[$colIdx]['data']) ? $cols[$colIdx]['data'] : null;
            if ($colData) $field = $colData;
            $dir = strtoupper($o0['dir'] ?? $dir);
        }
    }
    if (!isset($allowed[$field])) $field = $defaultField;
    if ($dir !== 'ASC' && $dir !== 'DESC') $dir = $defaultDir;
    return $allowed[$field] . ' ' . $dir;
}

function apiPaging(&$take, &$skip)
{
    // DataTables: start + length
    if (isset($_GET['length'])) {
        $take = (int)$_GET['length'];
        $skip = (int)($_GET['start'] ?? 0);
        if ($take <= 0) $take = 15;
        if ($take > 100) $take = 100;
        if ($skip < 0) $skip = 0;
        return;
    }
    $take = (int)($_GET['take'] ?? $_GET['pageSize'] ?? 15);
    $skip = (int)($_GET['skip'] ?? 0);
    $page = (int)($_GET['page'] ?? 1);
    if ($take <= 0) $take = 15;
    if ($take > 100) $take = 100;
    if ($skip <= 0 && $page > 1) $skip = ($page - 1) * $take;
    if ($skip < 0) $skip = 0;
}

function isDataTablesRequest()
{
    return isset($_GET['draw']) || isset($_GET['length']) || isset($_GET['start']);
}

function dtResponse($rows, $total, $filtered = null)
{
    $draw = (int)($_GET['draw'] ?? 0);
    jsonResponse([
        'draw' => $draw,
        'recordsTotal' => $total,
        'recordsFiltered' => $filtered ?? $total,
        // Kompatibilitas mundur (Kendo): biarkan key lama tetap ada
        'data' => $rows,
        'total' => $filtered ?? $total,
    ]);
}
