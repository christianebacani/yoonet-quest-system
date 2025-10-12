<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/skill_progression.php';

// Check if user is logged in and has admin rights
if (!is_logged_in()) {
    redirect('login.php');
}

$quest_id = isset($_GET['quest_id']) ? (int)$_GET['quest_id'] : 0;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$employee_id_param = isset($_GET['employee_id']) ? trim((string)$_GET['employee_id']) : '';
$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
$error = '';
$success = '';

// Try to resolve missing identifiers gracefully before redirecting
if ($quest_id && !$user_id) {
    try {
        if ($submission_id > 0) {
            $stmt = $pdo->prepare("SELECT u.id AS user_id, qs.quest_id, u.employee_id FROM quest_submissions qs LEFT JOIN users u ON qs.employee_id = u.employee_id WHERE qs.id = ?");
            $stmt->execute([$submission_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $user_id = (int)($row['user_id'] ?? 0);
                if (!$quest_id && isset($row['quest_id'])) { $quest_id = (int)$row['quest_id']; }
                if (!$employee_id_param && isset($row['employee_id'])) { $employee_id_param = (string)$row['employee_id']; }
            }
        }
        if (!$user_id && $employee_id_param !== '') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
            $stmt->execute([$employee_id_param]);
            $uid = $stmt->fetchColumn();
            if ($uid) { $user_id = (int)$uid; }
        }
    } catch (PDOException $e) {
        error_log('quest_assessment: resolution error ' . $e->getMessage());
    }
}

if (!$quest_id || !$user_id) {
    // Show a friendly message instead of bouncing back immediately
    $error = 'Missing or invalid parameters for assessment. Please return to Submitted Quest list and try again.';
}

// Get quest details
if (empty($error)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM quests WHERE id = ?");
        $stmt->execute([$quest_id]);
        $quest = $stmt->fetch();
        
        if (!$quest) {
            $error = 'Quest not found.';
        }
    } catch (PDOException $e) {
        error_log("Error fetching quest: " . $e->getMessage());
        $error = 'Unable to load quest details.';
    }
}

// Get user details (non-fatal if missing; we'll still show submission + employee_id)
if (empty($error)) {
    try {
        // Note: users table does not have a `username` column; select existing fields only
        $stmt = $pdo->prepare("SELECT id, full_name, employee_id, email FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("Error fetching user: " . $e->getMessage());
        $user = [];
    }
}

// Get quest skills
$skillManager = new SkillProgression($pdo);
$quest_skills = $skillManager->getQuestSkills($quest_id);

// Normalize quest skills to always include readable skill_name and tier_level (T1..T5)
$normalized_skills = [];
if (is_array($quest_skills) && !empty($quest_skills)) {
    // Build id -> name map if we only have skill_id
    $ids = [];
    foreach ($quest_skills as $row) {
        if (!isset($row['skill_name']) && isset($row['skill_id'])) {
            $ids[] = (int)$row['skill_id'];
        }
    }
    $nameMap = [];
    if (!empty($ids)) {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!empty($ids)) {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, skill_name FROM comprehensive_skills WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $nameMap[(int)$r['id']] = (string)$r['skill_name'];
                }
            } catch (PDOException $e) {
                error_log('assessment: failed to map skill ids -> names: ' . $e->getMessage());
            }
        }
    }

    foreach ($quest_skills as $row) {
        $name = '';
        if (isset($row['skill_name']) && $row['skill_name'] !== '') {
            $name = (string)$row['skill_name'];
        } elseif (isset($row['skill_id']) && isset($nameMap[(int)$row['skill_id']])) {
            $name = $nameMap[(int)$row['skill_id']];
        } else {
            $name = 'Skill';
        }

        // Normalize tier_level to T1..T5
        $tier_level = 'T2';
        if (isset($row['tier_level']) && preg_match('~^T[1-5]$~', (string)$row['tier_level'])) {
            $tier_level = (string)$row['tier_level'];
        } elseif (isset($row['tier'])) {
            $t = (int)$row['tier'];
            if ($t < 1) $t = 1; if ($t > 5) $t = 5;
            $tier_level = 'T' . $t;
        }

        $normalized_skills[] = [
            'skill_name' => $name,
            'tier_level' => $tier_level,
        ];
    }
}
$quest_skills = $normalized_skills ?: (is_array($quest_skills) ? $quest_skills : []);

