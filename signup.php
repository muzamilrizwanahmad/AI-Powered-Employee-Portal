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

$errors = [];
$name = '';
$email = '';
$department = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($name) || empty($email) || empty($department) || empty($password) || empty($confirm_password)) {
        $errors[] = 'All fields are required.';
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!empty($password) && strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    // Check if email already registered
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = 'Email is already registered.';
        }
    }

    // Insert new user if valid
    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        $insertStmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, department, role) VALUES (?, ?, ?, ?, ?)');
        $insertStmt->execute([$name, $email, $passwordHash, $department, 'employee']);

        header('Location: login.php?signup=success');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Signup - Employee Portal</title>
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
                    <h1 class="auth-title">Create your account</h1>
                    <p class="auth-subtitle">Sign up to access your workspace</p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?= htmlspecialchars($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form action="signup.php" method="POST" class="auth-form">
                    <div class="form-group">
                        <label for="name">Full name</label>
                        <input type="text" id="name" name="name" class="form-control" value="<?= htmlspecialchars($name) ?>" placeholder="e.g. Jane Doe" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>" placeholder="e.g. jane@company.com" required>
                    </div>

                    <div class="form-group">
                        <label for="department">Department name</label>
                        <input type="text" id="department" name="department" class="form-control" value="<?= htmlspecialchars($department) ?>" placeholder="e.g. Human Resources" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="password" name="password" class="form-control" placeholder="At least 6 characters" required>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('password', this)" aria-label="Toggle password visibility">
                                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm password</label>
                        <div class="input-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter password" required>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('confirm_password', this)" aria-label="Toggle password visibility">
                                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">Sign Up</button>
                </form>
            </div>

            <div class="auth-footer">
                Already have an account? <a href="login.php">Log in</a>
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
