<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Helpdesk IT - <?php echo isset($pageTitle) ? $pageTitle : 'Sistem Tiket'; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php
    $currentUser = null;
    if (function_exists('getCurrentUser')) {
        $currentUser = getCurrentUser();
    }
    ?>
    <?php if ($currentUser): ?>
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="nav-brand">Helpdesk IT</a>
            <div class="nav-links">
                <a href="index.php">Tiket</a>
                <a href="dashboard.php">Dashboard</a>
                <a href="create_ticket.php" class="btn-nav">+ Tiket Baru</a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                <a href="user_management.php" class="btn-nav">User</a>
                <?php endif; ?>
                <div class="nav-user">
                    <span class="nav-user-name"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                    <span class="nav-user-role"><?php echo htmlspecialchars($currentUser['role']); ?></span>
                    <a href="logout.php" class="btn-nav">Logout</a>
                </div>
            </div>
        </div>
    </nav>
    <?php else: ?>
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="nav-brand">Helpdesk IT</a>
            <div class="nav-links">
                <a href="login.php" class="btn-nav">Masuk</a>
            </div>
        </div>
    </nav>
    <?php endif; ?>
    <main class="container">
