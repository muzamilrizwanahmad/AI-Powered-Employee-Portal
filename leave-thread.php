<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/groq-client.php';

$reqId = (int)($_GET['id'] ?? 0);
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'employee';

if ($reqId <= 0) {
    header('Location: ' . ($userRole === 'employee' ? 'leave-request.php' : 'leave-approval.php'));
    exit;
}

// Fetch leave request details
$stmt = $pdo->prepare('
    SELECT lr.*, u.name as employee_name, u.email as employee_email, u.department as employee_dept
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    WHERE lr.id = ?
');
$stmt->execute([$reqId]);
$leaveRequest = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leaveRequest) {
    die("Error: Leave request #{$reqId} not found.");
}

// STRICT ACCESS CONTROL:
// Admins and Superadmins can access any thread.
// Employees can ONLY access threads for leave requests they own.
$isAdmin = in_array($userRole, ['admin', 'superadmin'], true);
$isOwner = ((int)$leaveRequest['user_id'] === (int)$userId);

if (!$isAdmin && !$isOwner) {
    header('Location: leave-request.php');
    exit;
}

// Quick stats for sidebar badges
$pendingLeaveReqCount = 0;
$pendingAdminReqCount = 0;
$unreadAdminLeaveMsgCount = 0;
$unreadLeaveMsgCount = 0;
$latestAdminReq = null;

