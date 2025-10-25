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


$error = '';
$success = '';
// Accept quest_id from GET or POST so form POSTs still include the id
$quest_id = 0;
if (isset($_GET['quest_id'])) {
    $quest_id = (int)$_GET['quest_id'];
} elseif (isset($_POST['quest_id'])) {
    $quest_id = (int)$_POST['quest_id'];
}
$employee_id = $_SESSION['employee_id'] ?? '';

// Fetch quest info (assume $pdo is available from config.php)
if ($quest_id) {
    $stmt = $pdo->prepare("SELECT * FROM quests WHERE id = ?");
    $stmt->execute([$quest_id]);
    $quest = $stmt->fetch(PDO::FETCH_ASSOC);
    // Fetch skills/tiers
    $quest['skills'] = [];
    $stmt2 = $pdo->prepare("SELECT cs.skill_name FROM quest_skills qs JOIN comprehensive_skills cs ON qs.skill_id = cs.id WHERE qs.quest_id = ?");
    $stmt2->execute([$quest_id]);
    $quest['skills'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} else {
    $error = 'Invalid quest.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $quest_id && $employee_id) {
    $submission_type = $_POST['submission_type'] ?? '';
    $comments = trim($_POST['comments'] ?? '');
    $valid = true;
    $file_path = '';
    $drive_link = '';
    $text = '';
    $allowed_exts = ['pdf','doc','docx','jpg','jpeg','png','txt','zip'];
    $max_size = 5 * 1024 * 1024;

    // Deadline check: block submission if past due_date
    if (!empty($quest['due_date'])) {
        $now = date('Y-m-d H:i:s');
        if ($now > $quest['due_date']) {
            $error = 'You cannot submit for this quest. The deadline has passed.';
            $valid = false;
        }
    }

    // Helper: convert php.ini size like "8M" to bytes
    $phpSizeToBytes = function($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $num = (int)$val;
        switch($last) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    };

    if ($submission_type === 'file') {
        // if $_FILES not set or no file, check whether POST size exceeded server limits
        if (!isset($_FILES['quest_file'])) {
            $postMax = $phpSizeToBytes(ini_get('post_max_size'));
            $uploadMax = $phpSizeToBytes(ini_get('upload_max_filesize'));
            $contentLen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
            if ($contentLen > 0 && ($contentLen > $postMax || $contentLen > $uploadMax)) {
                $error = 'Uploaded file exceeds server limits (post_max_size or upload_max_filesize). Try a smaller file.';
            } else {
                $error = 'Please upload a file.';
            }
            $valid = false;
        } else {
            $file = $_FILES['quest_file'];
            // Provide clearer messages for common PHP upload errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                switch ($file['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error = 'File too large. Maximum allowed size is 5MB or server limit.';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error = 'File upload was incomplete. Please try again.';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $error = 'No file was uploaded.';
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $error = 'Server misconfiguration: missing temporary folder.';
                        break;
                    case UPLOAD_ERR_CANT_WRITE:
                        $error = 'Server error: failed to write file to disk.';
                        break;
                    case UPLOAD_ERR_EXTENSION:
                        $error = 'File upload blocked by a PHP extension.';
                        break;
                    default:
                        $error = 'Unknown upload error. Error code: ' . (int)$file['error'];
                }
                $valid = false;
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_exts)) {
                    $error = 'Invalid file type.';
                    $valid = false;
                } elseif ($file['size'] > $max_size) {
                    $error = 'File too large (max 5MB).';
                    $valid = false;
                } else {
                    $upload_dir = __DIR__ . '/uploads/quest_submissions/';
                    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
                    $new_name = $employee_id . '_' . time() . '.' . $ext;
                    $dest = $upload_dir . $new_name;
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        $file_path = 'uploads/quest_submissions/' . $new_name;
                    } else {
                        $error = 'Failed to move uploaded file. Check server permissions.';
                        $valid = false;
                    }
                }
            }
        }
    } elseif ($submission_type === 'link') {
        $drive_link = trim($_POST['drive_link'] ?? '');
        if (empty($drive_link) || !filter_var($drive_link, FILTER_VALIDATE_URL)) {
            $error = 'Please enter a valid URL.';
            $valid = false;
        }
    } elseif ($submission_type === 'text') {
        $text = trim($_POST['text_content'] ?? '');
        if (empty($text)) {
            $error = 'Please enter your text submission.';
            $valid = false;
        }
    } else {
        $error = 'Invalid submission type.';
        $valid = false;
    }

    if ($valid) {
        // Build a resilient INSERT that only uses columns that exist in the current DB schema
        $questSubmissionColumns = [];
        try {
            $schemaStmt = $pdo->query("SHOW COLUMNS FROM quest_submissions");
            $cols = $schemaStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) { $questSubmissionColumns[] = $c['Field']; }
        } catch (PDOException $schemaEx) {
            // If schema inspection fails, fallback to a conservative set of columns
            error_log('Unable to inspect quest_submissions schema: ' . $schemaEx->getMessage());
            $questSubmissionColumns = ['employee_id','quest_id','file_path','drive_link','text_content','status','submitted_at'];
        }

        $insertColumns = [];
        $placeholders = [];
        $params = [];

        // employee_id / quest_id
        if (in_array('employee_id', $questSubmissionColumns, true)) {
            $insertColumns[] = 'employee_id'; $placeholders[] = '?'; $params[] = $employee_id;
        }
        if (in_array('quest_id', $questSubmissionColumns, true)) {
            $insertColumns[] = 'quest_id'; $placeholders[] = '?'; $params[] = $quest_id;
        }

        // submission_type (if present)
        if (in_array('submission_type', $questSubmissionColumns, true)) {
            $insertColumns[] = 'submission_type'; $placeholders[] = '?'; $params[] = $submission_type;
        }

        // Content column depending on submission type
        $hasContentColumn = false;
        if ($submission_type === 'file' && in_array('file_path', $questSubmissionColumns, true)) {
            $insertColumns[] = 'file_path'; $placeholders[] = '?'; $params[] = $file_path; $hasContentColumn = true;
        } elseif ($submission_type === 'link' && in_array('drive_link', $questSubmissionColumns, true)) {
            $insertColumns[] = 'drive_link'; $placeholders[] = '?'; $params[] = $drive_link; $hasContentColumn = true;
        } elseif ($submission_type === 'text' && in_array('text_content', $questSubmissionColumns, true)) {
            $insertColumns[] = 'text_content'; $placeholders[] = '?'; $params[] = $text; $hasContentColumn = true;
        }

        if (!$hasContentColumn) {
            $error = 'Server not configured to store this type of submission. Please contact an administrator.';
            $valid = false;
        }

        // Optional comments
        if ($valid && in_array('comments', $questSubmissionColumns, true)) {
            $insertColumns[] = 'comments'; $placeholders[] = '?'; $params[] = $comments;
        }

        // status
        if ($valid && in_array('status', $questSubmissionColumns, true)) {
            $insertColumns[] = 'status';
            // use placeholder for status value
            $placeholders[] = '?'; $params[] = 'submitted';
        }

        // submitted_at: use NOW() if available (don't add a parameter)
        if ($valid && in_array('submitted_at', $questSubmissionColumns, true)) {
            $insertColumns[] = 'submitted_at'; $placeholders[] = 'NOW()';
        }

        if ($valid) {
            $insertSql = sprintf(
                "INSERT INTO quest_submissions (%s) VALUES (%s)",
                implode(', ', $insertColumns),
                implode(', ', $placeholders)
            );
            try {
                $stmt = $pdo->prepare($insertSql);
                $stmt->execute($params);
            } catch (PDOException $insertEx) {
                error_log('Quest submission insert failed: ' . $insertEx->getMessage());
                $error = 'Failed to record submission. Please try again or contact support.';
                $valid = false;
            }
        }

        if ($valid) {
            // Update user_quests status to 'submitted'
            try {
                $stmt2 = $pdo->prepare("UPDATE user_quests SET status = 'submitted' WHERE quest_id = ? AND employee_id = ?");
                $stmt2->execute([$quest_id, $employee_id]);
            } catch (PDOException $uEx) {
                error_log('Failed to update user_quests for submission: ' . $uEx->getMessage());
            }
            $success = 'Submission successful!';
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
    .submission-container { max-width: 700px; margin: 48px auto; padding: 0 16px; }
    .file-type-pdf { color: #b91c1c; font-weight: 600; }
    .file-type-doc, .file-type-docx { color: #2563eb; font-weight: 600; }
    .file-type-jpg, .file-type-jpeg, .file-type-png { color: #059669; font-weight: 600; }
    .file-type-txt { color: #f59e42; font-weight: 600; }
    .file-type-zip { color: #6366f1; font-weight: 600; }
        .submission-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px #e0e7ef33; padding: 32px 32px 28px 32px; margin-bottom: 32px; border: 1px solid #e5e7eb; }
        /* Make file input cover the dropzone and be transparent */
        #dropzone { position: relative; }
        #quest_file {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0; cursor: pointer; z-index: 2;
            display: block !important;
        }
        .quest-header { display: flex; flex-direction: column; gap: 8px; margin-bottom: 18px; }
        .quest-title { font-size: 1.5rem; font-weight: 700; color: var(--primary-color, #3730a3); }
        .quest-desc { color: #374151; font-size: 1.08em; margin-bottom: 4px; }
        .meta { color: #64748b; font-size: 1em; margin-bottom: 0; }
        .skills-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 0; }
        .skill-badge { background: #f0fdf4; color: #166534; border: 1px solid #34d399; border-radius: 8px; padding: 4px 10px; font-size: 0.98em; font-weight: 500; }
        .tier-badge { background: #e0e7ff; color: #3730a3; border: 1px solid #6366f1; border-radius: 8px; padding: 4px 10px; font-size: 0.98em; font-weight: 500; }
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
                <div class="quest-title"><?php echo htmlspecialchars($quest['title'] ?? 'Submit Quest'); ?></div>
                <div class="quest-desc"><?php echo nl2br(htmlspecialchars($quest['description'] ?? '')); ?></div>
                <?php if (!empty($quest['due_date'])): ?>
                <div class="meta">Due: <?php echo !empty($quest['due_date']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($quest['due_date']))) : '—'; ?></div>
                <?php endif; ?>
                <?php if (!empty($quest['skills'])): ?>
                <div class="skills-list">
                    <?php foreach ($quest['skills'] as $skill): ?>
                        <span class="skill-badge"><?php echo htmlspecialchars($skill['skill_name']); ?></span>
                        <?php if (!empty($skill['tier'])): ?><span class="tier-badge">Tier: <?php echo htmlspecialchars($skill['tier']); ?></span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($error)) { ?>
                <div class="toast toast-error" id="toastMsg"><?php echo htmlspecialchars($error); ?></div>
            <?php } elseif (!empty($success)) { ?>
                <div class="success-overlay" id="successOverlay">
                    <div class="success-card modern-success-card">
                        <div class="success-icon" aria-label="Success" style="background:#22c55e1a; border-radius:50%; width:64px; height:64px; display:flex; align-items:center; justify-content:center; margin:0 auto 18px auto;">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 10.8 17 4 11.2"></polyline></svg>
                        </div>
                        <div class="success-title" style="font-size:1.5rem;font-weight:700;color:#22c55e;margin-bottom:10px;">Submission Successful</div>
                        <div class="success-message" style="color:#374151;font-size:1.08em;margin-bottom:18px;">Your quest has been submitted and is now awaiting review.<br>You can view or edit your submission from your quest list.</div>
                        <a href="my_quests.php" class="btn btn-primary" style="margin-top:8px; background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 8px; padding: 12px 32px; font-size: 1.1em; font-weight: 600; box-shadow: 0 2px 8px #6366f133; transition: background 0.2s, box-shadow 0.2s;">Back to My Quests</a>
                    </div>
                </div>
                <style>
                .success-overlay {
                    position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
                    background: rgba(60,64,90,0.10); z-index: 99999; display: flex; align-items: center; justify-content: center;
                }
                .modern-success-card {
                    background: #fff; border-radius: 20px; box-shadow: 0 8px 32px #22c55e22;
                    padding: 40px 32px 32px 32px; text-align: center; min-width: 320px; max-width: 90vw;
                    border: none;
                }
                @media (max-width: 600px) {
                    .modern-success-card { padding: 24px 4vw 18px 4vw; min-width: 0; }
                }
                </style>
            <?php }
            if (empty($success)) { ?>
            <form method="post" enctype="multipart/form-data" id="submissionForm">
                <input type="hidden" name="quest_id" value="<?php echo htmlspecialchars($quest_id); ?>">
                <div class="form-row form-radio-group modern-radio-group">
                    <label class="modern-radio"><input type="radio" name="submission_type" value="file" checked onclick="toggleSections('file')"><span>File</span></label>
                    <label class="modern-radio"><input type="radio" name="submission_type" value="link" onclick="toggleSections('link')"><span>Link/Google Drive</span></label>
                    <label class="modern-radio"><input type="radio" name="submission_type" value="text" onclick="toggleSections('text')"><span>Text</span></label>
                </div>
                <div id="fileSec" class="form-row">
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
                <div id="linkSec" class="form-row" style="display:none;">
                    <label class="form-label" for="drive_link">Drive/URL</label>
                    <input class="form-input" type="text" id="drive_link" name="drive_link" placeholder="https://...">
                </div>
                <div id="textSec" class="form-row" style="display:none;">
                    <label class="form-label" for="text_content">Text Content</label>
                    <textarea class="form-textarea" id="text_content" name="text_content" rows="6"></textarea>
                </div>
                <div class="form-row">
                    <label class="form-label" for="comments">Comments (optional)</label>
                    <textarea class="form-textarea" id="comments" name="comments" rows="3" placeholder="Add any comments or notes for your submission..."></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top: 18px;">
                    <button type="submit" name="submit_quest" class="btn btn-primary" id="submitBtn" style="background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 8px; padding: 12px 32px; font-size: 1.1em; font-weight: 600; box-shadow: 0 2px 8px #6366f133; transition: background 0.2s, box-shadow 0.2s; cursor: pointer;">Submit Quest</button>
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
                    default: return '<i class="fas fa-file" style="color:#6366f1;"></i>';
                }
            }
            function validateFiles() {
                fileError.style.display = 'none';
                fileInfo.innerHTML = '';
                if (!fileInput.files.length) {
                    clearFileBtn.style.display = 'none';
                    return;
                }
                let errors = [];
                for (let i = 0; i < fileInput.files.length; i++) {
                    const f = fileInput.files[i];
                    const ext = f.name.substring(f.name.lastIndexOf('.')+1).toLowerCase();
                    if (!allowedTypes.includes(f.type) && !allowedExts.includes('.'+ext)) {
                        errors.push(`${f.name}: Unsupported file type.`);
                    } else if (f.size > maxSize) {
                        errors.push(`${f.name}: File too large (max 5MB).`);
                    } else {
                        let cls = '';
                        if (ext === 'pdf') cls = 'file-type-pdf';
                        else if (ext === 'doc' || ext === 'docx') cls = 'file-type-doc';
                        else if (ext === 'jpg' || ext === 'jpeg' || ext === 'png') cls = 'file-type-jpg';
                        else if (ext === 'txt') cls = 'file-type-txt';
                        else if (ext === 'zip') cls = 'file-type-zip';
                        fileInfo.innerHTML += `<span class="${cls}" style="margin-right:10px;">${getFileIcon(ext)} ${f.name}</span>`;
                    }
                }
                if (errors.length) {
                    fileError.innerHTML = errors.join('<br>');
                    fileError.style.display = 'block';
                    fileInput.value = '';
                    fileInfo.innerHTML = '';
                    clearFileBtn.style.display = 'none';
                } else {
                    fileError.style.display = 'none';
                    clearFileBtn.style.display = 'inline-block';
                }
            }
        }
        // Optional: fake progress bar on submit for realism
        const form = document.getElementById('submissionForm');
        if (form && progressBar && progressBarInner && fileInput) {
            form.addEventListener('submit', function(e) {
                if (fileInput.files && fileInput.files.length) {
                    progressBar.style.display = 'block';
                    progressBarInner.style.width = '0%';
                    let progress = 0;
                    const interval = setInterval(function() {
                        progress += Math.random() * 20 + 10;
                        if (progress >= 100) {
                            progressBarInner.style.width = '100%';
                            clearInterval(interval);
                        } else {
                            progressBarInner.style.width = progress + '%';
                        }
                    }, 120);
                }
            });
        }
    });
    </script>
    <style>
        .modern-radio-group {
            display: flex;
            gap: 24px;
            margin-bottom: 18px;
        }
        .modern-radio {
            position: relative;
            display: flex;
            align-items: center;
            font-size: 1.08em;
            font-weight: 600;
            color: #6366f1;
            cursor: pointer;
            padding: 4px 12px;
            border-radius: 8px;
            transition: background 0.2s, color 0.2s;
        }
        .modern-radio input[type="radio"] {
            accent-color: #6366f1;
            margin-right: 8px;
            width: 18px;
            height: 18px;
        }
        .modern-radio input[type="radio"]:focus + span {
            outline: 2px solid #6366f1;
            outline-offset: 2px;
        }
        .modern-radio input[type="radio"]:checked + span,
        .modern-radio:hover span {
            color: #fff;
            background: #6366f1;
            border-radius: 6px;
            padding: 2px 10px;
        }
        .error-message {
            color: #ef4444;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 8px 14px;
            margin-top: 8px;
            font-size: 1em;
            font-weight: 500;
            display: block;
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .submission-container { max-width: 700px; margin: 48px auto; padding: 0 16px; }
        .submission-card { background: #fff; border-radius: 18px; box-shadow: 0 6px 32px #6366f11a; padding: 36px 36px 30px 36px; margin-bottom: 32px; border: 1px solid #e5e7eb; }
        .quest-header { display: flex; flex-direction: column; gap: 10px; margin-bottom: 22px; }
        .quest-title { font-size: 2rem; font-weight: 800; color: #6366f1; letter-spacing: -1px; }
        .quest-desc { color: #374151; font-size: 1.13em; margin-bottom: 4px; }
        .meta { color: #64748b; font-size: 1em; margin-bottom: 0; }
        .skills-list { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 0; }
        .skill-badge { background: #f0fdf4; color: #166534; border: 1px solid #34d399; border-radius: 8px; padding: 4px 12px; font-size: 1em; font-weight: 600; }
        .tier-badge { background: #e0e7ff; color: #3730a3; border: 1px solid #6366f1; border-radius: 8px; padding: 4px 12px; font-size: 1em; font-weight: 600; }
        .back-btn { display: inline-block; margin-bottom: 20px; background: #f3f4f6; color: #6366f1; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 22px; font-weight: 700; text-decoration: none; transition: background 0.2s, color 0.2s; font-size: 1.05em; }
        .back-btn:hover { background: #e0e7ff; color: #3730a3; }
        .form-row { margin-bottom: 24px; }
        .form-label { font-weight: 700; color: #374151; margin-bottom: 8px; display: block; font-size: 1.05em; }
        .form-input, .form-textarea { width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; font-size: 1.05em; color: #374151; background: #f9fafb; transition: border 0.2s; }
        .form-input:focus, .form-textarea:focus { border: 1.5px solid #6366f1; outline: none; background: #fff; }
        .dropzone { border: 2px dashed #6366f1; border-radius: 10px; padding: 28px 0; text-align: center; color: #6366f1; background: #f8fafc; font-size: 1.08em; cursor: pointer; margin-bottom: 8px; transition: border 0.2s, background 0.2s; }
        .dropzone.dragover { background: #e0e7ff; border-color: #4f46e5; }
        .file-info { color: #374151; font-size: 1em; margin-bottom: 4px; }
        .progress-bar { width: 100%; height: 8px; background: #e5e7eb; border-radius: 6px; margin: 12px 0 0 0; overflow: hidden; }
        .progress-bar-inner { height: 100%; background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); width: 0%; transition: width 0.3s; }
        .btn-primary, .btn.btn-primary {
            background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px 32px;
            font-size: 1.1em;
            font-weight: 600;
            box-shadow: 0 2px 8px #6366f133;
            transition: background 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .btn-primary:hover, .btn.btn-primary:hover {
            background: linear-gradient(90deg, #4f46e5 0%, #6366f1 100%);
            box-shadow: 0 4px 16px #6366f133;
        }
        .toast {
            position: fixed;
            top: 32px;
            left: 50%;
            transform: translateX(-50%);
            min-width: 280px;
            max-width: 90vw;
            z-index: 9999;
            padding: 18px 32px;
            border-radius: 12px;
            font-size: 1.1em;
            font-weight: 600;
            box-shadow: 0 4px 24px #6366f133;
            opacity: 0.98;
            animation: fadeIn 0.5s, fadeOut 0.5s 2.5s forwards;
            text-align: center;
        }
        .toast-success {
            background: #f0fdf4;
            color: #166534;
            border: 1.5px solid #34d399;
        }
        .toast-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1.5px solid #fca5a5;
        }
        @keyframes fadeIn {
            from { opacity: 0; top: 0; }
            to { opacity: 0.98; top: 32px; }
        }
        @keyframes fadeOut {
            to { opacity: 0; top: 0; pointer-events: none; }
        }
    </style>
    </body>
    </html>