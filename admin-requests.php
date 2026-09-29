<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'superadmin']);

$pdo = require __DIR__ . '/config/db.php';

$adminId = $_SESSION['user_id'];
$success = '';
$errors = [];

// Quick stats for sidebar badges
$pendingLeaveReqCount = (int)$pdo->query('SELECT COUNT(*) FROM leave_requests WHERE status = "pending"')->fetchColumn();
$unreadAdminLeaveMsgCount = (int)$pdo->query('
    SELECT COUNT(*) 
    FROM leave_messages lm
    JOIN leave_requests lr ON lm.leave_request_id = lr.id
    WHERE lm.sender_id = lr.user_id AND lm.is_read = 0
')->fetchColumn();

// Handle Approve / Reject Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reqId = (int)($_POST['req_id'] ?? 0);

    if ($reqId > 0 && in_array($action, ['approve', 'reject'], true)) {
        // Fetch request and user info
        $chk = $pdo->prepare('SELECT ar.*, u.name FROM admin_requests ar JOIN users u ON ar.user_id = u.id WHERE ar.id = ? AND ar.status = "pending"');
        $chk->execute([$reqId]);
        $req = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            $errors[] = 'Admin request not found or already processed.';
        } else {
            $targetUserId = (int)$req['user_id'];
            $userName = $req['name'];

            if ($action === 'approve') {
                try {
                    $pdo->beginTransaction();

                    // Update request record
                    $updReq = $pdo->prepare('UPDATE admin_requests SET status = "approved", resolved_at = CURRENT_TIMESTAMP, resolved_by = ? WHERE id = ?');
                    $updReq->execute([$adminId, $reqId]);

                    // Promote user to admin
                    $updUser = $pdo->prepare('UPDATE users SET role = "admin" WHERE id = ?');
                    $updUser->execute([$targetUserId]);

                    $pdo->commit();
                    $success = 'Successfully approved admin access for ' . htmlspecialchars($userName) . '. User promoted to Admin.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Failed to approve request: ' . $e->getMessage();
                }
            } elseif ($action === 'reject') {
                $updReq = $pdo->prepare('UPDATE admin_requests SET status = "rejected", resolved_at = CURRENT_TIMESTAMP, resolved_by = ? WHERE id = ?');
                $updReq->execute([$adminId, $reqId]);
                $success = 'Admin request for ' . htmlspecialchars($userName) . ' has been rejected.';
            }
        }
    }
}

// Fetch all pending admin requests
$pendingStmt = $pdo->prepare('SELECT ar.*, u.name, u.email, u.department FROM admin_requests ar JOIN users u ON ar.user_id = u.id WHERE ar.status = "pending" ORDER BY ar.requested_at ASC');
$pendingStmt->execute();
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingAdminReqCount = count($pendingRequests);

// Fetch resolved admin requests history
$historyStmt = $pdo->prepare('
    SELECT ar.*, u.name as user_name, u.email, u.department, r.name as resolver_name
    FROM admin_requests ar
    JOIN users u ON ar.user_id = u.id
    LEFT JOIN users r ON ar.resolved_by = r.id
    WHERE ar.status IN ("approved", "rejected")
    ORDER BY ar.resolved_at DESC
    LIMIT 50
');
$historyStmt->execute();
$historyRequests = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Requests - Employee Portal</title>
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <div class="admin-container">
        <aside class="admin-sidebar">
            <div class="sidebar-top">
                <span class="admin-badge">Employee Portal</span>
                <ul class="sidebar-nav">
                    <li><a href="admin-dashboard.php" class="sidebar-nav-item"><span>🏠 Admin Home</span></a></li>
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
                        <a href="admin-requests.php" class="sidebar-nav-item active">
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
                <h1 class="admin-page-title">Admin Access Requests</h1>
                <p class="admin-page-subtitle">Review, approve, or reject employee requests for administrative permissions.</p>
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

            <!-- Pending Admin Access Requests -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">Pending Requests</h3>
                    <span class="status-pill status-pending"><?= $pendingAdminReqCount ?> Pending</span>
                </div>

                <div class="table-responsive">
                    <?php if (empty($pendingRequests)): ?>
                        <div class="no-data">No pending admin access requests at this time.</div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Employee Name</th>
                                    <th>Email</th>
                                    <th>Department</th>
                                    <th>Requested Date</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingRequests as $req): ?>
                                    <tr>
                                        <td><strong>#<?= (int)$req['id'] ?></strong></td>
                                        <td><strong><?= htmlspecialchars($req['name']) ?></strong></td>
                                        <td><?= htmlspecialchars($req['email']) ?></td>
                                        <td><?= htmlspecialchars($req['department'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($req['requested_at']))) ?></td>
                                        <td style="text-align: right;">
                                            <form action="admin-requests.php" method="POST" style="display: inline;">
                                                <input type="hidden" name="req_id" value="<?= (int)$req['id'] ?>">
                                                <button type="submit" name="action" value="approve" class="btn-pill btn-pill-approve" onclick="return confirm('Approve admin access for <?= htmlspecialchars($req['name']) ?>?');">✓ Approve</button>
                                                <button type="submit" name="action" value="reject" class="btn-pill btn-pill-reject" onclick="return confirm('Reject admin access for <?= htmlspecialchars($req['name']) ?>?');">✗ Reject</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Resolved Admin Access Requests History -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">Resolved Requests History</h3>
                </div>

                <div class="table-responsive">
                    <?php if (empty($historyRequests)): ?>
                        <div class="no-data">No resolved requests in history yet.</div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Email</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Requested Date</th>
                                    <th>Resolved Date</th>
                                    <th>Resolved By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historyRequests as $h): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($h['user_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($h['email']) ?></td>
                                        <td><?= htmlspecialchars($h['department'] ?? 'N/A') ?></td>
                                        <td>
                                            <?php $st = strtolower($h['status']); ?>
                                            <span class="status-pill <?= $st === 'approved' ? 'status-approved' : 'status-rejected' ?>">
                                                <?= htmlspecialchars(ucfirst($st)) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($h['requested_at']))) ?></td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($h['resolved_at']))) ?></td>
                                        <td><?= htmlspecialchars($h['resolver_name'] ?? 'System') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>
</body>
</html>
