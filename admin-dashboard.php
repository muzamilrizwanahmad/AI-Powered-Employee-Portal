<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'superadmin']);

$pdo = require __DIR__ . '/config/db.php';

// Quick stats for admin overview
$pendingAdminReqCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_requests WHERE status = "pending"')->fetchColumn();
$pendingLeaveReqCount = (int)$pdo->query('SELECT COUNT(*) FROM leave_requests WHERE status = "pending"')->fetchColumn();
$totalDocCount = (int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
$companyRuleCount = (int)$pdo->query('SELECT COUNT(*) FROM company_rules')->fetchColumn();

// Count unread leave messages from employees for admin attention
$unreadAdminLeaveMsgCount = (int)$pdo->query('
    SELECT COUNT(*) 
    FROM leave_messages lm
    JOIN leave_requests lr ON lm.leave_request_id = lr.id
    WHERE lm.sender_id = lr.user_id AND lm.is_read = 0
')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Employee Portal</title>
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <div class="admin-container">
        <aside class="admin-sidebar">
            <div class="sidebar-top">
                <span class="admin-badge">Employee Portal</span>
                <ul class="sidebar-nav">
                    <li><a href="admin-dashboard.php" class="sidebar-nav-item active"><span>🏠 Admin Home</span></a></li>
                    <li>
                        <a href="leave-approval.php" class="sidebar-nav-item">
                            <span>🏖️ Leave Approval</span>
                            <?php if ($unreadAdminLeaveMsgCount > 0): ?>
                                <span class="nav-counter"><?= $unreadAdminLeaveMsgCount ?> unread</span>
                            <?php elseif ($pendingLeaveReqCount > 0): ?>
                                <span class="nav-counter"><?= $pendingLeaveReqCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="admin-requests.php" class="sidebar-nav-item">
                            <span>🔑 Admin Requests</span>
                            <?php if ($pendingAdminReqCount > 0): ?>
                                <span class="nav-counter"><?= $pendingAdminReqCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="document-custody-tracking.php" class="sidebar-nav-item"><span>📋 Document Tracking</span></a></li>
                    <li><a href="company-rules.php" class="sidebar-nav-item"><span>📜 Company Rules</span></a></li>
                </ul>
            </div>
            <div class="sidebar-footer">
                <div class="admin-user-name"><?= htmlspecialchars($_SESSION['name']) ?></div>
                <div class="admin-user-role">
                    <?= htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin')) ?> &bull; <?= htmlspecialchars($_SESSION['department'] ?? 'Admin') ?>
                </div>
                <a href="logout.php" class="link-logout">Log out</a>
            </div>
        </aside>

        <main class="admin-main">
            <div class="admin-page-header">
                <h1 class="admin-page-title">Admin Control Center</h1>
                <p class="admin-page-subtitle">Manage leave request approvals, admin access requests, document custody tracking, and company rules.</p>
            </div>

            <!-- Profile Info Card -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">Welcome back, <?= htmlspecialchars($_SESSION['name']) ?></h3>
                    <span class="status-pill status-pending"><?= htmlspecialchars(ucfirst($_SESSION['role'] ?? 'admin')) ?></span>
                </div>
                <p style="font-size: 0.95rem; color: var(--ink); margin-bottom: 0.35rem;"><strong>Department:</strong> <?= htmlspecialchars($_SESSION['department'] ?? 'Administration') ?></p>
                <p style="font-size: 0.95rem; color: var(--ink); margin-bottom: 0.35rem;"><strong>Email:</strong> <?= htmlspecialchars($_SESSION['email'] ?? '') ?></p>
            </div>

            <!-- Stat Cards Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $pendingLeaveReqCount ?></div>
                    <div class="stat-label">Pending Leave Requests</div>
                    <?php if ($unreadAdminLeaveMsgCount > 0): ?>
                        <div style="color: var(--red); font-weight: 600; font-size: 0.8rem; margin-top: 0.4rem;">
                            🔴 <?= $unreadAdminLeaveMsgCount ?> unread message<?= $unreadAdminLeaveMsgCount > 1 ? 's' : '' ?>
                        </div>
                    <?php endif; ?>
                    <a href="leave-approval.php" class="btn-pill btn-pill-yellow" style="margin-top: 1rem; text-align: center; justify-content: center;">Review Leaves &rarr;</a>
                </div>

                <div class="stat-card">
                    <div class="stat-number"><?= $pendingAdminReqCount ?></div>
                    <div class="stat-label">Pending Admin Requests</div>
                    <a href="admin-requests.php" class="btn-pill btn-pill-yellow" style="margin-top: 1rem; text-align: center; justify-content: center;">Manage Requests &rarr;</a>
                </div>

                <div class="stat-card">
                    <div class="stat-number"><?= $totalDocCount ?></div>
                    <div class="stat-label">Total Company Documents</div>
                    <a href="document-custody-tracking.php" class="btn-pill btn-pill-yellow" style="margin-top: 1rem; text-align: center; justify-content: center;">Track Custody Logs &rarr;</a>
                </div>

                <div class="stat-card">
                    <div class="stat-number"><?= $companyRuleCount ?></div>
                    <div class="stat-label">Company Rules Defined</div>
                    <a href="company-rules.php" class="btn-pill btn-pill-yellow" style="margin-top: 1rem; text-align: center; justify-content: center;">Manage Rules &rarr;</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
