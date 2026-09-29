<?php
require_once __DIR__ . '/includes/auth.php';
require_role('employee');

$pdo = require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/groq-client.php';

$userId = $_SESSION['user_id'];
$errors = [];

// Handle New Chat Message Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $userMsg = trim($_POST['message'] ?? '');

    if (empty($userMsg)) {
        $errors[] = 'Please enter a message or question.';
    } else {
        // 1. Save user's message into database
        $insUser = $pdo->prepare('INSERT INTO ai_helper_messages (user_id, role, content, created_at) VALUES (?, "user", ?, CURRENT_TIMESTAMP)');
        $insUser->execute([$userId, $userMsg]);

        // 2. Fetch all company rules to inject into system prompt
        $rulesStmt = $pdo->query('SELECT rule_text FROM company_rules ORDER BY id ASC');
        $rulesList = $rulesStmt->fetchAll(PDO::FETCH_COLUMN);

        $formattingInstructions = "Respond in plain, natural conversational text only. Do NOT use markdown formatting of any kind — no tables, no pipe characters (|), no asterisks for bold or italics (**text**), no headers (#), no bullet symbols like * or -. If you need to list multiple items (like different leave types), write them as a simple numbered list using plain numbers like '1.', '2.', '3.' followed by a period and a space, or just describe them in flowing sentences. Keep the tone clear, friendly, and easy to read as plain text.";

        if (!empty($rulesList)) {
            $rulesFormatted = implode("\n- ", $rulesList);
            $systemPrompt = "You are a helpful HR assistant for this company. Answer employee questions about company policies, SOPs, and rules using ONLY the following official company rules as your source of truth:\n- " . $rulesFormatted . "\n\nIf the employee's question is general workplace advice not covered by these rules, you may answer helpfully using general knowledge, but if it's unclear or the answer isn't in these rules, tell the employee to contact an admin for clarification rather than guessing.\n\n" . $formattingInstructions;
        } else {
            $systemPrompt = "You are a helpful HR assistant for this company. Currently no official company rules are configured in the portal. Answer employee questions using general workplace knowledge, but advise them to contact an admin for official company policies.\n\n" . $formattingInstructions;
        }

        // 3. Fetch recent conversation history context (last 10 messages)
        $histStmt = $pdo->prepare('SELECT role, content FROM ai_helper_messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
        $histStmt->execute([$userId]);
        $recentHistory = array_reverse($histStmt->fetchAll(PDO::FETCH_ASSOC));

        // 4. Construct messages payload for Groq API
        $groqMessages = [];
        $groqMessages[] = ['role' => 'system', 'content' => $systemPrompt];

        foreach ($recentHistory as $msg) {
            $gRole = ($msg['role'] === 'ai') ? 'assistant' : 'user';
            $groqMessages[] = ['role' => $gRole, 'content' => $msg['content']];
        }

        // 5. Query Groq API
        $aiReply = ask_groq($groqMessages);

        // 6. Save AI reply into database
        $insAI = $pdo->prepare('INSERT INTO ai_helper_messages (user_id, role, content, created_at) VALUES (?, "ai", ?, CURRENT_TIMESTAMP)');
        $insAI->execute([$userId, $aiReply]);

        // Redirect to avoid form re-submission
        header('Location: ai-helper.php');
        exit;
    }
}

// Fetch full conversation history for this user
$chatStmt = $pdo->prepare('SELECT * FROM ai_helper_messages WHERE user_id = ? ORDER BY created_at ASC');
$chatStmt->execute([$userId]);
$chatMessages = $chatStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch total unread leave messages count for sidebar
$unreadStmt = $pdo->prepare('
    SELECT COUNT(*) 
    FROM leave_messages lm
    JOIN leave_requests lr ON lm.leave_request_id = lr.id
    WHERE lr.user_id = ? AND (lm.sender_id != ? OR lm.sender_id IS NULL) AND lm.is_read = 0
');
$unreadStmt->execute([$userId, $userId]);
$unreadLeaveMsgCount = (int)$unreadStmt->fetchColumn();

// Fetch latest admin request for sidebar
$reqStmt = $pdo->prepare('SELECT status FROM admin_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 1');
$reqStmt->execute([$userId]);
$latestAdminReq = $reqStmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI HR Helper - Employee Portal</title>
    <link rel="stylesheet" href="css/employee.css">
</head>
<body>
    <div class="employee-container">
        <aside class="employee-sidebar">
            <div class="sidebar-top">
                <span class="employee-badge">Employee Portal</span>
                <ul class="sidebar-nav">
                    <li><a href="employee-dashboard.php" class="sidebar-nav-item"><span>🏠 Dashboard</span></a></li>
                    <li>
                        <a href="leave-request.php" class="sidebar-nav-item">
                            <span>📝 Leave Requests</span>
                            <?php if ($unreadLeaveMsgCount > 0): ?>
                                <span class="nav-counter"><?= $unreadLeaveMsgCount ?> unread</span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="document-custody.php" class="sidebar-nav-item"><span>📁 Document Custody</span></a></li>
                    <li><a href="ai-helper.php" class="sidebar-nav-item active"><span>🤖 AI Helper</span></a></li>
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
            <div class="page-header">
                <h1 class="page-title">AI HR Assistant</h1>
                <p class="page-subtitle">Ask questions about company policies, leave entitlements, rules, or general workplace guidance.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $err): ?>
                        <div><?= htmlspecialchars($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="chat-container">
                <div class="chat-box" id="chatBox">
                    <?php if (empty($chatMessages)): ?>
                        <div class="no-data" style="margin: auto;">
                            👋 Hello <?= htmlspecialchars($_SESSION['name']) ?>! I'm your AI HR Assistant. Ask me anything about company rules or policies below.
                        </div>
                    <?php else: ?>
                        <?php foreach ($chatMessages as $msg): ?>
                            <?php $isUser = ($msg['role'] === 'user'); ?>
                            <div class="chat-bubble <?= $isUser ? 'user' : 'ai' ?>">
                                <div class="chat-header">
                                    <span class="chat-author">
                                        <?= $isUser ? 'You' : '🤖 AI HR Helper' ?>
                                    </span>
                                    <span class="chat-time"><?= htmlspecialchars(date('M d, H:i', strtotime($msg['created_at']))) ?></span>
                                </div>
                                <div class="chat-content" style="white-space: pre-wrap;"><?= htmlspecialchars($msg['content']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <form action="ai-helper.php" method="POST" class="chat-input-bar">
                    <input type="hidden" name="action" value="send_message">
                    <input type="text" name="message" class="input-pill" placeholder="Ask a question about leave policies, company rules, or workplace advice..." required id="messageInput" style="flex: 1;">
                    <button type="submit" class="btn-pill btn-pill-yellow">Send &rarr;</button>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Auto scroll chat box to bottom
        const chatBox = document.getElementById('chatBox');
        if (chatBox) {
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        // Submit form on Enter key
        document.getElementById('messageInput')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.form.submit();
            }
        });
    </script>
</body>
</html>
