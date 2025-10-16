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

$quest_id = isset($_GET['quest_id']) ? (int)$_GET['quest_id'] : 0;
$error = '';
$success = '';
$quest = null;

if ($quest_id <= 0) {
    $error = 'Missing quest reference.';
}

if (empty($error)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM quests WHERE id = ?");
        $stmt->execute([$quest_id]);
        $quest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$quest) { $error = 'Quest not found.'; }
    } catch (PDOException $e) {
        $error = 'Error loading quest.';
        error_log('submit_quest: ' . $e->getMessage());
    }
}

if (empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = $_SESSION['employee_id'] ?? '';
    $submissionType = $_POST['submission_type'] ?? '';
    $drive_link = trim($_POST['drive_link'] ?? '');
    $text_content = trim($_POST['text_content'] ?? '');
    $file_path = '';
    $status = 'submitted';
    $submitted_at = date('Y-m-d H:i:s');

    // Handle file upload
    if ($submissionType === 'file' && isset($_FILES['quest_file']) && $_FILES['quest_file']['error'] === UPLOAD_ERR_OK) {
        $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt', 'zip'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        $file = $_FILES['quest_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions)) {
            $error = 'Invalid file type.';
        } elseif ($file['size'] > $maxFileSize) {
            $error = 'File too large.';
        } else {
            $uploadDir = __DIR__ . '/uploads/quest_submissions/';
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
            $newName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
            $abs = $uploadDir . $newName;
            if (!move_uploaded_file($file['tmp_name'], $abs)) {
                $error = 'Failed to save file.';
            } else {
                $file_path = 'uploads/quest_submissions/' . $newName;
            }
        }
    }

    if (empty($error)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO quest_submissions (employee_id, quest_id, submission_type, file_path, drive_link, text_content, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $employee_id,
                $quest_id,
                $submissionType,
                $file_path ?: null,
                $drive_link ?: null,
                $text_content ?: null,
                $status,
                $submitted_at
            ]);
            // Update user_quests status
            $stmt2 = $pdo->prepare("UPDATE user_quests SET status = 'submitted' WHERE employee_id = ? AND quest_id = ?");
            $stmt2->execute([$employee_id, $quest_id]);
            $success = 'Quest submitted successfully!';
        } catch (PDOException $e) {
            $error = 'Error saving submission.';
            error_log('submit_quest save: ' . $e->getMessage());
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Submit Quest</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .container { max-width: 600px; margin: 40px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px #e0e7ef33; }
        .quest-title { font-size: 1.3rem; font-weight: 700; color: #3730a3; margin-bottom: 8px; }
        .meta { color: #64748b; font-size: 0.98em; margin-bottom: 16px; }
        .form-row { margin-bottom: 18px; }
        .form-label { font-weight: 600; color: #374151; margin-bottom: 6px; display: block; }
        .form-radio-group { display: flex; gap: 18px; margin-bottom: 10px; }
        .form-radio-group label { font-weight: 500; color: #4b5563; }
        .form-input, .form-textarea { width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; font-size: 1em; }
        .form-textarea { min-height: 90px; resize: vertical; }
        .btn { display:inline-block; padding: 10px 20px; border-radius: 8px; background:#4F46E5; color:#fff; text-decoration:none; border:none; font-weight:600; transition:background 0.2s; cursor:pointer; font-size:1em; }
        .btn:hover { background:#3737b8; color:#fff; }
        .success { background: #ECFDF5; border-color: #10B981; color: #065F46; margin-bottom: 12px; padding: 12px; border-radius: 8px; }
        .error { background: #FEF2F2; border-color: #FCA5A5; color: #991B1B; margin-bottom: 12px; padding: 12px; border-radius: 8px; }
    </style>
    <script>
    function toggleSections(type) {
        document.getElementById('fileSec').style.display = (type==='file') ? 'block' : 'none';
        document.getElementById('linkSec').style.display = (type==='link') ? 'block' : 'none';
        document.getElementById('textSec').style.display = (type==='text') ? 'block' : 'none';
    }
    </script>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="quest-title"><?php echo htmlspecialchars($quest['title'] ?? 'Submit Quest'); ?></div>
            <div class="meta">Due: <?php echo !empty($quest['due_date']) ? htmlspecialchars(date('Y-m-d', strtotime($quest['due_date']))) : '—'; ?></div>
            <?php if (!empty($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php elseif (!empty($success)): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if (empty($success)): ?>
            <form method="post" enctype="multipart/form-data">
                <div class="form-row form-radio-group">
                    <label><input type="radio" name="submission_type" value="file" checked onclick="toggleSections('file')"> File</label>
                    <label><input type="radio" name="submission_type" value="link" onclick="toggleSections('link')"> Link</label>
                    <label><input type="radio" name="submission_type" value="text" onclick="toggleSections('text')"> Text</label>
                </div>
                <div id="fileSec" class="form-row">
                    <label class="form-label" for="quest_file">Upload File</label>
                    <input class="form-input" type="file" id="quest_file" name="quest_file">
                    <div class="meta">PDF, DOC, DOCX, JPG, PNG, TXT, ZIP (Max 5MB)</div>
                </div>
                <div id="linkSec" class="form-row" style="display:none;">
                    <label class="form-label" for="drive_link">Drive/URL</label>
                    <input class="form-input" type="text" id="drive_link" name="drive_link" placeholder="https://...">
                </div>
                <div id="textSec" class="form-row" style="display:none;">
                    <label class="form-label" for="text_content">Text Content</label>
                    <textarea class="form-textarea" id="text_content" name="text_content" rows="6"></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="submit" class="btn">Submit Quest</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
