<?php
// Header layout Bootstrap 5 + sidebar responsif (offcanvas di mobile).
// Self-sufficient: muat config bila belum ada.
if (!function_exists('getCurrentUser')) {
    require_once __DIR__ . '/../config.php';
}

$currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
$currentRole = $currentUser['role'] ?? null;
$activePage = basename($_SERVER['PHP_SELF'] ?? '');
if (!isset($pageTitle) || !$pageTitle) {
    $pageTitle = 'Dashboard';
}

function side_link_active($files, $activePage) {
    if (is_string($files)) $files = [$files];
    return in_array($activePage, $files) ? ' active' : '';
}

$roleLabel = ['admin' => 'Administrator', 'teknisi' => 'Teknisi', 'pelapor' => 'Pelapor'][$currentRole] ?? ($currentRole ?? 'Tamu');
$userInitial = $currentUser ? strtoupper(mb_substr($currentUser['name'] ?? $currentUser['username'] ?? 'U', 0, 1)) : 'T';
$roleBadge = ['admin' => 'text-bg-danger', 'teknisi' => 'text-bg-primary', 'pelapor' => 'text-bg-success'][$currentRole] ?? 'text-bg-secondary';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Helpdesk IT - <?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1a1a2e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <?php if (!empty($currentUser)): ?>
    <!-- DataTables + Bootstrap 5, dimuat hanya saat login -->
    <link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.min.css">
    <style>
      table.dataTable { font-size: .875rem; }
      table.dataTable td { vertical-align: middle; }
      .dataTables_wrapper .dataTables_paginate .paginate_button { padding: .25em .6em; }
    </style>
    <?php endif; ?>
    <?php if (!empty($currentUser) && in_array($activePage, ['dashboard.php', 'laporan.php'], true)): ?>
    <!-- Chart.js gratis untuk dashboard & laporan -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <?php endif; ?>
    <?php if (!empty($currentUser)): ?><meta name="user-role" content="<?php echo htmlspecialchars($currentRole ?? ''); ?>"><?php endif; ?>
    <?php if (!empty($currentUser)): ?><meta name="user-id" content="<?php echo (int)$currentUser['id']; ?>"><?php endif; ?>
    <?php if (!empty($currentUser)): ?><meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
