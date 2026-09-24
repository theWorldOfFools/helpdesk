<?php
// GET api/dashboard.php?type=trend|status|priority|workload|leader_teknisi|leader_pelapor|div_pivot&month=YYYY-MM&days=30
require_once __DIR__ . '/_common.php';

$conn = getDBConnection();
$type = trim($_GET['type'] ?? 'trend');
$role = $apiUser['role'] ?? '';
$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$days = max(7, min(90, (int)($_GET['days'] ?? 30)));

$scopeWhere = '1=1';
$scopeParams = [];
if ($role === 'pelapor') {
    $scopeWhere = 'user_id = $1';
    $scopeParams = [$apiUser['id']];
}

switch ($type) {
    case 'trend': {
        // created vs resolved per hari N hari terakhir (scope role)
        $sql = "SELECT d::date AS day,
                COUNT(DISTINCT CASE WHEN t.created_at::date = d::date THEN t.id END) AS created,
                COUNT(DISTINCT CASE WHEN t.resolved_at::date = d::date THEN t.id END) AS resolved
                FROM generate_series(NOW()::date - INTERVAL '" . $days . " days', NOW()::date, '1 day') d
                LEFT JOIN tickets t ON (t.created_at::date = d::date OR t.resolved_at::date = d::date)";
        if ($scopeParams) {
            $sql .= " AND t.user_id = \$1";
            // generate_series tanpa filter scope untuk hari kosong tetap muncul: pindah scope ke JOIN
            $r = pg_query_params($conn, $sql . " GROUP BY d ORDER BY d", $scopeParams);
        } else {
            $r = pg_query($conn, $sql . " GROUP BY d ORDER BY d");
        }
        $labels = [];
        $created = [];
        $resolved = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) {
                $labels[] = date('d M', strtotime($row['day']));
                $created[] = (int)$row['created'];
                $resolved[] = (int)$row['resolved'];
            }
            pg_free_result($r);
        }
        pg_close($conn);
        jsonResponse(['labels' => $labels, 'created' => $created, 'resolved' => $resolved]);
    }
    case 'status':
    case 'priority':
    case 'division': {
        $col = $type === 'division' ? 'division' : $type;
        $allowed = ['status' => 1, 'priority' => 1, 'division' => 1];
        if (!isset($allowed[$col])) jsonResponse(['error' => 'Bad type'], 400);
        $r = pg_query_params($conn, "SELECT $col AS k, COUNT(*) AS c FROM tickets WHERE ($scopeWhere) GROUP BY $col ORDER BY $col", $scopeParams);
        $out = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = ['label' => $row['k'], 'value' => (int)$row['c']];
            pg_free_result($r);
        }
        pg_close($conn);
        jsonResponse(['data' => $out]);
    }
    case 'div_pivot': {
        if ($role !== 'admin') jsonResponse(['error' => 'Forbidden'], 403);
        $r = pg_query($conn, "SELECT division, status, COUNT(*) AS c, AVG(EXTRACT(EPOCH FROM (COALESCE(resolved_at, NOW()) - created_at))/3600) AS avg_h FROM tickets GROUP BY division, status ORDER BY division");
        $pivot = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) {
                $d = $row['division'] ?: '-';
                if (!isset($pivot[$d])) $pivot[$d] = ['division' => $d, 'total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0, 'avg_h' => 0];
                $pivot[$d][$row['status']] = (int)$row['c'];
                $pivot[$d]['total'] += (int)$row['c'];
            }
            pg_free_result($r);
        }
        pg_close($conn);
        jsonResponse(['data' => array_values($pivot)]);
    }
    case 'workload': {
        // Beban teknisi aktif + resolved bulan ini (admin & teknisi boleh)
        if (!in_array($role, ['admin', 'teknisi'], true)) jsonResponse(['error' => 'Forbidden'], 403);
        $r = pg_query($conn, "SELECT u.id, u.name,
                COUNT(*) FILTER (WHERE t.status IN ('open','in_progress')) AS active,
                COUNT(*) FILTER (WHERE t.status IN ('resolved','closed') AND date_trunc('month', COALESCE(t.resolved_at, t.updated_at)) = date_trunc('month', ('$month-01')::date)) AS done_month
                FROM users u LEFT JOIN tickets t ON t.assigned_to = u.id
                WHERE u.role = 'teknisi' AND u.is_active = TRUE
                GROUP BY u.id, u.name ORDER BY active DESC, done_month DESC");
        $out = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'active' => (int)$row['active'], 'done' => (int)$row['done_month']];
            pg_free_result($r);
        }
        pg_close($conn);
        jsonResponse(['data' => $out]);
    }
    case 'leader_teknisi': {
        // Skor = resolved_count / (1 + avg_jam/24), periode bulanan
        $r = pg_query($conn, "SELECT u.id, u.name, COUNT(*) AS resolved_count,
                AVG(EXTRACT(EPOCH FROM (t.resolved_at - t.created_at))/3600) AS avg_h
                FROM tickets t JOIN users u ON u.id = t.assigned_to
                WHERE t.status IN ('resolved','closed') AND t.resolved_at IS NOT NULL
                  AND date_trunc('month', t.resolved_at) = date_trunc('month', ('$month-01')::date)
                GROUP BY u.id, u.name HAVING COUNT(*) > 0
                ORDER BY COUNT(*) DESC");
        $out = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) {
                $cnt = (int)$row['resolved_count'];
                $avg = $row['avg_h'] !== null ? round((float)$row['avg_h'], 1) : null;
                $score = $avg !== null ? round($cnt / (1 + $avg / 24), 2) : (float)$cnt;
                $out[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'resolved' => $cnt, 'avg_h' => $avg, 'score' => $score];
            }
            pg_free_result($r);
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score'] ?: $b['resolved'] <=> $a['resolved']);
        foreach ($out as $i => &$row) $row['rank'] = $i + 1;
        unset($row); // putus referensi agar foreach berikut tidak menimpa elemen terakhir
        // Tandai posisi saya
        $me = null;
        foreach ($out as $row) {
            if ((int)$row['id'] === (int)$apiUser['id']) {
                $me = $row;
                break;
            }
        }
        pg_close($conn);
        jsonResponse(['data' => array_slice($out, 0, 10), 'me' => $me, 'month' => $month]);
    }
    case 'leader_pelapor': {
        $r = pg_query_params($conn, "SELECT u.id, u.name, u.division, COUNT(t.id) AS total
                FROM users u JOIN tickets t ON t.user_id = u.id
                WHERE date_trunc('month', t.created_at) = date_trunc('month', ('$month-01')::date)
                GROUP BY u.id, u.name, u.division ORDER BY COUNT(t.id) DESC LIMIT 10", []);
        $out = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) $out[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'division' => $row['division'], 'total' => (int)$row['total']];
            pg_free_result($r);
        }
        foreach ($out as $i => &$row) $row['rank'] = $i + 1;
        unset($row); // putus referensi agar foreach berikut tidak menimpa elemen terakhir
        $me = null;
        $mr = pg_query_params($conn, "SELECT COUNT(*) FROM tickets WHERE user_id=$1 AND date_trunc('month', created_at)=date_trunc('month', ('$month-01')::date)", [$apiUser['id']]);
        $myTotal = $mr ? (int)pg_fetch_result($mr, 0, 0) : 0;
        if ($mr) pg_free_result($mr);
        foreach ($out as $row) {
            if ((int)$row['id'] === (int)$apiUser['id']) {
                $me = $row;
                break;
            }
        }
        if (!$me && $myTotal > 0) $me = ['id' => (int)$apiUser['id'], 'name' => $apiUser['name'], 'division' => $apiUser['division'] ?? '-', 'total' => $myTotal, 'rank' => null];
        pg_close($conn);
        jsonResponse(['data' => $out, 'me' => $me, 'month' => $month]);
    }
    default:
        pg_close($conn);
        jsonResponse(['error' => 'Unknown type'], 400);
}