if ($isAdmin) {
    $pendingLeaveReqCount = (int)$pdo->query('SELECT COUNT(*) FROM leave_requests WHERE status = "pending"')->fetchColumn();
    $pendingAdminReqCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_requests WHERE status = "pending"')->fetchColumn();
    $unreadAdminLeaveMsgCount = (int)$pdo->query('
        SELECT COUNT(*) 
        FROM leave_messages lm
        JOIN leave_requests lr ON lm.leave_request_id = lr.id
        WHERE lm.sender_id = lr.user_id AND lm.is_read = 0
    ')->fetchColumn();
} else {
    $unreadStmt = $pdo->prepare('
        SELECT COUNT(*) 
        FROM leave_messages lm
        JOIN leave_requests lr ON lm.leave_request_id = lr.id
        WHERE lr.user_id = ? AND (lm.sender_id != ? OR lm.sender_id IS NULL) AND lm.is_read = 0
    ');
    $unreadStmt->execute([$userId, $userId]);
    $unreadLeaveMsgCount = (int)$unreadStmt->fetchColumn();

    $reqStmt = $pdo->prepare('SELECT status FROM admin_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 1');
    $reqStmt->execute([$userId]);
    $latestAdminReq = $reqStmt->fetch(PDO::FETCH_ASSOC);
}

// Mark unread messages in this thread (sent by others or AI) as read for the logged-in user
$markRead = $pdo->prepare('
    UPDATE leave_messages
    SET is_read = 1
    WHERE leave_request_id = ? AND (sender_id != ? OR sender_id IS NULL) AND is_read = 0
');
$markRead->execute([$reqId, $userId]);

$errors = [];

// Handle POST Actions (Send Reply or Ask AI for Advice)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Employee / Admin regular message reply
    if ($action === 'send_message') {
        $msgText = trim($_POST['message'] ?? '');

        if (empty($msgText)) {
            $errors[] = 'Message content cannot be empty.';
        } else {
            $insertStmt = $pdo->prepare('
                INSERT INTO leave_messages (leave_request_id, sender_id, message, is_ai_advice, is_read, created_at)
                VALUES (?, ?, ?, 0, 0, CURRENT_TIMESTAMP)
            ');
            $insertStmt->execute([$reqId, $userId, $msgText]);

            header('Location: leave-thread.php?id=' . $reqId);
            exit;
        }
    }

    // 2. Admin Ask AI for Advice
    elseif ($action === 'ai_advice') {
        if (!$isAdmin) {
            $errors[] = 'Only administrators can request AI advice.';
        } else {
            // Fetch employee's past 5 leave requests
            $pastStmt = $pdo->prepare('
                SELECT id, message, status, created_at, resolved_at
                FROM leave_requests
                WHERE user_id = ? AND id != ?
                ORDER BY created_at DESC
                LIMIT 5
            ');
            $pastStmt->execute([$leaveRequest['user_id'], $reqId]);
            $pastLeaves = $pastStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch current messages in thread
            $threadStmt = $pdo->prepare('
                SELECT lm.*, u.name as sender_name
                FROM leave_messages lm
                LEFT JOIN users u ON lm.sender_id = u.id
                WHERE lm.leave_request_id = ?
                ORDER BY lm.created_at ASC
            ');
            $threadStmt->execute([$reqId]);
            $threadMessages = $threadStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch company rules
            $rulesStmt = $pdo->query('SELECT rule_text FROM company_rules ORDER BY id ASC');
            $rulesList = $rulesStmt->fetchAll(PDO::FETCH_COLUMN);

            $rulesFormatted = !empty($rulesList) ? implode("\n- ", $rulesList) : "No official rules defined.";

            $systemPrompt = "You are an HR assistant providing mid-conversation advice to an admin evaluating a leave request message thread. Using ONLY the following official company rules as your basis:\n- " . $rulesFormatted . "\n\nreview the leave request, the employee's past leave history, and the message thread so far. Provide helpful advice, point out any rule violations or concerns, suggest clarifying questions, and recommend next steps. Respond in plain conversational text only — no markdown, no tables, no asterisks, no pipe characters, no headers. Use simple numbered points like '1.', '2.' if listing multiple things.";

            $userContent = "Leave Request #" . $leaveRequest['id'] . " by " . $leaveRequest['employee_name'] . " (" . ($leaveRequest['employee_dept'] ?? 'N/A') . ")\n";
            $userContent .= "Submitted Date: " . $leaveRequest['created_at'] . "\n";
            $userContent .= "Reason: " . $leaveRequest['message'] . "\n\n";

            $userContent .= "Employee's Recent Leave History (Last 5 Requests):\n";
            if (empty($pastLeaves)) {
                $userContent .= "No prior leave requests found.\n";
            } else {
                foreach ($pastLeaves as $idx => $pl) {
                    $userContent .= ($idx + 1) . ". Date: " . $pl['created_at'] . " | Status: " . strtoupper($pl['status']) . " | Reason: " . $pl['message'] . "\n";
                }
            }

            $userContent .= "\nConversation Thread Messages So Far:\n";
            if (empty($threadMessages)) {
                $userContent .= "No discussion replies in thread yet.\n";
            } else {
                foreach ($threadMessages as $tm) {
                    $sender = $tm['sender_name'] ?? ($tm['is_ai_advice'] ? 'AI Advice System' : 'User');
                    $userContent .= "- [" . $tm['created_at'] . "] " . $sender . ": " . $tm['message'] . "\n";
                }
            }

            $aiReply = ask_groq([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent]
            ]);

            if (strpos($aiReply, 'Error') === 0 || strpos($aiReply, 'API Error') === 0 || strpos($aiReply, 'cURL Error') === 0 || strpos($aiReply, 'Network Error') === 0) {
                $errors[] = 'AI Advice unavailable, try again. (' . $aiReply . ')';
            } else {
                // Insert AI reply into leave_messages table
                $insAi = $pdo->prepare('
                    INSERT INTO leave_messages (leave_request_id, sender_id, message, is_ai_advice, is_read, created_at)
                    VALUES (?, NULL, ?, 1, 0, CURRENT_TIMESTAMP)
                ');
                $insAi->execute([$reqId, $aiReply]);

                header('Location: leave-thread.php?id=' . $reqId);
                exit;
            }
        }
    }
}

// Fetch all messages in this thread
$msgStmt = $pdo->prepare('
    SELECT lm.*, u.name as sender_name, u.role as sender_role
    FROM leave_messages lm
    LEFT JOIN users u ON lm.sender_id = u.id
    WHERE lm.leave_request_id = ?
    ORDER BY lm.created_at ASC
');
$msgStmt->execute([$reqId]);
$messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Thread #<?= $reqId ?> - Employee Portal</title>
    <link rel="stylesheet" href="<?= $isAdmin ? 'css/admin.css' : 'css/employee.css' ?>">
</head>
<body>
<?php if ($isAdmin): ?>
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
<?php else: ?>
    <div class="employee-container">
        <aside class="employee-sidebar">
            <div class="sidebar-top">
                <span class="employee-badge">Employee Portal</span>
                <ul class="sidebar-nav">
                    <li><a href="employee-dashboard.php" class="sidebar-nav-item"><span>🏠 Dashboard</span></a></li>
                    <li>
                        <a href="leave-request.php" class="sidebar-nav-item active">
                            <span>📝 Leave Requests</span>
                            <?php if ($unreadLeaveMsgCount > 0): ?>
                                <span class="nav-counter"><?= $unreadLeaveMsgCount ?> unread</span>
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
<?php endif; ?>

            <div style="margin-bottom: 1.5rem;">
                <a href="<?= $isAdmin ? 'leave-approval.php' : 'leave-request.php' ?>" class="btn-pill btn-pill-outline">&larr; Back to <?= $isAdmin ? 'Leave Approvals' : 'My Leave Requests' ?></a>
            </div>

            <!-- Original Leave Submission Header Card -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <span style="font-size: 0.8rem; font-weight: 600; color: var(--grey-text); text-transform: uppercase;">📌 Original Submission</span>
                        <h3 class="card-title" style="margin-top: 0.2rem;">Leave Request #<?= (int)$leaveRequest['id'] ?></h3>
                    </div>
                    <?php
                    $st = strtolower($leaveRequest['status']);
                    $stClass = 'status-pending';
                    if ($st === 'approved') $stClass = 'status-approved';
                    if ($st === 'rejected') $stClass = 'status-rejected';
                    ?>
                    <span class="status-pill <?= $stClass ?>"><?= htmlspecialchars(ucfirst($st)) ?></span>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem; font-size: 0.9rem;">
                    <div><strong>Employee:</strong> <?= htmlspecialchars($leaveRequest['employee_name']) ?> (<?= htmlspecialchars($leaveRequest['employee_email']) ?>)</div>
                    <div><strong>Department:</strong> <?= htmlspecialchars($leaveRequest['employee_dept'] ?? 'N/A') ?></div>
                    <div><strong>Submitted Date:</strong> <?= htmlspecialchars(date('M d, Y H:i', strtotime($leaveRequest['created_at']))) ?></div>
                    <div>
                        <strong>Attachment:</strong> 
                        <?php if (!empty($leaveRequest['attachment_path'])): ?>
                            <a href="<?= htmlspecialchars($leaveRequest['attachment_path']) ?>" target="_blank" class="btn-pill btn-pill-outline" style="padding: 0.2rem 0.6rem; font-size: 0.75rem;">📄 View Document</a>
                        <?php else: ?>
                            <span style="color: var(--grey-text);">None</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="background: var(--cream); border-left: 4px solid var(--yellow); padding: 1rem; border-radius: 12px; font-size: 0.95rem;">
                    <strong>Submitted Reason / Details:</strong>
                    <p style="margin-top: 0.4rem; white-space: pre-wrap; color: var(--ink);"><?= htmlspecialchars($leaveRequest['message']) ?></p>
                </div>
            </div>

            <!-- Conversation Thread -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Replies & Discussion Thread</h3>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $err): ?>
                            <div><?= htmlspecialchars($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="chat-box" style="margin-bottom: 1.5rem;">
                    <?php if (empty($messages)): ?>
                        <div class="no-data">
                            No replies in this thread yet. Send a message below to discuss this leave request.
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                            <?php 
                            $isAI = ((int)($msg['is_ai_advice'] ?? 0) === 1);
                            $isSelf = ((int)$msg['sender_id'] === (int)$userId);
                            $class = $isAI ? 'ai' : ($isSelf ? 'user' : 'ai');
                            ?>
                            <div class="chat-bubble <?= $class ?>" style="<?= $isAI ? 'background-color: #FFFDF5; border-color: var(--yellow); align-self: center; width: 95%;' : '' ?>">
                                <div class="chat-header">
                                    <span class="chat-author">
                                        <?php if ($isAI): ?>
                                            🤖 AI Advice System
                                        <?php else: ?>
                                            <?= htmlspecialchars($msg['sender_name'] ?? 'User') ?>
                                            <span class="status-pill <?= in_array($msg['sender_role'], ['admin', 'superadmin'], true) ? 'status-pending' : '' ?>" style="font-size: 0.65rem; padding: 0.15rem 0.5rem; margin-left: 0.25rem;">
                                                <?= htmlspecialchars(ucfirst($msg['sender_role'] ?? 'user')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="chat-time"><?= htmlspecialchars(date('M d, Y H:i', strtotime($msg['created_at']))) ?></span>
                                </div>
                                <div class="chat-content" style="white-space: pre-wrap;"><?= htmlspecialchars($msg['message']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Post New Message Form & AI Advice Button -->
                <form action="leave-thread.php?id=<?= $reqId ?>" method="POST" style="margin-bottom: 1rem;">
                    <input type="hidden" name="action" value="send_message">
                    <textarea name="message" class="textarea-rounded" rows="3" placeholder="Type a reply regarding this leave request..." required></textarea>
                    <div style="margin-top: 0.75rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                        <button type="submit" class="btn-pill btn-pill-yellow">💬 Send Reply</button>
                    </div>
                </form>

                <?php if ($isAdmin): ?>
                    <form action="leave-thread.php?id=<?= $reqId ?>" method="POST" style="margin-top: 0.5rem;">
                        <input type="hidden" name="action" value="ai_advice">
                        <button type="submit" class="btn-pill btn-pill-outline">🤖 Ask AI for advice</button>
                    </form>
                <?php endif; ?>
            </div>

        </main>
    </div>
</body>
</html>
