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
$success = '';
$submission = null;

if ($submission_id <= 0) {
    $error = 'Missing submission reference.';
}

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

// Ownership and state check
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
            if (!in_array($ext, $allowedExtensions, true)) {
                throw new RuntimeException('Invalid file type.');
            }
            if ($file['size'] > $maxFileSize) {
                throw new RuntimeException('File too large.');
            }
            $uploadDir = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'quest_submissions' . DIRECTORY_SEPARATOR;
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
            $newName = $submission['employee_id'] . '_' . time() . '.' . $ext;
            $abs = $uploadDir . $newName;
            if (!move_uploaded_file($file['tmp_name'], $abs)) {
                throw new RuntimeException('Failed to save file.');
            }
            $relative = 'uploads/quest_submissions/' . $newName;
            $update[] = 'file_path = ?';
            $params[] = $relative;
            // clear link/text fields if present
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
            // Optional text content in case of simple text edits
            $text_content = trim($_POST['text_content'] ?? '');
            if ($text_content !== '') {
                $update[] = 'text_content = ?';
                $params[] = $text_content;
                $update[] = 'file_path = NULL';
                $update[] = 'drive_link = NULL';
            }
        }

        if (!empty($update)) {
            // Reset status to submitted and update timestamp
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

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Submission</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background:#f3f4f6; font-family: 'Segoe UI', Arial, sans-serif; }
        .page { max-width: 900px; margin: 24px auto; padding: 0 16px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; }
        .title { font-weight:800; font-size:20px; color:#111827; }
        .meta { color:#6b7280; font-size:12px; }
        .btn { display:inline-block; padding:8px 12px; border-radius:8px; background:#111827; color:#fff; text-decoration:none; font-weight:600; }
        input[type=text], textarea { width:100%; border:1px solid #e5e7eb; border-radius:8px; padding:8px; }
        .row { display:flex; gap:12px; flex-wrap:wrap; }
        .col { flex:1; min-width:260px; }
        label { font-weight:600; font-size:12px; color:#374151; }
    </style>
    <script>
        function backToDashboard(){ window.history.back(); }
        function toggleSections(type){
            document.getElementById('fileSec').style.display = (type==='file') ? 'block' : 'none';
            document.getElementById('linkSec').style.display = (type==='link') ? 'block' : 'none';
            document.getElementById('textSec').style.display = (type==='text') ? 'block' : 'none';
        }
    </script>
<?php $curType = 'file'; $st = strtolower(trim($submission['status'] ?? 'pending')); if (!empty($submission['drive_link'])) $curType='link'; elseif (!empty($submission['text_content']) || (!empty($submission['submission_text']) && !filter_var($submission['submission_text'], FILTER_VALIDATE_URL))) $curType='text'; ?>
</head>
<body>
<div class="page">
    <div class="card" style="margin-bottom:12px; display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div class="title">Edit Submission</div>
            <?php if ($submission): ?>
                <div class="meta">Quest ID: <?php echo (int)$submission['quest_id']; ?> • Status: <?php echo htmlspecialchars($st); ?></div>
            <?php endif; ?>
        </div>
        <div><a class="btn" href="javascript:backToDashboard()">Back</a></div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="card" style="background:#FEF2F2; border-color:#FCA5A5; color:#991B1B; margin-bottom:12px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="card" style="background:#ECFDF5; border-color:#10B981; color:#065F46; margin-bottom:12px;"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (empty($error)): ?>
    <div class="card">
        <form method="post" enctype="multipart/form-data">
            <div class="row" style="margin-bottom:8px;">
                <label><input type="radio" name="submission_type" value="file" <?php echo $curType==='file'?'checked':''; ?> onclick="toggleSections('file')"> File</label>
                <label><input type="radio" name="submission_type" value="link" <?php echo $curType==='link'?'checked':''; ?> onclick="toggleSections('link')"> Link</label>
                <label><input type="radio" name="submission_type" value="text" <?php echo $curType==='text'?'checked':''; ?> onclick="toggleSections('text')"> Text</label>
            </div>

            <div id="fileSec" style="display: <?php echo $curType==='file'?'block':'none'; ?>; margin-bottom:8px;">
                <label for="quest_file">Upload new file</label>
                <input type="file" id="quest_file" name="quest_file">
                <div class="meta">PDF, DOC, DOCX, JPG, PNG, TXT, ZIP (Max 5MB)</div>
            </div>

            <div id="linkSec" style="display: <?php echo $curType==='link'?'block':'none'; ?>; margin-bottom:8px;">
                <label for="drive_link">Drive/URL</label>
                <input type="text" id="drive_link" name="drive_link" value="<?php echo htmlspecialchars($submission['drive_link'] ?? ''); ?>" placeholder="https://...">
            </div>

            <div id="textSec" style="display: <?php echo $curType==='text'?'block':'none'; ?>; margin-bottom:8px;">
                <label for="text_content">Text Content</label>
                <textarea id="text_content" name="text_content" rows="6"><?php echo htmlspecialchars($submission['text_content'] ?? ($submission['submission_text'] ?? '')); ?></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="submit" class="btn" style="background:#4F46E5;">Save Changes</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    </div>
</body>
</html>
