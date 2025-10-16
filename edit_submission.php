<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!is_logged_in()) { header('Location: login.php'); exit(); }
$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
$error = '';
$success = '';
$submission = null;
if ($submission_id <= 0) { $error = 'Missing submission reference.'; }
if (empty($error)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM quest_submissions WHERE id = ?");
        $stmt->execute([$submission_id]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$submission) { $error = 'Submission not found.'; }
    } catch (PDOException $e) {
        $error = 'Error loading submission.';
        error_log('edit_submission: ' . $e->getMessage());
    }
}
if (empty($error)) {
    $currentEmployee = $_SESSION['employee_id'] ?? null;
    $role = $_SESSION['role'] ?? '';
    $isAdmin = ($role === 'admin');
    if (!$isAdmin && (!$currentEmployee || $submission['employee_id'] != $currentEmployee)) {
        $error = 'You are not allowed to edit this submission.';
    }
    $st = strtolower(trim($submission['status'] ?? 'pending'));
    if (in_array($st, ['approved','rejected'], true)) {
        $error = 'This submission has already been graded and can no longer be edited.';
    }
}
if (empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submissionType = $_POST['submission_type'] ?? '';
    $drive_link = trim($_POST['drive_link'] ?? '');
    $update = [];
    $params = [];
    $fileUpdated = false;
    try {
        if ($submissionType === 'file' && isset($_FILES['quest_file']) && $_FILES['quest_file']['error'] === UPLOAD_ERR_OK) {
            $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt', 'zip'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB
            $file = $_FILES['quest_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions, true)) { throw new RuntimeException('Invalid file type.'); }
            if ($file['size'] > $maxFileSize) { throw new RuntimeException('File too large.'); }
            $uploadDir = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'quest_submissions' . DIRECTORY_SEPARATOR;
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
            $newName = $submission['employee_id'] . '_' . time() . '.' . $ext;
            $abs = $uploadDir . $newName;
            if (!move_uploaded_file($file['tmp_name'], $abs)) { throw new RuntimeException('Failed to save file.'); }
            $relative = 'uploads/quest_submissions/' . $newName;
            $update[] = 'file_path = ?';
            $params[] = $relative;
            $update[] = 'drive_link = NULL';
            $update[] = 'submission_text = NULL';
            $update[] = 'text_content = NULL';
            $fileUpdated = true;
        } elseif ($submissionType === 'link' && $drive_link !== '' && filter_var($drive_link, FILTER_VALIDATE_URL)) {
            $update[] = 'drive_link = ?';
            $params[] = $drive_link;
            $update[] = 'file_path = NULL';
            $update[] = 'submission_text = NULL';
            $update[] = 'text_content = NULL';
        } else {
            $text_content = trim($_POST['text_content'] ?? '');
            if ($text_content !== '') {
                $update[] = 'text_content = ?';
                $params[] = $text_content;
                $update[] = 'file_path = NULL';
                $update[] = 'drive_link = NULL';
            }
        }
        if (!empty($update)) {
            $update[] = "status = 'submitted'";
            $update[] = 'submitted_at = NOW()';
            $sql = 'UPDATE quest_submissions SET ' . implode(', ', $update) . ' WHERE id = ?';
            $params[] = $submission_id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $success = 'Submission updated successfully.';
        } else {
            $error = 'No changes provided.';
        }
    } catch (Throwable $e) {
        $error = 'Update failed: ' . $e->getMessage();
        error_log('edit_submission update: ' . $e->getMessage());
    }
}
// UI Section (Tailwind, card-based, modern)
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Submission</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/buttons.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); transition: all 0.3s ease; width: 100%; max-width: 100%; }
        .card:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.07), 0 4px 6px -2px rgba(0,0,0,0.05); }
        .btn-primary { background-color: #6366f1; transition: all 0.2s ease; color: #fff; }
        .btn-primary:hover { background-color: #4f46e5; transform: translateY(-1px); }
        .btn-secondary { background-color: #e0e7ff; color: #4f46e5; transition: all 0.2s ease; }
        .btn-secondary:hover { background-color: #c7d2fe; }
        .form-label { font-weight: 600; color: #374151; margin-bottom: 8px; display: block; }
        .error-message { color: #ef4444; font-size: 0.95em; margin-top: 0.25rem; }
        .success-message { color: #22c55e; font-size: 1.1em; margin-bottom: 1rem; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-2xl mx-auto px-4 py-10">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-2xl font-bold text-indigo-700 flex items-center gap-2"><i class="fa-solid fa-pen-to-square"></i> Edit Submission</h1>
            <a href="my_quests.php" class="btn btn-secondary">&larr; Back to My Quests</a>
        </div>
        <div class="card p-8">
            <?php if (!empty($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php elseif (!empty($success)): ?>
                <div class="success-message"><i class="fa fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?></div>
                <a href="my_quests.php" class="btn btn-primary mt-4">Back to My Quests</a>
            <?php endif; ?>
            <?php if (empty($success)): ?>
            <form method="post" enctype="multipart/form-data" id="submissionForm" class="space-y-6">
                <div class="mb-4">
                    <label class="form-label">Submission Type</label>
                    <div class="flex gap-6">
                        <?php $curType = 'file';
                        if (!empty($submission['file_path'])) $curType = 'file';
                        elseif (!empty($submission['drive_link'])) $curType = 'link';
                        elseif (!empty($submission['text_content']) || !empty($submission['submission_text'])) $curType = 'text';
                        ?>
                        <label class="inline-flex items-center"><input type="radio" name="submission_type" value="file" <?php echo $curType==='file'?'checked':''; ?> onclick="toggleSections('file')"> <span class="ml-2">File</span></label>
                        <label class="inline-flex items-center"><input type="radio" name="submission_type" value="link" <?php echo $curType==='link'?'checked':''; ?> onclick="toggleSections('link')"> <span class="ml-2">Link/Google Drive</span></label>
                        <label class="inline-flex items-center"><input type="radio" name="submission_type" value="text" <?php echo $curType==='text'?'checked':''; ?> onclick="toggleSections('text')"> <span class="ml-2">Text</span></label>
                    </div>
                </div>
                <div id="fileSec" class="mb-4" style="display:<?php echo $curType==='file'?'block':'none'; ?>;">
                    <label class="form-label" for="quest_file">Upload File</label>
                    <input class="w-full border rounded px-3 py-2" type="file" id="quest_file" name="quest_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.zip">
                    <div class="text-xs text-gray-500 mt-1">PDF, DOC, DOCX, JPG, PNG, TXT, ZIP (Max 5MB)</div>
                </div>
                <div id="linkSec" class="mb-4" style="display:<?php echo $curType==='link'?'block':'none'; ?>;">
                    <label class="form-label" for="drive_link">Drive/URL</label>
                    <input class="w-full border rounded px-3 py-2" type="text" id="drive_link" name="drive_link" value="<?php echo htmlspecialchars($submission['drive_link'] ?? ''); ?>" placeholder="https://...">
                </div>
                <div id="textSec" class="mb-4" style="display:<?php echo $curType==='text'?'block':'none'; ?>;">
                    <label class="form-label" for="text_content">Text Content</label>
                    <textarea class="w-full border rounded px-3 py-2" id="text_content" name="text_content" rows="6"><?php echo htmlspecialchars($submission['text_content'] ?? ($submission['submission_text'] ?? '')); ?></textarea>
                </div>
                <div class="flex justify-end gap-2 mt-6">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
    function toggleSections(type) {
        document.getElementById('fileSec').style.display = (type==='file') ? 'block' : 'none';
        document.getElementById('linkSec').style.display = (type==='link') ? 'block' : 'none';
        document.getElementById('textSec').style.display = (type==='text') ? 'block' : 'none';
    }
    </script>
</body>
</html>
