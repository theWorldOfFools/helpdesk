<?php

define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'helpdesk_db');
define('DB_USER', 'postgres');
define('DB_PASS', '');

session_start();

function getDBConnection()
{
    $conn = pg_connect(
        "host=" . DB_HOST .
        " port=" . DB_PORT .
        " dbname=" . DB_NAME .
        " user=" . DB_USER .
        " password=" . DB_PASS
    );

    if (!$conn) {
        die("Koneksi database gagal: " . pg_last_error());
    }

    return $conn;
}

function generateTicketNumber()
{
    $conn = getDBConnection();
    $result = pg_query($conn, "SELECT TO_CHAR(NOW(), 'YYYYMM') || '-' || LPAD(COALESCE(MAX(CAST(SUBSTRING(ticket_number FROM '[0-9]+$') AS INTEGER)) + 1, 1), 4, '0') FROM tickets");
    $ticketNumber = pg_fetch_result($result, 0, 0);
    pg_close($conn);
    return $ticketNumber;
}

function hashPassword($password)
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

function getCurrentUser()
{
    if (isset($_SESSION['user_id'])) {
        $conn = getDBConnection();
        $id = (int)$_SESSION['user_id'];
        $result = pg_query_params($conn, "SELECT * FROM users WHERE id = $1 AND is_active = TRUE", [$id]);
        if ($result && pg_num_rows($result) > 0) {
            $user = pg_fetch_assoc($result);
            pg_free_result($result);
            pg_close($conn);
            return $user;
        }
        pg_free_result($result);
        pg_close($conn);
    }
    return null;
}

function isLoggedIn()
{
    return getCurrentUser() !== null;
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireRole($allowedRoles)
{
    requireLogin();
    $user = getCurrentUser();
    if (!in_array($user['role'], $allowedRoles)) {
        header('Location: index.php');
        exit;
    }
    return $user;
}

function canEditTicket($ticket, $user)
{
    if ($user['role'] === 'admin') return true;
    if ($user['role'] === 'teknisi') return true;
    return false;
}

function canDeleteTicket($ticket, $user)
{
    return $user['role'] === 'admin';
}

function canViewTicket($ticket, $user)
{
    if ($user['role'] === 'admin' || $user['role'] === 'teknisi') return true;
    if ($user['role'] === 'pelapor' && (int)$ticket['user_id'] === (int)$user['id']) return true;
    return false;
}
