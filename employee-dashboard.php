<?php
require_once __DIR__ . '/includes/auth.php';
require_role('employee');

$pdo = require __DIR__ . '/config/db.php';

$userId = $_SESSION['user_id'];
$success = '';
$errors = [];

// Handle Admin Access Request POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_admin') {
    // Check if user already has a pending request
    $chk = $pdo->prepare('SELECT COUNT(*) FROM admin_requests WHERE user_id = ? AND status = "pending"');
    $chk->execute([$userId]);

    if ((int)$chk->fetchColumn() > 0) {
        $errors[] = 'You already have a pending admin access request.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO admin_requests (user_id, status, requested_at) VALUES (?, "pending", CURRENT_TIMESTAMP)');
        $stmt->execute([$userId]);
        $success = 'Your request for admin access has been sent successfully.';
    }
}

// Fetch latest admin access request for this user
$reqStmt = $pdo->prepare('SELECT status, requested_at, resolved_at FROM admin_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 1');
$reqStmt->execute([$userId]);
$latestAdminReq = $reqStmt->fetch(PDO::FETCH_ASSOC);

// Count total unread leave messages for this employee
$unreadStmt = $pdo->prepare('
    SELECT COUNT(*) 
    FROM leave_messages lm
    JOIN leave_requests lr ON lm.leave_request_id = lr.id
    WHERE lr.user_id = ? AND (lm.sender_id != ? OR lm.sender_id IS NULL) AND lm.is_read = 0
');
$unreadStmt->execute([$userId, $userId]);
$unreadLeaveMsgCount = (int)$unreadStmt->fetchColumn();

// Count pending leave requests for this employee
$pendingLeaveStmt = $pdo->prepare('SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND status = "pending"');
$pendingLeaveStmt->execute([$userId]);
$pendingLeaveCount = (int)$pendingLeaveStmt->fetchColumn();

// Count documents in custody for this employee
$myDocStmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE current_holder_id = ?');
$myDocStmt->execute([$userId]);
$myDocCount = (int)$myDocStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - Employee Portal</title>
    <link rel="stylesheet" href="css/employee.css">
</head>
<body>
    <div class="employee-container">
        <aside class="employee-sidebar">
            <div class="sidebar-top">
                <span class="employee-badge">Employee Portal</span>
                <ul class="sidebar-nav">
                    <li><a href="employee-dashboard.php" class="sidebar-nav-item active"><span>🏠 Dashboard</span></a></li>
                    <li>
                        <a href="leave-request.php" class="sidebar-nav-item">
                            <span>📝 Leave Requests</span>
                            <?php if ($unreadLeaveMsgCount > 0): ?>
                                <span class="nav-counter"><?= $unreadLeaveMsgCount ?> unread</span>
                            <?php elseif ($pendingLeaveCount > 0): ?>
                                <span class="nav-counter"><?= $pendingLeaveCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="document-custody.php" class="sidebar-nav-item"><span>📁 Document Custody</span></a></li>
                    <li><a href="ai-helper.php" class="sidebar-nav-item"><span>🤖 AI Helper</span></a></li>
                    <li><a href="ai-judgement.php" class="sidebar-nav-item"><span>⚖️ AI Judgement</span></a></li>
                </ul>
            </div>

            <div class="sidebar-footer">
                <div class="employee-user-name"><?= htmlspecialchars($_SESSION['name']) ?></div>
                <div class="employee-user-dept"><?= htmlspecialchars($_SESSION['department'] ?? 'Employee') ?></div>

                <!-- Admin Privileges Status in Sidebar Footer -->
                <?php if ($latestAdminReq && $latestAdminReq['status'] === 'pending'): ?>
                    <span class="status-pill status-pending" style="font-size: 0.75rem; text-align: center;">⏳ Admin Request Pending</span>
                <?php elseif ($latestAdminReq && $latestAdminReq['status'] === 'rejected'): ?>
                    <form action="employee-dashboard.php" method="POST" style="margin-top: 0.2rem;">
                        <input type="hidden" name="action" value="request_admin">
                        <button type="submit" class="btn-pill btn-pill-reject-outline" style="font-size: 0.75rem; width: 100%; justify-content: center;">Re-submit Admin Access</button>
                    </form>
                <?php else: ?>
                    <form action="employee-dashboard.php" method="POST" style="margin-top: 0.2rem;">
                        <input type="hidden" name="action" value="request_admin">
                        <button type="submit" class="btn-pill btn-pill-outline" style="font-size: 0.75rem; width: 100%; justify-content: center;">🔐 Request Admin Access</button>
                    </form>
                <?php endif; ?>

                <a href="logout.php" class="link-logout">Log out</a>
            </div>
        </aside>

        <main class="employee-main">
            <div class="page-header">
                <h1 class="page-title">Welcome back, <?= htmlspecialchars($_SESSION['name']) ?></h1>
                <p class="page-subtitle">Manage your leave requests, document custody, and portal access.</p>
            </div>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $err): ?>
                        <div><?= htmlspecialchars($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Stat Cards Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $pendingLeaveCount ?></div>
                    <div class="stat-label">Pending Leave Requests</div>
                    <a href="leave-request.php" class="btn-pill btn-pill-yellow" style="margin-top: 1rem; text-align: center; justify-content: center;">Apply / View Leaves &rarr;</a>
                </div>

                <div class="stat-card">
                    <div class="stat-number"><?= $myDocCount ?></div>
                    <div class="stat-label">Documents in Your Custody</div>
                    <a href="document-custody.php" class="btn-pill btn-pill-yellow" style="margin-top: 1rem; text-align: center; justify-content: center;">Manage Documents &rarr;</a>
                </div>

                <div class="stat-card">
                    <div class="stat-number"><?= $unreadLeaveMsgCount ?></div>
                    <div class="stat-label">Unread Discussion Messages</div>
                    <a href="leave-request.php" class="btn-pill btn-pill-yellow" style="margin-top: 1rem; text-align: center; justify-content: center;">View Messages &rarr;</a>
                </div>
            </div>

            <!-- Profile Overview Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Employee Profile Overview</h3>
                    <span class="status-pill status-pending"><?= htmlspecialchars(ucfirst($_SESSION['role'] ?? 'employee')) ?></span>
                </div>
                <p style="font-size: 0.95rem; color: var(--ink); margin-bottom: 0.35rem;"><strong>Department:</strong> <?= htmlspecialchars($_SESSION['department'] ?? 'N/A') ?></p>
                <p style="font-size: 0.95rem; color: var(--ink); margin-bottom: 0.35rem;"><strong>Email:</strong> <?= htmlspecialchars($_SESSION['email'] ?? 'N/A') ?></p>
            </div>

            <!-- Quick Action Features Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--ink);">AI HR Helper</h3>
                    <p style="font-size: 0.85rem; color: var(--grey-text); margin-bottom: 1rem;">Ask questions about official company rules, casual/sick leave entitlements, or policy guidance.</p>
                    <a href="ai-helper.php" class="btn-pill btn-pill-outline" style="text-align: center; justify-content: center;">Ask AI Helper &rarr;</a>
                </div>

                <div class="stat-card">
                    <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--ink);">AI Workplace Judgement</h3>
                    <p style="font-size: 0.85rem; color: var(--grey-text); margin-bottom: 1rem;">Submit workplace dispute scenarios and perspectives for an impartial AI mediation assessment.</p>
                    <a href="ai-judgement.php" class="btn-pill btn-pill-outline" style="text-align: center; justify-content: center;">Dispute Mediation &rarr;</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
