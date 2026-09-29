<?php
require_once __DIR__ . '/includes/auth.php';
require_role('employee');

$pdo = require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/groq-client.php';

$userId = $_SESSION['user_id'];
$errors = [];

// Handle Form Submission for New Dispute Judgement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_judgement') {
    $scenario = trim($_POST['scenario'] ?? '');
    $rawPerspectives = $_POST['perspectives'] ?? [];

    $validPerspectives = [];
    if (is_array($rawPerspectives)) {
        foreach ($rawPerspectives as $p) {
            $trimmed = trim($p);
            if (!empty($trimmed)) {
                $validPerspectives[] = $trimmed;
            }
        }
    }

    if (empty($scenario)) {
        $errors[] = 'Dispute scenario description cannot be empty.';
    } else {
        // 1. Create chat record
        $title = mb_substr($scenario, 0, 50) . (mb_strlen($scenario) > 50 ? '...' : '');
        $insChat = $pdo->prepare('INSERT INTO ai_judgement_chats (user_id, title, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
        $insChat->execute([$userId, $title]);
        $chatId = (int)$pdo->lastInsertId();

        // 2. Insert user scenario message
        $insMsg = $pdo->prepare('INSERT INTO ai_judgement_messages (chat_id, role, content, created_at) VALUES (?, "user_scenario", ?, CURRENT_TIMESTAMP)');
        $insMsg->execute([$chatId, $scenario]);

        // 3. Insert individual perspective messages
        $insPersp = $pdo->prepare('INSERT INTO ai_judgement_messages (chat_id, role, content, created_at) VALUES (?, "user_perspective", ?, CURRENT_TIMESTAMP)');
        foreach ($validPerspectives as $pText) {
            $insPersp->execute([$chatId, $pText]);
        }

        // 4. Build System Prompt with Company Rules & Plain Text Directive
        $rulesStmt = $pdo->query('SELECT rule_text FROM company_rules ORDER BY id ASC');
        $rulesList = $rulesStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($rulesList)) {
            $rulesFormatted = implode("\n- ", $rulesList);
            $systemPrompt = "You are an impartial workplace dispute mediator for this company. You will be given a dispute scenario and optionally multiple employees' perspectives on it. Using ONLY the following official company rules as your basis for judgment:\n- " . $rulesFormatted . "\n\ndetermine who (if anyone) was in the wrong, explain your reasoning clearly, suggest specific questions that should be asked to the parties involved to clarify the situation further, and suggest what action the company should take according to the rules. If the rules don't clearly cover this situation, say so honestly and give a reasonable, fair recommendation based on general workplace fairness principles. Respond in plain conversational text only — no markdown, no tables, no asterisks, no pipe characters, no headers. Use simple numbered points like '1.', '2.' if listing multiple things.";
        } else {
            $systemPrompt = "You are an impartial workplace dispute mediator for this company. Currently no official company rules are configured in the portal. Analyze the given dispute scenario and perspectives, determine who (if anyone) was in the wrong, explain your reasoning clearly, suggest clarifying questions, and offer a fair recommendation based on general workplace fairness principles. Respond in plain conversational text only — no markdown, no tables, no asterisks, no pipe characters, no headers. Use simple numbered points like '1.', '2.' if listing multiple things.";
        }

        // 5. Build User Prompt Payload
        $userPrompt = "Dispute Scenario:\n" . $scenario;
        if (!empty($validPerspectives)) {
            $userPrompt .= "\n\nEmployee Perspectives:";
            foreach ($validPerspectives as $idx => $pText) {
                $userPrompt .= "\nPerspective " . ($idx + 1) . ": " . $pText;
            }
        }

        $groqMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        // 6. Call Groq API
        $aiReply = ask_groq($groqMessages);

        // 7. Save AI Reply
        $insAI = $pdo->prepare('INSERT INTO ai_judgement_messages (chat_id, role, content, created_at) VALUES (?, "ai", ?, CURRENT_TIMESTAMP)');
        $insAI->execute([$chatId, $aiReply]);

        // Redirect to open the newly created chat
        header("Location: ai-judgement.php?chat_id={$chatId}");
        exit;
    }
}

// Fetch user's past chats for sidebar
$chatsStmt = $pdo->prepare('SELECT * FROM ai_judgement_chats WHERE user_id = ? ORDER BY created_at DESC');
$chatsStmt->execute([$userId]);
$userChats = $chatsStmt->fetchAll(PDO::FETCH_ASSOC);

