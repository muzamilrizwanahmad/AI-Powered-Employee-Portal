<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'employee') {
        header('Location: employee-dashboard.php');
    } else {
        header('Location: admin-dashboard.php');
    }
    exit;
}

$pdo = require_once __DIR__ . '/config/db.php';

$error = '';
$success = '';
$email = '';

if (isset($_GET['signup']) && $_GET['signup'] === 'success') {
    $success = 'Registration successful! Please log in with your credentials.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Invalid email or password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Regenerate session ID for security
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department'] = $user['department'];

            // Redirect based on user role
            if ($user['role'] === 'admin' || $user['role'] === 'superadmin') {
                header('Location: admin-dashboard.php');
            } else {
                header('Location: employee-dashboard.php');
            }
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Employee Portal</title>
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="public-assets/css/auth.css">
</head>
<body>
    <div class="auth-wrapper">
        <!-- LEFT PANEL: Form Side -->
        <div class="auth-left">
            <div>
                <div class="auth-header">
                    <span class="auth-badge">Employee Portal</span>
                    <h1 class="auth-title">Welcome back</h1>
                    <p class="auth-subtitle">Log in to your workspace</p>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" class="auth-form">
                    <div class="form-group">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>" placeholder="e.g. user@company.com" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="password" name="password" class="form-control" placeholder="Enter password" required>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('password', this)" aria-label="Toggle password visibility">
                                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">Log In</button>
                </form>
            </div>

            <div class="auth-footer">
                Don't have an account? <a href="signup.php">Sign up</a>
            </div>
        </div>

        <!-- RIGHT PANEL: Visual Side -->
        <div class="auth-right">
            <button type="button" class="auth-close-btn" aria-label="Close">×</button>

            <!-- Card 1: Top-Left Area -->
            <div class="floating-card card-top-left">
                <div class="card-title">
                    <span class="status-dot green"></span>
                    Leave Request Approved
                </div>
                <div class="card-subtitle">Ahmed K. — Today, 10:42am</div>
            </div>

            <!-- Card 2: Middle-Right Area - Week Strip -->
            <div class="floating-card card-middle-right">
                <div class="week-strip">
                    <div class="day-box">Sun</div>
                    <div class="day-box">Mon</div>
                    <div class="day-box">Tue</div>
                    <div class="day-box active">Wed</div>
                    <div class="day-box">Thu</div>
                    <div class="day-box">Fri</div>
                    <div class="day-box">Sat</div>
                </div>
            </div>

            <!-- Card 3: Bottom Area - Document Handover -->
            <div class="floating-card card-bottom">
                <div class="doc-info">
                    <span class="doc-title">Document Handover</span>
                    <span class="doc-subtext">IT Policy.pdf → Sana R.</span>
                </div>
                <div class="avatar-stack">
                    <div class="avatar avatar-1">AK</div>
                    <div class="avatar avatar-2">SR</div>
                    <div class="avatar avatar-3">MA</div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        btn.innerHTML = isPassword 
            ? '<svg viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>'
            : '<svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    }
    </script>
</body>
</html>
