<?php
require_once 'config.php';

$pageTitle = 'Login';
$conn = getDBConnection();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = pg_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    $result = pg_query_params($conn, "SELECT * FROM users WHERE username = $1 AND is_active = TRUE", [$username]);

    if ($result && pg_num_rows($result) > 0) {
        $user = pg_fetch_assoc($result);
        if (verifyPassword($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['name'] = $user['name'];
            pg_free_result($result);
            pg_close($conn);
            header('Location: index.php');
            exit;
        } else {
            $message = 'Username atau password salah!';
            $messageType = 'danger';
        }
    } else {
        $message = 'Username atau password salah!';
        $messageType = 'danger';
    }
    pg_free_result($result);
}
pg_close($conn);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Helpdesk IT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            background: #fff;
            border-radius: 12px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .login-container h1 {
            text-align: center;
            color: #1a1a2e;
            margin-bottom: 10px;
        }
        .login-container .subtitle {
            text-align: center;
            color: #999;
            margin-bottom: 30px;
            font-size: 0.9rem;
        }
        .login-container .form-group {
            margin-bottom: 15px;
        }
        .login-container .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        .login-container .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        .login-container .form-group input:focus {
            outline: none;
            border-color: #1a1a2e;
        }
        .login-container .btn-primary {
            width: 100%;
            padding: 12px;
            font-size: 1rem;
        }
        .login-container .login-info {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            font-size: 0.8rem;
            color: #666;
        }
        .login-container .login-info strong {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Helpdesk IT</h1>
        <p class="subtitle">Sistem Tiket Operasional Divisi IT</p>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required placeholder="Masukkan username">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Masukkan password">
            </div>
            <button type="submit" class="btn btn-primary">Masuk</button>
        </form>
        <div class="login-info">
            <strong>Info Login:</strong><br>
            Admin: admin / admin123<br>
            Teknisi: teknisi1 / admin123<br>
            Pelapor: pelapor1 / admin123
        </div>
    </div>
</body>
</html>
<?php pg_close($conn); ?>