// Fetch latest submission by this user for this quest (schema-adaptive)
$latestSubmission = null;
// Prefer employee_id from user; fallback to explicit employee_id param
$employeeIdForSubmission = $user['employee_id'] ?? ($employee_id_param !== '' ? $employee_id_param : null);
if (!empty($employeeIdForSubmission)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM quest_submissions WHERE employee_id = ? AND quest_id = ? ORDER BY submitted_at DESC LIMIT 1");
        $stmt->execute([$employeeIdForSubmission, $quest_id]);
        $latestSubmission = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('Error fetching latest submission: ' . $e->getMessage());
        $latestSubmission = null;
    }
}

// If user record is missing or incomplete but we have a submission, try resolving by employee_id from the submission
if ((!is_array($user) || empty($user) || empty($user['full_name'])) && $latestSubmission && !empty($latestSubmission['employee_id'])) {
    try {
        // Select only existing columns from users table
        $stmt = $pdo->prepare("SELECT id, full_name, email, employee_id FROM users WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$latestSubmission['employee_id']]);
        $maybeUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($maybeUser) {
            $user = $maybeUser;
            if (!$user_id) { $user_id = (int)$user['id']; }
            if (!$employeeIdForSubmission) { $employeeIdForSubmission = $user['employee_id']; }
        }
    } catch (PDOException $e) {
        error_log('user resolve by employee_id failed: ' . $e->getMessage());
    }
}

// Handle form submission
if (empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {
    $assessments = $_POST['assessments'] ?? [];
    $total_points = 0;
    $awarded_skills = [];
    
    try {
        $pdo->beginTransaction();
        
        foreach ($assessments as $skill_name => $assessment) {
            $performance = (float)$assessment['performance'];
            $base_points = (int)$assessment['base_points'];
            $notes = sanitize_input($assessment['notes'] ?? '');
            
            // Award skill points
            $result = $skillManager->awardSkillPoints($user_id, $skill_name, $base_points, $performance);
            
            if ($result['success']) {
                $awarded_skills[] = [
                    'skill' => $skill_name,
                    'points' => $result['points_awarded'],
                    'level' => $result['new_level'],
                    'stage' => $result['new_stage']
                ];
                $total_points += $result['points_awarded'];
            }
        }
        
        // Mark quest as completed for this user
        $stmt = $pdo->prepare("
            INSERT INTO quest_completions (quest_id, user_id, completed_at, total_points_awarded, notes) 
            VALUES (?, ?, NOW(), ?, ?) 
            ON DUPLICATE KEY UPDATE 
            completed_at = NOW(), total_points_awarded = ?, notes = ?
        ");
        $stmt->execute([$quest_id, $user_id, $total_points, '', $total_points, '']);
        
        $pdo->commit();
        $success = "Quest assessment completed! Total points awarded: {$total_points}";
        
        // Redirect to prevent resubmission
        header("Location: quest_assessment.php?quest_id={$quest_id}&user_id={$user_id}&success=1");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error submitting assessment: " . $e->getMessage());
        $error = "Failed to submit assessment. Please try again.";
    }
}

if (isset($_GET['success'])) {
    $success = "Quest assessment completed successfully!";
}

// Calculate tier points
function getTierPoints($tier) {
    switch($tier) {
        // Aligned with provided tier dropdown: T1 25, T2 40, T3 60, T4 85, T5 115
        case 'T1': return 25;
        case 'T2': return 40;
        case 'T3': return 60;
        case 'T4': return 85;
        case 'T5': return 115;
        default: return 25;
    }
}

// Human-friendly labels per tier
function getTierLabel($tier) {
    switch ($tier) {
        case 'T1': return 'Beginner';
        case 'T2': return 'Intermediate';
        case 'T3': return 'Advanced';
        case 'T4': return 'Expert';
        case 'T5': return 'Master';
        default: return '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quest Assessment - YooNet Quest System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <!-- Modern lightbox for previews -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css">
    <style>
        body { background: #f3f4f6; min-height: 100vh; font-family: 'Segoe UI', Arial, sans-serif; margin:0; }
        .assessment-container { max-width: 1200px; margin: 24px auto; padding: 0 16px; }
        .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px; }
        .topbar .title { font-size: 1.5rem; font-weight: 700; color:#111827; }
        .topbar .meta { color:#6b7280; font-size:0.9rem; }
    .btn-link { display:inline-block; padding:8px 12px; border-radius:8px; background:#111827; color:#fff; text-decoration:none; }
        .page-grid { display:grid; grid-template-columns: 1fr; gap: 16px; }
        @media (min-width: 980px){ .page-grid { grid-template-columns: 2fr 1fr; } }
        .col-left { display:flex; flex-direction:column; gap:16px; }
        .col-right { display:flex; flex-direction:column; gap:16px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; }
        .card h3 { margin:0 0 8px 0; font-size:1.1rem; color:#111827; }
        .meta-line { display:flex; justify-content:space-between; color:#6b7280; font-size: 0.9rem; }
        .assessment-content { padding: 0; }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin: 16px 0 12px 0;
            text-align: left;
        }
        
        .skill-assessment {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .skill-assessment:hover {
            border-color: #4338ca;
            box-shadow: 0 4px 12px rgba(67, 56, 202, 0.1);
        }
        
        .skill-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .skill-name .skill-icon { color:#4F46E5; }
        
        .tier-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid var(--tier-accent, #6366f1);
        }
        
        .base-tier { font-weight: 700; color: var(--tier-text, #3730A3); }
        .base-points { font-weight: bold; color: #1e293b; }
    /* Tier dynamic color tokens */
    /* Green → Cyan → Blue → Indigo → Purple */
    .tier-T1 { --tier-accent:#A7F3D0; --tier-text:#065F46; } /* Beginner */
    .tier-T2 { --tier-accent:#67E8F9; --tier-text:#155E75; } /* Intermediate */
    .tier-T3 { --tier-accent:#60A5FA; --tier-text:#1E3A8A; } /* Advanced */
    .tier-T4 { --tier-accent:#818CF8; --tier-text:#312E81; } /* Expert */
    .tier-T5 { --tier-accent:#C084FC; --tier-text:#4C1D95; } /* Master */
        
        .performance-section {
            margin: 15px 0;
        }
        
        .performance-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 10px;
        }
        
    .performance-select { width: 100%; max-width: 380px; padding: 10px 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem; transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease; }
    /* Color themes per performance level */
    .performance-select.pf-none { background: #FEE2E2; border-color: #EF4444; color: #991B1B; }
    .performance-select.pf-below { background: #FEF3C7; border-color: #F59E0B; color: #92400E; }
    .performance-select.pf-meets { background: #E5E7EB; border-color: #9CA3AF; color: #111827; }
    .performance-select.pf-exceeds { background: #DBEAFE; border-color: #3B82F6; color: #1E40AF; }
    .performance-select.pf-exceptional { background: #EDE9FE; border-color: #8B5CF6; color: #5B21B6; }
        .line { color:#374151; margin-top:8px; }
        .line small { color:#6b7280; }
        
        .notes-section {
            margin-top: 15px;
        }
        
        .notes-textarea {
            width: 100%;
            min-height: 80px;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-family: inherit;
            resize: vertical;
        }
        
        .notes-textarea:focus {
            border-color: #4338ca;
            outline: none;
        }
        
        /* removed total-section styles (no longer used) */
        
        .submit-section {
            text-align: center;
            margin-top: 30px;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #4338ca, #6366f1);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(67, 56, 202, 0.3);
        }

        /* Submission preview styles */
        .submission-card { background: #ffffff; border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px; }
        .submission-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .submission-title { font-weight: 700; color: #1f2937; }
        .submission-meta { color: #6b7280; font-size: 0.9rem; }
        .badge { display:inline-block; padding:4px 8px; border-radius:9999px; font-size: 12px; font-weight:600; }
        .badge-pending{ background:#FEF3C7; color:#92400E; border:1px solid #F59E0B; }
        .badge-under{ background:#DBEAFE; color:#1E40AF; border:1px solid #3B82F6; }
        .badge-approved{ background:#D1FAE5; color:#065F46; border:1px solid #10B981; }
        .badge-rejected{ background:#FEE2E2; color:#991B1B; border:1px solid #EF4444; }
        .preview-block { margin-top: 12px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:12px; }
        .preview-media { max-width:100%; max-height:480px; border-radius:8px; border:1px solid #e5e7eb; }
    .preview-text { white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; background:#111827; color:#e5e7eb; padding:12px; border-radius:8px; overflow:auto; }

    /* Skill chips */
    .chip-skill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:9999px; background:#F1F5F9; color:#111827; border:1px solid #CBD5E1; font-size:0.85rem; }
    .chip-skill i { color:#4F46E5; }

    /* Inline containers used by lightbox */
        .glightbox-inline { display:none; }
        .docx-view { background:#ffffff; max-height:75vh; overflow:auto; padding:16px; border-radius:8px; }
        .docx-view .docx-html { color:#111827; }

    /* Hide GLightbox bottom caption/description to avoid duplicate filenames */
    .gdesc, .gdesc-inner, .gslide-desc { display:none !important; }

        /* Lightbox modal header with filename */
    .modal-header { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 12px; border-bottom:1px solid var(--mh-border, #e5e7eb); background:var(--mh-bg, #ffffff); position:sticky; top:0; z-index:2; border-radius:8px 8px 0 0; }
    .modal-title { display:flex; align-items:center; gap:8px; font-weight:600; color:var(--mh-text, #111827); min-width:0; }
    .modal-title i { color: inherit; }
        .modal-title .file-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:70vw; }
        .modal-actions a { text-decoration:none; }
        .modal-body { background:#ffffff; max-height:75vh; overflow:auto; padding:10px 0; border-radius:0 0 8px 8px; }

    /* Header color themes per file type */
    .mh-image { --mh-bg:#EEF2FF; --mh-text:#3730A3; --mh-border:#C7D2FE; }
    .mh-pdf   { --mh-bg:#FEE2E2; --mh-text:#991B1B; --mh-border:#FCA5A5; }
    .mh-text  { --mh-bg:#F1F5F9; --mh-text:#0F172A; --mh-border:#CBD5E1; }
    .mh-doc   { --mh-bg:#DBEAFE; --mh-text:#1E40AF; --mh-border:#93C5FD; }
    .mh-xls   { --mh-bg:#DCFCE7; --mh-text:#065F46; --mh-border:#86EFAC; }
    .mh-ppt   { --mh-bg:#FFEDD5; --mh-text:#9A3412; --mh-border:#FDBA74; }
    .mh-office{ --mh-bg:#EDE9FE; --mh-text:#5B21B6; --mh-border:#C4B5FD; }
    </style>
</head>
<body>
    <div class="assessment-container">
        <div class="nav-header">
            <div class="nav-left">
                <a class="btn btn-navigation btn-back" href="pending_reviews.php">
                    <span class="btn-icon">◀</span>
                    <span class="btn-text">Back</span>
                </a>
                <h1 class="nav-title">Review Submission</h1>
            </div>
            <div class="nav-right">
                <span class="meta">Quest: <?= htmlspecialchars(is_array($quest) && isset($quest['title']) ? $quest['title'] : 'Unknown Quest') ?><?= isset($quest['id']) ? ' • ID #'.(int)$quest['id'] : '' ?></span>
            </div>
        </div>
        
        <div class="assessment-content">
            <div class="page-grid">
                <div class="col-left">
                    <?php if (is_array($latestSubmission) && !empty($latestSubmission)): ?>
                <?php
                    $status = strtolower(trim($latestSubmission['status'] ?? 'pending'));
                    $submittedAt = $latestSubmission['submitted_at'] ?? null;
                    $when = $submittedAt ? date('M d, Y g:i A', strtotime($submittedAt)) : 'Unknown time';
                            $filePath = $latestSubmission['file_path'] ?? '';
                    $driveLink = $latestSubmission['drive_link'] ?? '';
                    $textContent = $latestSubmission['text_content'] ?? '';
                    $submissionText = $latestSubmission['submission_text'] ?? '';

                    $badgeClass = 'badge badge-pending';
                    $badgeLabel = 'Pending';
                    if ($status === 'under_review') { $badgeClass = 'badge badge-under'; $badgeLabel = 'Under Review'; }
                    elseif ($status === 'approved') { $badgeClass = 'badge badge-approved'; $badgeLabel = 'Reviewed'; }
                    elseif ($status === 'rejected') { $badgeClass = 'badge badge-rejected'; $badgeLabel = 'Declined'; }
                ?>
                <div class="card">
                    <div class="submission-header">
                        <div>
                            <div class="submission-title">Submission</div>
                            <div class="submission-meta">Submitted: <?= htmlspecialchars($when) ?></div>
                        </div>
                        <span class="<?= $badgeClass ?>"><?= $badgeLabel ?></span>
                    </div>

                    <?php
                        $rendered = false;
                        // Helper: normalize absolute disk paths to web paths
                        $toWeb = function($p) {
                            if (!is_string($p) || $p === '') return '';
                            $n = str_replace('\\','/',$p);
                            $root = str_replace('\\','/', realpath(__DIR__) . '/');
                            if (stripos($n, $root) === 0) {
                                $rel = substr($n, strlen($root));
                                return $rel;
                            }
                            $pos = stripos($n, '/uploads/');
                            if ($pos !== false) {
                                return substr($n, $pos+1); // drop leading slash
                            }
                            return $n; // fallback
                        };

                        $path = is_string($filePath) ? trim($filePath) : '';
                        $web = $toWeb($path);
                        // Helper to produce absolute URLs for embedding when available
                        $absUrl = function($rel) {
                            if (preg_match('~^https?://~i', $rel)) return $rel;
                            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                            $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                            $rel = ltrim($rel, '/');
                            return $scheme . '://' . $host . '/' . $rel;
                        };
                        if ($web !== '') {
                            $ext = strtolower(pathinfo($web, PATHINFO_EXTENSION));
                            $src = preg_match('~^https?://~i', $web) ? $web : $web;
                            $abs = $absUrl($src);
                            $fname = htmlspecialchars(basename($web));
                                     if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                                          $title = $fname; // already escaped
                                          $inlineId = 'inline-'.md5($web);
                                          echo '<div class="preview-block">'
                                              . '<a class="glightbox" href="#' . $inlineId . '" data-type="inline">' 
                                              . '<img class="preview-media" src="' . htmlspecialchars($src) . '" alt="submission image" />'
                                              . '</a>'
                                              . '<div class="btn-group" style="margin-top:8px;">'
                                              . '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineId . '" data-type="inline">Open</a>'
                                              . '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($src) . '" download>Download</a>'
                                              . '</div>'
                                              . '<div class="glightbox-inline" id="' . $inlineId . '">'
                                                  . '<div class="modal-header mh-image"><div class="modal-title"><i class="fas fa-image"></i><span class="file-name">' . $title . '</span></div></div>'
                                                  . '<div class="modal-body"><img style="max-width:100%;height:auto;display:block;margin:0 auto;" src="' . htmlspecialchars($src) . '" alt="' . $title . '"/></div>'
                                              . '</div>'
                                              . '</div>';
                                $rendered = true;
                            } elseif ($ext === 'pdf') {
                                          $title = $fname;
                                          $inlineId = 'inline-'.md5($web);
                                          echo '<div class="preview-block">'
                                              . '<div class="btn-group" style="margin-top:8px;">'
                                              . '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineId . '" data-type="inline">Open</a>'
                                              . '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($src) . '" download>Download</a>'
                                              . '</div>'
                                              . '<div class="glightbox-inline" id="' . $inlineId . '">'
                                                  . '<div class="modal-header mh-pdf"><div class="modal-title"><i class="fas fa-file-pdf"></i><span class="file-name">' . $title . '</span></div></div>'
                                                  . '<div class="modal-body"><iframe src="' . htmlspecialchars($abs) . '" width="100%" height="640" style="border:0;"></iframe></div>'
                                              . '</div>'
                                              . '</div>';
                                $rendered = true;
                                     } elseif (in_array($ext, ['txt','md','csv'])) {
                                          $inlineId = 'inline-'.md5($web);
                                          $title = $fname;
                                          echo '<div class="preview-block">'
                                              . '<div class="glightbox-inline" id="' . $inlineId . '">'
                                                  . '<div class="modal-header mh-text"><div class="modal-title"><i class="fas fa-file-alt"></i><span class="file-name">' . $title . '</span></div></div>'
                                                  . '<div class="modal-body"><div class="docx-view"><div class="docx-html">' . htmlspecialchars(@file_get_contents($web)) . '</div></div></div>'
                                              . '</div>'
                                              . '<div class="btn-group" style="margin-top:8px;">'
                                              . '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineId . '" data-gallery="submission" data-type="inline">Open</a>'
                                              . '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($src) . '" download>Download</a>'
                                              . '</div>'
                                              . '</div>';
                                $rendered = true;
                            } else {
                                          // For docx and other office files, create a lightbox container we can populate via JS using Mammoth (docx)
                                          $inlineId = 'inline-'.md5($web);
                                          $title = $fname;
                                          // If public host (not localhost), offer Google Docs Viewer as a quick-view fallback
                                          $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                          $isLocal = (stripos($host, 'localhost') !== false) || (stripos($host, '127.0.0.1') !== false);
                                          $gview = 'https://docs.google.com/gview?embedded=1&url=' . rawurlencode($abs);
                                          echo '<div class="preview-block">'
                                              . '<div class="btn-group" style="margin-top:8px;">'
                                              . (
                                                  (in_array($ext, ['doc','docx','ppt','pptx','xls','xlsx']) && !$isLocal)
                                                  ? '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineId . '" data-type="inline">Open</a>'
                                                  : '<a class="btn btn-primary btn-sm glightbox office-open" data-file="' . htmlspecialchars($src) . '" data-inline="#' . $inlineId . '" data-title="' . $title . '">Open</a>'
                                                )
                                              . '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($src) . '" download>Download</a>'
                                              . '</div>'
                                              . '<div class="glightbox-inline" id="' . $inlineId . '">'
                                                  . '<div class="modal-header ' . (in_array($ext, ['xls','xlsx']) ? 'mh-xls' : (in_array($ext, ['ppt','pptx']) ? 'mh-ppt' : (in_array($ext, ['doc','docx']) ? 'mh-doc' : 'mh-office'))) . '"><div class="modal-title"><i class="' . (in_array($ext, ['xls','xlsx']) ? 'fas fa-file-excel' : (in_array($ext, ['ppt','pptx']) ? 'fas fa-file-powerpoint' : 'fas fa-file-word')) . '"></i><span class="file-name">' . $title . '</span></div></div>'
                                                  . '<div class="modal-body">'
                                                      . (
                                                          (in_array($ext, ['doc','docx','ppt','pptx','xls','xlsx']) && !$isLocal)
                                                          ? '<iframe src="' . htmlspecialchars($gview) . '" width="100%" height="640" style="border:0;"></iframe>'
                                                          : '<div class="docx-view"><div class="docx-html">Loading preview…</div></div>'
                                                        )
                                                  . '</div>'
                                              . '</div>'
                                              . '</div>';
                                $rendered = true;
                            }
                        }

                        // Drive link or URL stored in drive_link or submission_text
                        $link = '';
                        if (!$rendered) {
                            if (!empty($driveLink) && filter_var($driveLink, FILTER_VALIDATE_URL)) { $link = $driveLink; }
                            elseif (!empty($submissionText) && filter_var($submissionText, FILTER_VALIDATE_URL)) { $link = $submissionText; }
                        }
                        if (!$rendered && $link !== '') {
                            echo '<div class="preview-block">'
                                . '<div>External Link:</div>'
                                . '<div class="btn-group" style="margin-top:8px;">'
                                . '<a class="btn btn-primary btn-sm" href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener">Open Link</a>'
                                . '</div>'
                                . '<div style="margin-top:6px;color:#374151;word-break:break-all;">' . htmlspecialchars($link) . '</div>'
                                . '</div>';
                            $rendered = true;
                        }

                        // Plain text content fallback
                        if (!$rendered && !empty($textContent)) {
                            echo '<div class="preview-block"><div class="preview-text">' . htmlspecialchars($textContent) . '</div></div>';
                            $rendered = true;
                        }

                        if (!$rendered && !empty($submissionText) && !filter_var($submissionText, FILTER_VALIDATE_URL)) {
                            echo '<div class="preview-block"><div class="preview-text">' . htmlspecialchars($submissionText) . '</div></div>';
                            $rendered = true;
                        }

                        if (!$rendered) {
                            echo '<div class="preview-block">No preview available. Check attachments or links in the submission record.</div>';
                        }
                    ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <h3>No Submission Found</h3>
                    <div class="submission-meta">This learner has no recorded submission for this quest yet.</div>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <div class="section-title">SKILL ASSESSMENT:</div>
            <?php 
                // Display a compact list of involved skills for clarity
                $display_skills = is_array($quest_skills) ? array_map(fn($s) => $s['skill_name'] ?? 'Skill', $quest_skills) : [];
            ?>
            <?php if (!empty($display_skills)): ?>
                <div class="card" style="border-left:4px solid #6366f1;" aria-label="Skills involved in this quest">
                    <div style="font-weight:600;color:#111827;margin-bottom:6px;">Skills involved</div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        <?php foreach ($display_skills as $sn): ?>
                            <span class="chip-skill"><i class="fas fa-lightbulb" aria-hidden="true"></i><?= htmlspecialchars($sn) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php $safe_skills = is_array($quest_skills) ? $quest_skills : []; ?>
            <form method="post" id="assessmentForm">
                <?php foreach ($safe_skills as $skill): ?>
                    <?php 
                    $skill_name = isset($skill['skill_name']) ? (string)$skill['skill_name'] : 'Skill';
                    $tier_level = isset($skill['tier_level']) ? (string)$skill['tier_level'] : 'T2';
                    $base_points = getTierPoints($tier_level);
                    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($skill_name));
                    ?>
                    <div class="skill-assessment" aria-label="Assessment for skill <?= htmlspecialchars($skill_name) ?>">
                        <div class="skill-name">
                            <i class="fas fa-award skill-icon" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($skill_name) ?></span>
                        </div>
                        
                        <div class="tier-info tier-<?= htmlspecialchars($tier_level) ?>">
                            <span class="base-tier">Base Tier: <?= htmlspecialchars($tier_level) ?> - <?= htmlspecialchars(getTierLabel($tier_level)) ?> (<?= $base_points ?> pts)</span>
                        </div>
                        
                        <div class="performance-section">
                            <div class="performance-label">Performance:</div>
                            <select class="performance-select" name="assessments[<?= $skill_name ?>][performance]" id="perf_<?= $safeId ?>" data-skill="<?= $safeId ?>" data-base="<?= $base_points ?>" onchange="onPerfChange(this)">
                                <option value="0.0">Not performed (0%) = 0 pts</option>
                                <option value="0.8">Below Expectations (-20%) = <?= round($base_points*0.8) ?> pts</option>
                                <option value="1.0" selected>Meets Expectations (+0%) = <?= $base_points ?> pts</option>
                                <option value="1.2">Exceeds Expectations (+20%) = <?= round($base_points*1.2) ?> pts</option>
                                <option value="1.4">Exceptional (+40%) = <?= round($base_points*1.4) ?> pts</option>
                            </select>

                            <div class="line">Adjusted: <strong><span class="adjusted-points" id="adj_<?= $safeId ?>"><?= $base_points ?></span> pts</strong></div>
                        </div>
                        
                        <div class="notes-section">
                            <label for="notes_<?= $safeId ?>">Notes:</label>
                            <textarea 
                                name="assessments[<?= $skill_name ?>][notes]" 
                                id="notes_<?= $safeId ?>"
                                class="notes-textarea" 
                                placeholder="Add assessment notes..."
                            ></textarea>
                        </div>
                        
                        <input type="hidden" name="assessments[<?= $skill_name ?>][base_points]" value="<?= $base_points ?>">
                    </div>
                <?php endforeach; ?>
                
                <!-- Removed green Total Points box as requested -->
                
                <div class="form-actions form-actions-right">
                    <button type="submit" name="submit_assessment" class="btn btn-primary" <?= (!$user_id || !$quest_id) ? 'disabled' : '' ?> >
                        <i class="fas fa-trophy"></i> Assessment &amp; XP Points
                    </button>
                </div>
            </form>
                </div><!-- /col-left -->
                <div class="col-right">
                    <div class="card">
                        <h3>Submitter Details</h3>
                        <div style="line-height:1.8;">
                            <?php 
                                $displayName = trim((string)($user['full_name'] ?? ''));
                                if ($displayName === '' && !empty($latestSubmission['employee_id'])) {
                                    // As an extra fallback, try to fetch name by employee_id right here
                                    try {
                                        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE employee_id = ? LIMIT 1");
                                        $stmt->execute([$latestSubmission['employee_id']]);
                                        $displayName = (string)($stmt->fetchColumn() ?: '');
                                    } catch (PDOException $e) {
                                        // ignore
                                    }
                                }
                                if ($displayName === '') { $displayName = 'Unknown User'; }
                            ?>
                            <div><strong>Name:</strong> <?= htmlspecialchars($displayName) ?></div>
                            <div><strong>Employee ID:</strong> <?= htmlspecialchars($user['employee_id'] ?? ($employee_id_param !== '' ? $employee_id_param : ($latestSubmission['employee_id'] ?? ''))) ?></div>
                            <!-- Username column not present in users table; omit from UI to prevent confusion -->
                            <div><strong>Email:</strong> <?= htmlspecialchars($user['email'] ?? '') ?></div>
                        </div>
                    </div>
                    <div class="card">
                        <h3>Submission Info</h3>
                        <?php 
                            $st = is_array($latestSubmission) ? strtolower(trim($latestSubmission['status'] ?? 'pending')) : 'pending';
                            $submittedAt = is_array($latestSubmission) ? ($latestSubmission['submitted_at'] ?? null) : null;
                        ?>
                        <div style="line-height:1.8;">
                            <div><strong>Status:</strong> 
                                <?php if ($st === 'under_review'): ?>Under Review
                                <?php elseif ($st === 'approved'): ?>Reviewed
                                <?php elseif ($st === 'rejected'): ?>Declined
                                <?php else: ?>Pending<?php endif; ?>
                            </div>
                            <div><strong>Submitted:</strong> <?= $submittedAt ? htmlspecialchars(date('M d, Y g:i A', strtotime($submittedAt))) : '—' ?></div>
                            <div><strong>Skills:</strong> <?= is_array($safe_skills) ? count($safe_skills) : 0 ?></div>
                        </div>
                    </div>
                </div><!-- /col-right -->
            </div><!-- /page-grid -->
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.5.1/mammoth.browser.min.js"></script>
    <script>
        function onPerfChange(sel){
            const base = parseFloat(sel.getAttribute('data-base')) || 0;
            const mult = parseFloat(sel.value) || 1.0;
            const skillId = sel.getAttribute('data-skill');
            const adjusted = Math.round(base * mult);
            const adjSpan = document.getElementById('adj_'+skillId);
            if(adjSpan){ adjSpan.textContent = adjusted; }
            updateTotal();
        }

        function updateTotal(){
            // Kept for future use; gracefully no-op if total display is removed
            let total = 0; const breakdown = [];
            document.querySelectorAll('.adjusted-points').forEach(span => {
                const val = parseInt(span.textContent) || 0;
                total += val; breakdown.push(val);
            });
            const tp = document.getElementById('totalPoints');
            const bd = document.getElementById('pointsBreakdown');
            if(tp) tp.textContent = total;
            if(bd) bd.textContent = `(${breakdown.join(' + ')})`;
        }

        // Initialize once DOM is ready
        document.addEventListener('DOMContentLoaded', function(){
            updateTotal();
            const lightbox = GLightbox({
                selector: '.glightbox',
                touchNavigation: true,
                openEffect: 'zoom',
                closeEffect: 'fade',
                plyr: { css: '' }
            });

            // Apply color coding to performance selects
            function applyPerfColor(sel){
                sel.classList.remove('pf-none','pf-below','pf-meets','pf-exceeds','pf-exceptional');
                const v = parseFloat(sel.value);
                if (v === 0) sel.classList.add('pf-none');
                else if (v < 1) sel.classList.add('pf-below');
                else if (v === 1) sel.classList.add('pf-meets');
                else if (v > 1 && v <= 1.2) sel.classList.add('pf-exceeds');
                else sel.classList.add('pf-exceptional');
            }
            document.querySelectorAll('.performance-select').forEach(sel => {
                applyPerfColor(sel);
                sel.addEventListener('change', () => applyPerfColor(sel));
            });

            // Handle Office (docx) previews via Mammoth in an inline lightbox
            document.querySelectorAll('a.office-open').forEach((a) => {
                a.addEventListener('click', async (e) => {
                    e.preventDefault();
                    const fileUrl = a.getAttribute('data-file');
                    const inlineSel = a.getAttribute('data-inline');
                    const title = a.getAttribute('data-title') || 'Document';
                    try {
                        const res = await fetch(fileUrl);
                        const buf = await res.arrayBuffer();
                        const result = await window.mammoth.convertToHtml({ arrayBuffer: buf });
                        const container = document.querySelector(inlineSel + ' .docx-html');
                        if (container) container.innerHTML = result.value || '<em>Empty document</em>';
                    } catch (err) {
                        const container = document.querySelector(inlineSel + ' .docx-html');
                        if (container) container.innerHTML = '<em>Preview failed. Please download the file.</em>';
                        console.error('DOCX preview error:', err);
                    }
                    // Open inline lightbox
                    lightbox.open({ href: inlineSel, type: 'inline', title: title });
                });
            });
        });
    </script>
</body>
</html>