</head>
<body class="bg-body-tertiary"<?php if (!empty($currentUser)) echo ' data-role="' . htmlspecialchars($currentRole ?? '') . '"'; ?>>
<?php if ($currentUser): ?>
<div class="d-flex min-vh-100">

    <!-- Sidebar: statis di desktop, offcanvas di mobile -->
    <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Menu utama">
        <div class="offcanvas-header d-lg-none border-bottom border-white border-opacity-10">
            <span class="fw-bold text-white">Helpdesk IT</span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Tutup menu"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column flex-grow-1 p-0">
            <div class="d-flex align-items-center gap-2 px-3 pt-3 pb-2">
                <span class="side-logo" aria-hidden="true"><i class="bi bi-headset"></i></span>
                <span class="flex-grow-1 lh-sm">
                    <strong class="d-block text-white">Helpdesk IT</strong>
                    <small class="text-white-50">Sistem Tiket Operasional</small>
                </span>
                <span class="badge <?php echo $roleBadge; ?>"><?php echo htmlspecialchars($currentRole); ?></span>
            </div>

            <div class="d-flex align-items-center gap-2 m-3 p-2 rounded-3 bg-white bg-opacity-10 border border-white border-opacity-10">
                <span class="side-avatar" aria-hidden="true"><?php echo htmlspecialchars($userInitial); ?></span>
                <span class="text-truncate">
                    <strong class="d-block text-white text-truncate"><?php echo htmlspecialchars($currentUser['name']); ?></strong>
                    <small class="text-white-50"><?php echo htmlspecialchars($roleLabel); ?> · <?php echo htmlspecialchars($currentUser['division'] ?? '-'); ?></small>
                </span>
            </div>

            <nav class="nav nav-pills flex-column gap-1 px-3 pb-3 flex-grow-1">
                <p class="side-label">Menu Utama</p>

                <?php if ($currentRole === 'admin'): ?>
                    <a href="dashboard.php" class="nav-link side-link<?php echo side_link_active('dashboard.php', $activePage); ?>">
                        <i class="bi bi-grid-1x2"></i> Dashboard
                    </a>
                    <a href="index.php" class="nav-link side-link<?php echo side_link_active('index.php', $activePage); ?>">
                        <i class="bi bi-ticket-detailed"></i> Semua Tiket
                    </a>
                    <a href="create_ticket.php" class="nav-link side-link<?php echo side_link_active('create_ticket.php', $activePage); ?>">
                        <i class="bi bi-plus-circle"></i> Buat Tiket
                    </a>
                    <p class="side-label">Change Request</p>
                    <a href="cr_list.php" class="nav-link side-link<?php echo side_link_active(['cr_list.php','view_cr.php','edit_cr.php'], $activePage); ?>">
                        <i class="bi bi-arrow-repeat"></i> Daftar CR
                    </a>
                    <a href="create_cr.php" class="nav-link side-link<?php echo side_link_active('create_cr.php', $activePage); ?>">
                        <i class="bi bi-plus-square"></i> Buat CR
                    </a>
                    <a href="laporan.php" class="nav-link side-link<?php echo side_link_active('laporan.php', $activePage); ?>">
                        <i class="bi bi-clipboard-data"></i> Laporan
                    </a>
                    <a href="kb.php" class="nav-link side-link<?php echo side_link_active(['kb.php', 'kb_manage.php'], $activePage); ?>">
                        <i class="bi bi-journal-text"></i> Basis Solusi
                    </a>
                    <p class="side-label">Mastering</p>
                    <a href="user_management.php" class="nav-link side-link<?php echo side_link_active('user_management.php', $activePage); ?>">
                        <i class="bi bi-people"></i> Kelola Pengguna
                    </a>
                    <a href="dashboard.php#divisi" class="nav-link side-link">
                        <i class="bi bi-building"></i> Data Divisi
                    </a>

                <?php elseif ($currentRole === 'teknisi'): ?>
                    <a href="dashboard.php" class="nav-link side-link<?php echo side_link_active('dashboard.php', $activePage); ?>">
                        <i class="bi bi-lightning-charge"></i> Dashboard Kerja
                    </a>
                    <a href="index.php" class="nav-link side-link<?php echo side_link_active(['index.php','view_ticket.php','edit_ticket.php'], $activePage); ?>">
                        <i class="bi bi-ticket-detailed"></i> Antrean Tiket
                    </a>
                    <a href="index.php?filter_status=open" class="nav-link side-link">
                        <i class="bi bi-clock-history"></i> Perlu Ditangani
                    </a>
                    <a href="create_ticket.php" class="nav-link side-link<?php echo side_link_active('create_ticket.php', $activePage); ?>">
                        <i class="bi bi-plus-circle"></i> Buat Tiket
                    </a>
                    <p class="side-label">Change Request</p>
                    <a href="cr_list.php" class="nav-link side-link<?php echo side_link_active(['cr_list.php','view_cr.php','edit_cr.php'], $activePage); ?>">
                        <i class="bi bi-arrow-repeat"></i> Daftar CR
                    </a>
                    <a href="create_cr.php" class="nav-link side-link<?php echo side_link_active('create_cr.php', $activePage); ?>">
                        <i class="bi bi-plus-square"></i> Buat CR
                    </a>
                    <a href="laporan.php" class="nav-link side-link<?php echo side_link_active('laporan.php', $activePage); ?>">
                        <i class="bi bi-clipboard-data"></i> Laporan
                    </a>
                    <a href="kb.php" class="nav-link side-link<?php echo side_link_active('kb.php', $activePage); ?>">
                        <i class="bi bi-journal-text"></i> Basis Solusi
                    </a>

                <?php else: /* pelapor */ ?>
                    <a href="dashboard.php" class="nav-link side-link<?php echo side_link_active('dashboard.php', $activePage); ?>">
                        <i class="bi bi-house"></i> Dashboard Saya
                    </a>
                    <a href="index.php" class="nav-link side-link<?php echo side_link_active(['index.php','view_ticket.php'], $activePage); ?>">
                        <i class="bi bi-ticket-detailed"></i> Tiket Saya
                    </a>
                    <a href="create_ticket.php" class="nav-link side-link side-cta<?php echo side_link_active('create_ticket.php', $activePage); ?>">
                        <i class="bi bi-plus-lg"></i> Buat Tiket Baru
                    </a>
                    <a href="cr_list.php" class="nav-link side-link<?php echo side_link_active(['cr_list.php','view_cr.php'], $activePage); ?>">
                        <i class="bi bi-arrow-repeat"></i> CR Saya
                    </a>
                    <a href="create_cr.php" class="nav-link side-link side-cta<?php echo side_link_active('create_cr.php', $activePage); ?>">
                        <i class="bi bi-plus-lg"></i> Buat CR Baru
                    </a>
                    <a href="laporan.php" class="nav-link side-link<?php echo side_link_active('laporan.php', $activePage); ?>">
                        <i class="bi bi-clipboard-data"></i> Laporan Saya
                    </a>
                    <a href="kb.php" class="nav-link side-link<?php echo side_link_active('kb.php', $activePage); ?>">
                        <i class="bi bi-journal-text"></i> Basis Solusi
                    </a>
                <?php endif; ?>

                <p class="side-label">Akun</p>
                <a href="profile.php" class="nav-link side-link<?php echo side_link_active('profile.php', $activePage); ?>">
                    <i class="bi bi-person-circle"></i> Profil Saya
                </a>
                <a href="notifications.php" class="nav-link side-link<?php echo side_link_active('notifications.php', $activePage); ?>">
                    <i class="bi bi-bell"></i> Notifikasi
                </a>
                <a href="logout.php" class="nav-link side-link side-logout">
                    <i class="bi bi-box-arrow-right"></i> Keluar
                </a>
            </nav>

            <div class="text-center text-white-50 small p-3 border-top border-white border-opacity-10">© <?php echo date('Y'); ?> Helpdesk IT</div>
        </div>
    </aside>

    <div class="flex-grow-1 d-flex flex-column" style="min-width:0;">
        <!-- Topbar full-width -->
        <header class="navbar sticky-top bg-white border-bottom px-3 px-lg-4 py-2">
            <button class="btn btn-outline-secondary d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Buka/tutup menu">
                <i class="bi bi-list"></i>
            </button>
            <div class="flex-grow-1">
                <h1 class="h5 mb-0 text-truncate"><?php echo htmlspecialchars($pageTitle); ?></h1>
                <small class="text-secondary"><?php echo htmlspecialchars($roleLabel); ?> · <?php echo date('d M Y'); ?></small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="create_ticket.php" class="btn btn-primary btn-sm d-none d-md-inline-block">+ Tiket Baru</a>
                <a href="create_cr.php" class="btn btn-outline-primary btn-sm d-none d-md-inline-block">+ CR</a>
                <?php
                $notifConn = getDBConnection();
                $unreadN = unreadNotifCount($notifConn, $currentUser['id']);
                pg_close($notifConn);
                ?>
                <a href="notifications.php" class="btn btn-outline-secondary btn-sm position-relative" title="Notifikasi" aria-label="Notifikasi">
                    <i class="bi bi-bell"></i>
                    <?php if ($unreadN > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo $unreadN > 9 ? '9+' : $unreadN; ?></span><?php endif; ?>
                </a>
                <a href="profile.php" class="text-decoration-none"><span class="topbar-user" title="<?php echo htmlspecialchars($currentUser['name']); ?>"><?php echo htmlspecialchars($userInitial); ?></span></a>
            </div>
        </header>
        <!-- Konten FULL: container-fluid tanpa max-width -->
        <main class="container-fluid py-3 py-lg-4 flex-grow-1">
<?php else: ?>
<div class="guest-wrap">
<?php endif; ?>
