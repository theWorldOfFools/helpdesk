<?php
require_once 'config.php';

$conn = getDBConnection();

// Auto-login remember-me sudah ditangani sentral di config.php (tryRememberLogin()).
// Kalau sudah login, langsung arahkan sesuai role
if (isLoggedIn()) {
    $existingUser = getCurrentUser();
    if ($existingUser) {
        $existingRole = $existingUser['role'] ?? '';
        if ($existingRole === 'admin') {
            header('Location: dashboard.php');
        } elseif ($existingRole === 'teknisi') {
            header('Location: index.php');
        } elseif ($existingRole === 'pelapor') {
            header('Location: create_ticket.php');
        } else {
            header('Location: index.php');
        }
        exit;
    }
}

$conn = getDBConnection();

$message = '';
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameValue = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($usernameValue === '' || $password === '') {
        $message = 'Username dan password wajib diisi!';
    } else {
        // Rate limit: max 5 gagal dalam 5 menit per IP+username
        $rl = pg_query_params($conn, "SELECT COUNT(*) FROM login_attempts WHERE ip=$1 AND username=$2 AND success=FALSE AND attempted_at > NOW() - INTERVAL '5 minutes'", [$ip, $usernameValue]);
        $fails = $rl ? (int)pg_fetch_result($rl, 0, 0) : 0;
        if ($rl) pg_free_result($rl);
        if ($fails >= 5) {
            $message = 'Terlalu banyak percobaan gagal. Coba lagi dalam 5 menit.';
        } else {
        $result = pg_query_params($conn, "SELECT * FROM users WHERE username = $1 AND is_active = TRUE", [$usernameValue]);

        if ($result && pg_num_rows($result) > 0) {
            $user = pg_fetch_assoc($result);
            if (verifyPassword($password, $user['password'])) {
                pg_query_params($conn, "INSERT INTO login_attempts (ip, username, success) VALUES ($1,$2,TRUE)", [$ip, $usernameValue]);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['name'] = $user['name'];
                logActivity($conn, $user['id'], 'login', 'Login via form');
                // Remember-me fungsional (checkbox di form)
                if (!empty($_POST['remember'])) {
                    $raw = bin2hex(random_bytes(32));
                    pg_query_params($conn, "UPDATE users SET remember_token = $1 WHERE id = $2", [hash('sha256', $raw), $user['id']]);
                    setcookie('hd_remember', $raw, time() + 30 * 86400, '/', '', !empty($_SERVER['HTTPS']), true);
                }
                $mustChange = !empty($user['must_change_password']) && ($user['must_change_password'] === 't' || $user['must_change_password'] == 1);
                pg_free_result($result);
                pg_close($conn);

                if ($mustChange) {
                    $_SESSION['flash_warn'] = 'Demi keamanan, silakan ganti password default Anda terlebih dahulu.';
                    header('Location: profile.php?force=1');
                    exit;
                }
                // Role-based redirect
                switch ($user['role']) {
                    case 'admin':
                        header('Location: dashboard.php');
                        break;
                    case 'teknisi':
                        header('Location: index.php');
                        break;
                    case 'pelapor':
                        header('Location: create_ticket.php');
                        break;
                    default:
                        header('Location: index.php');
                }
                exit;
            } else {
                pg_query_params($conn, "INSERT INTO login_attempts (ip, username, success) VALUES ($1,$2,FALSE)", [$ip, $usernameValue]);
                $message = 'Username atau password salah!';
            }
        } else {
            pg_query_params($conn, "INSERT INTO login_attempts (ip, username, success) VALUES ($1,$2,FALSE)", [$ip, $usernameValue]);
            $message = 'Username atau password salah!';
        }
        if (isset($result) && $result) {
            pg_free_result($result);
        }
        } // end rate-limit else
    }
}
pg_close($conn);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Helpdesk IT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="bg-decor" aria-hidden="true">
        <span class="blob b1"></span>
        <span class="blob b2"></span>
        <span class="blob b3"></span>
    </div>

    <main class="container min-vh-100 d-flex flex-column align-items-center justify-content-center py-4 position-relative" style="z-index:1;">
        <div class="card border-0 shadow-lg overflow-hidden w-100" style="max-width:920px;">
            <div class="row g-0">
                <aside class="col-lg-6 login-brand-panel text-white p-4 p-lg-5 d-flex flex-column justify-content-center">
                    <div class="brand-badge mb-3" aria-hidden="true"><i class="bi bi-headset fs-3 text-white"></i></div>
                    <h1 class="h2 fw-bold text-white">Helpdesk IT</h1>
                    <p class="text-white-50">Sistem tiket operasional divisi IT. Lapor, pantau, dan selesaikan kendala lebih cepat dalam satu tempat.</p>

                    <div class="d-none d-lg-flex flex-column gap-2 mt-2">
                        <div class="d-flex gap-2 align-items-start bg-white bg-opacity-10 border border-white border-opacity-10 rounded-3 p-2 px-3">
                            <i class="bi bi-lightning-charge-fill"></i>
                            <div><strong class="d-block small">Respon cepat</strong><span class="small text-white-50">Setiap laporan otomatis bernomor dan tercatat rapi.</span></div>
                        </div>
                        <div class="d-flex gap-2 align-items-start bg-white bg-opacity-10 border border-white border-opacity-10 rounded-3 p-2 px-3">
                            <i class="bi bi-shield-lock-fill"></i>
                            <div><strong class="d-block small">Akses berbasis peran</strong><span class="small text-white-50">Admin, teknisi, dan pelapor punya alur halaman masing-masing.</span></div>
                        </div>
                        <div class="d-flex gap-2 align-items-start bg-white bg-opacity-10 border border-white border-opacity-10 rounded-3 p-2 px-3">
                            <i class="bi bi-graph-up-arrow"></i>
                            <div><strong class="d-block small">Monitoring real-time</strong><span class="small text-white-50">Pantau status open, in-progress, resolved, dan closed.</span></div>
                        </div>
                    </div>

                    <div class="d-none d-lg-flex gap-4 mt-4 small text-white-50">
                        <div><strong class="d-block text-white">3 Role</strong>Admin · Teknisi · Pelapor</div>
                        <div><strong class="d-block text-white">24/7</strong>Akses kapan saja</div>
                        <div><strong class="d-block text-white">100%</strong>Data tercatat</div>
                    </div>
                </aside>

                <section class="col-lg-6 p-4 p-lg-5">
                    <h2 class="h4 fw-bold">Selamat datang kembali</h2>
                    <p class="text-secondary small mb-4">Masuk untuk melanjutkan ke area kerja sesuai peran kamu.</p>

                    <?php if ($message): ?>
                        <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
                            <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-1"></i>
                            <span><?php echo htmlspecialchars($message); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" autocomplete="on">
                        <div class="mb-3">
                            <label for="username" class="form-label fw-semibold small">Username</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="username" name="username" required placeholder="cth: admin" autocomplete="username" value="<?php echo htmlspecialchars($usernameValue); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label fw-semibold small">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" required placeholder="Masukkan password" autocomplete="current-password">
                                <button type="button" class="btn btn-outline-secondary" id="togglePass" aria-label="Tampilkan password">
                                    <i class="bi bi-eye" id="eyeOpen"></i>
                                    <i class="bi bi-eye-slash" id="eyeClosed" style="display:none;"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3 small text-secondary">
                            <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="remember" value="1" checked> Ingat saya</label>
                            <span>Sistem tiket internal IT</span>
                        </div>

                        <button type="submit" class="btn btn-danger w-100 py-2 fw-bold">
                            Masuk ke Dashboard <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </form>

                    <div class="d-flex align-items-center gap-2 my-4 text-secondary small text-uppercase"><hr class="flex-grow-1"><span>Akun demo — klik untuk isi</span><hr class="flex-grow-1"></div>
                    <div class="row g-2">
                        <div class="col-4">
                            <button type="button" class="btn btn-outline-secondary w-100 demo-chip" data-user="admin" data-pass="admin123">
                                <strong class="d-block small"><span class="badge bg-danger me-1">&nbsp;</span>Admin</strong>
                                <span class="small text-secondary">admin / admin123</span>
                            </button>
                        </div>
                        <div class="col-4">
                            <button type="button" class="btn btn-outline-secondary w-100 demo-chip" data-user="teknisi1" data-pass="admin123">
                                <strong class="d-block small"><span class="badge bg-primary me-1">&nbsp;</span>Teknisi</strong>
                                <span class="small text-secondary">teknisi1 / admin123</span>
                            </button>
                        </div>
                        <div class="col-4">
                            <button type="button" class="btn btn-outline-secondary w-100 demo-chip" data-user="pelapor1" data-pass="admin123">
                                <strong class="d-block small"><span class="badge bg-success me-1">&nbsp;</span>Pelapor</strong>
                                <span class="small text-secondary">pelapor1 / admin123</span>
                            </button>
                        </div>
                    </div>

                    <div class="alert alert-light border mt-3 small text-secondary mb-0">
                        Setelah login kamu diarahkan otomatis:<br>
                        <strong>Admin</strong> → Dashboard &nbsp;·&nbsp; <strong>Teknisi</strong> → Daftar Tiket &nbsp;·&nbsp; <strong>Pelapor</strong> → Buat Tiket
                    </div>
                </section>
            </div>
        </div>
        <p class="text-white-50 small mt-3 mb-0">&copy; <?php echo date('Y'); ?> Helpdesk IT · Sistem Tiket Operasional Divisi IT</p>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            var pass = document.getElementById('password');
            var btn = document.getElementById('togglePass');
            var eyeOpen = document.getElementById('eyeOpen');
            var eyeClosed = document.getElementById('eyeClosed');
            if (btn && pass) {
                btn.addEventListener('click', function () {
                    var show = pass.type === 'password';
                    pass.type = show ? 'text' : 'password';
                    eyeOpen.style.display = show ? 'none' : '';
                    eyeClosed.style.display = show ? '' : 'none';
                    btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
                    pass.focus();
                });
            }
            var userInput = document.getElementById('username');
            document.querySelectorAll('.demo-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    userInput.value = chip.getAttribute('data-user');
                    pass.value = chip.getAttribute('data-pass');
                    userInput.focus();
                });
            });
            var firstInput = document.getElementById('username');
            if (firstInput && !firstInput.value) firstInput.focus();
        })();
    </script>
</body>
</html>
