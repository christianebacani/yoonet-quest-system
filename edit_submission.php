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

// Inspect quest_submissions columns so updates only touch existing columns (handles schema variations)
$qsCols = [];
try {
    $colStmt = $pdo->query("DESCRIBE quest_submissions");
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) { $qsCols[] = $c['Field']; }
} catch (Throwable $e) {
    // If DESCRIBE fails, fallback to optimistic updates (existing code will attempt and possibly fail)
    $qsCols = [];
}

// Ownership and state check
if (empty($error)) {
    $currentEmployee = $_SESSION['employee_id'] ?? null;
    $role = $_SESSION['role'] ?? '';
    $isAdmin = ($role === 'admin');

    // Ownership check: accept exact employee_id OR legacy 'user_<id>' stored values
    $ownerAllowed = false;
    if ($isAdmin) {
        $ownerAllowed = true;
    } else {
        if ($currentEmployee && (string)$submission['employee_id'] === (string)$currentEmployee) {
            $ownerAllowed = true;
        } else {
            // Check users table to find current user's numeric id and compare to 'user_<id>' format
            try {
                $uStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? LIMIT 1");
                $uStmt->execute([$currentEmployee]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                $cur_user_id = $uRow['id'] ?? null;
                if ($cur_user_id && (string)$submission['employee_id'] === 'user_' . (string)$cur_user_id) {
                    $ownerAllowed = true;
                }
            } catch (PDOException $e) {
                // ignore and fall through to denied
            }
        }
    }
    if (!$ownerAllowed) {
        $error = 'You are not allowed to edit this submission.';
    }

    $st = strtolower(trim($submission['status'] ?? 'pending'));
    // Consider 'approved', 'rejected', and 'graded' as final (non-editable)
    if (in_array($st, ['approved','rejected','graded'], true)) {
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
            // Prepare old path for removal after DB update (do not delete yet)
            if (!empty($submission['file_path'])) {
                $oldPath = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $submission['file_path']);
            } else {
                $oldPath = null;
            }
            // clear known link/text fields if they exist in the schema
            $nullify = ['drive_link','submission_text','text_content','text'];
            foreach ($nullify as $col) {
                if (empty($qsCols) || in_array($col, $qsCols, true)) {
                    $update[] = "$col = NULL";
                }
            }
            $fileUpdated = true;
            // set submission_type to file if column exists
            if (empty($qsCols) || in_array('submission_type', $qsCols, true)) {
                $update[] = "submission_type = 'file'";
            }
        } elseif ($submissionType === 'link' && $drive_link !== '' && filter_var($drive_link, FILTER_VALIDATE_URL)) {
            if (empty($qsCols) || in_array('drive_link', $qsCols, true)) {
                $update[] = 'drive_link = ?';
                $params[] = $drive_link;
            }
            if (empty($qsCols) || in_array('file_path', $qsCols, true)) { $update[] = 'file_path = NULL'; }
            $nullify = ['submission_text','text_content','text'];
            foreach ($nullify as $col) {
                if (empty($qsCols) || in_array($col, $qsCols, true)) { $update[] = "$col = NULL"; }
            }
            if (empty($qsCols) || in_array('submission_type', $qsCols, true)) {
                $update[] = "submission_type = 'link'";
            }
        } else {
            // Optional text content in case of simple text edits
            $text_content = trim($_POST['text_content'] ?? '');
            if ($text_content !== '') {
                if (empty($qsCols) || in_array('text_content', $qsCols, true)) {
                    $update[] = 'text_content = ?';
                    $params[] = $text_content;
                } else {
                    // fallback to submission_text if text_content doesn't exist
                    $update[] = 'submission_text = ?';
                    $params[] = $text_content;
                }
                if (empty($qsCols) || in_array('submission_type', $qsCols, true)) {
                    $update[] = "submission_type = 'text'";
                }
                if (empty($qsCols) || in_array('file_path', $qsCols, true)) { $update[] = 'file_path = NULL'; }
                if (empty($qsCols) || in_array('drive_link', $qsCols, true)) { $update[] = 'drive_link = NULL'; }
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

            // If DB update succeeded, remove old file (if any) to avoid orphaned files
            try {
                if (!empty($fileUpdated) && !empty($oldPath) && file_exists($oldPath) && is_file($oldPath)) {
                    @unlink($oldPath);
                }
            } catch (Throwable $e) {
                // Don't fail the whole request if file deletion fails; log and continue
                error_log('edit_submission: failed to delete old file: ' . $e->getMessage());
            }

            // Refresh submission from DB so the UI shows the updated values
            try {
                $rStmt = $pdo->prepare("SELECT * FROM quest_submissions WHERE id = ? LIMIT 1");
                $rStmt->execute([$submission_id]);
                $submission = $rStmt->fetch(PDO::FETCH_ASSOC) ?: $submission;
            } catch (Throwable $e) {
                // ignore refresh errors; keep previous $submission in memory
            }

            $success = 'Submission updated successfully.';
        } else {
            $error = 'No changes provided.';
        }
    } catch (Throwable $e) {
        $error = 'Update failed: ' . $e->getMessage();
        error_log('edit_submission update: ' . $e->getMessage());
    }
}

