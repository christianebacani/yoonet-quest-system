<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
$error = '';
$submission = null;
$quest = null;

if ($submission_id <= 0) {
    $error = 'Missing submission reference.';
}

if (empty($error)) {
    try {
        $stmt = $pdo->prepare("SELECT qs.*, q.title AS quest_title FROM quest_submissions qs JOIN quests q ON qs.quest_id = q.id WHERE qs.id = ?");
        $stmt->execute([$submission_id]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$submission) { $error = 'Submission not found.'; }
    } catch (PDOException $e) {
        $error = 'Error loading submission.';
        error_log('view_submission: ' . $e->getMessage());
    }
}

// Ownership check
if (empty($error)) {
    $currentEmployee = $_SESSION['employee_id'] ?? null;
    $role = $_SESSION['role'] ?? '';
    $isAdmin = ($role === 'admin');
    if (!$isAdmin && (!$currentEmployee || $submission['employee_id'] != $currentEmployee)) {
        $error = 'You are not allowed to view this submission.';
    }
}

function abs_url($rel) {
    if (preg_match('~^https?://~i', $rel)) return $rel;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $rel = ltrim((string)$rel, '/');
    $prefix = trim($base, '/');
    $path = $prefix !== '' ? ($prefix . '/' . $rel) : $rel;
    return $scheme . '://' . $host . '/' . $path;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submission</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background:#f3f4f6; font-family: 'Segoe UI', Arial, sans-serif; }
        .page { max-width: 1000px; margin: 24px auto; padding: 0 16px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; }
        .title { font-weight:800; font-size:20px; color:#111827; }
        .meta { color:#6b7280; font-size:12px; }
        .preview { margin-top:12px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:12px; }
        .preview img { max-width:100%; height:auto; border-radius:6px; }
        .btn { display:inline-block; padding:8px 12px; border-radius:8px; background:#111827; color:#fff; text-decoration:none; font-weight:600; }
        .btn-gray { background:#4B5563; }
        .btn-green { background:#059669; }
        .file-pill { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:9999px; background:#EEF2FF; color:#3730A3; border:1px solid #C7D2FE; font-size:12px; font-weight:600; }
        .row { display:flex; flex-wrap:wrap; align-items:center; gap:10px; justify-content:space-between; }
    </style>
</head>
<body>
<div class="page">
    <div class="card" style="margin-bottom:12px; display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div class="title">View Submission</div>
            <?php if ($submission): ?>
                <div class="meta">Quest: <?php echo htmlspecialchars($submission['quest_title']); ?> • Submitted: <?php echo !empty($submission['submitted_at']) ? date('M d, Y g:i A', strtotime($submission['submitted_at'])) : '—'; ?></div>
            <?php endif; ?>
        </div>
        <div>
            <a class="btn" href="javascript:history.back()">Back</a>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="card" style="background:#FEF2F2; border-color:#FCA5A5; color:#991B1B;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php else: ?>
        <?php
            $filePath = $submission['file_path'] ?? '';
            $driveLink = $submission['drive_link'] ?? '';
            $textContent = $submission['text_content'] ?? '';
            $submissionText = $submission['submission_text'] ?? '';
            $rendered = false;
        ?>
        <div class="card">
            <?php
                $fileName = '';
                if (!empty($filePath)) {
                    $fileName = basename($filePath);
                } elseif (!empty($driveLink)) {
                    $u = parse_url($driveLink);
                    $path = $u['path'] ?? '';
                    $bn = $path !== '' ? basename($path) : '';
                    $fileName = $bn !== '' ? $bn : ($u['host'] ?? 'external link');
                }
            ?>
            <?php if (!empty($filePath) || (!empty($driveLink) && filter_var($driveLink, FILTER_VALIDATE_URL))): ?>
                <?php $absFile = !empty($filePath) ? abs_url($filePath) : $driveLink; ?>
                <div class="row" style="margin-bottom:8px;">
                    <div class="file-pill">📄 <?php echo htmlspecialchars($fileName ?: 'Submission'); ?></div>
                    <div style="display:flex; gap:8px;">
                        <a href="#submission-inline-preview" class="btn">Open</a>
                        <a href="<?php echo htmlspecialchars($absFile); ?>" target="_blank" rel="noopener" class="btn btn-gray">View in new tab</a>
                        <a href="<?php echo htmlspecialchars($absFile); ?>" download class="btn btn-green">Download</a>
                    </div>
                </div>
            <?php endif; ?>
            <div class="preview" id="submission-inline-preview">
                <?php if (!empty($filePath)): ?>
                    <?php $web = abs_url($filePath); $ext = strtolower(pathinfo($web, PATHINFO_EXTENSION)); ?>
                    <?php if (in_array($ext, ['jpg','jpeg','png','gif','webp'])): ?>
                        <img src="<?php echo htmlspecialchars($web); ?>" alt="submission image" />
                        <?php $rendered = true; ?>
                    <?php elseif ($ext === 'pdf'): ?>
                        <iframe src="<?php echo htmlspecialchars($web); ?>" width="100%" style="border:0; min-height:70vh;"></iframe>
                        <?php $rendered = true; ?>
                    <?php elseif (in_array($ext, ['txt','md','csv'])): ?>
                        <pre style="white-space:pre-wrap; background:#111827; color:#e5e7eb; padding:12px; border-radius:8px;"><?php echo htmlspecialchars(@file_get_contents($filePath)); ?></pre>
                        <?php $rendered = true; ?>
                    <?php else: ?>
                        <p>No inline preview for this file type.</p>
                        <?php $rendered = true; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$rendered && !empty($driveLink) && filter_var($driveLink, FILTER_VALIDATE_URL)): ?>
                    <p>External Link:</p>
                    <a class="btn" href="<?php echo htmlspecialchars($driveLink); ?>" target="_blank" rel="noopener">Open Link</a>
                    <?php $rendered = true; ?>
                <?php endif; ?>

                <?php if (!$rendered && !empty($textContent)): ?>
                    <pre style="white-space:pre-wrap; background:#111827; color:#e5e7eb; padding:12px; border-radius:8px;"><?php echo htmlspecialchars($textContent); ?></pre>
                    <?php $rendered = true; ?>
                <?php endif; ?>

                <?php if (!$rendered && !empty($submissionText)): ?>
                    <?php if (filter_var($submissionText, FILTER_VALIDATE_URL)): ?>
                        <a class="btn" href="<?php echo htmlspecialchars($submissionText); ?>" target="_blank" rel="noopener">Open Link</a>
                    <?php else: ?>
                        <pre style="white-space:pre-wrap; background:#111827; color:#e5e7eb; padding:12px; border-radius:8px;"><?php echo htmlspecialchars($submissionText); ?></pre>
                    <?php endif; ?>
                    <?php $rendered = true; ?>
                <?php endif; ?>

                <?php if (!$rendered): ?>
                    <p>No preview available for this submission.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    </div>
</body>
</html>
