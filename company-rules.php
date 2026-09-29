<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'superadmin']);

$pdo = require __DIR__ . '/config/db.php';

$adminId = $_SESSION['user_id'];
$errors = [];
$success = '';
$ruleTextInput = '';

// Quick stats for sidebar badges
$pendingLeaveReqCount = (int)$pdo->query('SELECT COUNT(*) FROM leave_requests WHERE status = "pending"')->fetchColumn();
$pendingAdminReqCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_requests WHERE status = "pending"')->fetchColumn();
$unreadAdminLeaveMsgCount = (int)$pdo->query('
    SELECT COUNT(*) 
    FROM leave_messages lm
    JOIN leave_requests lr ON lm.leave_request_id = lr.id
    WHERE lm.sender_id = lr.user_id AND lm.is_read = 0
')->fetchColumn();

// Handle CRUD POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. CREATE RULE
    if ($action === 'add') {
        $ruleTextInput = trim($_POST['rule_text'] ?? '');
        if (empty($ruleTextInput)) {
            $errors[] = 'Rule text cannot be empty.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO company_rules (rule_text, created_by, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
            $stmt->execute([$ruleTextInput, $adminId]);
            $success = 'Company rule added successfully.';
            $ruleTextInput = ''; // Clear textarea after success
        }
    }

    // 2. EDIT RULE
    elseif ($action === 'edit') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $updatedText = trim($_POST['rule_text'] ?? '');

        if ($ruleId <= 0) {
            $errors[] = 'Invalid rule selected for editing.';
        } elseif (empty($updatedText)) {
            $errors[] = 'Rule text cannot be empty.';
        } else {
            $chk = $pdo->prepare('SELECT id FROM company_rules WHERE id = ?');
            $chk->execute([$ruleId]);
            if (!$chk->fetch()) {
                $errors[] = 'Company rule not found.';
            } else {
                $stmt = $pdo->prepare('UPDATE company_rules SET rule_text = ? WHERE id = ?');
                $stmt->execute([$updatedText, $ruleId]);
                $success = 'Company rule updated successfully.';
            }
        }
    }

    // 3. DELETE RULE
    elseif ($action === 'delete') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId <= 0) {
            $errors[] = 'Invalid rule selected for deletion.';
        } else {
            $chk = $pdo->prepare('SELECT id FROM company_rules WHERE id = ?');
            $chk->execute([$ruleId]);
            if (!$chk->fetch()) {
                $errors[] = 'Company rule not found.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM company_rules WHERE id = ?');
                $stmt->execute([$ruleId]);
                $success = 'Company rule deleted successfully.';
            }
        }
    }
}

// Fetch all company rules, most recent first
$rulesStmt = $pdo->prepare('
    SELECT cr.*, u.name as admin_name, u.email as admin_email
    FROM company_rules cr
    LEFT JOIN users u ON cr.created_by = u.id
    ORDER BY cr.created_at DESC
');
$rulesStmt->execute();
$companyRules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Rules Management - Employee Portal Admin</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        .edit-drawer { position: absolute; right: 0; top: 100%; margin-top: 0.5rem; z-index: 10; background: #FFFFFF; border: 1px solid var(--border); padding: 1.25rem; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); width: 340px; text-align: left; }
    </style>
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
                    <li><a href="document-custody-tracking.php" class="sidebar-nav-item"><span>📋 Document Tracking</span></a></li>
                    <li><a href="company-rules.php" class="sidebar-nav-item active"><span>📜 Company Rules</span></a></li>
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
                <h1 class="admin-page-title">Company Rules Management</h1>
                <p class="admin-page-subtitle">Define, update, and manage company policies. These rules serve as organizational guidelines and will be referenced by AI system features.</p>
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

            <!-- Section 1: Add New Company Rule -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">Add New Company Rule</h3>
                </div>
                <form action="company-rules.php" method="POST">
                    <input type="hidden" name="action" value="add">
                    <div style="margin-bottom: 1.25rem;">
                        <label for="rule_text" style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.4rem; color: var(--ink);">
                            Rule Statement / Policy Text <span style="color: var(--red);">*</span>
                        </label>
                        <textarea id="rule_text" name="rule_text" class="admin-textarea" rows="4" placeholder="e.g. Leave requests exceeding 3 days require a medical note or prior department manager approval..." required><?= htmlspecialchars($ruleTextInput) ?></textarea>
                    </div>
                    <button type="submit" class="btn-pill btn-pill-yellow">+ Add Company Rule</button>
                </form>
            </div>

            <!-- Section 2: List of Existing Company Rules -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">Defined Company Rules</h3>
                    <span class="status-pill status-pending"><?= count($companyRules) ?> Rules</span>
                </div>

                <div class="table-responsive">
                    <?php if (empty($companyRules)): ?>
                        <div class="no-data">No company rules defined yet. Add your first rule above.</div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Rule Statement</th>
                                    <th>Added By</th>
                                    <th>Added Date</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($companyRules as $rule): ?>
                                    <tr>
                                        <td><strong>#<?= (int)$rule['id'] ?></strong></td>
                                        <td style="white-space: pre-wrap; font-size: 0.9rem;"><strong><?= htmlspecialchars($rule['rule_text']) ?></strong></td>
                                        <td>
                                            <strong><?= htmlspecialchars($rule['admin_name'] ?? 'Admin') ?></strong>
                                            <div style="font-size: 0.8rem; color: var(--grey-text);"><?= htmlspecialchars($rule['admin_email'] ?? '') ?></div>
                                        </td>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($rule['created_at']))) ?></td>
                                        <td style="text-align: right; white-space: nowrap; position: relative;">
                                            <!-- Edit Form Drawer -->
                                            <details style="display: inline-block; margin-right: 0.35rem;">
                                                <summary class="btn-pill btn-pill-outline">✏️ Edit</summary>
                                                <div class="edit-drawer">
                                                    <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--ink);">Edit Rule #<?= (int)$rule['id'] ?></h4>
                                                    <form action="company-rules.php" method="POST">
                                                        <input type="hidden" name="action" value="edit">
                                                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                                        <div style="margin-bottom: 0.75rem;">
                                                            <textarea name="rule_text" class="admin-textarea" rows="4" required><?= htmlspecialchars($rule['rule_text']) ?></textarea>
                                                        </div>
                                                        <button type="submit" class="btn-pill btn-pill-yellow" style="width: 100%;">Save Changes</button>
                                                    </form>
                                                </div>
                                            </details>

                                            <!-- Delete Form -->
                                            <form action="company-rules.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this company rule?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                                <button type="submit" class="btn-pill btn-pill-reject">🗑️ Delete</button>
                                            </form>
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