// Check if viewing a specific chat
$activeChatId = (int)($_GET['chat_id'] ?? 0);
$activeChat = null;
$chatMessages = [];

if ($activeChatId > 0) {
    $chkChat = $pdo->prepare('SELECT * FROM ai_judgement_chats WHERE id = ? AND user_id = ?');
    $chkChat->execute([$activeChatId, $userId]);
    $activeChat = $chkChat->fetch(PDO::FETCH_ASSOC);

    if ($activeChat) {
        $msgStmt = $pdo->prepare('SELECT * FROM ai_judgement_messages WHERE chat_id = ? ORDER BY id ASC');
        $msgStmt->execute([$activeChatId]);
        $chatMessages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Chat not found or not owned by user -> redirect to main page
        header('Location: ai-judgement.php');
        exit;
    }
}

// Fetch unread messages count for main sidebar
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
    <title>AI Workplace Judgement - Employee Portal</title>
    <link rel="stylesheet" href="css/employee.css">
    <style>
        .judgement-layout { display: flex; gap: 1.5rem; flex-wrap: wrap; }
        .judgement-sidebar { width: 260px; flex-shrink: 0; background-color: var(--cream); border: 1px solid var(--border); border-radius: 20px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; }
        .judgement-content { flex: 1; min-width: 280px; }
        .past-chat-item { display: flex; flex-direction: column; gap: 0.2rem; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: var(--ink); font-size: 0.85rem; font-weight: 500; border: 1px solid transparent; transition: all 0.2s ease; }
        .past-chat-item:hover { background-color: rgba(0, 0, 0, 0.04); }
        .past-chat-item.active { background-color: var(--yellow-subtle); border-color: var(--yellow); font-weight: 700; }
    </style>
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
                    <li><a href="ai-helper.php" class="sidebar-nav-item"><span>🤖 AI Helper</span></a></li>
                    <li><a href="ai-judgement.php" class="sidebar-nav-item active"><span>⚖️ AI Judgement</span></a></li>
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
                <h1 class="page-title">AI Workplace Dispute Mediation</h1>
                <p class="page-subtitle">Submit workplace dispute scenarios and perspectives to receive an impartial AI mediation assessment grounded in official company rules.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $err): ?>
                        <div><?= htmlspecialchars($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="judgement-layout">
                <!-- Nested Sidebar (Past Dispute Judgements) -->
                <aside class="judgement-sidebar">
                    <a href="ai-judgement.php" class="btn-pill btn-pill-yellow" style="justify-content: center; width: 100%;">+ New Dispute Chat</a>
                    <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--grey-text); letter-spacing: 0.5px;">Your Past Judgements</div>
                    
                    <div style="display: flex; flex-direction: column; gap: 0.4rem; max-height: 500px; overflow-y: auto;">
                        <?php if (empty($userChats)): ?>
                            <div class="no-data" style="font-size: 0.8rem; padding: 1rem 0;">No past dispute judgements yet.</div>
                        <?php else: ?>
                            <?php foreach ($userChats as $c): ?>
                                <a href="ai-judgement.php?chat_id=<?= (int)$c['id'] ?>" class="past-chat-item <?= ($activeChatId === (int)$c['id']) ? 'active' : '' ?>">
                                    <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">⚖️ <?= htmlspecialchars($c['title']) ?></div>
                                    <div style="font-size: 0.7rem; color: var(--grey-text);"><?= htmlspecialchars(date('M d, Y H:i', strtotime($c['created_at']))) ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </aside>

                <!-- Main Panel -->
                <div class="judgement-content">
                    <?php if ($activeChat): ?>
                        <!-- VIEW PAST / SUBMITTED CHAT -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Dispute Case #<?= (int)$activeChat['id'] ?>: <?= htmlspecialchars($activeChat['title']) ?></h3>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--grey-text); margin-bottom: 1.25rem;">
                                Submitted on: <?= htmlspecialchars(date('M d, Y H:i', strtotime($activeChat['created_at']))) ?>
                            </div>

                            <?php 
                            $perspectiveCount = 0;
                            foreach ($chatMessages as $msg): 
                            ?>
                                <?php if ($msg['role'] === 'user_scenario'): ?>
                                    <div style="background-color: var(--cream); border: 1px solid var(--border); border-radius: 16px; padding: 1.25rem; margin-bottom: 1rem;">
                                        <div style="font-weight: 700; font-size: 0.8rem; text-transform: uppercase; color: var(--grey-text); margin-bottom: 0.5rem;">📋 Dispute Scenario Description</div>
                                        <div style="white-space: pre-wrap; font-size: 0.95rem; color: var(--ink);"><?= htmlspecialchars($msg['content']) ?></div>
                                    </div>
                                <?php elseif ($msg['role'] === 'user_perspective'): ?>
                                    <?php $perspectiveCount++; ?>
                                    <div style="background-color: #FFFFFF; border: 1px solid var(--border); border-radius: 16px; padding: 1.25rem; margin-bottom: 1rem;">
                                        <div style="font-weight: 700; font-size: 0.8rem; text-transform: uppercase; color: var(--grey-text); margin-bottom: 0.5rem;">🗣️ Employee Perspective #<?= $perspectiveCount ?></div>
                                        <div style="white-space: pre-wrap; font-size: 0.95rem; color: var(--ink);"><?= htmlspecialchars($msg['content']) ?></div>
                                    </div>
                                <?php elseif ($msg['role'] === 'ai'): ?>
                                    <div style="background-color: #FFFDF5; border: 1px solid var(--yellow); border-radius: 16px; padding: 1.5rem; margin-bottom: 1rem;">
                                        <div style="font-weight: 700; font-size: 0.85rem; text-transform: uppercase; color: var(--ink); margin-bottom: 0.75rem;">⚖️ Official AI Mediation Judgement</div>
                                        <div style="white-space: pre-wrap; color: var(--ink); font-size: 0.95rem; line-height: 1.6;"><?= htmlspecialchars($msg['content']) ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <!-- NEW DISPUTE SUBMISSION FORM -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Submit a New Workplace Dispute</h3>
                            </div>
                            <form action="ai-judgement.php" method="POST">
                                <input type="hidden" name="action" value="create_judgement">
                                
                                <div style="margin-bottom: 1.25rem;">
                                    <label for="scenario" style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.4rem; color: var(--ink);">
                                        Describe the Dispute Scenario <span style="color: var(--red);">*</span>
                                    </label>
                                    <textarea id="scenario" name="scenario" class="textarea-rounded" rows="4" placeholder="Explain what happened in detail (e.g. Employee A requested leave 1 day prior, but Manager B denied it citing urgent project deadlines...)" required></textarea>
                                </div>

                                <div id="perspectivesContainer">
                                    <!-- Dynamic perspective boxes appended here -->
                                </div>

                                <div style="margin-bottom: 1.5rem;">
                                    <button type="button" class="btn-pill btn-pill-outline" onclick="addPerspective()" style="width: 100%; justify-content: center;">+ Add Employee Perspective</button>
                                </div>

                                <div>
                                    <button type="submit" class="btn-pill btn-pill-yellow">Get AI Judgement &rarr;</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        let perspectiveCounter = 0;

        function addPerspective() {
            perspectiveCounter++;
            const container = document.getElementById('perspectivesContainer');
            
            const group = document.createElement('div');
            group.style.backgroundColor = 'var(--cream)';
            group.style.border = '1px solid var(--border)';
            group.style.borderRadius = '16px';
            group.style.padding = '1rem 1.25rem';
            group.style.marginBottom = '1rem';
            group.className = 'perspective-group';
            group.id = 'perspective_' + perspectiveCounter;
            
            group.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.85rem; font-weight: 600; color: var(--ink);">Employee Perspective #${perspectiveCounter}</span>
                    <button type="button" class="btn-pill btn-pill-reject-outline" style="font-size: 0.75rem; padding: 0.2rem 0.6rem;" onclick="removePerspective(${perspectiveCounter})">✕ Remove</button>
                </div>
                <div>
                    <textarea name="perspectives[]" class="textarea-rounded" rows="3" placeholder="Enter this employee's viewpoint or explanation..." required></textarea>
                </div>
            `;
            
            container.appendChild(group);
        }

        function removePerspective(id) {
            const el = document.getElementById('perspective_' + id);
            if (el) {
                el.remove();
                reindexPerspectives();
            }
        }

        function reindexPerspectives() {
            const groups = document.querySelectorAll('.perspective-group');
            perspectiveCounter = 0;
            groups.forEach((group, index) => {
                perspectiveCounter = index + 1;
                const headerSpan = group.querySelector('span');
                if (headerSpan) {
                    headerSpan.textContent = `Employee Perspective #${perspectiveCounter}`;
                }
            });
        }
    </script>
</body>
</html>
