<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'superadmin']);

$pdo = require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/groq-client.php';

$adminId = $_SESSION['user_id'];
$success = '';
$errors = [];
$aiVerdictResult = null;

// Quick stats for sidebar counters
$pendingAdminReqCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_requests WHERE status = "pending"')->fetchColumn();

// Handle POST Actions (Approve, Reject, or AI Verdict)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reqId = (int)($_POST['req_id'] ?? 0);

    // 1. Handle Approve / Reject
    if ($reqId > 0 && in_array($action, ['approve', 'reject'], true)) {
        $chk = $pdo->prepare('SELECT lr.id, u.name FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.id = ? AND lr.status = "pending"');
        $chk->execute([$reqId]);
        $req = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            $errors[] = 'Leave request not found or already processed.';
        } else {
            $status = ($action === 'approve') ? 'approved' : 'rejected';
            $stmt = $pdo->prepare('UPDATE leave_requests SET status = ?, resolved_at = CURRENT_TIMESTAMP, resolved_by = ? WHERE id = ?');
            $stmt->execute([$status, $adminId, $reqId]);

            $success = 'Leave request for ' . htmlspecialchars($req['name']) . ' was successfully ' . $status . '.';
        }
    }

    // 2. Handle AI Verdict Generation
    elseif ($reqId > 0 && $action === 'ai_verdict') {
        $chk = $pdo->prepare('
            SELECT lr.*, u.name as employee_name, u.email as employee_email, u.department as employee_dept, u.id as emp_id
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            WHERE lr.id = ?
        ');
        $chk->execute([$reqId]);
        $verdictReq = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$verdictReq) {
            $errors[] = 'Leave request not found.';
        } else {
            // Fetch employee's past 5 leave requests
            $pastStmt = $pdo->prepare('
                SELECT id, message, status, created_at, resolved_at
                FROM leave_requests
                WHERE user_id = ? AND id != ?
                ORDER BY created_at DESC
                LIMIT 5
            ');
            $pastStmt->execute([$verdictReq['emp_id'], $reqId]);
            $pastLeaves = $pastStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch all company rules
            $rulesStmt = $pdo->query('SELECT rule_text FROM company_rules ORDER BY id ASC');
            $rulesList = $rulesStmt->fetchAll(PDO::FETCH_COLUMN);

            $rulesFormatted = !empty($rulesList) ? implode("\n- ", $rulesList) : "No official rules defined.";
            $systemPrompt = "You are an HR assistant helping an admin evaluate a leave request. Using ONLY the following official company rules as your basis:\n- " . $rulesFormatted . "\n\nreview the employee's leave request and their recent leave history. Point out any flaws, inconsistencies, or concerns in this leave request. Suggest specific counter-questions the admin could ask the employee to clarify the situation. Then give a clear recommendation: should this request be approved or rejected, and why, based on the rules. Respond in plain conversational text only — no markdown, no tables, no asterisks, no pipe characters, no headers. Use simple numbered points like '1.', '2.' if listing multiple things.";

            $userContent = "Employee: " . $verdictReq['employee_name'] . " (" . ($verdictReq['employee_dept'] ?? 'N/A') . ")\n";
            $userContent .= "Current Leave Request ID: #" . $verdictReq['id'] . "\n";
            $userContent .= "Submitted Date: " . $verdictReq['created_at'] . "\n";
            $userContent .= "Request Reason Message: " . $verdictReq['message'] . "\n";
            $userContent .= "Has Attachment: " . (!empty($verdictReq['attachment_path']) ? 'Yes' : 'No') . "\n\n";

            $userContent .= "Employee's Recent Leave Request History (Last 5 Requests):\n";
            if (empty($pastLeaves)) {
                $userContent .= "No prior leave requests found for this employee.\n";
            } else {
                foreach ($pastLeaves as $idx => $pl) {
                    $userContent .= ($idx + 1) . ". Request #" . $pl['id'] . " | Date: " . $pl['created_at'] . " | Status: " . strtoupper($pl['status']) . " | Reason: " . $pl['message'] . "\n";
                }
            }

            $aiReply = ask_groq([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent]
            ]);

            if (strpos($aiReply, 'Error') === 0 || strpos($aiReply, 'API Error') === 0 || strpos($aiReply, 'cURL Error') === 0 || strpos($aiReply, 'Network Error') === 0) {
                $errors[] = 'AI Verdict unavailable, please try again. (' . $aiReply . ')';
            } else {
                $aiVerdictResult = [
                    'req_id' => $reqId,
                    'employee_name' => $verdictReq['employee_name'],
                    'content' => $aiReply
                ];
            }
        }
    }
}

