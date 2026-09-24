<?php
// GET api/reports.php — data menu Laporan.
// Types: kpi | trend | sla_response | sla_resolution | breach
// Filter: month=YYYY-MM ATAU date_from/date_to (YYYY-MM-DD), division, priority.
// Scope role: pelapor = tiket own saja; admin/teknisi = semua.
require_once __DIR__ . '/_common.php';

$conn = getDBConnection();
$role = $apiUser['role'] ?? '';
$type = trim($_GET['type'] ?? 'kpi');

$month = trim($_GET['month'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $dateFrom = $month . '-01';
    $dateTo = date('Y-m-t', strtotime($month . '-01'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d');
if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

$division = trim($_GET['division'] ?? '');
$priority = trim($_GET['priority'] ?? '');
if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) $priority = '';

// Kondisi filter dasar (periode pembuatan + divisi + prioritas + scope role)
function reportConds($apiUser, $dateFrom, $dateTo, $division, $priority, $prefix = 't')
{
    $conds = ["$prefix.created_at::date >= \$__FROM__", "$prefix.created_at::date <= \$__TO__"];
    $params = [$dateFrom, $dateTo];
    if ($division !== '') {
        $params[] = $division;
        $conds[] = "$prefix.division = $" . count($params);
    }
    if ($priority !== '') {
        $params[] = $priority;
        $conds[] = "$prefix.priority = $" . count($params);
    }
    if (($apiUser['role'] ?? '') === 'pelapor') {
        $params[] = $apiUser['id'];
        $conds[] = "$prefix.user_id = $" . count($params);
    }
    // ganti placeholder tanggal dengan nomor param yang benar (selalu $1,$2)
    $conds[0] = "$prefix.created_at::date >= \$1";
    $conds[1] = "$prefix.created_at::date <= \$2";
    return [$conds, $params];
}

[$baseConds, $baseParams] = reportConds($apiUser, $dateFrom, $dateTo, $division, $priority);
$baseWhere = implode(' AND ', $baseConds);
$respLimit = slaResponseLimit();
$respTarget = slaResponseTarget();

switch ($type) {
    case 'kpi': {
        // Total, selesai, SLA respons (60 mnt kerja), SLA resolusi, MTTR jam kerja
        $sql = "WITH first_act AS (
                    SELECT h.ticket_id, MIN(h.created_at) AS fa
                    FROM ticket_history h JOIN users u ON u.id = h.actor_id
                    WHERE u.role IN ('admin','teknisi') GROUP BY h.ticket_id
                ),
                scoped AS (SELECT t.* FROM tickets t WHERE $baseWhere)
                SELECT
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status IN ('resolved','closed')) AS done,
                    COUNT(f.ticket_id) AS responded,
                    COUNT(*) FILTER (WHERE f.fa IS NOT NULL AND working_minutes(next_working_start(s.created_at), f.fa) <= $respLimit) AS resp_ok,
                    COUNT(*) FILTER (WHERE f.fa IS NULL AND working_minutes(next_working_start(s.created_at), LOCALTIMESTAMP) > $respLimit) AS resp_breach_pending,
                    COUNT(*) FILTER (WHERE s.status IN ('resolved','closed') AND s.resolved_at IS NOT NULL AND s.resolved_at <= COALESCE(s.sla_due_at, s.resolved_at)) AS res_ok,
                    COUNT(*) FILTER (WHERE s.status IN ('resolved','closed')) AS res_done,
                    COUNT(*) FILTER (WHERE s.status NOT IN ('resolved','closed') AND s.sla_due_at IS NOT NULL AND s.sla_due_at < LOCALTIMESTAMP) AS res_breach_open,
                    AVG(working_minutes(next_working_start(s.created_at), s.resolved_at)) FILTER (WHERE s.resolved_at IS NOT NULL) AS mttr_min
                FROM scoped s LEFT JOIN first_act f ON f.ticket_id = s.id";
        $r = pg_query_params($conn, $sql, $baseParams);
        $k = $r ? pg_fetch_assoc($r) : [];
        if ($r) pg_free_result($r);
        $total = (int)($k['total'] ?? 0);
        $done = (int)($k['done'] ?? 0);
        $respDen = (int)($k['responded'] ?? 0) + (int)($k['resp_breach_pending'] ?? 0);
        $respNum = (int)($k['resp_ok'] ?? 0);
        $resDone = (int)($k['res_done'] ?? 0);
        $resOk = (int)($k['res_ok'] ?? 0);
        pg_close($conn);
        jsonResponse([
            'total' => $total,
            'done' => $done,
            'pct_done' => $total ? round($done / $total * 100, 1) : 0,
            'resp' => ['num' => $respNum, 'den' => $respDen, 'pct' => $respDen ? round($respNum / $respDen * 100, 1) : 100, 'target' => 100],
            'res' => ['num' => $resOk, 'den' => $resDone, 'pct' => $resDone ? round($resOk / $resDone * 100, 1) : 100, 'breach_open' => (int)($k['res_breach_open'] ?? 0)],
            'mttr_min' => $k['mttr_min'] !== null ? round((float)$k['mttr_min']) : null,
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
        ]);
    }
    case 'trend': {
        // created vs resolved per hari + backlog berjalan
        $sql = "WITH days AS (
                    SELECT generate_series(\$1::date, \$2::date, '1 day')::date AS d
                )
                SELECT d::text AS day,
                    (SELECT COUNT(*) FROM tickets t WHERE t.created_at::date = d " . ($role === 'pelapor' ? "AND t.user_id = \$3" : "") . ") AS created,
                    (SELECT COUNT(*) FROM tickets t WHERE t.resolved_at::date = d " . ($role === 'pelapor' ? "AND t.user_id = \$3" : "") . ") AS resolved,
                    (SELECT COUNT(*) FROM tickets t WHERE t.created_at::date <= d AND (t.resolved_at IS NULL OR t.resolved_at::date > d) " . ($role === 'pelapor' ? "AND t.user_id = \$3" : "") . ") AS backlog
                FROM days ORDER BY d";
        $p = [$dateFrom, $dateTo];
        if ($role === 'pelapor') $p[] = $apiUser['id'];
        // filter divisi/prioritas ikut bila diisi
        $extra = '';
        $pe = [];
        if ($division !== '') {
            $pe[] = $division;
            $extra .= ' AND t.division = $' . (count($p) + count($pe));
        }
        if ($priority !== '') {
            $pe[] = $priority;
            $extra .= ' AND t.priority = $' . (count($p) + count($pe));
        }
        if ($extra !== '') {
            $sql = "WITH days AS (SELECT generate_series(\$1::date, \$2::date, '1 day')::date AS d)
                SELECT d::text AS day,
                    (SELECT COUNT(*) FROM tickets t WHERE t.created_at::date = d $extra " . ($role === 'pelapor' ? "AND t.user_id = \$" . (count($p) + count($pe) + 1) : "") . ") AS created,
                    (SELECT COUNT(*) FROM tickets t WHERE t.resolved_at::date = d $extra " . ($role === 'pelapor' ? "AND t.user_id = \$" . (count($p) + count($pe) + 1) : "") . ") AS resolved,
                    (SELECT COUNT(*) FROM tickets t WHERE t.created_at::date <= d AND (t.resolved_at IS NULL OR t.resolved_at::date > d) $extra " . ($role === 'pelapor' ? "AND t.user_id = \$" . (count($p) + count($pe) + 1) : "") . ") AS backlog
                FROM days ORDER BY d";
            $p = array_merge($p, $pe);
            if ($role === 'pelapor') $p[] = $apiUser['id'];
        }
        $r = pg_query_params($conn, $sql, $p);
        $labels = $created = $resolved = $backlog = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) {
                $labels[] = date('d M', strtotime($row['day']));
                $created[] = (int)$row['created'];
                $resolved[] = (int)$row['resolved'];
                $backlog[] = (int)$row['backlog'];
            }
            pg_free_result($r);
        }
        pg_close($conn);
        jsonResponse(['labels' => $labels, 'created' => $created, 'resolved' => $resolved, 'backlog' => $backlog]);
    }
    case 'sla_response': {
        // Numerator/denominator + tren + breach list + pending muda
        $sql = "WITH first_act AS (
                    SELECT h.ticket_id, MIN(h.created_at) AS fa
                    FROM ticket_history h JOIN users u ON u.id = h.actor_id
                    WHERE u.role IN ('admin','teknisi') GROUP BY h.ticket_id
                ),
                scoped AS (SELECT t.* FROM tickets t WHERE $baseWhere)
                SELECT s.id, s.ticket_number, s.title, s.priority, s.division, s.status, s.created_at,
                    u.name AS pelapor_name, a.name AS assignee_name, f.fa,
                    CASE WHEN f.fa IS NULL THEN NULL ELSE working_minutes(next_working_start(s.created_at), f.fa) END AS resp_min
                FROM scoped s
                LEFT JOIN first_act f ON f.ticket_id = s.id
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN users a ON a.id = s.assigned_to
                ORDER BY s.created_at DESC";
        $r = pg_query_params($conn, $sql, $baseParams);
        $num = $den = $pending = 0;
        $breach = [];
        $pendList = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) {
                if ($row['fa'] !== null) {
                    $den++;
                    if ((int)$row['resp_min'] <= $respLimit) {
                        $num++;
                    } else {
                        $breach[] = [
                            'id' => (int)$row['id'], 'ticket_number' => $row['ticket_number'], 'title' => $row['title'],
                            'priority' => $row['priority'], 'status' => $row['status'], 'pelapor' => $row['pelapor_name'],
                            'assignee' => $row['assignee_name'], 'resp_min' => (int)$row['resp_min'],
                            'over_min' => (int)$row['resp_min'] - $respLimit,
                        ];
                    }
                } else {
                    // belum direspons: cek umur kerja
                    $ageR = pg_query_params($conn, "SELECT working_minutes(next_working_start(\$1::timestamp), LOCALTIMESTAMP)", [$row['created_at']]);
                    $age = $ageR ? (int)pg_fetch_result($ageR, 0, 0) : 0;
                    if ($ageR) pg_free_result($ageR);
                    if ($age > $respLimit) {
                        $den++;
                        $breach[] = [
                            'id' => (int)$row['id'], 'ticket_number' => $row['ticket_number'], 'title' => $row['title'],
                            'priority' => $row['priority'], 'status' => $row['status'], 'pelapor' => $row['pelapor_name'],
                            'assignee' => $row['assignee_name'], 'resp_min' => null, 'over_min' => $age - $respLimit,
                        ];
                    } else {
                        $pending++;
                        $pendList[] = [
                            'id' => (int)$row['id'], 'ticket_number' => $row['ticket_number'], 'title' => $row['title'],
                            'priority' => $row['priority'], 'status' => $row['status'], 'age_min' => $age,
                        ];
                    }
                }
            }
            pg_free_result($r);
        }
        usort($breach, fn($a, $b) => $b['over_min'] <=> $a['over_min']);
        pg_close($conn);
        jsonResponse([
            'num' => $num, 'den' => $den, 'pct' => $den ? round($num / $den * 100, 1) : 100, 'target' => $respTarget,
            'pending' => $pending, 'breach' => array_slice($breach, 0, 50), 'pending_list' => array_slice($pendList, 0, 20),
        ]);
    }
    case 'sla_resolution': {
        // Compliance + MTTR per prioritas & divisi (jam kerja)
        [$rc, $rp] = reportConds($apiUser, $dateFrom, $dateTo, $division, $priority, 's');
        $rw = implode(' AND ', $rc);
        $sql = "SELECT s.priority, s.division,
                    COUNT(*) FILTER (WHERE s.status IN ('resolved','closed')) AS done,
                    COUNT(*) FILTER (WHERE s.status IN ('resolved','closed') AND s.resolved_at <= COALESCE(s.sla_due_at, s.resolved_at)) AS ontime,
                    AVG(working_minutes(next_working_start(s.created_at), s.resolved_at)) FILTER (WHERE s.resolved_at IS NOT NULL) AS mttr
                FROM tickets s WHERE $rw GROUP BY s.priority, s.division ORDER BY s.priority, s.division";
        $r = pg_query_params($conn, $sql, $rp);
        $byPri = [];
        $byDiv = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) {
                $done = (int)$row['done'];
                $e = ['done' => $done, 'ontime' => (int)$row['ontime'], 'pct' => $done ? round((int)$row['ontime'] / $done * 100, 1) : 100, 'mttr' => $row['mttr'] !== null ? round((float)$row['mttr']) : null];
                $pk = $row['priority'];
                if (!isset($byPri[$pk])) $byPri[$pk] = ['done' => 0, 'ontime' => 0, 'mttr_sum' => 0, 'mttr_n' => 0];
                $byPri[$pk]['done'] += $e['done'];
                $byPri[$pk]['ontime'] += $e['ontime'];
                if ($e['mttr'] !== null) {
                    $byPri[$pk]['mttr_sum'] += $e['mttr'] * $done;
                    $byPri[$pk]['mttr_n'] += $done;
                }
                $dk = $row['division'] ?: '-';
                if (!isset($byDiv[$dk])) $byDiv[$dk] = ['done' => 0, 'ontime' => 0, 'mttr_sum' => 0, 'mttr_n' => 0];
                $byDiv[$dk]['done'] += $e['done'];
                $byDiv[$dk]['ontime'] += $e['ontime'];
                if ($e['mttr'] !== null) {
                    $byDiv[$dk]['mttr_sum'] += $e['mttr'] * $done;
                    $byDiv[$dk]['mttr_n'] += $done;
                }
            }
            pg_free_result($r);
        }
        $fin = function ($arr) {
            $out = [];
            foreach ($arr as $k => $v) {
                $out[] = ['label' => $k, 'done' => $v['done'], 'ontime' => $v['ontime'], 'pct' => $v['done'] ? round($v['ontime'] / $v['done'] * 100, 1) : 100, 'mttr' => $v['mttr_n'] ? round($v['mttr_sum'] / $v['mttr_n']) : null];
            }
            return $out;
        };
        pg_close($conn);
        jsonResponse(['by_priority' => $fin($byPri), 'by_division' => $fin($byDiv)]);
    }
    case 'breach': {
        // Daftar breach resolusi: open/in_progress overdue + resolved telat
        [$rc, $rp] = reportConds($apiUser, $dateFrom, $dateTo, $division, $priority, 's');
        $rw = implode(' AND ', $rc);
        $sql = "SELECT s.id, s.ticket_number, s.title, s.priority, s.division, s.status, s.created_at, s.sla_due_at,
                    u.name AS pelapor_name, a.name AS assignee_name,
                    CASE WHEN s.status IN ('resolved','closed') AND s.resolved_at IS NOT NULL
                         THEN working_minutes(s.sla_due_at, s.resolved_at)
                         ELSE working_minutes(s.sla_due_at, LOCALTIMESTAMP) END AS over_min
                FROM tickets s
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN users a ON a.id = s.assigned_to
                WHERE $rw
                  AND s.sla_due_at IS NOT NULL
                  AND ((s.status NOT IN ('resolved','closed') AND s.sla_due_at < LOCALTIMESTAMP)
                       OR (s.status IN ('resolved','closed') AND s.resolved_at > s.sla_due_at))
                ORDER BY over_min DESC LIMIT 50";
        $r = pg_query_params($conn, $sql, $rp);
        $out = [];
        if ($r) {
            while ($row = pg_fetch_assoc($r)) {
                $out[] = [
                    'id' => (int)$row['id'], 'ticket_number' => $row['ticket_number'], 'title' => $row['title'],
                    'priority' => $row['priority'], 'division' => $row['division'], 'status' => $row['status'],
                    'pelapor' => $row['pelapor_name'], 'assignee' => $row['assignee_name'],
                    'sla_due' => $row['sla_due_at'], 'over_min' => (int)$row['over_min'],
                ];
            }
            pg_free_result($r);
        }
        pg_close($conn);
        jsonResponse(['data' => $out]);
    }
    default:
        pg_close($conn);
        jsonResponse(['error' => 'Unknown type'], 400);
}
