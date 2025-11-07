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

    // Load the related quest so we can display the same UI as submit_quest.php
    if (empty($error) && $submission && !empty($submission['quest_id'])) {
        try {
            $qstmt = $pdo->prepare("SELECT * FROM quests WHERE id = ?");
            $qstmt->execute([(int)$submission['quest_id']]);
            $quest = $qstmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($quest) {
                $quest['skills'] = [];
                $sstmt = $pdo->prepare("SELECT cs.skill_name FROM quest_skills qs JOIN comprehensive_skills cs ON qs.skill_id = cs.id WHERE qs.quest_id = ?");
                $sstmt->execute([(int)$submission['quest_id']]);
                $quest['skills'] = $sstmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log('edit_submission: failed to load quest: ' . $e->getMessage());
        }
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

// Determine current submission type for UI toggles (avoid undefined variable notices)
$curType = 'file';
if (!empty($submission) && is_array($submission)) {
    if (!empty($submission['drive_link'])) {
        $curType = 'link';
    } elseif (!empty($submission['text_content']) || !empty($submission['submission_text'])) {
        $curType = 'text';
    } elseif (!empty($submission['file_path'])) {
        $curType = 'file';
    } else {
        // default when nothing present
        $curType = 'file';
    }
}
?>
<?php
// Handle form POST to update an existing submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($submission) && is_array($submission)) {
    $employee_id = $_SESSION['employee_id'] ?? '';
    // ownership check
    if (!empty($submission['employee_id']) && $employee_id && ((string)$submission['employee_id'] !== (string)$employee_id)) {
        $error = 'You do not have permission to edit this submission.';
    } else {
    $submission_type = $_POST['submission_type'] ?? '';
    $commentsPost = trim($_POST['comments'] ?? '');
    $drive_link = trim($_POST['drive_link'] ?? '');
    $text_content = trim($_POST['text_content'] ?? ($_POST['text'] ?? ''));
    // client_support specific fields (will be set only when applicable)
    $ticket_reference = '';
    $time_spent_hours = null;
    $evidence_json = '';
    $resolution_status = '';
    $follow_up_required = 0;
    $newSupportFilePath = '';
        $allowed_exts = ['pdf','doc','docx','jpg','jpeg','png','txt','zip'];
        $max_size = 5 * 1024 * 1024;
        $valid = true;
        $newFilePath = '';

        // only allow edit if not graded/approved/rejected
        $st = strtolower(trim($submission['status'] ?? 'pending'));
        if (in_array($st, ['approved','rejected','graded','final'], true)) {
            $error = 'This submission cannot be edited because it has already been finalized.';
            $valid = false;
        }

        // If this quest is a Client & Support Operation, collect its custom inputs
        if ($valid && isset($quest['display_type']) && $quest['display_type'] === 'client_support') {
            // force text submission type for client_support edits
            $submission_type = 'text';
            $ticket_reference = trim($_POST['ticket_id'] ?? ($_POST['ticket_reference'] ?? ''));
            $text_content = trim($_POST['action_taken'] ?? $text_content);
            $time_spent_hours = isset($_POST['time_spent']) && $_POST['time_spent'] !== '' ? (float)$_POST['time_spent'] : null;
            $evidence = $_POST['evidence'] ?? [];
            if (!empty($evidence) && is_array($evidence)) { $evidence_json = json_encode(array_values($evidence)); }
            $resolution_status = trim($_POST['resolution_status'] ?? '');
            $follow_up_required = isset($_POST['follow_up_required']) ? 1 : 0;

            // Validate required action_taken
            if (empty($text_content)) {
                $error = 'Please describe the action taken to resolve the client request or complete the task.';
                $valid = false;
            }

            // Support file upload handling (optional)
            if ($valid && isset($_FILES['support_file']) && isset($_FILES['support_file']['error'])) {
                $supp = $_FILES['support_file'];
                if ($supp['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($supp['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['pdf','doc','docx','jpg','jpeg','png','txt','zip'], true)) {
                        $error = 'Invalid support file type.'; $valid = false;
                    } elseif ($supp['size'] > $max_size) {
                        $error = 'Support file too large (max 5MB).'; $valid = false;
                    } else {
                        $upload_dir = __DIR__ . '/uploads/quest_submissions/';
                        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
                        $new_name = ($employee_id ?: 'u') . '_support_' . time() . '.' . $ext;
                        $dest = $upload_dir . $new_name;
                        if (move_uploaded_file($supp['tmp_name'], $dest)) {
                            $newSupportFilePath = 'uploads/quest_submissions/' . $new_name;
                            $supportOriginalName = $supp['name'];
                        } else {
                            $error = 'Failed to move support file. Check server permissions.'; $valid = false;
                        }
                    }
                } elseif ($supp['error'] !== UPLOAD_ERR_NO_FILE) {
                    $error = 'Support file upload error (code ' . (int)$supp['error'] . ').'; $valid = false;
                }
            }
        }

        // File handling if file chosen
        if ($valid && $submission_type === 'file') {
            if (!empty($_FILES['quest_file']) && $_FILES['quest_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['quest_file'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $error = 'File upload error: ' . (int)$file['error'];
                    $valid = false;
                } else {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_exts, true)) { $error = 'Invalid file type.'; $valid = false; }
                    elseif ($file['size'] > $max_size) { $error = 'File too large (max 5MB).'; $valid = false; }
                    else {
                        $upload_dir = __DIR__ . '/uploads/quest_submissions/';
                        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
                        $new_name = ($employee_id ?: 'u') . '_' . time() . '.' . $ext;
                        $dest = $upload_dir . $new_name;
                        if (move_uploaded_file($file['tmp_name'], $dest)) {
                            $newFilePath = 'uploads/quest_submissions/' . $new_name;
                        } else { $error = 'Failed to move uploaded file.'; $valid = false; }
                    }
                }

                    // Enforce single content type for 'custom' quests: only one of file / link / text may be provided
                    $isCustomQuest = (isset($quest['quest_type']) && $quest['quest_type'] === 'custom');
                    if ($isCustomQuest) {
                        $hasFileInput = isset($_FILES['quest_file']) && (!isset($_FILES['quest_file']['error']) || $_FILES['quest_file']['error'] !== UPLOAD_ERR_NO_FILE);
                        $hasLinkInput = !empty(trim($_POST['drive_link'] ?? ''));
                        $hasTextInput = !empty(trim($_POST['text_content'] ?? ''));
                        $providedCount = 0;
                        if ($hasFileInput) $providedCount++;
                        if ($hasLinkInput) $providedCount++;
                        if ($hasTextInput) $providedCount++;
                        if ($providedCount > 1) {
                            $error = 'For custom quests you may only submit one content type (file OR link OR text). Please provide only the selected type.';
                            $valid = false;
                        }
                    }
            }
        }

        // validate link
        // allow clients to request removal of existing file via hidden flag
        $removeFileFlag = !empty($_POST['remove_file']) && $_POST['remove_file'] === '1';

        if ($valid && $submission_type === 'link') {
            if (empty($drive_link) || !filter_var($drive_link, FILTER_VALIDATE_URL)) { $error = 'Please enter a valid URL.'; $valid = false; }
        }

        // validate text
        if ($valid && $submission_type === 'text') {
            if (empty($text_content)) { $error = 'Please enter your text submission.'; $valid = false; }
        }

        // Build UPDATE using $qsCols (columns inspected earlier)
        if ($valid) {
            $params = [];
            $updateParts = [];

            if (in_array('submission_type', $qsCols, true)) { $updateParts[] = 'submission_type = ?'; $params[] = $submission_type; }
            // File path (try common variants)
            if ($submission_type === 'file' && $newFilePath !== '') {
                if (in_array('file_path', $qsCols, true)) { $updateParts[] = 'file_path = ?'; $params[] = $newFilePath; }
                elseif (in_array('filepath', $qsCols, true)) { $updateParts[] = 'filepath = ?'; $params[] = $newFilePath; }
                // also update original filename/display name columns if present
                $origCols = ['file_name','original_name','original_filename','file_original_name','original_file_name'];
                $origName = $_FILES['quest_file']['name'] ?? basename($newFilePath);
                foreach ($origCols as $oc) {
                    if (in_array($oc, $qsCols, true)) { $updateParts[] = $oc . ' = ?'; $params[] = $origName; break; }
                }
            }
            // If a support file was uploaded for client_support edits, persist it too
            if (!empty($newSupportFilePath)) {
                // prefer same set of columns as general file handling
                if (in_array('file_path', $qsCols, true)) { $updateParts[] = 'file_path = ?'; $params[] = $newSupportFilePath; }
                elseif (in_array('support_file', $qsCols, true)) { $updateParts[] = 'support_file = ?'; $params[] = $newSupportFilePath; }
                elseif (in_array('supporting_file', $qsCols, true)) { $updateParts[] = 'supporting_file = ?'; $params[] = $newSupportFilePath; }
                // store original uploaded name into any available original filename column
                $supportOrig = $supportOriginalName ?? basename($newSupportFilePath);
                foreach (['file_name','support_original_name','original_name','original_filename'] as $oc) {
                    if (in_array($oc, $qsCols, true)) { $updateParts[] = $oc . ' = ?'; $params[] = $supportOrig; break; }
                }
            }
            // If user requested to remove the file without uploading a new one, clear file_path and original name
            if ($submission_type === 'file' && $newFilePath === '' && !empty($removeFileFlag)) {
                if (in_array('file_path', $qsCols, true)) { $updateParts[] = 'file_path = ?'; $params[] = ''; }
                elseif (in_array('filepath', $qsCols, true)) { $updateParts[] = 'filepath = ?'; $params[] = ''; }
                foreach (['file_name','original_name','original_filename','file_original_name','original_file_name'] as $oc) {
                    if (in_array($oc, $qsCols, true)) { $updateParts[] = $oc . ' = ?'; $params[] = ''; break; }
                }
                // remove stored file from disk (best-effort)
                if (!empty($submission['file_path'])) { $oldp = __DIR__ . '/' . $submission['file_path']; if (is_file($oldp)) { @unlink($oldp); } }
            }
            // If switching away from file, clear any previous file path so view shows the new type
            if ($submission_type !== 'file') {
                if (in_array('file_path', $qsCols, true)) { $updateParts[] = 'file_path = ?'; $params[] = ''; }
                elseif (in_array('filepath', $qsCols, true)) { $updateParts[] = 'filepath = ?'; $params[] = ''; }
                // Also clear any stored original filename/display name columns so UI doesn't still show an old filename
                foreach (['file_name','original_name','original_filename','file_original_name','original_file_name'] as $oc) {
                    if (in_array($oc, $qsCols, true)) { $updateParts[] = $oc . ' = ?'; $params[] = ''; }
                }
            }
            // Drive/link
            if ($submission_type === 'link') {
                if (in_array('drive_link', $qsCols, true)) { $updateParts[] = 'drive_link = ?'; $params[] = $drive_link; }
                elseif (in_array('link', $qsCols, true)) { $updateParts[] = 'link = ?'; $params[] = $drive_link; }
            }
            // If switching away from link, clear any previous drive/link value
            if ($submission_type !== 'link') {
                if (in_array('drive_link', $qsCols, true)) { $updateParts[] = 'drive_link = ?'; $params[] = ''; }
                elseif (in_array('link', $qsCols, true)) { $updateParts[] = 'link = ?'; $params[] = ''; }
            }
            // Text content - try both possible column names
            if ($submission_type === 'text') {
                if (in_array('text_content', $qsCols, true)) { $updateParts[] = 'text_content = ?'; $params[] = $text_content; }
                elseif (in_array('submission_text', $qsCols, true)) { $updateParts[] = 'submission_text = ?'; $params[] = $text_content; }
            }
            // Client & Support specific fields (ensure these are saved for client_support quests)
            if (isset($quest['display_type']) && $quest['display_type'] === 'client_support') {
                // Ticket / reference
                if (in_array('ticket_reference', $qsCols, true)) { $updateParts[] = 'ticket_reference = ?'; $params[] = $ticket_reference; }
                elseif (in_array('ticket_id', $qsCols, true)) { $updateParts[] = 'ticket_id = ?'; $params[] = $ticket_reference; }
                elseif (in_array('ticket', $qsCols, true)) { $updateParts[] = 'ticket = ?'; $params[] = $ticket_reference; }

                // Action taken - prefer explicit column name if present
                if (in_array('action_taken', $qsCols, true)) { $updateParts[] = 'action_taken = ?'; $params[] = $text_content; }
                // time spent (hours)
                foreach (['time_spent_hours','time_spent','time_spent_hrs'] as $tc) {
                    if (in_array($tc, $qsCols, true)) { $updateParts[] = $tc . ' = ?'; $params[] = ($time_spent_hours === null ? '' : $time_spent_hours); break; }
                }
                // Evidence (JSON or list)
                if (in_array('evidence_json', $qsCols, true)) { $updateParts[] = 'evidence_json = ?'; $params[] = $evidence_json; }
                elseif (in_array('evidence', $qsCols, true)) { $updateParts[] = 'evidence = ?'; $params[] = $evidence_json; }
                elseif (in_array('evidence_list', $qsCols, true)) { $updateParts[] = 'evidence_list = ?'; $params[] = $evidence_json; }

                // Resolution outcome
                if (in_array('resolution_status', $qsCols, true)) { $updateParts[] = 'resolution_status = ?'; $params[] = $resolution_status; }
                // Follow-up required
                if (in_array('follow_up_required', $qsCols, true)) { $updateParts[] = 'follow_up_required = ?'; $params[] = $follow_up_required; }
                elseif (in_array('follow_up', $qsCols, true)) { $updateParts[] = 'follow_up = ?'; $params[] = $follow_up_required; }
            }
            // If switching away from text, clear any previous text fields
            if ($submission_type !== 'text') {
                if (in_array('text_content', $qsCols, true)) { $updateParts[] = 'text_content = ?'; $params[] = ''; }
                if (in_array('submission_text', $qsCols, true)) { $updateParts[] = 'submission_text = ?'; $params[] = ''; }
            }
            if (in_array('comments', $qsCols, true)) { $updateParts[] = 'comments = ?'; $params[] = $commentsPost; }
            if (in_array('status', $qsCols, true)) { $updateParts[] = 'status = ?'; $params[] = 'submitted'; }
            if (in_array('updated_at', $qsCols, true)) { $updateParts[] = 'updated_at = NOW()'; }
            elseif (in_array('submitted_at', $qsCols, true)) { $updateParts[] = 'submitted_at = NOW()'; }

            if (!empty($updateParts)) {
                $sql = 'UPDATE quest_submissions SET ' . implode(', ', $updateParts) . ' WHERE id = ? LIMIT 1';
                $params[] = $submission_id;
                try {
                    $ustmt = $pdo->prepare($sql);
                    $ustmt->execute($params);

                    // If file replaced, remove old file (best-effort)
                    if (!empty($newFilePath) && !empty($submission['file_path']) && $submission['file_path'] !== $newFilePath) {
                        $old = __DIR__ . '/' . $submission['file_path'];
                        if (is_file($old)) { @unlink($old); }
                    }

                    // refresh submission for UI
                    $rs = $pdo->prepare("SELECT * FROM quest_submissions WHERE id = ?");
                    $rs->execute([$submission_id]);
                    $submission = $rs->fetch(PDO::FETCH_ASSOC) ?: $submission;
                    $success = 'Submission updated successfully!';
                    // Broadcast update to other contexts (BroadcastChannel + localStorage)
                    // BroadcastChannel (modern browsers)
                    echo "<script>try{ if(window.BroadcastChannel){ var bc=new BroadcastChannel('yqs_updates'); bc.postMessage({type:'submission_updated', id:".((int)$submission_id)."}); }}catch(e){};</script>";
                } catch (PDOException $uex) {
                    error_log('edit_submission update failed: ' . $uex->getMessage());
                    $error = 'Failed to save changes. Please try again.';
                }
            } else {
                $error = 'Nothing to update.';
            }
        }
    }
}
?>
<!DOCTYPE html>
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
        /* Make file input cover the dropzone and be transparent */
        #dropzone { position: relative; }
        #quest_file {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0; cursor: pointer; z-index: 2;
            display: block !important;
        }
        /* Support dropzone input should also be invisible and cover the drop area
           This prevents the native centered "Choose file" button from appearing
           while keeping the input clickable across the whole dropzone. */
        #support_dropzone { position: relative; }
        #support_file {
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
                <div class="quest-title"><?php echo htmlspecialchars($quest['title'] ?? 'Edit Submission'); ?></div>
                <div class="quest-desc"><?php echo nl2br(htmlspecialchars($quest['description'] ?? ($submission ? 'Update your quest submission below. You can change the file, link, or text as needed.' : ''))); ?></div>
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
            <?php }
            if (!empty($success)) { ?>
                <div id="successOverlay" style="background:transparent;padding:12px 0;margin-bottom:12px;">
                    <div style="background:#ECFDF5;border:1px solid #BBF7D0;color:#065F46;padding:12px 16px;border-radius:10px;display:flex;align-items:center;gap:12px;">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#16A34A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <div style="flex:1;"><strong style="display:block;color:#065F46;">Submission Updated</strong><span style="display:block;color:#065F46;opacity:0.9;">Your quest submission has been updated and is now awaiting review.</span></div>
                        <a href="my_quests.php" class="btn btn-primary" style="background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 8px; padding: 8px 14px; font-weight:600;">Back to My Quests</a>
                    </div>
                </div>
                <script>
                // Notify other windows/tabs that a submission was updated so they can refresh
                (function(){
                    try { localStorage.setItem('yqs_submission_updated', JSON.stringify({ submission_id: <?php echo (int)$submission_id; ?>, ts: Date.now() })); } catch(e){}
                    try {
                        if (window.BroadcastChannel) {
                            var bc = new BroadcastChannel('yqs_updates'); bc.postMessage({type:'submission_updated', id:<?php echo (int)$submission_id; ?>});
                        }
                    } catch(e){}
                })();
                </script>
            <?php } ?>
            <form method="post" enctype="multipart/form-data" id="submissionForm">
                <input type="hidden" name="quest_id" value="<?php echo htmlspecialchars($submission['quest_id'] ?? ''); ?>">
                <input type="hidden" name="submission_id" value="<?php echo htmlspecialchars($submission_id); ?>">
                <?php if (isset($quest['display_type']) && $quest['display_type'] === 'client_support'): ?>
                <?php
                    // Prepare prefill values from $submission
                    $pref_ticket = htmlspecialchars($submission['ticket_reference'] ?? $submission['ticket_id'] ?? '');
                    $pref_action = htmlspecialchars($submission['action_taken'] ?? $submission['text_content'] ?? $submission['submission_text'] ?? '');
                    $pref_time = htmlspecialchars($submission['time_spent_hours'] ?? $submission['time_spent'] ?? '');
                    // load evidence list if stored as JSON in a few possible columns
                    $evidenceList = [];
                    foreach (['evidence','evidence_json','evidence_list'] as $ec) {
                        if (!empty($submission[$ec])) {
                            $decoded = json_decode($submission[$ec], true);
                            if (is_array($decoded)) { $evidenceList = $decoded; break; }
                        }
                    }
                    $pref_resolution = htmlspecialchars($submission['resolution_status'] ?? 'resolved');
                    $pref_followup = !empty($submission['follow_up_required']) && (int)$submission['follow_up_required'] === 1 ? true : false;
                    // support file path candidates
                    $supportPath = $submission['support_file'] ?? $submission['supporting_file'] ?? $submission['file_path'] ?? '';
                    $supportDisplay = '';
                    if (!empty($submission['support_original_name'])) { $supportDisplay = $submission['support_original_name']; }
                    else {
                        $cands = [$submission['file_name'] ?? null, $submission['original_name'] ?? null, $submission['original_filename'] ?? null];
                        foreach ($cands as $c) { if (!empty($c)) { $supportDisplay = $c; break; } }
                        if (empty($supportDisplay) && !empty($supportPath)) { $supportDisplay = basename($supportPath); }
                    }
                ?>
                <div class="form-row">
                    <label class="form-label" for="ticket_id">Ticket / Reference ID</label>
                    <input class="form-input" type="text" id="ticket_id" name="ticket_id" value="<?php echo $pref_ticket; ?>" placeholder="Optional ticket or reference">
                </div>

                <div class="form-row">
                    <label class="form-label" for="action_taken">Action Taken / Resolution (required)</label>
                    <textarea class="form-textarea" id="action_taken" name="action_taken" rows="6" placeholder="Describe the steps you took, findings, and the resolution. This will be used by reviewers."><?php echo $pref_action; ?></textarea>
                    <div class="meta" style="margin-top:8px;">Tip: include key timestamps, relevant commands/log snippets or ticket links, and any customer confirmation or screenshots to speed up review.</div>
                </div>

                <div class="form-row">
                    <label class="form-label" for="time_spent">Time Spent (hours)</label>
                    <input class="form-input" type="number" id="time_spent" name="time_spent" step="0.25" min="0" value="<?php echo $pref_time; ?>" placeholder="e.g. 1.5">
                </div>

                <div class="form-row">
                    <label class="form-label">Evidence / Attachments</label>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label style="font-weight:600;"><input type="checkbox" name="evidence[]" value="screenshot" <?php echo in_array('screenshot', $evidenceList, true) ? 'checked' : ''; ?>> Screenshot(s)</label>
                        <label style="font-weight:600;"><input type="checkbox" name="evidence[]" value="log" <?php echo in_array('log', $evidenceList, true) ? 'checked' : ''; ?>> Log file / system output</label>
                        <label style="font-weight:600;"><input type="checkbox" name="evidence[]" value="customer_confirmation" <?php echo in_array('customer_confirmation', $evidenceList, true) ? 'checked' : ''; ?>> Customer confirmation / email</label>
                        <label style="font-weight:600;"><input type="checkbox" name="evidence[]" value="ticket_update" <?php echo in_array('ticket_update', $evidenceList, true) ? 'checked' : ''; ?>> Ticket / system update</label>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Upload supporting file (optional)</label>
                    <div id="support_dropzone" class="dropzone" style="text-align:center;">
                        Drag & drop your file here or click to select
                        <input type="file" id="support_file" name="support_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.zip">
                    </div>
                    <div class="meta" style="margin-top:8px; text-align:center; font-size:0.95em; color:#6b7280;">PDF, DOC, DOCX, JPG, PNG, TXT, ZIP (Max 5MB)</div>
                    <button type="button" id="clearSupportFileBtn" class="btn btn-primary" style="margin-top:8px;<?php echo !empty($supportPath) ? 'display:inline-block;' : 'display:none;'; ?>">Unselect File</button>
                    <div class="error-message" id="supportFileError" style="display:none;"></div>
                    <div class="file-info" id="supportFileInfo" style="margin-top:8px;text-align:center;"><?php echo htmlspecialchars($supportDisplay); ?></div>
                    <div class="progress-bar" id="supportProgressBar" style="display:none;"><div class="progress-bar-inner" id="supportProgressBarInner"></div></div>
                </div>

                <div class="form-row">
                    <label class="form-label" for="resolution_status">Resolution Outcome</label>
                    <select name="resolution_status" id="resolution_status" class="form-input">
                        <option value="resolved" <?php echo $pref_resolution === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="escalated" <?php echo $pref_resolution === 'escalated' ? 'selected' : ''; ?>>Escalated</option>
                        <option value="deferred" <?php echo $pref_resolution === 'deferred' ? 'selected' : ''; ?>>Deferred/Waiting</option>
                    </select>
                </div>

                <div class="form-row">
                    <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="follow_up_required" value="1" <?php echo $pref_followup ? 'checked' : ''; ?>> Follow-up required</label>
                </div>

                <div class="form-row">
                    <label class="form-label" for="comments">Comments (optional)</label>
                    <textarea class="form-textarea" id="comments" name="comments" rows="3" placeholder="Any additional notes..."><?php echo htmlspecialchars($submission['comments'] ?? ''); ?></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top: 18px;">
                    <button type="submit" name="submit_quest" class="btn btn-primary" id="submitBtn" style="cursor: pointer;">Save Changes</button>
                </div>
                <?php else: ?>
                <!-- fallback to existing generic UI -->
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
                    <button type="button" id="clearFileBtn" class="btn btn-primary" style="margin-top:8px;<?php echo !empty($submission['file_path']) ? 'display:inline-block;' : 'display:none;'; ?> background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 8px; padding: 10px 28px; font-size: 1.05em; font-weight: 600; box-shadow: 0 2px 8px #6366f133; transition: background 0.2s, box-shadow 0.2s;">Unselect File</button>
                    <div class="error-message" id="fileError" style="display:none;"></div>
                    <div class="file-info" id="fileInfo" style="margin-top:8px;">
                        <?php
                            // Prefer stored original filename fields for display
                            $fileNameCandidates = [
                                $submission['file_name'] ?? null,
                                $submission['original_name'] ?? null,
                                $submission['original_filename'] ?? null,
                                $submission['file_original_name'] ?? null,
                                $submission['original_file_name'] ?? null,
                            ];
                            $displayName = '';
                            foreach ($fileNameCandidates as $c) { if (!empty($c)) { $displayName = $c; break; } }
                            if (empty($displayName) && !empty($submission['file_path'])) { $displayName = basename($submission['file_path']); }
                            echo htmlspecialchars($displayName);
                        ?>
                    </div>
                    <input type="hidden" name="remove_file" id="remove_file" value="0">
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
                <div class="form-row">
                    <label class="form-label" for="comments">Comments (optional)</label>
                    <textarea class="form-textarea" id="comments" name="comments" rows="3" placeholder="Add any comments or notes for your submission..."><?php echo htmlspecialchars($submission['comments'] ?? ''); ?></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top: 18px;">
                    <button type="submit" name="submit_quest" class="btn btn-primary" id="submitBtn" style="background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%); color: #fff; border: none; border-radius: 8px; padding: 12px 32px; font-size: 1.1em; font-weight: 600; box-shadow: 0 2px 8px #6366f133; transition: background 0.2s, box-shadow 0.2s; cursor: pointer;">Save Changes</button>
                </div>
                <?php endif; ?>
            </form>
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
                // If user selects a new file, ensure remove flag is cleared
                var removeInput = document.getElementById('remove_file'); if (removeInput) { removeInput.value = '0'; }
                if (fileInput.files.length) {
                    clearFileBtn.style.display = 'inline-block';
                } else {
                    // if no file selected, hide only if there is no existing stored file name
                    var hasStored = (fileInfo && fileInfo.textContent && fileInfo.textContent.trim() !== '');
                    clearFileBtn.style.display = hasStored ? 'inline-block' : 'none';
                }
            });
            // Unselect/Clear file button logic
            if (clearFileBtn) {
                clearFileBtn.addEventListener('click', function() {
                    // mark removal for server, clear file input and show placeholder
                    fileInput.value = '';
                    var removeInput = document.getElementById('remove_file'); if (removeInput) { removeInput.value = '1'; }
                    if (fileInfo) { fileInfo.textContent = 'No file selected'; }
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
        // Support file (client_support) dropzone & validation — mirrors quest file behavior so UI matches
        (function() {
            const supportDropzone = document.getElementById('support_dropzone');
            const supportFileInput = document.getElementById('support_file');
            const supportFileInfo = document.getElementById('supportFileInfo');
            const supportFileError = document.getElementById('supportFileError');
            const supportProgressBar = document.getElementById('supportProgressBar');
            const supportProgressBarInner = document.getElementById('supportProgressBarInner');
            const clearSupportFileBtn = document.getElementById('clearSupportFileBtn');
            if (!supportDropzone || !supportFileInput || !supportFileInfo) return;
            const allowedTypesSupport = [
                'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'image/jpeg', 'image/png', 'text/plain', 'application/zip'
            ];
            const allowedExtsSupport = ['.pdf','.doc','.docx','.jpg','.jpeg','.png','.txt','.zip'];
            const maxSizeSupport = 5 * 1024 * 1024;

            function getFileIconSupport(ext) {
                switch(ext) {
                    case 'pdf': return '<i class="fas fa-file-pdf" style="color:#b91c1c;"></i>';
                    case 'doc': case 'docx': return '<i class="fas fa-file-word" style="color:#2563eb;"></i>';
                    case 'jpg': case 'jpeg': case 'png': return '<i class="fas fa-file-image" style="color:#059669;"></i>';
                    case 'txt': return '<i class="fas fa-file-alt" style="color:#f59e42;"></i>';
                    case 'zip': return '<i class="fas fa-file-archive" style="color:#6366f1;"></i>';
                    default: return '<i class="fas fa-file" style="color:#6366f1;"></i>';
                }
            }

            supportDropzone.addEventListener('dragover', function(e) { e.preventDefault(); supportDropzone.classList.add('dragover'); });
            supportDropzone.addEventListener('dragleave', function(e) { e.preventDefault(); supportDropzone.classList.remove('dragover'); });
            supportDropzone.addEventListener('drop', function(e) {
                e.preventDefault(); supportDropzone.classList.remove('dragover');
                if (e.dataTransfer.files && e.dataTransfer.files.length) {
                    supportFileInput.files = e.dataTransfer.files; validateSupportFiles();
                }
            });
            supportFileInput.addEventListener('change', function() { validateSupportFiles(); if (supportFileInput.files.length) { clearSupportFileBtn.style.display = 'inline-block'; } else { clearSupportFileBtn.style.display = 'none'; } });
            if (clearSupportFileBtn) {
                clearSupportFileBtn.addEventListener('click', function() { supportFileInput.value = ''; supportFileInfo.textContent = ''; supportFileError.style.display = 'none'; clearSupportFileBtn.style.display = 'none'; });
            }

            function validateSupportFiles() {
                supportFileError.style.display = 'none'; supportFileInfo.innerHTML = '';
                if (!supportFileInput.files.length) { clearSupportFileBtn.style.display = 'none'; return; }
                let errors = [];
                for (let i = 0; i < supportFileInput.files.length; i++) {
                    const f = supportFileInput.files[i];
                    const ext = f.name.substring(f.name.lastIndexOf('.')+1).toLowerCase();
                    if (!allowedTypesSupport.includes(f.type) && !allowedExtsSupport.includes('.'+ext)) {
                        errors.push(`${f.name}: Unsupported file type.`);
                    } else if (f.size > maxSizeSupport) {
                        errors.push(`${f.name}: File too large (max 5MB).`);
                    } else {
                        let cls = '';
                        if (ext === 'pdf') cls = 'file-type-pdf';
                        else if (ext === 'doc' || ext === 'docx') cls = 'file-type-doc';
                        else if (ext === 'jpg' || ext === 'jpeg' || ext === 'png') cls = 'file-type-jpg';
                        else if (ext === 'txt') cls = 'file-type-txt';
                        else if (ext === 'zip') cls = 'file-type-zip';
                        supportFileInfo.innerHTML += `<span class="${cls}" style="margin-right:10px;">${getFileIconSupport(ext)} ${f.name}</span>`;
                    }
                }
                if (errors.length) {
                    supportFileError.innerHTML = errors.join('<br>'); supportFileError.style.display = 'block'; supportFileInput.value = ''; supportFileInfo.innerHTML = ''; clearSupportFileBtn.style.display = 'none';
                } else {
                    supportFileError.style.display = 'none'; clearSupportFileBtn.style.display = 'inline-block';
                }
            }
        })();
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
    <script>
    // Confetti canvas + sound for success (shared behavior)
    (function() {
        function launchConfetti(kind) {
            // create canvas
            var c = document.createElement('canvas');
            c.style.position = 'fixed'; c.style.left = 0; c.style.top = 0; c.style.width = '100%'; c.style.height = '100%';
            c.style.pointerEvents = 'none'; c.style.zIndex = 99999; document.body.appendChild(c);
            var ctx = c.getContext('2d');
            function resize() { c.width = window.innerWidth; c.height = window.innerHeight; }
            resize(); window.addEventListener('resize', resize);

            var particles = [];
            var colors = ['#ef4444','#f59e0b','#fde68a','#34d399','#60a5fa','#a78bfa','#fb7185'];
            for (var i=0;i<120;i++) {
                particles.push({
                    x: Math.random()*c.width,
                    y: Math.random()*c.height - c.height/2,
                    vx: (Math.random()-0.5)*8,
                    vy: Math.random()*6+2,
                    r: Math.random()*8+4,
                    color: colors[Math.floor(Math.random()*colors.length)],
                    rot: Math.random()*360,
                    vr: (Math.random()-0.5)*10
                });
            }

            var ticks = 0;
            var raf;
            function frame() {
                ctx.clearRect(0,0,c.width,c.height);
                for (var i=0;i<particles.length;i++) {
                    var p = particles[i];
                    p.x += p.vx; p.y += p.vy; p.vy += 0.12; p.rot += p.vr;
                    ctx.save();
                    ctx.translate(p.x,p.y); ctx.rotate(p.rot*Math.PI/180);
                    ctx.fillStyle = p.color; ctx.fillRect(-p.r/2,-p.r/2,p.r,p.r*0.6);
                    ctx.restore();
                }
                ticks++; if (ticks>220) { cancelAnimationFrame(raf); document.body.removeChild(c); window.removeEventListener('resize', resize); }
                else raf = requestAnimationFrame(frame);
            }
            playSuccessSound(kind);
            raf = requestAnimationFrame(frame);
        }

        function playSuccessSound(kind) {
            try {
                var ctx = new (window.AudioContext || window.webkitAudioContext)();
                var now = ctx.currentTime;
                if (kind === 'create') {
                    // brief 3-note ascending arpeggio
                    var freqs = [440, 660, 880];
                    freqs.forEach(function(f, idx){
                        var o = ctx.createOscillator(); var g = ctx.createGain();
                        o.type = 'sine'; o.frequency.value = f; g.gain.value = 0.001;
                        o.connect(g); g.connect(ctx.destination);
                        var t = now + idx*0.08;
                        g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.12, t+0.02);
                        g.gain.exponentialRampToValueAtTime(0.0001, t+0.28);
                        o.start(t); o.stop(t+0.3);
                    });
                } else {
                    // edit: single warm chord
                    var freqs = [330, 440, 550];
                    freqs.forEach(function(f){
                        var o = ctx.createOscillator(); var g = ctx.createGain();
                        o.type = 'sine'; o.frequency.value = f; g.gain.value = 0.0001;
                        o.connect(g); g.connect(ctx.destination);
                        g.gain.setValueAtTime(0.0001, now); g.gain.exponentialRampToValueAtTime(0.14, now+0.02);
                        g.gain.exponentialRampToValueAtTime(0.0001, now+0.4);
                        o.start(now); o.stop(now+0.45);
                    });
                }
            } catch (e) { /* audio not supported */ }
        }

        // auto-launch when success overlay present
        document.addEventListener('DOMContentLoaded', function(){
            var ov = document.getElementById('successOverlay');
            if (ov) {
                // determine kind: edit page -> 'edit'
                launchConfetti('edit');
            }
        });
    })();
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
                }
            }
        }
    });
    </script>
</body>
</html>
