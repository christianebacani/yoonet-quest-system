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

    // Deadline check: block submission if past due_date (only if due_date is valid)
    if (!empty($quest['due_date'])) {
        $due_ts = strtotime($quest['due_date']);
        if ($due_ts !== false && $due_ts > 0) {
            $now_ts = time();
            if ($now_ts > $due_ts) {
                $error = 'You cannot submit for this quest. The deadline has passed.';
                $valid = false;
            }
        }
    }

    // Block submission if user's quest status is 'missed'
    try {
        $uqStmt = $pdo->prepare("SELECT status FROM user_quests WHERE quest_id = ? AND employee_id = ? LIMIT 1");
        $uqStmt->execute([$quest_id, $employee_id]);
        $uqRow = $uqStmt->fetch(PDO::FETCH_ASSOC);
        if ($uqRow && isset($uqRow['status']) && $uqRow['status'] === 'missed') {
            $error = 'You cannot submit for this quest. The quest is marked as missed.';
            $valid = false;
        }
    } catch (PDOException $e) {
        // ignore DB check errors, rely on due_date check above
        error_log('submit_quest: failed to check user_quests status: ' . $e->getMessage());
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

    // Allow optional support file upload for client_support quests (stored into file_path like regular file submissions)
    if (isset($quest['display_type']) && $quest['display_type'] === 'client_support') {
        if (isset($_FILES['support_file']) && isset($_FILES['support_file']['error'])) {
            $supp = $_FILES['support_file'];
            if ($supp['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($supp['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_exts)) {
                    $error = 'Invalid support file type.';
                    $valid = false;
                } elseif ($supp['size'] > $max_size) {
                    $error = 'Support file too large (max 5MB).';
                    $valid = false;
                } else {
                    $upload_dir = __DIR__ . '/uploads/quest_submissions/';
                    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
                    $new_name = $employee_id . '_support_' . time() . '.' . $ext;
                    $dest = $upload_dir . $new_name;
                    if (move_uploaded_file($supp['tmp_name'], $dest)) {
                        $file_path = 'uploads/quest_submissions/' . $new_name;
                        $original_filename = $supp['name'];
                    } else {
                        $error = 'Failed to move support file. Check server permissions.';
                        $valid = false;
                    }
                }
            } elseif ($supp['error'] !== UPLOAD_ERR_NO_FILE) {
                // other upload error
                $error = 'Support file upload error (code ' . (int)$supp['error'] . ').';
                $valid = false;
            }
        }
    }

    // Special handling for Client & Support Operations quests.
    // For these quests require an "action taken" description (text submission) and allow optional evidence.
    $ticket_reference = '';
    $time_spent_hours = null;
    $evidence_json = '';
    if (isset($quest['display_type']) && $quest['display_type'] === 'client_support') {
        // Force text submission type and collect client-specific fields
        $submission_type = 'text';
        $text = trim($_POST['action_taken'] ?? '');
        $ticket_reference = trim($_POST['ticket_id'] ?? '');
        $time_spent_hours = isset($_POST['time_spent']) && $_POST['time_spent'] !== '' ? (float)$_POST['time_spent'] : null;
        $evidence = $_POST['evidence'] ?? [];
        if (!empty($evidence) && is_array($evidence)) {
            $evidence_json = json_encode(array_values($evidence));
        }
        $resolution_status = trim($_POST['resolution_status'] ?? '');
        $follow_up_required = isset($_POST['follow_up_required']) ? 1 : 0;

        // NOTE: We will only merge ticket/time into comments later if the DB schema
        // does not include dedicated columns for those fields. That check is done
        // after inspecting the `quest_submissions` table schema to avoid duplicating
        // structured data into the free-text comments field when dedicated columns
        // are available.

        // Enforce presence of action taken
        if (empty($text)) {
            $error = 'Please describe the action taken to resolve the client request or complete the task.';
            $valid = false;
        }
        // Require at least one evidence checkbox OR a file upload OR a ticket id OR time spent
        $hasEvidence = !empty($evidence_json) || (!empty($_FILES['support_file'] ?? []) && (!isset($_FILES['support_file']['error']) || $_FILES['support_file']['error'] !== UPLOAD_ERR_NO_FILE)) || $ticket_reference !== '' || $time_spent_hours !== null;
        if (!$hasEvidence) {
            // Not strictly failing but warn — prefer at least one evidence item
            // We'll not block submission but we add a note in comments
            if (!empty($comments)) $comments .= "\n"; 
            $comments .= "[No evidence attached]";
        }
    }

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
                            // preserve original uploaded filename for display if DB supports it
                            $original_filename = $file['name'];
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
        // For client_support quests we use 'action_taken' as the text input (already set earlier).
        if (isset($quest['display_type']) && $quest['display_type'] === 'client_support') {
            // $text should already be populated from the client_support handling above; re-check it here
            if (empty($text)) {
                $error = 'Please describe the action taken to resolve the client request or complete the task.';
                $valid = false;
            }
        } else {
            $text = trim($_POST['text_content'] ?? '');
            if (empty($text)) {
                $error = 'Please enter your text submission.';
                $valid = false;
            }
        }
    } else {
        $error = 'Invalid submission type.';
        $valid = false;
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

    if ($valid) {
        // Build a resilient INSERT that only uses columns that exist in the current DB schema
        $questSubmissionColumns = [];
        try {
            $schemaStmt = $pdo->query("SHOW COLUMNS FROM quest_submissions");
            $cols = $schemaStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) { $questSubmissionColumns[] = $c['Field']; }
            // If DB doesn't have any original-filename column, try to add a conservative one for display
            $origCols = ['file_name','original_name','original_filename','file_original_name','original_file_name'];
            $hasOrig = false;
            foreach ($origCols as $oc) { if (in_array($oc, $questSubmissionColumns, true)) { $hasOrig = true; break; } }
            if (!$hasOrig) {
                try {
                    // Try to add a column to preserve original filenames. IF NOT EXISTS is supported on newer MySQL; wrap in try/catch for compatibility.
                    $pdo->exec("ALTER TABLE quest_submissions ADD COLUMN IF NOT EXISTS file_name VARCHAR(255) DEFAULT '' NULL");
                    // refresh columns
                    $schemaStmt = $pdo->query("SHOW COLUMNS FROM quest_submissions");
                    $cols = $schemaStmt->fetchAll(PDO::FETCH_ASSOC);
                    $questSubmissionColumns = [];
                    foreach ($cols as $c) { $questSubmissionColumns[] = $c['Field']; }
                } catch (PDOException $altex) {
                    // ignore alter errors; it's best-effort
                    error_log('Could not add file_name column to quest_submissions: ' . $altex->getMessage());
                }
            }
            // Ensure that if we have a file to save ($file_path) the DB has file_path and file_name columns
            if (!empty($file_path)) {
                if (!in_array('file_path', $questSubmissionColumns, true)) {
                    try {
                        $pdo->exec("ALTER TABLE quest_submissions ADD COLUMN file_path VARCHAR(255) NULL");
                        $questSubmissionColumns[] = 'file_path';
                    } catch (PDOException $altex) {
                        error_log('Could not add file_path column to quest_submissions: ' . $altex->getMessage());
                    }
                }
                if (!in_array('file_name', $questSubmissionColumns, true)) {
                    try {
                        $pdo->exec("ALTER TABLE quest_submissions ADD COLUMN file_name VARCHAR(255) NULL");
                        $questSubmissionColumns[] = 'file_name';
                    } catch (PDOException $altex) {
                        // ignore
                    }
                }
            }
            // If this is a client_support submission and the DB does NOT have dedicated
            // columns for ticket/time, merge those values into the comments for
            // backward compatibility so the data is still preserved in the submission.
            if (isset($quest['display_type']) && $quest['display_type'] === 'client_support') {
                $hasTicketCol = in_array('ticket_reference', $questSubmissionColumns, true) || in_array('ticket_id', $questSubmissionColumns, true);
                $hasTimeCol = in_array('time_spent_hours', $questSubmissionColumns, true) || in_array('time_spent', $questSubmissionColumns, true);
                if (!$hasTicketCol || !$hasTimeCol) {
                    $extra = '';
                    if (!$hasTicketCol && !empty($ticket_reference)) $extra .= "Ticket: $ticket_reference\n";
                    if (!$hasTimeCol && $time_spent_hours !== null) $extra .= "Time Spent (hrs): $time_spent_hours\n";
                    if ($extra !== '') {
                        if (!empty($comments)) $comments = $comments . "\n" . $extra; else $comments = $extra;
                    }
                }
            }
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
            // If the schema has a column to preserve the original uploaded filename, store it
            $origCols = ['file_name','original_name','original_filename','file_original_name','original_file_name'];
            $storedOriginal = $original_filename ?? basename($file_path);
            foreach ($origCols as $oc) {
                if (in_array($oc, $questSubmissionColumns, true)) {
                    $insertColumns[] = $oc; $placeholders[] = '?'; $params[] = $storedOriginal; break;
                }
            }
        } elseif ($submission_type === 'link' && in_array('drive_link', $questSubmissionColumns, true)) {
            $insertColumns[] = 'drive_link'; $placeholders[] = '?'; $params[] = $drive_link; $hasContentColumn = true;
        } elseif ($submission_type === 'text') {
            // Prefer dedicated action/text columns for client_support quests. Use the
            // first available column from this ordered list so form inputs reliably
            // map to DB columns regardless of schema naming.
            $textColsPriority = ['action_taken','action','text_content','text','submission_text'];
            $chosen = null;
            foreach ($textColsPriority as $tc) {
                if (in_array($tc, $questSubmissionColumns, true)) { $chosen = $tc; break; }
            }
            if ($chosen) {
                $insertColumns[] = $chosen; $placeholders[] = '?'; $params[] = $text; $hasContentColumn = true;
            }
        }

        // If a support file was uploaded for client_support (or any case where $file_path is set),
        // ensure we save it into an appropriate column so the view can always find it.
        if (!empty($file_path)) {
            // Prefer these columns (first match wins)
            $pathColsPriority = ['file_path','support_file','supporting_file','uploaded_file','attachment','filepath','submission_file'];
            $written = false;
            foreach ($pathColsPriority as $pc) {
                if (in_array($pc, $questSubmissionColumns, true)) {
                    if (!in_array($pc, $insertColumns, true)) { $insertColumns[] = $pc; $placeholders[] = '?'; $params[] = $file_path; }
                    $written = true; break;
                }
            }
            // If none of the expected columns exist, attempt a best-effort ALTER to add file_path
            if (!$written) {
                try {
                    // Add column if possible (MySQL: ADD COLUMN)
                    $pdo->exec("ALTER TABLE quest_submissions ADD COLUMN file_path VARCHAR(255) NULL");
                    // refresh columns array and mark for insertion
                    $questSubmissionColumns[] = 'file_path';
                    $insertColumns[] = 'file_path'; $placeholders[] = '?'; $params[] = $file_path;
                    $written = true;
                } catch (PDOException $altex) {
                    // ignore; if we can't alter the table, try fallback columns that might be present
                    error_log('Could not add file_path column to quest_submissions: ' . $altex->getMessage());
                }
            }

            // Persist original filename when possible
            $origCols = ['file_name','original_name','original_filename','file_original_name','original_file_name'];
            $storedOriginal = $original_filename ?? basename($file_path);
            foreach ($origCols as $oc) {
                if (in_array($oc, $questSubmissionColumns, true) && !in_array($oc, $insertColumns, true)) {
                    $insertColumns[] = $oc; $placeholders[] = '?'; $params[] = $storedOriginal; break;
                }
            }
        }

        if (!$hasContentColumn) {
            $error = 'Server not configured to store this type of submission. Please contact an administrator.';
            $valid = false;
        }

        // Optional comments
        if ($valid && in_array('comments', $questSubmissionColumns, true)) {
            $insertColumns[] = 'comments'; $placeholders[] = '?'; $params[] = $comments;
        }

        // Client & Support specific fields: ticket, time spent, evidence JSON, resolution status, follow-up
        if ($valid) {
            // ticket_reference or ticket_id
            if (in_array('ticket_reference', $questSubmissionColumns, true)) { $insertColumns[] = 'ticket_reference'; $placeholders[] = '?'; $params[] = $ticket_reference; }
            elseif (in_array('ticket_id', $questSubmissionColumns, true)) { $insertColumns[] = 'ticket_id'; $placeholders[] = '?'; $params[] = $ticket_reference; }

            // time_spent_hours or time_spent
            if (in_array('time_spent_hours', $questSubmissionColumns, true)) { $insertColumns[] = 'time_spent_hours'; $placeholders[] = '?'; $params[] = $time_spent_hours; }
            elseif (in_array('time_spent', $questSubmissionColumns, true)) { $insertColumns[] = 'time_spent'; $placeholders[] = '?'; $params[] = $time_spent_hours; }

            // evidence_json or evidence
            if (in_array('evidence_json', $questSubmissionColumns, true)) { $insertColumns[] = 'evidence_json'; $placeholders[] = '?'; $params[] = $evidence_json; }
            elseif (in_array('evidence', $questSubmissionColumns, true)) { $insertColumns[] = 'evidence'; $placeholders[] = '?'; $params[] = $evidence_json; }

            // resolution_status
            if (in_array('resolution_status', $questSubmissionColumns, true)) { $insertColumns[] = 'resolution_status'; $placeholders[] = '?'; $params[] = $resolution_status ?? ''; }

            // follow_up_required
            if (in_array('follow_up_required', $questSubmissionColumns, true)) {
                $insertColumns[] = 'follow_up_required'; $placeholders[] = '?'; $params[] = $follow_up_required ?? 0;
            } else {
                // Try to add the column to persist follow-up flags for client_support submissions.
                try {
                    $pdo->exec("ALTER TABLE quest_submissions ADD COLUMN follow_up_required TINYINT(1) NOT NULL DEFAULT 0");
                    // refresh in-memory column list so subsequent logic can see it
                    $questSubmissionColumns[] = 'follow_up_required';
                    $insertColumns[] = 'follow_up_required'; $placeholders[] = '?'; $params[] = $follow_up_required ?? 0;
                } catch (PDOException $ax) {
                    // Best-effort: if we cannot alter the table, skip storing follow-up but do not block submission
                    error_log('Could not add follow_up_required column: ' . $ax->getMessage());
                }
            }
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
        /* Support dropzone styling to match requested design (rounded dashed purple box) */
        #support_dropzone { position: relative; border: 2px dashed #6366f1; border-radius: 14px; padding: 22px 0; text-align: center; color: #6366f1; background: #fbfbfe; min-height: 64px; display:flex; align-items:center; justify-content:center; font-size:1.05em; }
        #support_file { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 2; display: block !important; }
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
            <?php if (isset($quest['display_type']) && $quest['display_type'] === 'client_support'): ?>
            <!-- Modernized submission UI for Client & Support Operations quests (matches other quest types) -->
            <form method="post" enctype="multipart/form-data" id="submissionForm">
                <input type="hidden" name="quest_id" value="<?php echo htmlspecialchars($quest_id); ?>">
                <div class="form-row">
                    <label class="form-label" for="ticket_id">Ticket / Reference ID</label>
                    <input class="form-input" type="text" id="ticket_id" name="ticket_id" value="<?php echo htmlspecialchars($quest['client_reference'] ?? ''); ?>" placeholder="Optional ticket or reference">
                </div>

                <div class="form-row">
                    <label class="form-label" for="action_taken">Action Taken / Resolution (required)</label>
                    <textarea class="form-textarea" id="action_taken" name="action_taken" rows="6" placeholder="Describe the steps you took, findings, and the resolution. This will be used by reviewers."><?php echo htmlspecialchars($_POST['action_taken'] ?? ''); ?></textarea>
                    <div class="meta" style="margin-top:8px;">Tip: include key timestamps, relevant commands/log snippets or ticket links, and any customer confirmation or screenshots to speed up review.</div>
                </div>

                <div class="form-row">
                    <label class="form-label" for="time_spent">Time Spent (hours)</label>
                    <input class="form-input" type="number" id="time_spent" name="time_spent" step="0.25" min="0" value="<?php echo htmlspecialchars($_POST['time_spent'] ?? ''); ?>" placeholder="e.g. 1.5">
                </div>

                <div class="form-row">
                    <label class="form-label">Evidence / Attachments</label>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label style="font-weight:600;"><input type="checkbox" name="evidence[]" value="screenshot"> Screenshot(s)</label>
                        <label style="font-weight:600;"><input type="checkbox" name="evidence[]" value="log"> Log file / system output</label>
                        <label style="font-weight:600;"><input type="checkbox" name="evidence[]" value="customer_confirmation"> Customer confirmation / email</label>
                        <label style="font-weight:600;"><input type="checkbox" name="evidence[]" value="ticket_update"> Ticket / system update</label>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Upload supporting file (optional)</label>
                    <div id="support_dropzone" class="dropzone" style="text-align:center;">
                        Drag & drop your file here or click to select
                        <input type="file" id="support_file" name="support_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.zip">
                    </div>
                    <div class="meta" style="margin-top:8px; text-align:center; font-size:0.95em; color:#6b7280;">PDF, DOC, DOCX, JPG, PNG, TXT, ZIP (Max 5MB)</div>
                    <button type="button" id="clearSupportFileBtn" class="btn btn-primary" style="margin-top:8px;display:none;">Unselect File</button>
                    <div class="error-message" id="supportFileError" style="display:none;"></div>
                    <div class="file-info" id="supportFileInfo" style="margin-top:8px;text-align:center;"></div>
                    <div class="progress-bar" id="supportProgressBar" style="display:none;"><div class="progress-bar-inner" id="supportProgressBarInner"></div></div>
                </div>

                <div class="form-row">
                    <label class="form-label" for="resolution_status">Resolution Outcome</label>
                    <select name="resolution_status" id="resolution_status" class="form-input">
                        <option value="resolved">Resolved</option>
                        <option value="escalated">Escalated</option>
                        <option value="deferred">Deferred/Waiting</option>
                    </select>
                </div>

                <div class="form-row">
                    <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="follow_up_required" value="1" <?php echo isset($_POST['follow_up_required']) ? 'checked' : ''; ?>> Follow-up required</label>
                </div>

                <div class="form-row">
                    <label class="form-label" for="comments">Comments (optional)</label>
                    <textarea class="form-textarea" id="comments" name="comments" rows="3" placeholder="Any additional notes..."><?php echo htmlspecialchars($_POST['comments'] ?? ''); ?></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top: 18px;">
                    <button type="submit" name="submit_quest" class="btn btn-primary" id="submitBtn" style="cursor: pointer;">Submit Resolution</button>
                </div>
            </form>
            <?php else: ?>
            <?php if (isset($quest['quest_type']) && $quest['quest_type'] === 'custom'): ?>
            <form method="post" enctype="multipart/form-data" id="submissionForm">
                <input type="hidden" name="quest_id" value="<?php echo htmlspecialchars($quest_id); ?>">
                <input type="hidden" name="submission_type" value="file">
                <div class="form-row">
                    <label class="form-label" for="quest_file">Upload File</label>
                    <div id="dropzone" class="dropzone">Drag & drop your file here or click to select
                        <input class="form-input" type="file" id="quest_file" name="quest_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.zip">
                    </div>
                    <button type="button" id="clearFileBtn" class="btn btn-primary" style="margin-top:8px;display:none;">Unselect File</button>
                    <div class="error-message" id="fileError" style="display:none;"></div>
                    <div class="file-info" id="fileInfo" style="margin-top:8px;"></div>
                    <div class="meta">PDF, DOC, DOCX, JPG, PNG, TXT, ZIP (Max 5MB)</div>
                    <div class="progress-bar" id="progressBar" style="display:none;"><div class="progress-bar-inner" id="progressBarInner"></div></div>
                </div>
                <div class="form-row">
                    <label class="form-label" for="comments">Comments (optional)</label>
                    <textarea class="form-textarea" id="comments" name="comments" rows="3" placeholder="Add any comments or notes for your submission..."></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top: 18px;">
                    <button type="submit" name="submit_quest" class="btn btn-primary" id="submitBtn" style="cursor: pointer;">Submit Quest</button>
                </div>
            </form>
            <?php else: ?>
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
            <?php endif; ?>
            <?php endif; ?>
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
    // Confetti + sound on successful create (matches edit animation, different sound)
    (function() {
        function launchConfetti(kind) {
            var c = document.createElement('canvas');
            c.style.position = 'fixed'; c.style.left = 0; c.style.top = 0; c.style.width = '100%'; c.style.height = '100%';
            c.style.pointerEvents = 'none'; c.style.zIndex = 99999; document.body.appendChild(c);
            var ctx = c.getContext('2d');
            function resize() { c.width = window.innerWidth; c.height = window.innerHeight; }
            resize(); window.addEventListener('resize', resize);
            var particles = []; var colors = ['#ef4444','#f59e0b','#fde68a','#34d399','#60a5fa','#a78bfa','#fb7185'];
            for (var i=0;i<120;i++){ particles.push({ x: Math.random()*c.width, y: Math.random()*c.height - c.height/2, vx: (Math.random()-0.5)*8, vy: Math.random()*6+2, r: Math.random()*8+4, color: colors[Math.floor(Math.random()*colors.length)], rot: Math.random()*360, vr: (Math.random()-0.5)*10 }); }
            var ticks=0, raf;
            function frame(){ ctx.clearRect(0,0,c.width,c.height); for(var i=0;i<particles.length;i++){ var p=particles[i]; p.x+=p.vx; p.y+=p.vy; p.vy+=0.12; p.rot+=p.vr; ctx.save(); ctx.translate(p.x,p.y); ctx.rotate(p.rot*Math.PI/180); ctx.fillStyle=p.color; ctx.fillRect(-p.r/2,-p.r/2,p.r,p.r*0.6); ctx.restore(); } ticks++; if(ticks>220){ cancelAnimationFrame(raf); document.body.removeChild(c); window.removeEventListener('resize', resize); } else raf=requestAnimationFrame(frame); }
            playSuccessSound(kind); raf=requestAnimationFrame(frame);
        }
        function playSuccessSound(kind){ try{ var ctx=new (window.AudioContext||window.webkitAudioContext)(); var now=ctx.currentTime; if(kind==='create'){ var freqs=[520,680,880]; freqs.forEach(function(f,idx){ var o=ctx.createOscillator(), g=ctx.createGain(); o.type='sine'; o.frequency.value=f; g.gain.value=0.0001; o.connect(g); g.connect(ctx.destination); var t=now+idx*0.06; g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.12,t+0.02); g.gain.exponentialRampToValueAtTime(0.0001,t+0.22); o.start(t); o.stop(t+0.26); }); } else { var freqs=[330,440,550]; freqs.forEach(function(f){ var o=ctx.createOscillator(), g=ctx.createGain(); o.type='sine'; o.frequency.value=f; g.gain.value=0.0001; o.connect(g); g.connect(ctx.destination); g.gain.setValueAtTime(0.0001,now); g.gain.exponentialRampToValueAtTime(0.14,now+0.02); g.gain.exponentialRampToValueAtTime(0.0001,now+0.4); o.start(now); o.stop(now+0.45); }); } }catch(e){} }
        document.addEventListener('DOMContentLoaded', function(){ var ov = document.getElementById('successOverlay'); if(ov){ launchConfetti('create'); } });
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