<?php
require_once __DIR__ . '/includes/auth.php';
require_role('employee');

$pdo = require __DIR__ . '/config/db.php';

$userId = $_SESSION['user_id'];
$errors = [];
$success = '';
$messageInput = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $messageInput = trim($_POST['message'] ?? '');
    
    // Validate Message
    if (empty($messageInput)) {
        $errors[] = 'Leave request message is required.';
    }

    $attachmentPath = null;

    // Handle File Upload if provided
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'An error occurred during file upload. Error code: ' . $file['error'];
        } else {
            // Check file size (5MB = 5 * 1024 * 1024 = 5242880 bytes)
            $maxSizeBytes = 5 * 1024 * 1024;
            if ($file['size'] > $maxSizeBytes) {
                $errors[] = 'File size exceeds the maximum allowed limit of 5MB.';
            }

            // Check file extension
            $origName = $file['name'];
            $extension = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowedExtensions = ['doc', 'docx', 'pdf'];

            if (!in_array($extension, $allowedExtensions, true)) {
                $errors[] = 'Invalid file type. Only .doc, .docx, and .pdf files are allowed.';
            }

            // Verify MIME type using finfo
            if (empty($errors) && file_exists($file['tmp_name'])) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                $allowedMimeTypes = [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/x-pdf',
                    'binary/octet-stream' // fallback for some browser/OS variations
                ];

                if (!in_array($mimeType, $allowedMimeTypes, true)) {
                    $errors[] = 'File content type is invalid. Please upload a valid .doc, .docx, or .pdf file.';
                }
            }

            // If valid, save file
            if (empty($errors)) {
                $uploadsDir = __DIR__ . '/uploads';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                }

                $uniqueFilename = 'leave_u' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $targetFile = $uploadsDir . '/' . $uniqueFilename;

                if (move_uploaded_file($file['tmp_name'], $targetFile)) {
                    $attachmentPath = 'uploads/' . $uniqueFilename;
                } else {
                    $errors[] = 'Failed to save the uploaded file. Please try again.';
                }
            }
        }
    }

    // Insert into database if no errors
    if (empty($errors)) {
        $stmt = $pdo->prepare('INSERT INTO leave_requests (user_id, message, attachment_path, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $messageInput, $attachmentPath, 'pending']);

        $success = 'Your leave request has been submitted successfully.';
        $messageInput = ''; // Clear textarea after success
    }
}

// Fetch user's previous leave requests (most recent first)
$stmt = $pdo->prepare('SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$userId]);
$leaveRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map unread leave message counts for this user
$unreadMap = [];
$unrStmt = $pdo->prepare('
    SELECT lm.leave_request_id, COUNT(*) as unread_count
    FROM leave_messages lm
    JOIN leave_requests lr ON lm.leave_request_id = lr.id
    WHERE lr.user_id = ? AND (lm.sender_id != ? OR lm.sender_id IS NULL) AND lm.is_read = 0
    GROUP BY lm.leave_request_id
');
$unrStmt->execute([$userId, $userId]);
$unreadLeaveMsgCount = 0;
foreach ($unrStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $unreadMap[(int)$row['leave_request_id']] = (int)$row['unread_count'];
    $unreadLeaveMsgCount += (int)$row['unread_count'];
}

// Fetch latest admin request status for sidebar
$reqStmt = $pdo->prepare('SELECT status FROM admin_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 1');
$reqStmt->execute([$userId]);
$latestAdminReq = $reqStmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Application - Employee Portal</title>
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
            <div class="page-header">
                <h1 class="page-title">Leave Application</h1>
                <p class="page-subtitle">Submit a new leave request or review your submission history.</p>
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

            <!-- Submit New Leave Request Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Submit New Leave Request</h3>
                </div>

                <form action="leave-request.php" method="POST" enctype="multipart/form-data">
                    <div style="margin-bottom: 1.25rem;">
                        <label for="message" style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.4rem; color: var(--ink);">
                            Reason / Details for Leave <span style="color: var(--red);">*</span>
                        </label>
                        <textarea id="message" name="message" class="textarea-rounded" rows="4" placeholder="Please describe why you are requesting leave and the duration..." required><?= htmlspecialchars($messageInput) ?></textarea>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <label for="attachment" style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.4rem; color: var(--ink);">
                            Proof / Attachment (Optional)
                        </label>
                        <div class="file-input-wrapper">
                            <input type="file" id="attachment" name="attachment" accept=".doc,.docx,.pdf">
                            <div style="font-size: 0.8rem; color: var(--grey-text); margin-top: 0.35rem;">Allowed file types: .doc, .docx, .pdf (Max size: 5MB)</div>
                        </div>
                    </div>

                    <button type="submit" class="btn-pill btn-pill-yellow">Submit Request &rarr;</button>
                </form>
            </div>

            <!-- Card-Style Leave History -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">My Leave Request History</h3>
                    <span class="status-pill status-pending"><?= count($leaveRequests) ?> Total</span>
                </div>

                <?php if (empty($leaveRequests)): ?>
                    <div class="no-data">You have not submitted any leave requests yet.</div>
                <?php else: ?>
                    <div class="card-list">
                        <?php foreach ($leaveRequests as $req): ?>
                            <?php 
                            $reqId = (int)$req['id'];
                            $hasUnread = !empty($unreadMap[$reqId]);
                            $status = strtolower($req['status']);
                            $statusClass = 'status-pending';
                            if ($status === 'approved') $statusClass = 'status-approved';
                            if ($status === 'rejected') $statusClass = 'status-rejected';

                            $fullMsg = $req['message'];
                            $shortMsg = mb_strlen($fullMsg) > 90 ? mb_substr($fullMsg, 0, 90) . '...' : $fullMsg;
                            ?>
                            <div class="card-row">
                                <div class="card-row-info">
                                    <div class="card-row-title">
                                        Request #<?= $reqId ?>
                                        <span class="status-pill <?= $statusClass ?>" style="margin-left: 0.5rem; font-size: 0.75rem; padding: 0.2rem 0.65rem;">
                                            <?= htmlspecialchars(ucfirst($status)) ?>
                                        </span>
                                        <?php if ($hasUnread): ?>
                                            <span class="nav-counter" style="margin-left: 0.35rem;">🔴 <?= $unreadMap[$reqId] ?> unread</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.9rem; color: var(--ink); margin-top: 0.25rem;">
                                        <?= htmlspecialchars($shortMsg) ?>
                                    </div>
                                    <div class="card-row-meta" style="margin-top: 0.25rem;">
                                        Submitted on: <?= htmlspecialchars(date('M d, Y H:i', strtotime($req['created_at']))) ?>
                                        <?php if (!empty($req['attachment_path'])): ?>
                                            &bull; <a href="<?= htmlspecialchars($req['attachment_path']) ?>" target="_blank" class="link-thread" style="color: var(--ink);">📄 Attachment</a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card-row-actions">
                                    <a href="leave-thread.php?id=<?= $reqId ?>" class="link-thread">
                                        💬 View conversation &rarr;
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</body>
</html>
