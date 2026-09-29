<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'superadmin']);

$pdo = require __DIR__ . '/config/db.php';

$search = trim($_GET['search'] ?? '');

// Quick stats for sidebar badges
$pendingLeaveReqCount = (int)$pdo->query('SELECT COUNT(*) FROM leave_requests WHERE status = "pending"')->fetchColumn();
$pendingAdminReqCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_requests WHERE status = "pending"')->fetchColumn();
$unreadAdminLeaveMsgCount = (int)$pdo->query('
    SELECT COUNT(*) 
    FROM leave_messages lm
    JOIN leave_requests lr ON lm.leave_request_id = lr.id
    WHERE lm.sender_id = lr.user_id AND lm.is_read = 0
')->fetchColumn();

// Fetch documents with holder details and search filtering
if (!empty($search)) {
    $stmt = $pdo->prepare('
        SELECT d.*, u.name as holder_name, u.email as holder_email, u.department as holder_dept
        FROM documents d
        LEFT JOIN users u ON d.current_holder_id = u.id
        WHERE u.name LIKE ? OR d.name LIKE ?
        ORDER BY d.created_at DESC
    ');
    $searchTerm = "%{$search}%";
    $stmt->execute([$searchTerm, $searchTerm]);
} else {
    $stmt = $pdo->prepare('
        SELECT d.*, u.name as holder_name, u.email as holder_email, u.department as holder_dept
        FROM documents d
        LEFT JOIN users u ON d.current_holder_id = u.id
        ORDER BY d.created_at DESC
    ');
    $stmt->execute();
}
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch all custody logs grouped by document_id
$logStmt = $pdo->prepare('
    SELECT c.*, u_from.name as from_name, u_to.name as to_name
    FROM custody_log c
    LEFT JOIN users u_from ON c.from_user_id = u_from.id
    JOIN users u_to ON c.to_user_id = u_to.id
    ORDER BY c.handed_over_at ASC
');
$logStmt->execute();
$logsList = $logStmt->fetchAll(PDO::FETCH_ASSOC);

$custodyLogsMap = [];
foreach ($logsList as $log) {
    $custodyLogsMap[$log['document_id']][] = $log;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Custody Tracking - Employee Portal Admin</title>
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
                        <a href="admin-requests.php" class="sidebar-nav-item">
                            <span>🔑 Admin Requests</span>
                            <?php if ($pendingAdminReqCount > 0): ?>
                                <span class="nav-counter"><?= $pendingAdminReqCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="document-custody-tracking.php" class="sidebar-nav-item active"><span>📋 Document Tracking</span></a></li>
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
                <h1 class="admin-page-title">Document Custody Tracking</h1>
                <p class="admin-page-subtitle">Monitor all registered company documents, search by current holder name, and inspect complete handover audit logs.</p>
            </div>

            <!-- Search Form -->
            <form action="document-custody-tracking.php" method="GET" class="search-wrapper">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="input-pill" placeholder="Search by current holder name or document title...">
                <button type="submit" class="btn-pill btn-pill-yellow">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="document-custody-tracking.php" class="btn-pill btn-pill-outline">Reset Filter</a>
                <?php endif; ?>
            </form>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">All Company Documents</h3>
                    <span class="status-pill status-pending"><?= count($documents) ?> Documents</span>
                </div>

                <div class="table-responsive">
                    <?php if (empty($documents)): ?>
                        <div class="no-data">No documents found matching your query.</div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Document Name</th>
                                    <th>Current Holder</th>
                                    <th>Date Added</th>
                                    <th>Last Updated</th>
                                    <th>Custody Audit Log</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                    <?php 
                                    $docId = (int)$doc['id'];
                                    $logs = $custodyLogsMap[$docId] ?? [];
                                    ?>
                                    <tr>
                                        <td><strong>#<?= $docId ?></strong></td>
                                        <td><strong><?= htmlspecialchars($doc['name']) ?></strong></td>
                                        <td>
                                            <?php if (!empty($doc['holder_name'])): ?>
                                                <span class="status-pill status-pending">
                                                    👤 <?= htmlspecialchars($doc['holder_name']) ?>
                                                </span>
                                                <div style="font-size: 0.8rem; color: var(--grey-text); margin-top: 0.25rem;">
                                                    <?= htmlspecialchars($doc['holder_dept'] ?? 'N/A') ?> (<?= htmlspecialchars($doc['holder_email']) ?>)
                                                </div>
                                            <?php else: ?>
                                                <span class="status-pill" style="background-color: var(--border); color: var(--grey-text);">Unassigned / None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($doc['created_at']))) ?></td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($doc['updated_at']))) ?></td>
                                        <td>
                                            <?php if (!empty($logs)): ?>
                                                <details>
                                                    <summary class="btn-pill btn-pill-outline" style="font-size: 0.8rem; padding: 0.25rem 0.75rem;">📜 View Timeline (<?= count($logs) ?> handover<?= count($logs) > 1 ? 's' : '' ?>)</summary>
                                                    <div style="margin-top: 0.75rem; background: #FFFFFF; border: 1px solid var(--border); border-radius: 12px; padding: 1rem; font-size: 0.85rem;">
                                                        <?php foreach ($logs as $l): ?>
                                                            <div style="padding-left: 0.75rem; border-left: 2px solid var(--yellow); margin-bottom: 0.75rem;">
                                                                <div style="font-size: 0.75rem; color: var(--grey-text); font-weight: 600;">
                                                                    <?= htmlspecialchars(date('M d, Y H:i', strtotime($l['handed_over_at']))) ?>
                                                                </div>
                                                                <div style="color: var(--ink); margin-top: 0.25rem;">
                                                                    <strong><?= htmlspecialchars($l['from_name'] ?? 'Initial Holder') ?></strong> &rarr; <strong><?= htmlspecialchars($l['to_name']) ?></strong>
                                                                    <div style="font-style: italic; color: var(--grey-text); margin-top: 0.15rem;">
                                                                        Reason: "<?= htmlspecialchars($l['reason']) ?>"
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            <?php else: ?>
                                                <span style="color: var(--grey-text); font-size: 0.8rem;">No handovers</span>
                                            <?php endif; ?>
                                        </td>
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