// Determine current submission type for showing the correct form section
$curType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $curType = $_POST['submission_type'] ?? '';
}
if (empty($curType)) {
    if (!empty($submission['file_path'])) {
        $curType = 'file';
    } elseif (!empty($submission['drive_link'])) {
        $curType = 'link';
    } elseif (!empty($submission['text_content']) || !empty($submission['submission_text'])) {
        $curType = 'text';
    } else {
        $curType = 'file';
    }
}


?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Submission</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
    .submission-container { max-width: 700px; margin: 48px auto; padding: 0 16px; }
    .file-type-pdf { color: #b91c1c; font-weight: 600; }
    .file-type-doc, .file-type-docx { color: #2563eb; font-weight: 600; }
    .file-type-jpg, .file-type-jpeg, .file-type-png { color: #059669; font-weight: 600; }
    .file-type-txt { color: #f59e42; font-weight: 600; }
    .file-type-zip { color: #6366f1; font-weight: 600; }
        .submission-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px #e0e7ef33; padding: 32px 32px 28px 32px; margin-bottom: 32px; border: 1px solid #e5e7eb; }
        #dropzone { position: relative; }
        #quest_file {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0; cursor: pointer; z-index: 2;
            display: block !important;
        }
        .back-btn { display: inline-block; margin-bottom: 18px; background: #f3f4f6; color: #6366f1; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 18px; font-weight: 600; text-decoration: none; transition: background 0.2s; }
        .back-btn:hover { background: #e0e7ff; color: #3730a3; }
        .form-row { margin-bottom: 22px; }
        .form-label { font-weight: 600; color: #374151; margin-bottom: 8px; display: block; }
        .submission-group { margin-bottom: 18px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px 16px; background: #f9fafb; position: relative; }
        .remove-btn { position: absolute; top: 10px; right: 10px; background: #ef4444; color: #fff; border: none; border-radius: 50%; width: 28px; height: 28px; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .remove-btn:hover { background: #dc2626; }
        .add-btn { display: inline-block; margin-bottom: 18px; background: #4F46E5; color: #fff; border: none; border-radius: 8px; padding: 8px 18px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .add-btn:hover { background: #3737b8; }
        .progress-bar { width: 100%; height: 8px; background: #e5e7eb; border-radius: 6px; margin: 12px 0 0 0; overflow: hidden; }
        .progress-bar-inner { height: 100%; background: var(--primary-color, #4F46E5); width: 0%; transition: width 0.3s; }
    </style>
</head>
<body>
    <div class="submission-container">
    <a href="my_quests.php" class="btn btn-primary back-btn" style="background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 8px; padding: 10px 28px; font-size: 1.05em; font-weight: 600; box-shadow: 0 2px 8px #6366f133; transition: background 0.2s, box-shadow 0.2s; margin-bottom: 18px; display: inline-block;">&larr; Back to My Quests</a>
        <div class="submission-card">
            <div class="quest-header">
                <div class="quest-title">Edit Submission</div>
                <div class="quest-desc">Update your quest submission below. You can change the file, link, or text as needed.</div>
                <?php if ($submission): ?>
                <div class="meta">Quest ID: <?php echo (int)$submission['quest_id']; ?> • Status: <?php echo htmlspecialchars($st); ?></div>
                <?php endif; ?>
            </div>
            <?php if (!empty($error)) { ?>
                <div class="toast toast-error" id="toastMsg"><?php echo htmlspecialchars($error); ?></div>
            <?php } elseif (!empty($success)) { ?>
                <div class="success-overlay" id="successOverlay">
                    <div class="success-card">
                        <div class="success-icon">&#10003;</div>
                        <div class="success-title">Submission Updated!</div>
                        <div class="success-message">Your quest submission has been updated.<br>You can view or edit your submission from your quest list.</div>
                        <a href="my_quests.php" class="btn btn-primary" style="margin-top:18px; background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 8px; padding: 12px 32px; font-size: 1.1em; font-weight: 600; box-shadow: 0 2px 8px #6366f133; transition: background 0.2s, box-shadow 0.2s;">Back to My Quests</a>
                    </div>
                </div>
                <style>
                .success-overlay {
                    position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(60,64,90,0.18); z-index: 99999; display: flex; align-items: center; justify-content: center;
                }
                .success-card {
                    background: #fff; border-radius: 18px; box-shadow: 0 8px 32px #6366f133;
                    padding: 48px 36px 36px 36px; text-align: center; min-width: 320px; max-width: 90vw;
                }
                .success-icon {
                    font-size: 3.2rem; color: #22c55e; margin-bottom: 12px; font-weight: bold;
                }
                .success-title {
                    font-size: 1.6rem; font-weight: 700; color: #3730a3; margin-bottom: 8px;
                }
                .success-message {
                    color: #374151; font-size: 1.08em; margin-bottom: 8px;
                }
                @media (max-width: 600px) {
                    .success-card { padding: 32px 8vw 24px 8vw; min-width: 0; }
                }
                </style>
            <?php }
            if (empty($success)) { ?>
            <form method="post" enctype="multipart/form-data" id="submissionForm">
                <div class="form-row form-radio-group modern-radio-group">
                    <label class="modern-radio"><input type="radio" name="submission_type" value="file" <?php echo $curType==='file'?'checked':''; ?> onclick="toggleSections('file')"><span>File</span></label>
                    <label class="modern-radio"><input type="radio" name="submission_type" value="link" <?php echo $curType==='link'?'checked':''; ?> onclick="toggleSections('link')"><span>Link/Google Drive</span></label>
                    <label class="modern-radio"><input type="radio" name="submission_type" value="text" <?php echo $curType==='text'?'checked':''; ?> onclick="toggleSections('text')"><span>Text</span></label>
                </div>
                <div id="fileSec" class="form-row" style="display:<?php echo $curType==='file'?'block':'none'; ?>;">
                    <label class="form-label" for="quest_file">Upload File</label>
                    <div id="dropzone" class="dropzone">Drag & drop your file here or click to select
                        <input class="form-input" type="file" id="quest_file" name="quest_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.zip">
                    </div>
                    <button type="button" id="clearFileBtn" class="btn btn-primary" style="margin-top:8px;display:none; background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 8px; padding: 10px 28px; font-size: 1.05em; font-weight: 600; box-shadow: 0 2px 8px #6366f133; transition: background 0.2s, box-shadow 0.2s;">Unselect File</button>
                    <div class="error-message" id="fileError" style="display:none;"></div>
                    <div class="file-info" id="fileInfo" style="margin-top:8px;"></div>
                    <div class="meta">PDF, DOC, DOCX, JPG, PNG, TXT, ZIP (Max 5MB)</div>
                    <div class="progress-bar" id="progressBar" style="display:none;"><div class="progress-bar-inner" id="progressBarInner"></div></div>
                </div>
                <div id="linkSec" class="form-row" style="display:<?php echo $curType==='link'?'block':'none'; ?>;">
                    <label class="form-label" for="drive_link">Drive/URL</label>
                    <input class="form-input" type="text" id="drive_link" name="drive_link" value="<?php echo htmlspecialchars($submission['drive_link'] ?? ''); ?>" placeholder="https://...">
                </div>
                <div id="textSec" class="form-row" style="display:<?php echo $curType==='text'?'block':'none'; ?>;">
                    <label class="form-label" for="text_content">Text Content</label>
                    <textarea class="form-textarea" id="text_content" name="text_content" rows="6"><?php echo htmlspecialchars($submission['text_content'] ?? ($submission['submission_text'] ?? '')); ?></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top: 18px;">
                    <button type="submit" class="btn btn-primary" id="submitBtn" style="background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 8px; padding: 12px 32px; font-size: 1.1em; font-weight: 600; box-shadow: 0 2px 8px #6366f133; transition: background 0.2s, box-shadow 0.2s; cursor: pointer;">Save Changes</button>
                </div>
            </form>
            <?php } ?>
        </div>
    </div>
    <script>
    function toggleSections(type) {
        document.getElementById('fileSec').style.display = (type==='file') ? 'block' : 'none';
        document.getElementById('linkSec').style.display = (type==='link') ? 'block' : 'none';
        document.getElementById('textSec').style.display = (type==='text') ? 'block' : 'none';
    }
    // Toast auto-hide
    document.addEventListener('DOMContentLoaded', function() {
        var toast = document.getElementById('toastMsg');
        if (toast) {
            setTimeout(function() { toast.style.display = 'none'; }, 3000);
        }
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('quest_file');
    const fileInfo = document.getElementById('fileInfo');
    const fileError = document.getElementById('fileError');
    const progressBar = document.getElementById('progressBar');
    const progressBarInner = document.getElementById('progressBarInner');
    const clearFileBtn = document.getElementById('clearFileBtn');
        const allowedTypes = [
            'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg', 'image/png', 'text/plain', 'application/zip'
        ];
        const allowedExts = ['.pdf','.doc','.docx','.jpg','.jpeg','.png','.txt','.zip'];
        const maxSize = 5 * 1024 * 1024;
    if (dropzone && fileInput && fileInfo) {
            dropzone.addEventListener('dragover', function(e) {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });
            dropzone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                dropzone.classList.remove('dragover');
            });
            dropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropzone.classList.remove('dragover');
                if (e.dataTransfer.files && e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    validateFiles();
                }
            });
            fileInput.addEventListener('change', function() {
                validateFiles();
                if (fileInput.files.length) {
                    clearFileBtn.style.display = 'inline-block';
                } else {
                    clearFileBtn.style.display = 'none';
                }
            });
            // Unselect/Clear file button logic
            if (clearFileBtn) {
                clearFileBtn.addEventListener('click', function() {
                    fileInput.value = '';
                    fileInfo.textContent = '';
                    fileError.style.display = 'none';
                    clearFileBtn.style.display = 'none';
                });
            }
            function getFileIcon(ext) {
                switch(ext) {
                    case 'pdf': return '<i class="fas fa-file-pdf" style="color:#b91c1c;"></i>';
                    case 'doc': case 'docx': return '<i class="fas fa-file-word" style="color:#2563eb;"></i>';
                    case 'jpg': case 'jpeg': case 'png': return '<i class="fas fa-file-image" style="color:#059669;"></i>';
                    case 'txt': return '<i class="fas fa-file-alt" style="color:#f59e42;"></i>';
                    case 'zip': return '<i class="fas fa-file-archive" style="color:#6366f1;"></i>';
                    default: return '<i class="fas fa-file" style="color:#64748b;"></i>';
                }
            }
            function validateFiles() {
                fileInfo.textContent = '';
                fileError.style.display = 'none';
                if (!fileInput.files.length) return;
                var file = fileInput.files[0];
                var ext = file.name.split('.').pop().toLowerCase();
                var icon = getFileIcon(ext);
                var info = icon + ' ' + file.name;
                fileInfo.innerHTML = info;
                if (file.size > maxSize) {
                    fileError.textContent = 'File too large (max 5MB).';
                    fileError.style.display = 'block';
                }
            }
        }
    });
    </script>
</body>
</html>
