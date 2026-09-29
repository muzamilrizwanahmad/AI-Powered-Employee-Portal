<?php
require_once __DIR__ . '/includes/auth.php';

// Redirect logged-in users to their respective dashboard, or guests to login.php
if (is_logged_in()) {
    $role = $_SESSION['role'] ?? 'employee';
    if (in_array($role, ['admin', 'superadmin'], true)) {
        header('Location: admin-dashboard.php');
        exit;
    } else {
        header('Location: employee-dashboard.php');
        exit;
    }
}

header('Location: login.php');
exit;
