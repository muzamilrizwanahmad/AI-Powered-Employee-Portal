<?php
require_once __DIR__ . '/includes/auth.php';
require_role('employee');

$pdo = require __DIR__ . '/config/db.php';

$userId = $_SESSION['user_id'];
$errors = [];
$success = '';

// Handle POST actions (Add, Rename, Delete, Handover)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD DOCUMENT
    if ($action === 'add') {
        $docName = trim($_POST['name'] ?? '');
        if (empty($docName)) {
            $errors[] = 'Document name is required.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO documents (name, current_holder_id, created_at, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            $stmt->execute([$docName, $userId]);
            $success = 'Document "' . htmlspecialchars($docName) . '" added to your custody.';
        }
    }

    // 2. RENAME DOCUMENT
    elseif ($action === 'rename') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $newName = trim($_POST['new_name'] ?? '');

        if (empty($newName)) {
            $errors[] = 'New document name cannot be empty.';
        } else {
            // Verify ownership
            $chk = $pdo->prepare('SELECT id FROM documents WHERE id = ? AND current_holder_id = ?');
            $chk->execute([$docId, $userId]);
            if (!$chk->fetch()) {
                $errors[] = 'Document not found or you do not have custody of this document.';
            } else {
                $stmt = $pdo->prepare('UPDATE documents SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND current_holder_id = ?');
                $stmt->execute([$newName, $docId, $userId]);
                $success = 'Document renamed successfully to "' . htmlspecialchars($newName) . '".';
            }
        }
    }

    // 3. DELETE DOCUMENT
    elseif ($action === 'delete') {
        $docId = (int)($_POST['doc_id'] ?? 0);

        // Verify ownership
        $chk = $pdo->prepare('SELECT id, name FROM documents WHERE id = ? AND current_holder_id = ?');
        $chk->execute([$docId, $userId]);
        $doc = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            $errors[] = 'Document not found or you do not have custody of this document.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM documents WHERE id = ? AND current_holder_id = ?');
            $stmt->execute([$docId, $userId]);
            $success = 'Document "' . htmlspecialchars($doc['name']) . '" deleted successfully.';
        }
    }

    // 4. HANDOVER CUSTODY
    elseif ($action === 'handover') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $toUserId = (int)($_POST['to_user_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($toUserId <= 0) {
            $errors[] = 'Please select a valid user to hand over custody to.';
        } elseif ($toUserId === $userId) {
            $errors[] = 'You cannot hand over custody to yourself.';
        }

        if (empty($reason)) {
            $errors[] = 'Handover reason is required.';
        }

        if (empty($errors)) {
            // Verify target user exists
            $uChk = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
            $uChk->execute([$toUserId]);
            $targetUser = $uChk->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $errors[] = 'Selected user does not exist.';
            } else {
                // Verify document ownership
                $chk = $pdo->prepare('SELECT id, name FROM documents WHERE id = ? AND current_holder_id = ?');
                $chk->execute([$docId, $userId]);
                $doc = $chk->fetch(PDO::FETCH_ASSOC);

                if (!$doc) {
                    $errors[] = 'Document not found or you do not have custody of this document.';
                } else {
                    // Perform transaction
                    try {
                        $pdo->beginTransaction();

                        // Update current holder
                        $updateStmt = $pdo->prepare('UPDATE documents SET current_holder_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND current_holder_id = ?');
                        $updateStmt->execute([$toUserId, $docId, $userId]);

                        // Insert custody log
                        $logStmt = $pdo->prepare('INSERT INTO custody_log (document_id, from_user_id, to_user_id, reason, handed_over_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
                        $logStmt->execute([$docId, $userId, $toUserId, $reason]);

                        $pdo->commit();
                        $success = 'Custody of "' . htmlspecialchars($doc['name']) . '" handed over to ' . htmlspecialchars($targetUser['name']) . '.';
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $errors[] = 'Transaction failed: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Fetch documents currently in this employee's custody
$docStmt = $pdo->prepare('SELECT * FROM documents WHERE current_holder_id = ? ORDER BY created_at DESC');
$docStmt->execute([$userId]);
$myDocuments = $docStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch list of all other existing users for handover selection
$userStmt = $pdo->prepare('SELECT id, name, email, department, role FROM users WHERE id != ? ORDER BY name ASC');
$userStmt->execute([$userId]);
$otherUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent custody logs involving this user for history section
$logStmt = $pdo->prepare('
    SELECT c.*, d.name as doc_name, 
           u_from.name as from_name, u_to.name as to_name
    FROM custody_log c
    JOIN documents d ON c.document_id = d.id
    LEFT JOIN users u_from ON c.from_user_id = u_from.id
    JOIN users u_to ON c.to_user_id = u_to.id
    WHERE c.from_user_id = ? OR c.to_user_id = ?
    ORDER BY c.handed_over_at DESC
    LIMIT 20
');
$logStmt->execute([$userId, $userId]);
$custodyLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Document Custody - Employee Portal</title>
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
                    <li><a href="document-custody.php" class="sidebar-nav-item active"><span>📁 Document Custody</span></a></li>
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
            <div class="page-header">
                <h1 class="page-title">Document Custody Management</h1>
                <p class="page-subtitle">Register new documents in your custody, rename existing records, or transfer custody to a colleague.</p>
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

            <!-- Section 1: Add New Document Form -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Add New Document to Custody</h3>
                </div>
                <form action="document-custody.php" method="POST">
                    <input type="hidden" name="action" value="add">
                    <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 240px;">
                            <input type="text" id="name" name="name" class="input-pill" placeholder="e.g. Q3 Financial Report, Laptop Agreement #402..." required>
                        </div>
                        <button type="submit" class="btn-pill btn-pill-yellow">+ Add Document</button>
                    </div>
                </form>
            </div>

            <!-- Section 2: List Documents in My Custody (Card Rows) -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Documents Currently in My Custody</h3>
                    <span class="status-pill status-pending"><?= count($myDocuments) ?> Active</span>
                </div>

                <?php if (empty($myDocuments)): ?>
                    <div class="no-data">You currently do not have any documents in your custody.</div>
                <?php else: ?>
                    <div class="card-list">
                        <?php foreach ($myDocuments as $doc): ?>
                            <?php $docId = (int)$doc['id']; ?>
                            <div class="card-row">
                                <div class="card-row-info">
                                    <div class="card-row-title">
                                        📄 <?= htmlspecialchars($doc['name']) ?>
                                        <span class="card-row-meta" style="margin-left: 0.5rem; font-weight: normal;">(#<?= $docId ?>)</span>
                                    </div>
                                    <div class="card-row-meta">
                                        Added on: <?= htmlspecialchars(date('M d, Y H:i', strtotime($doc['created_at']))) ?>
                                        &bull; Updated: <?= htmlspecialchars(date('M d, Y H:i', strtotime($doc['updated_at']))) ?>
                                    </div>
                                </div>

                                <div class="card-row-actions" style="position: relative;">
                                    <!-- Rename Drawer Option -->
                                    <details style="position: relative; display: inline-block;">
                                        <summary class="btn-pill btn-pill-outline">✏️ Rename</summary>
                                        <div class="action-drawer">
                                            <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--ink);">Rename Document</h4>
                                            <form action="document-custody.php" method="POST">
                                                <input type="hidden" name="action" value="rename">
                                                <input type="hidden" name="doc_id" value="<?= $docId ?>">
                                                <div style="margin-bottom: 0.75rem;">
                                                    <input type="text" name="new_name" class="input-pill" value="<?= htmlspecialchars($doc['name']) ?>" required>
                                                </div>
                                                <button type="submit" class="btn-pill btn-pill-yellow" style="width: 100%; justify-content: center;">Save New Name</button>
                                            </form>
                                        </div>
                                    </details>

                                    <!-- Handover Drawer Option -->
                                    <details style="position: relative; display: inline-block;">
                                        <summary class="btn-pill btn-pill-yellow-outline">🔄 Handover</summary>
                                        <div class="action-drawer">
                                            <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--ink);">Handover Custody</h4>
                                            <form action="document-custody.php" method="POST">
                                                <input type="hidden" name="action" value="handover">
                                                <input type="hidden" name="doc_id" value="<?= $docId ?>">

                                                <div style="margin-bottom: 0.75rem;">
                                                    <label style="display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.25rem;">Hand Over To:</label>
                                                    <select name="to_user_id" class="select-pill" required>
                                                        <option value="">-- Select Employee --</option>
                                                        <?php foreach ($otherUsers as $u): ?>
                                                            <option value="<?= (int)$u['id'] ?>">
                                                                <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div style="margin-bottom: 0.75rem;">
                                                    <label style="display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.25rem;">Reason for Handover:</label>
                                                    <input type="text" name="reason" class="input-pill" placeholder="e.g. Audit review, Project handoff..." required>
                                                </div>

                                                <button type="submit" class="btn-pill btn-pill-yellow" style="width: 100%; justify-content: center;">Confirm Handover</button>
                                            </form>
                                        </div>
                                    </details>

                                    <!-- Delete Option -->
                                    <form action="document-custody.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this document from the system?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="doc_id" value="<?= $docId ?>">
                                        <button type="submit" class="btn-pill btn-pill-reject-outline">🗑️ Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Section 3: Recent Handover Activity Log -->
            <?php if (!empty($custodyLogs)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">My Handover Activity Log</h3>
                    </div>

                    <div class="card-list">
                        <?php foreach ($custodyLogs as $log): ?>
                            <div class="card-row" style="padding: 1rem 1.25rem;">
                                <div class="card-row-info">
                                    <div class="card-row-title" style="font-size: 0.95rem;">
                                        📄 <?= htmlspecialchars($log['doc_name']) ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--ink); margin-top: 0.2rem;">
                                        <strong><?= htmlspecialchars($log['from_name'] ?? 'System') ?></strong> &rarr; <strong><?= htmlspecialchars($log['to_name']) ?></strong>
                                    </div>
                                    <div class="card-row-meta" style="margin-top: 0.15rem;">
                                        Reason: "<?= htmlspecialchars($log['reason']) ?>" &bull; <?= htmlspecialchars(date('M d, Y H:i', strtotime($log['handed_over_at']))) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>
</body>
</html>