// Fetch Pending Leave Requests
$pendingStmt = $pdo->prepare('
    SELECT lr.*, u.name as employee_name, u.email as employee_email, u.department as employee_dept
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    WHERE lr.status = "pending"
    ORDER BY lr.created_at ASC
');
$pendingStmt->execute();
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingLeaveReqCount = count($pendingRequests);

// Fetch Approved Leave Requests
$approvedStmt = $pdo->prepare('
    SELECT lr.*, u.name as employee_name, u.department as employee_dept, r.name as resolver_name
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN users r ON lr.resolved_by = r.id
    WHERE lr.status = "approved"
    ORDER BY lr.resolved_at DESC
    LIMIT 50
');
$approvedStmt->execute();
$approvedRequests = $approvedStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Rejected Leave Requests
$rejectedStmt = $pdo->prepare('
    SELECT lr.*, u.name as employee_name, u.department as employee_dept, r.name as resolver_name
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN users r ON lr.resolved_by = r.id
    WHERE lr.status = "rejected"
    ORDER BY lr.resolved_at DESC
    LIMIT 50
');
$rejectedStmt->execute();
$rejectedRequests = $rejectedStmt->fetchAll(PDO::FETCH_ASSOC);

// Map unread leave message counts sent by employees for admins
$unreadAdminMap = [];
$unrAdminStmt = $pdo->prepare('
    SELECT lm.leave_request_id, COUNT(*) as unread_cnt
    FROM leave_messages lm
    JOIN leave_requests lr ON lm.leave_request_id = lr.id
    WHERE lm.sender_id = lr.user_id AND lm.is_read = 0
    GROUP BY lm.leave_request_id
');
$unrAdminStmt->execute();
$unreadAdminLeaveMsgCount = 0;
foreach ($unrAdminStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $unreadAdminMap[(int)$row['leave_request_id']] = (int)$row['unread_cnt'];
    $unreadAdminLeaveMsgCount += (int)$row['unread_cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Approval - Employee Portal Admin</title>
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
                        <a href="leave-approval.php" class="sidebar-nav-item active">
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
                <h1 class="admin-page-title">Leave Approvals</h1>
                <p class="admin-page-subtitle">Review employee leave requests, generate AI policy verdicts, and communicate via message threads.</p>
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

            <!-- AI Verdict Result Box -->
            <?php if ($aiVerdictResult): ?>
                <div class="admin-card" style="background-color: #FFFDF5; border-color: var(--yellow);">
                    <div class="admin-card-header" style="border-bottom-color: var(--yellow-subtle);">
                        <h3 class="admin-card-title" style="color: var(--ink);">
                            🤖 AI HR Verdict & Policy Analysis (Request #<?= (int)$aiVerdictResult['req_id'] ?> - <?= htmlspecialchars($aiVerdictResult['employee_name']) ?>)
                        </h3>
                        <button type="button" class="btn-pill btn-pill-outline" onclick="this.closest('.admin-card').style.display='none';">&times; Dismiss</button>
                    </div>
                    <div style="font-size: 0.95rem; line-height: 1.6; white-space: pre-wrap; color: var(--ink);">
                        <?= htmlspecialchars($aiVerdictResult['content']) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Pending Leave Requests Card -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">Pending Leave Requests</h3>
                    <span class="status-pill status-pending"><?= count($pendingRequests) ?> Pending</span>
                </div>

                <div class="table-responsive">
                    <?php if (empty($pendingRequests)): ?>
                        <div class="no-data">No pending leave requests at this time.</div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Employee</th>
                                    <th>Reason / Message</th>
                                    <th>Attachment</th>
                                    <th>Submitted Date</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingRequests as $req): ?>
                                    <?php
                                    $rId = (int)$req['id'];
                                    $unrCnt = $unreadAdminMap[$rId] ?? 0;
                                    ?>
                                    <tr>
                                        <td><strong>#<?= $rId ?></strong></td>
                                        <td>
                                            <strong><?= htmlspecialchars($req['employee_name']) ?></strong>
                                            <div style="font-size: 0.8rem; color: var(--grey-text);"><?= htmlspecialchars($req['employee_dept'] ?? 'N/A') ?></div>
                                        </td>
                                        <td style="max-width: 250px;">
                                            <div style="white-space: pre-wrap; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: 0.85rem;"><?= htmlspecialchars($req['message']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($req['attachment_path'])): ?>
                                                <a href="<?= htmlspecialchars($req['attachment_path']) ?>" target="_blank" class="btn-pill btn-pill-outline" style="font-size: 0.75rem; padding: 0.25rem 0.75rem;">📄 Attachment</a>
                                            <?php else: ?>
                                                <span style="color: var(--grey-text); font-size: 0.8rem;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($req['created_at']))) ?></td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <div style="display: inline-flex; gap: 0.35rem; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
                                                <a href="leave-thread.php?id=<?= $rId ?>" class="btn-pill btn-pill-outline" style="position: relative;">
                                                    💬 Discussion
                                                    <?php if ($unrCnt > 0): ?>
                                                        <span class="nav-counter" style="margin-left: 0.25rem;"><?= $unrCnt ?></span>
                                                    <?php endif; ?>
                                                </a>

                                                <form action="leave-approval.php" method="POST" style="display: inline;">
                                                    <input type="hidden" name="req_id" value="<?= $rId ?>">
                                                    <button type="submit" name="action" value="ai_verdict" class="btn-pill btn-pill-yellow">🤖 AI Verdict</button>
                                                    <button type="submit" name="action" value="approve" class="btn-pill btn-pill-approve" onclick="return confirm('Approve leave request for <?= htmlspecialchars($req['employee_name']) ?>?');">✓ Approve</button>
                                                    <button type="submit" name="action" value="reject" class="btn-pill btn-pill-reject" onclick="return confirm('Reject leave request for <?= htmlspecialchars($req['employee_name']) ?>?');">✗ Reject</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Approved Leave Requests History -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">Approved Requests History</h3>
                    <span class="status-pill status-approved">Approved</span>
                </div>

                <div class="table-responsive">
                    <?php if (empty($approvedRequests)): ?>
                        <div class="no-data">No approved leave requests yet.</div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Submitted Date</th>
                                    <th>Approved Date</th>
                                    <th>Approved By</th>
                                    <th style="text-align: right;">Thread</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approvedRequests as $app): ?>
                                    <tr>
                                        <td><strong>#<?= (int)$app['id'] ?></strong></td>
                                        <td><strong><?= htmlspecialchars($app['employee_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($app['employee_dept'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($app['created_at']))) ?></td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($app['resolved_at']))) ?></td>
                                        <td><?= htmlspecialchars($app['resolver_name'] ?? 'System') ?></td>
                                        <td style="text-align: right;">
                                            <a href="leave-thread.php?id=<?= (int)$app['id'] ?>" class="btn-pill btn-pill-outline">💬 View Thread</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Rejected Leave Requests History -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">Rejected Requests History</h3>
                    <span class="status-pill status-rejected">Rejected</span>
                </div>

                <div class="table-responsive">
                    <?php if (empty($rejectedRequests)): ?>
                        <div class="no-data">No rejected leave requests yet.</div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Submitted Date</th>
                                    <th>Rejected Date</th>
                                    <th>Rejected By</th>
                                    <th style="text-align: right;">Thread</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rejectedRequests as $rej): ?>
                                    <tr>
                                        <td><strong>#<?= (int)$rej['id'] ?></strong></td>
                                        <td><strong><?= htmlspecialchars($rej['employee_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($rej['employee_dept'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($rej['created_at']))) ?></td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($rej['resolved_at']))) ?></td>
                                        <td><?= htmlspecialchars($rej['resolver_name'] ?? 'System') ?></td>
                                        <td style="text-align: right;">
                                            <a href="leave-thread.php?id=<?= (int)$rej['id'] ?>" class="btn-pill btn-pill-outline">💬 View Thread</a>
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
