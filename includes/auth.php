<?php

function checkPermission($action, $user)
{
    $role = $user['role'] ?? null;

    switch ($action) {
        case 'create_ticket':
            return in_array($role, ['admin', 'teknisi', 'pelapor']);
        case 'view_all_tickets':
            return in_array($role, ['admin', 'teknisi']);
        case 'view_own_tickets':
            return in_array($role, ['admin', 'teknisi', 'pelapor']);
        case 'edit_ticket':
            return in_array($role, ['admin', 'teknisi']);
        case 'edit_status':
            return in_array($role, ['admin', 'teknisi']);
        case 'delete_ticket':
            return $role === 'admin';
        case 'manage_users':
            return $role === 'admin';
        case 'view_dashboard':
            return in_array($role, ['admin', 'teknisi', 'pelapor']);
        default:
            return false;
    }
}

function getUserTickets($conn, $userId)
{
    $result = pg_query_params($conn, "SELECT * FROM tickets WHERE user_id = $1 ORDER BY created_at DESC", [$userId]);
    $tickets = [];
    while ($row = pg_fetch_assoc($result)) {
        $tickets[] = $row;
    }
    pg_free_result($result);
    return $tickets;
}

function getAssignedTickets($conn, $division = null)
{
    if ($division) {
        $result = pg_query_params($conn, "SELECT * FROM tickets WHERE division = $1 ORDER BY created_at DESC", [$division]);
    } else {
        $result = pg_query($conn, "SELECT * FROM tickets ORDER BY created_at DESC");
    }
    $tickets = [];
    while ($row = pg_fetch_assoc($result)) {
        $tickets[] = $row;
    }
    pg_free_result($result);
    return $tickets;
}
