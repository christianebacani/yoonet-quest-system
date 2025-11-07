
<?php
// Use only the new base points and tier names logic (single progression, no quest type)
function getTierBasePoints($tier) {
    // Align tier base points with the rest of the app (T1..T5)
    // These values are used across the UI and skill components.
    // Canonical mapping: T1..T5 => 2, 5, 12, 25, 50
    $points = [1 => 2, 2 => 5, 3 => 12, 4 => 25, 5 => 50];
    $tier = (int)$tier;
    if ($tier < 1 || $tier > 5) $tier = 2;
    return $points[$tier];
}

function getTierLabel($tier) {
    $labels = [1 => 'Beginner', 2 => 'Intermediate', 3 => 'Advanced', 4 => 'Expert', 5 => 'Master'];
    $tier = (int)$tier;
    if ($tier < 1 || $tier > 5) $tier = 2;
    return $labels[$tier];
}

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

// Initialize variables to avoid undefined variable/array key warnings when data is missing
$employeeIdForSubmission = $employee_id_param !== '' ? $employee_id_param : '';
$user = [];
$quest = [];
$latestSubmission = [];
$quest_skills = [];

// Instantiate skill manager helper
try {
    $skillManager = new SkillProgression($pdo);
} catch (Throwable $e) {
    error_log('Failed to initialize SkillProgression: ' . $e->getMessage());
    $skillManager = null;
}

// Load quest, submission and skills from DB when identifiers are provided
try {
    // Prefer explicit submission_id when provided
    if ($submission_id > 0) {
        $stmt = $pdo->prepare("SELECT qs.*, q.title AS quest_title, q.id AS quest_id FROM quest_submissions qs LEFT JOIN quests q ON qs.quest_id = q.id WHERE qs.id = ? LIMIT 1");
        $stmt->execute([$submission_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row)) {
            $latestSubmission = $row;
            $quest_id = (int)($row['quest_id'] ?? $quest_id);
            if (empty($user_id) && !empty($row['user_id'])) { $user_id = (int)$row['user_id']; }
            if (empty($employeeIdForSubmission) && !empty($row['employee_id'])) { $employeeIdForSubmission = $row['employee_id']; }
        }
    }

    // Load quest record when quest_id present
    if ($quest_id > 0 && (empty($quest) || !isset($quest['id']))) {
        $stmt = $pdo->prepare("SELECT * FROM quests WHERE id = ? LIMIT 1");
        $stmt->execute([$quest_id]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($q) { $quest = $q; }
    }

    // If no explicit submission found, try to get latest submission for this quest + user/employee
    if (empty($latestSubmission) && $quest_id > 0 && ($user_id > 0 || $employeeIdForSubmission !== '')) {
        if ($user_id > 0) {
            $stmt = $pdo->prepare("SELECT qs.*, q.title AS quest_title, q.id AS quest_id FROM quest_submissions qs LEFT JOIN quests q ON qs.quest_id = q.id WHERE qs.quest_id = ? AND qs.user_id = ? ORDER BY qs.id DESC LIMIT 1");
            $stmt->execute([$quest_id, $user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT qs.*, q.title AS quest_title, q.id AS quest_id FROM quest_submissions qs LEFT JOIN quests q ON qs.quest_id = q.id WHERE qs.quest_id = ? AND qs.employee_id = ? ORDER BY qs.id DESC LIMIT 1");
            $stmt->execute([$quest_id, $employeeIdForSubmission]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row)) {
            $latestSubmission = $row;
            if (empty($user_id) && !empty($row['user_id'])) { $user_id = (int)$row['user_id']; }
            if (empty($employeeIdForSubmission) && !empty($row['employee_id'])) { $employeeIdForSubmission = $row['employee_id']; }
            if (empty($quest) && !empty($row['quest_id'])) {
                $stmt2 = $pdo->prepare("SELECT * FROM quests WHERE id = ? LIMIT 1");
                $stmt2->execute([(int)$row['quest_id']]);
                $q2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($q2) $quest = $q2;
            }
        }
    }

    // Load user details if available
    if ((empty($user) || !isset($user['full_name'])) && ($user_id > 0 || $employeeIdForSubmission !== '')) {
        if ($user_id > 0) {
            $stmt = $pdo->prepare('SELECT id, full_name, email, employee_id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$user_id]);
        } else {
            $stmt = $pdo->prepare('SELECT id, full_name, email, employee_id FROM users WHERE employee_id = ? LIMIT 1');
            $stmt->execute([$employeeIdForSubmission]);
        }
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) { $user = $u; if (empty($user_id)) $user_id = (int)($u['id'] ?? 0); }
    }

    // Fetch quest skills via skill manager helper
    if ($skillManager && $quest_id > 0) {
        $quest_skills = $skillManager->getQuestSkills($quest_id) ?: [];

        // Normalize quest_skills: ensure each row has skill_name and tier_level
        try {
            $ids = [];
            foreach ((array)$quest_skills as $r) {
                if (empty($r['skill_name']) && !empty($r['skill_id'])) { $ids[] = (int)$r['skill_id']; }
            }
            $nameMap = [];
            if (!empty($ids)) {
                $ids = array_values(array_unique(array_filter($ids)));
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, skill_name FROM comprehensive_skills WHERE id IN ($ph)");
                $stmt->execute($ids);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $nameMap[(int)$r['id']] = (string)$r['skill_name']; }
            }

            $normalized = [];
            foreach ((array)$quest_skills as $r) {
                $name = '';
                if (!empty($r['skill_name'])) { $name = (string)$r['skill_name']; }
                elseif (!empty($r['skill_id']) && isset($nameMap[(int)$r['skill_id']])) { $name = $nameMap[(int)$r['skill_id']]; }
                if ($name === '') { continue; }

                // Normalize tier level to T1..T5
                $tier = 'T2';
                $tierDetected = false;
                if (isset($r['tier_level'])) {
                    $tv = $r['tier_level'];
                    if (is_numeric($tv)) { $t = (int)$tv; if ($t < 1) $t = 1; if ($t > 5) $t = 5; $tier = 'T'.$t; $tierDetected = true; }
                    elseif (is_string($tv) && preg_match('~^T[1-5]$~', $tv)) { $tier = $tv; $tierDetected = true; }
                } elseif (isset($r['tier'])) {
                    $t = (int)$r['tier']; if ($t < 1) $t = 1; if ($t > 5) $t = 5; $tier = 'T'.$t; $tierDetected = true;
                } elseif (isset($r['required_level'])) {
                    // Accept a wider set of labels (including 'master') and be forgiving on case/whitespace
                    $map = ['beginner'=>'T1','intermediate'=>'T2','advanced'=>'T3','expert'=>'T4','master'=>'T5'];
                    $rl = strtolower(trim((string)$r['required_level'])); if (isset($map[$rl])) { $tier = $map[$rl]; $tierDetected = true; }
                }

                // Fallback: if no explicit tier info was detected, derive tier from base_points when available
                if (!$tierDetected) {
                    $bp = 0;
                    if (isset($r['base_points'])) { $bp = (int)$r['base_points']; }
                    elseif (isset($r['basepoints'])) { $bp = (int)$r['basepoints']; }
                    elseif (isset($r['base'])) { $bp = (int)$r['base']; }
                    if ($bp > 0) {
                        // find closest tier by comparing to getTierBasePoints
                        $bestT = 2; $bestDiff = PHP_INT_MAX;
                        for ($tt = 1; $tt <= 5; $tt++) {
                            $tp = getTierBasePoints($tt);
                            $diff = abs($tp - $bp);
                            if ($diff < $bestDiff) { $bestDiff = $diff; $bestT = $tt; }
                        }
                        $tier = 'T' . $bestT;
                    }
                }

                $normalized[] = array_merge($r, ['skill_name' => $name, 'tier_level' => $tier]);
            }
            $quest_skills = $normalized;
        } catch (Throwable $e) {
            error_log('quest_assessment skill normalization failed: ' . $e->getMessage());
        }
    }

} catch (PDOException $e) {
    error_log('quest_assessment data load failed: ' . $e->getMessage());
}

// Ensure required tables exist to prevent submission errors on fresh databases
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_earned_skills` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `skill_name` varchar(255) NOT NULL,
        `total_points` int(11) NOT NULL DEFAULT 0,
        `current_level` int(11) NOT NULL DEFAULT 1,
        `current_stage` enum('Learning','Applying','Mastering','Innovating') NOT NULL DEFAULT 'Learning',
        `last_used` timestamp NULL DEFAULT NULL,
        `recent_points` int(11) NOT NULL DEFAULT 0,
        `status` enum('ACTIVE','STALE','RUSTY') NOT NULL DEFAULT 'ACTIVE',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_user_skill_name` (`user_id`,`skill_name`),
        KEY `idx_user` (`user_id`),
        KEY `idx_skill_name` (`skill_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `quest_completions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `quest_id` int(11) NOT NULL,
        `user_id` int(11) NOT NULL,
        `completed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `total_points_awarded` int(11) NOT NULL DEFAULT 0,
        `notes` text,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_user_quest` (`quest_id`,`user_id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_quest` (`quest_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    // Store per-skill assessment results for each submission
    $pdo->exec("CREATE TABLE IF NOT EXISTS `quest_assessment_details` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `quest_id` int(11) NOT NULL,
        `user_id` int(11) NOT NULL,
        `submission_id` int(11) NOT NULL,
        `skill_name` varchar(255) NOT NULL,
        `base_points` int(11) NOT NULL DEFAULT 0,
        `performance_multiplier` decimal(4,2) NOT NULL DEFAULT 1.00,
        `performance_label` varchar(50) DEFAULT NULL,
        `adjusted_points` int(11) NOT NULL DEFAULT 0,
        `notes` text,
        `reviewed_by` varchar(50) DEFAULT NULL,
        `reviewed_at` timestamp NULL DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_submission_skill` (`submission_id`, `skill_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Throwable $e) {
    // Continue; if permissions prevent DDL, later operations may still succeed if tables already exist
}

// Try to resolve missing identifiers gracefully before redirecting
if ($quest_id && !$user_id) {
    try {
        if ($submission_id > 0) {
            $stmt = $pdo->prepare("SELECT u.id AS user_id, qs.quest_id, u.employee_id FROM quest_submissions qs LEFT JOIN users u ON qs.employee_id = u.employee_id WHERE qs.id = ?");
            $stmt->execute([$submission_id]);
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($fetched) && !empty($fetched)) {
                $user = $fetched;
                if (empty($user_id) && isset($user['user_id'])) { $user_id = (int)$user['user_id']; }
                if (empty($employeeIdForSubmission) && !empty($user['employee_id'])) { $employeeIdForSubmission = $user['employee_id']; }
            }
        }
    } catch (PDOException $e) {
        error_log('user resolve by employee_id failed: ' . $e->getMessage());
    }
}

// Handle form submission (grading)
if (empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {
    // Prevent grading if already graded/declined
    $currentStatus = strtolower(trim((string)($latestSubmission['status'] ?? '')));
    if (in_array($currentStatus, ['approved','rejected'], true)) {
        $error = 'This submission has already been graded.';
    } elseif (!$latestSubmission || empty($latestSubmission['id'])) {
        $error = 'No submission record found to grade.';
    }
}

if (empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {
    $assessments = $_POST['assessments'] ?? [];
    $total_points = 0;
    $awarded_skills = [];
    $currentSubmissionId = (int)($latestSubmission['id'] ?? 0);
    
    try {
        $pdo->beginTransaction();
        
        // Breadth factor + tier multiplier (Bundle 2)
        $totalSkillsAssessed = 0;
        foreach ($assessments as $assessment) {
            $bp = (int)($assessment['base_points'] ?? 0);
            $perf = (float)($assessment['performance'] ?? 0);
            if ($bp > 0 && $perf > 0) { $totalSkillsAssessed++; }
        }
        $breadthFactor = 1.0;
        if ($totalSkillsAssessed === 3) $breadthFactor = 0.90;
        elseif ($totalSkillsAssessed === 4) $breadthFactor = 0.75;
        elseif ($totalSkillsAssessed >= 5) $breadthFactor = 0.60;

        // Optional tier multipliers by detecting tier markers in skill name (e.g., "(T3)") or extend schema later
        $tierMultipliers = [1 => 0.85, 2 => 0.95, 3 => 1.00, 4 => 1.15, 5 => 1.30];
        $tierPattern = '/\(T([1-5])\)$/';

        foreach ($assessments as $skill_name => $assessment) {
            $performance = (float)($assessment['performance'] ?? 1.0);
            $base_points_raw = (int)($assessment['base_points'] ?? 0);
            $notes = sanitize_input($assessment['notes'] ?? '');

            // Use base_points_raw directly (already dynamically set by quest type/tier)
            $base_points = (int)round($base_points_raw * $breadthFactor);

            // Skip zero-point awards (e.g., Not performed)
            if ($base_points <= 0 || $performance <= 0) { continue; }

            // Award skill points
            $result = $skillManager->awardSkillPoints($user_id, $skill_name, $base_points, $performance);
            if (!($result['success'] ?? false)) {
                throw new Exception('Failed to award points for ' . $skill_name . ': ' . ($result['error'] ?? 'unknown error'));
            }

            $awarded_skills[] = [
                'skill' => $skill_name,
                'points' => $result['points_awarded'],
                'level' => $result['new_level'],
                'stage' => $result['new_stage']
            ];
            $total_points += $result['points_awarded'];

            // Save detailed assessment row for this skill
            try {
                $label = 'Meets Expectations';
                if ($performance == 0.0) $label = 'Not performed';
                elseif ($performance < 1.0) $label = 'Below Expectations';
                elseif ($performance > 1.0 && $performance <= 1.2) $label = 'Exceeds Expectations';
                elseif ($performance > 1.2) $label = 'Exceptional';

                $reviewedBy = $_SESSION['employee_id'] ?? (string)($_SESSION['user_id'] ?? '');
                $stmt = $pdo->prepare("INSERT INTO quest_assessment_details 
                    (quest_id, user_id, submission_id, skill_name, base_points, performance_multiplier, performance_label, adjusted_points, notes, reviewed_by, reviewed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        base_points = VALUES(base_points),
                        performance_multiplier = VALUES(performance_multiplier),
                        performance_label = VALUES(performance_label),
                        adjusted_points = VALUES(adjusted_points),
                        notes = VALUES(notes),
                        reviewed_by = VALUES(reviewed_by),
                        reviewed_at = VALUES(reviewed_at)");
                $stmt->execute([
                    $quest_id,
                    $user_id,
                    $currentSubmissionId,
                    $skill_name,
                    $base_points_raw, /* store original base */
                    $performance,
                    $label,
                    (int)round($base_points * $performance), /* adjusted_points reflects modified base */
                    $notes,
                    $reviewedBy,
                ]);
            } catch (PDOException $e) {
                error_log('Failed to save assessment detail: ' . $e->getMessage());
            }
        }
        
        // Mark quest as completed for this user (robust upsert without requiring unique index)
        $stmt = $pdo->prepare("SELECT id FROM quest_completions WHERE quest_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$quest_id, $user_id]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) {
            $stmt = $pdo->prepare("UPDATE quest_completions SET completed_at = NOW(), total_points_awarded = ?, notes = ? WHERE id = ?");
            $stmt->execute([$total_points, '', $existingId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO quest_completions (quest_id, user_id, completed_at, total_points_awarded, notes) VALUES (?, ?, NOW(), ?, ?)");
            $stmt->execute([$quest_id, $user_id, $total_points, '']);
        }
        
        // Mark the latest submission as graded/approved and stamp reviewer
        try {
            $reviewedBy = $_SESSION['employee_id'] ?? (string)($_SESSION['user_id'] ?? '');
            $stmt = $pdo->prepare("UPDATE quest_submissions SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$reviewedBy, (int)$latestSubmission['id']]);
        } catch (PDOException $e) {
            error_log('Failed to update submission status to approved: ' . $e->getMessage());
            throw new Exception('Failed to update submission status.');
        }

        // Best-effort: mark assignment record completed
        try {
            if (!empty($employeeIdForSubmission)) {
                $stmt = $pdo->prepare("UPDATE user_quests SET status = 'completed', completed_at = NOW() WHERE employee_id = ? AND quest_id = ? AND (status <> 'completed' OR status IS NULL)");
                $stmt->execute([$employeeIdForSubmission, $quest_id]);
            }
        } catch (PDOException $e) {
            error_log('Failed to update user_quests to completed: ' . $e->getMessage());
            // non-fatal
        }

    $pdo->commit();
        $success = "Grading completed! Total points awarded: {$total_points}";

        // Redirect to prevent resubmission; keep context for view-only state
    $redir = "quest_assessment.php?quest_id={$quest_id}&user_id={$user_id}&success=1";
    if (!empty($currentSubmissionId)) { $redir .= "&submission_id=" . (int)$currentSubmissionId; }
    header("Location: $redir");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error submitting assessment: " . $e->getMessage());
    $error = "Failed to grade submission. Please try again.";
    }
}

if (isset($_GET['success'])) {
    $success = "Quest assessment completed successfully!";
}

// Calculate tier points
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
    /* Let modal-body be the only scroll container to avoid double scrollbars */
    .docx-view { background:#ffffff; max-height:none; overflow:visible; padding:16px; border-radius:8px; }
    .docx-view .docx-html { color:#111827; }
    .docx-view .docx-html img { max-width:100%; height:auto; }

    /* Hide GLightbox bottom caption/description to avoid duplicate filenames */
    .gdesc, .gdesc-inner, .gslide-desc { display:none !important; }

    /* Ensure inline slides have a white canvas so content isn't perceived as black */
    .glightbox-container .gslide-inline .ginner { background:#ffffff; border-radius:8px; }
    .glightbox-container .gslide iframe { background:#ffffff; }
    .file-caption { color:#374151; font-size:0.9rem; margin:6px 0 0 0; word-break: break-all; }

    /* Preview container styles to align with view_submission */
    .preview-container { background:#fff; border-radius:10px; overflow:hidden; }
    .preview-header { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
    .preview-title { display:flex; align-items:center; gap:8px; font-weight:700; color:#111827; }
    .badge-file { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:9999px; font-size:12px; font-weight:700; border:1px solid transparent; }
    .badge-docx { background:#DBEAFE; color:#1E40AF; border-color:#93C5FD; }
    .badge-pdf  { background:#FEE2E2; color:#991B1B; border-color:#FCA5A5; }
    .badge-img  { background:#DCFCE7; color:#065F46; border-color:#86EFAC; }
    .badge-text { background:#E5E7EB; color:#111827; border-color:#D1D5DB; }
    .badge-link { background:#EDE9FE; color:#5B21B6; border-color:#C4B5FD; }
    .badge-other{ background:#E0E7FF; color:#3730A3; border-color:#C7D2FE; }
    .preview-body { padding:12px; }
    .preview-body iframe { width:100%; border:0; min-height:70vh; }

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
    <style>
        /* Reward pulse animation used on successful grading */
        @keyframes rewardPulse {
            0% { transform: scale(1); opacity: 1; }
            30% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }
        .pulse-animate { animation: rewardPulse 900ms cubic-bezier(.2,.9,.2,1); box-shadow: 0 10px 30px rgba(99,102,241,0.18); }

        /* Simple confetti particle */
        .confetti-piece {
            position: absolute;
            width: 10px;
            height: 14px;
            opacity: 0.95;
            will-change: transform, opacity;
            border-radius: 2px;
        }
        @keyframes confettiFall {
            0% { transform: translateY(-10vh) rotate(0deg); opacity: 1; }
            100% { transform: translateY(110vh) rotate(720deg); opacity: 0.6; }
        }
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
                    elseif ($status === 'approved') { $badgeClass = 'badge badge-approved'; $badgeLabel = 'Graded'; }
                    elseif ($status === 'rejected') { $badgeClass = 'badge badge-rejected'; $badgeLabel = 'Declined'; }
                ?>
                <div id="submissionPreview">
                <div class="card">
                    <div class="submission-header">
                        <div>
                            <div class="submission-title">Submission</div>
                            <div class="submission-meta">Submitted: <?= htmlspecialchars($when) ?></div>
                        </div>
                        <span class="<?= $badgeClass ?>"><?= $badgeLabel ?></span>
                    </div>

                    <?php
                        // Detect client_support quests early so we can suppress the top file preview
                        $isClientSupport = (isset($quest['display_type']) && $quest['display_type'] === 'client_support');

                        if (!$isClientSupport) {
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
                            $rel = ltrim((string)$rel, '/');
                            $prefix = trim($base, '/');
                            $path = $prefix !== '' ? ($prefix . '/' . $rel) : $rel;
                            return $scheme . '://' . $host . '/' . $path;
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
                                              . '<div class="btn-group" style="display:flex; gap:8px; flex-wrap:wrap;">'
                                              . '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineId . '" data-type="inline">Open</a>'
                                              . '<a class="btn btn-secondary btn-sm view-newtab" href="' . htmlspecialchars($abs) . '" data-abs="' . htmlspecialchars($abs) . '" data-ext="' . $ext . '" target="_blank" rel="noopener">View in new tab</a>'
                                              . '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($src) . '" download>Download</a>'
                                              . '</div>'
                                              . '<div class="glightbox-inline" id="' . $inlineId . '">'
                                                  . '<div class="preview-container">'
                                                      . '<div class="preview-header"><div class="preview-title"><span class="badge-file badge-img">IMG</span><span>' . $title . '</span></div></div>'
                                                      . '<div class="preview-body"><img style="max-width:100%;max-height:75vh;height:auto;display:block;margin:0 auto;background:#fff;" src="' . htmlspecialchars($abs) . '" alt="' . $title . '"/></div>'
                                                  . '</div>'
                                              . '</div>'
                                              . '</div>';
                                $rendered = true;
                            } elseif ($ext === 'pdf') {
                                          $title = $fname;
                                          $inlineId = 'inline-'.md5($web);
                                          echo '<div class="preview-block">'
                                              . '<div class="btn-group" style="display:flex; gap:8px; flex-wrap:wrap;">'
                                              . '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineId . '" data-type="inline">Open</a>'
                                              . '<a class="btn btn-secondary btn-sm view-newtab" href="' . htmlspecialchars($abs) . '" data-abs="' . htmlspecialchars($abs) . '" data-ext="' . $ext . '" target="_blank" rel="noopener">View in new tab</a>'
                                              . '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($src) . '" download>Download</a>'
                                              . '</div>'
                                              . '<div class="glightbox-inline" id="' . $inlineId . '">'
                                                  . '<div class="preview-container">'
                                                      . '<div class="preview-header"><div class="preview-title"><span class="badge-file badge-pdf">PDF</span><span>' . $title . '</span></div></div>'
                                                      . '<div class="preview-body"><iframe src="' . htmlspecialchars($abs) . '"></iframe></div>'
                                                  . '</div>'
                                              . '</div>'
                                              . '</div>';
                                $rendered = true;
                                     } elseif (in_array($ext, ['txt','md','csv'])) {
                                          $inlineId = 'inline-'.md5($web);
                                          $title = $fname;
                                          echo '<div class="preview-block">'
                                              . '<div class="glightbox-inline" id="' . $inlineId . '">'
                                                  . '<div class="preview-container">'
                                                      . '<div class="preview-header"><div class="preview-title"><span class="badge-file badge-text">TEXT</span><span>' . $title . '</span></div></div>'
                                                      . '<div class="preview-body"><div class="docx-view"><div class="docx-html">' . htmlspecialchars(@file_get_contents($web)) . '</div></div></div>'
                                                  . '</div>'
                                              . '</div>'
                                              . '<div class="btn-group" style="display:flex; gap:8px; flex-wrap:wrap;">'
                                              . '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineId . '" data-gallery="submission" data-type="inline">Open</a>'
                                              . '<a class="btn btn-secondary btn-sm view-newtab" href="' . htmlspecialchars($abs) . '" data-abs="' . htmlspecialchars($abs) . '" data-ext="' . $ext . '" target="_blank" rel="noopener">View in new tab</a>'
                                              . '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($src) . '" download>Download</a>'
                                              . '</div>'
                                              . '</div>';
                                $rendered = true;
                            } else {
                                // For Office files: DOCX uses Mammoth inline; others use Google Viewer when public
                                $inlineId = 'inline-'.md5($web);
                                $title = $fname;
                                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                $isLocal = (stripos($host, 'localhost') !== false) || (stripos($host, '127.0.0.1') !== false);
                                $gview = 'https://docs.google.com/gview?embedded=1&url=' . rawurlencode($abs);

                                echo '<div class="preview-block">'
                                    . '<div class="btn-group" style="display:flex; gap:8px; flex-wrap:wrap;">'
                                    . (
                                        $ext === 'docx'
                                        ? '<a class="btn btn-primary btn-sm glightbox office-open" href="#' . $inlineId . '" data-type="inline" role="button" tabindex="0" data-file="' . htmlspecialchars($abs) . '" data-inline="#' . $inlineId . '" data-title="' . $title . '">Open</a>'
                                        : '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineId . '" data-type="inline">Open</a>'
                                    )
                                    . '<a class="btn btn-secondary btn-sm view-newtab" href="' . htmlspecialchars($abs) . '" data-abs="' . htmlspecialchars($abs) . '" data-gview="' . htmlspecialchars($gview) . '" data-ext="' . $ext . '" target="_blank" rel="noopener">View in new tab</a>'
                                    . '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($src) . '" download>Download</a>'
                                    . '</div>'
                                    . '<div class="glightbox-inline" id="' . $inlineId . '">'
                                        . '<div class="preview-container">'
                                            . '<div class="preview-header"><div class="preview-title"><span class="badge-file ' . (in_array($ext, ['xls','xlsx']) ? 'badge-other' : (in_array($ext, ['ppt','pptx']) ? 'badge-other' : 'badge-docx')) . '">' . strtoupper($ext) . '</span><span>' . $title . '</span></div></div>'
                                            . '<div class="preview-body" style="min-height:70vh; background:#fff;">'
                                                . (
                                                    $ext === 'docx'
                                                    ? '<div class="docx-view"><div class="docx-html">Loading preview…</div></div>'
                                                    : (
                                                        !$isLocal
                                                        ? '<iframe src="' . htmlspecialchars($gview) . '" height="640"></iframe>'
                                                        : '<div style="padding:16px;line-height:1.6;">Preview for this file type is not available on localhost.<br><a class="btn btn-secondary btn-sm" href="' . htmlspecialchars($abs) . '" target="_blank" rel="noopener">Open in new tab</a></div>'
                                                    )
                                                )
                                            . '</div>'
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
                            $stype = isset($latestSubmission['submission_type']) ? strtolower(trim((string)$latestSubmission['submission_type'])) : '';
                            // If submission was saved as a link (gdrive), show the raw link instead of file action buttons
                            if ($stype === 'link') {
                                echo '<div class="preview-block">'
                                    . '<div style="margin-bottom:8px;color:#374151;word-break:break-all;">'
                                    . htmlspecialchars($link)
                                    . '</div>'
                                    . '</div>';
                                $rendered = true;
                            } elseif ($stype === 'text') {
                                // If submission was saved as text, render the text content directly
                                $txt = $textContent ?: ($submissionText ?? '');
                                echo '<div class="preview-block"><div class="preview-text">' . htmlspecialchars($txt) . '</div></div>';
                                $rendered = true;
                            } else {
                                // default: render as file-like link/buttons
                                // Friendly filename/title for URLs (use path basename or host)
                                $linkPath = parse_url($link, PHP_URL_PATH) ?: '';
                                $linkName = $linkPath !== '' ? basename($linkPath) : '';
                                if ($linkName === '' || $linkName === '/') { $linkName = parse_url($link, PHP_URL_HOST) ?: $link; }
                                $lnExt = strtolower(pathinfo($linkName, PATHINFO_EXTENSION));
                                $inlineId = 'inline-'.md5($link);

                                echo '<div class="preview-block">'
                                    . '<div class="btn-group" style="display:flex; gap:8px; flex-wrap:wrap;">'
                                    . '<a class="btn btn-primary btn-sm" href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener">Open</a>'
                                    . '<a class="btn btn-secondary btn-sm view-newtab" href="' . htmlspecialchars($link) . '" data-abs="' . htmlspecialchars($link) . '" data-ext="' . htmlspecialchars($lnExt) . '" target="_blank" rel="noopener">View in new tab</a>'
                                    . '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($link) . '" download>Download</a>'
                                    . '</div>'
                                    . '<div class="glightbox-inline" id="' . $inlineId . '">'
                                        . '<div class="preview-container">'
                                            . '<div class="preview-header"><div class="preview-title"><span class="badge-file ' . (in_array($lnExt, ['pdf']) ? 'badge-pdf' : (in_array($lnExt, ['jpg','jpeg','png','gif','webp']) ? 'badge-img' : 'badge-link')) . '">' . htmlspecialchars(strtoupper($lnExt ?: 'LNK')) . '</span><span>' . htmlspecialchars($linkName) . '</span></div></div>'
                                            . '<div class="preview-body" style="padding:12px;">'
                                                . '<div style="margin-bottom:8px;color:#374151;word-break:break-all;">' . htmlspecialchars($link) . '</div>'
                                                . '<div><a class="btn btn-secondary btn-sm" href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener">Open in new tab</a></div>'
                                            . '</div>'
                                        . '</div>'
                                    . '</div>'
                                . '</div>';
                                $rendered = true;
                            }
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

                        }

                        // If this quest is client_support, render client/support specific submitted fields
                        // Safely detect if any client-related fields exist without triggering undefined-key notices
                        $hasClientFields = false;
                        $clientKeys = ['ticket_reference','ticket_id','ticket','action_taken','time_spent','time_spent_hours','time_spent_hrs','evidence_json','evidence','resolution_status','follow_up_required','follow_up','comments'];
                        foreach ($clientKeys as $k) {
                            if (!empty($latestSubmission[$k] ?? null)) { $hasClientFields = true; break; }
                        }

                        if ($isClientSupport || $hasClientFields) {
                            $ticket = $latestSubmission['ticket_reference'] ?? $latestSubmission['ticket_id'] ?? $latestSubmission['ticket'] ?? '';
                            $action_taken = $latestSubmission['action_taken'] ?? $latestSubmission['text_content'] ?? $latestSubmission['text'] ?? '';
                            $time_spent = $latestSubmission['time_spent'] ?? $latestSubmission['time_spent_hours'] ?? $latestSubmission['time_spent_hrs'] ?? '';
                            $resolution_status = $latestSubmission['resolution_status'] ?? '';
                            $follow_up_raw = $latestSubmission['follow_up_required'] ?? $latestSubmission['follow_up'] ?? '';

                            // Clean comments similar to view_submission
                            $clean_comments = '';
                            if (!empty($latestSubmission['comments'] ?? null)) {
                                $clean_comments = (string)$latestSubmission['comments'];
                                $clean_comments = preg_replace("/\r\n|\r/", "\n", $clean_comments);
                                $lines = preg_split('/\n/', $clean_comments);
                                $lines = array_map('rtrim', $lines);
                                while (count($lines) && $lines[0] === '') { array_shift($lines); }
                                while (count($lines) && end($lines) === '') { array_pop($lines); }
                                $out = [];
                                $prevEmpty = false;
                                foreach ($lines as $ln) {
                                    if ($ln === '') {
                                        if (!$prevEmpty) { $out[] = ''; $prevEmpty = true; }
                                    } else {
                                        $out[] = $ln; $prevEmpty = false;
                                    }
                                }
                                $clean_comments = implode("\n", $out);
                                $clean_comments = trim($clean_comments);
                            }

                            // Evidence: support multiple storage formats
                            $evidence_list = [];
                            if (!empty($latestSubmission['evidence_json'] ?? null)) {
                                $tmp = json_decode($latestSubmission['evidence_json'], true);
                                if (is_array($tmp)) $evidence_list = $tmp;
                            } elseif (!empty($latestSubmission['evidence'] ?? null)) {
                                $evraw = $latestSubmission['evidence'];
                                $tmp = json_decode($evraw, true);
                                if (is_array($tmp)) $evidence_list = $tmp;
                                else $evidence_list = array_filter(array_map('trim', explode(',', $evraw)));
                            }

                            echo '<div style="margin-top:12px;">';
                            echo '<div class="section-title">Client & Support Details</div>';
                            echo '<div class="card" style="padding:14px;">';
                            echo '<dl style="display:grid;grid-template-columns:200px 1fr;gap:10px 18px;align-items:start;margin:0;">';

                            // Ticket / Reference
                            echo '<dt style="color:#6b7280;font-weight:700;">Ticket / Reference ID</dt>';
                            echo '<dd style="margin:0;color:#111827;">' . ($ticket !== '' ? htmlspecialchars($ticket) : '<span style="color:#9CA3AF;font-style:italic;">— not provided —</span>') . '</dd>';

                            // Action Taken
                            echo '<dt style="color:#6b7280;font-weight:700;">Action Taken / Resolution (required)</dt>';
                            echo '<dd style="margin:0;color:#111827;">';
                            if (!empty($action_taken)) {
                                echo '<div style="background:#fff;border:1px solid #e5e7eb;padding:10px;border-radius:8px;color:#111827;white-space:pre-wrap;">' . nl2br(htmlspecialchars($action_taken)) . '</div>';
                            } else {
                                echo '<span style="color:#9CA3AF;font-style:italic;">— not provided —</span>';
                            }
                            echo '</dd>';

                            // Time Spent
                            echo '<dt style="color:#6b7280;font-weight:700;">Time Spent (hours)</dt>';
                            echo '<dd style="margin:0;color:#111827;">' . ($time_spent !== '' ? htmlspecialchars($time_spent) : '<span style="color:#9CA3AF;font-style:italic;">— not provided —</span>') . '</dd>';

                            // Evidence / Attachments as checked disabled checkboxes
                            echo '<dt style="color:#6b7280;font-weight:700;">Evidence / Attachments</dt>';
                            echo '<dd style="margin:0;color:#111827;">';
                            if (!empty($evidence_list)) {
                                echo '<div style="display:flex;flex-direction:column;gap:8px;">';
                                foreach ($evidence_list as $ev) {
                                    $ev_trim = trim((string)$ev);
                                    if ($ev_trim === '') continue;
                                    $isUrl = filter_var($ev_trim, FILTER_VALIDATE_URL);
                                    $label = $isUrl ? $ev_trim : $ev_trim;
                                    $esc = htmlspecialchars($label);
                                    echo '<label style="display:flex;align-items:center;gap:10px;padding:6px 8px;border-radius:8px;background:#f8fafc;border:1px solid #e5e7eb;">';
                                    echo '<input type="checkbox" checked disabled style="width:16px;height:16px;" />';
                                    if ($isUrl) {
                                        echo '<a href="' . htmlspecialchars($ev_trim) . '" target="_blank" rel="noopener" style="color:#111827;text-decoration:underline;">' . $esc . '</a>';
                                    } else {
                                        echo '<span style="color:#111827;">' . $esc . '</span>';
                                    }
                                    echo '</label>';
                                }
                                echo '</div>';
                            } else {
                                echo '<span style="color:#9CA3AF;font-style:italic;">— none provided —</span>';
                            }
                            echo '</dd>';

                            // Upload supporting file (reuse file preview if available)
                            echo '<dt style="color:#6b7280;font-weight:700;">Upload supporting file</dt>';
                            echo '<dd style="margin:0;color:#111827;">';
                            if (!empty($filePath)) {
                                // Compute an absolute URL to the file (best-effort). If the top preview logic ran earlier
                                // the variables $abs and $inlineId may already exist; otherwise compute them here.
                                $absVal = '';
                                // If filePath is already a URL, use it
                                if (preg_match('~^https?://~i', $filePath)) {
                                    $absVal = $filePath;
                                } else {
                                    // Try to resolve relative paths against SCRIPT_NAME and DOCUMENT_ROOT
                                    $rel = ltrim((string)$filePath, '/');
                                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                                    $prefix = trim($base, '/');
                                    $path = $prefix !== '' ? ($prefix . '/' . $rel) : $rel;
                                    $absVal = $scheme . '://' . $host . '/' . $path;
                                }

                                // Friendly filename and extension
                                $displayFileName = basename(parse_url($absVal, PHP_URL_PATH) ?: $filePath);
                                $displayFileType = strtolower(pathinfo($displayFileName, PATHINFO_EXTENSION));
                                $ext = $displayFileType;
                                $inlineIdLocal = 'inline-' . md5($absVal);

                                // Offer Open / View in new tab / Download controls. Also render an inline preview block
                                echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
                                echo '<div class="file-pill" style="padding:6px 10px;">' . htmlspecialchars($displayFileName) . ($displayFileType ? ' <span style="margin-left:8px;font-weight:700;color:#111827;">• ' . htmlspecialchars(strtoupper($displayFileType)) . '</span>' : '') . '</div>';
                                echo '<div style="display:flex;gap:8px;align-items:center;">';

                                // Determine which Open behavior to provide
                                if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                                    echo '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineIdLocal . '" data-type="inline">Open</a>';
                                    echo '<a class="btn btn-secondary btn-sm view-newtab" href="' . htmlspecialchars($absVal) . '" data-abs="' . htmlspecialchars($absVal) . '" data-ext="' . htmlspecialchars($ext) . '" target="_blank" rel="noopener">View in new tab</a>';
                                    echo '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($absVal) . '" download>Download</a>';

                                    // Inline image preview
                                    echo '<div class="glightbox-inline" id="' . $inlineIdLocal . '">'
                                        . '<div class="preview-container">'
                                            . '<div class="preview-header"><div class="preview-title"><span class="badge-file badge-img">IMG</span><span>' . htmlspecialchars($displayFileName) . '</span></div></div>'
                                            . '<div class="preview-body"><img style="max-width:100%;max-height:75vh;height:auto;display:block;margin:0 auto;background:#fff;" src="' . htmlspecialchars($absVal) . '" alt="' . htmlspecialchars($displayFileName) . '"/></div>'
                                        . '</div>'
                                    . '</div>';

                                } elseif ($ext === 'pdf') {
                                    echo '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineIdLocal . '" data-type="inline">Open</a>';
                                    echo '<a class="btn btn-secondary btn-sm view-newtab" href="' . htmlspecialchars($absVal) . '" data-abs="' . htmlspecialchars($absVal) . '" data-ext="' . htmlspecialchars($ext) . '" target="_blank" rel="noopener">View in new tab</a>';
                                    echo '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($absVal) . '" download>Download</a>';

                                    echo '<div class="glightbox-inline" id="' . $inlineIdLocal . '">'
                                        . '<div class="preview-container">'
                                            . '<div class="preview-header"><div class="preview-title"><span class="badge-file badge-pdf">PDF</span><span>' . htmlspecialchars($displayFileName) . '</span></div></div>'
                                            . '<div class="preview-body"><iframe src="' . htmlspecialchars($absVal) . '"></iframe></div>'
                                        . '</div>'
                                    . '</div>';

                                } elseif (in_array($ext, ['txt','md','csv'])) {
                                    echo '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineIdLocal . '" data-type="inline">Open</a>';
                                    echo '<a class="btn btn-secondary btn-sm view-newtab" href="' . htmlspecialchars($absVal) . '" data-abs="' . htmlspecialchars($absVal) . '" data-ext="' . htmlspecialchars($ext) . '" target="_blank" rel="noopener">View in new tab</a>';
                                    echo '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($absVal) . '" download>Download</a>';

                                    $content = '';
                                    // Try to read text content server-side when possible
                                    try { $content = @file_get_contents($filePath) ?: @file_get_contents($absVal); } catch (Throwable $e) { $content = ''; }
                                    $safeContent = htmlspecialchars((string)$content);
                                    echo '<div class="glightbox-inline" id="' . $inlineIdLocal . '">'
                                        . '<div class="preview-container">'
                                            . '<div class="preview-header"><div class="preview-title"><span class="badge-file badge-text">TEXT</span><span>' . htmlspecialchars($displayFileName) . '</span></div></div>'
                                            . '<div class="preview-body"><div class="docx-view"><div class="docx-html">' . $safeContent . '</div></div></div>'
                                        . '</div>'
                                    . '</div>';

                                } else {
                                    // Office and other types — offer Open (docx inline if possible), View, Download
                                    $gview = 'https://docs.google.com/gview?embedded=1&url=' . rawurlencode($absVal);
                                    $officeOpen = ($ext === 'docx');
                                    echo ($officeOpen ? '<a class="btn btn-primary btn-sm glightbox office-open" href="#' . $inlineIdLocal . '" data-type="inline" data-file="' . htmlspecialchars($absVal) . '" data-inline="#' . $inlineIdLocal . '">Open</a>' : '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineIdLocal . '" data-type="inline">Open</a>');
                                    echo '<a class="btn btn-secondary btn-sm view-newtab" href="' . htmlspecialchars($absVal) . '" data-abs="' . htmlspecialchars($absVal) . '" data-gview="' . htmlspecialchars($gview) . '" data-ext="' . htmlspecialchars($ext) . '" target="_blank" rel="noopener">View in new tab</a>';
                                    echo '<a class="btn btn-outline-primary btn-sm" href="' . htmlspecialchars($absVal) . '" download>Download</a>';

                                    echo '<div class="glightbox-inline" id="' . $inlineIdLocal . '">'
                                        . '<div class="preview-container">'
                                            . '<div class="preview-header"><div class="preview-title"><span class="badge-file ' . (in_array($ext, ['xls','xlsx']) ? 'badge-other' : (in_array($ext, ['ppt','pptx']) ? 'badge-other' : 'badge-docx')) . '">' . htmlspecialchars(strtoupper($ext ?: 'FILE')) . '</span><span>' . htmlspecialchars($displayFileName) . '</span></div></div>'
                                            . '<div class="preview-body" style="min-height:70vh; background:#fff;">'
                                                . ($officeOpen ? '<div class="docx-view"><div class="docx-html">Loading preview…</div></div>' : ('<iframe src="' . htmlspecialchars($gview) . '" height="640"></iframe>'))
                                            . '</div>'
                                        . '</div>'
                                    . '</div>';
                                }

                                echo '</div></div>';
                            } else {
                                echo '<span style="color:#9CA3AF;font-style:italic;">— no supporting file uploaded —</span>';
                            }
                            echo '</dd>';

                            // Resolution Outcome
                            echo '<dt style="color:#6b7280;font-weight:700;">Resolution Outcome</dt>';
                            echo '<dd style="margin:0;color:#111827;">' . ($resolution_status !== '' ? htmlspecialchars($resolution_status) : '<span style="color:#9CA3AF;font-style:italic;">— not specified —</span>') . '</dd>';

                            // Follow-up required: render as a simple Yes/No string (no checkbox)
                            $fval = $follow_up_raw ?? '';
                            $fstr = is_bool($fval) ? ($fval ? '1' : '0') : trim((string)$fval);
                            $fstr_l = strtolower($fstr);
                            $followChecked = in_array($fstr_l, ['1','yes','true','on','y'], true);
                            echo '<dt style="color:#6b7280;font-weight:700;">Follow-up required</dt>';
                            echo '<dd style="margin:0;color:#111827;">' . ($followChecked ? 'Yes' : 'No') . '</dd>';

                            // Comments
                            echo '<dt style="color:#6b7280;font-weight:700;">Comments (optional)</dt>';
                            echo '<dd style="margin:0;color:#111827;">';
                            if (!empty($clean_comments)) {
                                $html = '';
                                $paras = preg_split("/\n{2,}/", $clean_comments);
                                foreach ($paras as $p) { $p = trim($p); if ($p === '') continue; $html .= '<p style="margin:0 0 8px;">' . nl2br(htmlspecialchars($p)) . '</p>'; }
                                echo '<div style="background:#fff;border:1px solid #e5e7eb;padding:10px;border-radius:8px;color:#111827;">' . $html . '</div>';
                            } elseif (!empty($latestSubmission['comments'] ?? null) && trim((string)$latestSubmission['comments']) !== '') {
                                $raw = (string)$latestSubmission['comments'];
                                $paras = preg_split("/\n{2,}/", preg_replace("/\r\n|\r/", "\n", $raw));
                                $html = '';
                                foreach ($paras as $p) { $p = trim($p); if ($p === '') continue; $html .= '<p style="margin:0 0 8px;">' . nl2br(htmlspecialchars($p)) . '</p>'; }
                                echo '<div style="background:#fff;border:1px solid #e5e7eb;padding:10px;border-radius:8px;color:#111827;">' . $html . '</div>';
                            } else {
                                echo '<span style="color:#9CA3AF;font-style:italic;">— none —</span>';
                            }
                            echo '</dd>';

                            echo '</dl></div></div>';
                        }

                        
                    ?>
                </div>
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
                <div id="successMessage" class="alert alert-success" role="status" aria-live="polite">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
                <div id="confettiContainer" aria-hidden="true" style="position:fixed;top:0;left:0;right:0;bottom:0;pointer-events:none;z-index:9999;"></div>
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
            
            <?php 
                $safe_skills = is_array($quest_skills) ? $quest_skills : []; 
                $gradedAlready = in_array(strtolower(trim((string)($latestSubmission['status'] ?? ''))), ['approved','rejected'], true);
                // Load existing assessment details per skill if graded
                $existingAssessments = [];
                if ($gradedAlready && !empty($latestSubmission['id'])) {
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM quest_assessment_details WHERE submission_id = ?");
                        $stmt->execute([(int)$latestSubmission['id']]);
                        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $existingAssessments[(string)$r['skill_name']] = $r;
                        }
                    } catch (PDOException $e) {
                        error_log('fetch assessment details failed: ' . $e->getMessage());
                    }
                }
            ?>
            <form method="post" id="assessmentForm">
                <?php if ($gradedAlready): ?>
                    <div class="card" style="border-left:4px solid #10B981;">
                        <div style="font-weight:600; color:#065F46;">This submission is already graded.</div>
                        <div class="meta">Reviewed by: <?= htmlspecialchars((string)($latestSubmission['reviewed_by'] ?? '')) ?><?= !empty($latestSubmission['reviewed_at']) ? ' • ' . htmlspecialchars(date('M d, Y g:i A', strtotime($latestSubmission['reviewed_at']))) : '' ?></div>
                    </div>
                <?php endif; ?>
                <?php foreach ($safe_skills as $skill): ?>
                    <?php 
                    $skill_name = isset($skill['skill_name']) ? (string)$skill['skill_name'] : 'Skill';
                    $tier_level = isset($skill['tier_level']) ? (string)$skill['tier_level'] : 'T2';
                    $tier_num = (int)str_replace('T', '', $tier_level);
                    $base_points = getTierBasePoints($tier_num);
                    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($skill_name));
                    $existing = $existingAssessments[$skill_name] ?? null;
                    $selectedMultiplier = $existing ? (float)$existing['performance_multiplier'] : 1.0;
                    $existingNotes = $existing ? (string)($existing['notes'] ?? '') : '';
                    $adjustedPoints = $existing ? (int)$existing['adjusted_points'] : $base_points;
                    ?>
                    <div class="skill-assessment" aria-label="Assessment for skill <?= htmlspecialchars($skill_name) ?>">
                        <div class="skill-name">
                            <i class="fas fa-award skill-icon" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($skill_name) ?></span>
                        </div>
                        
                        <div class="tier-info tier-<?= htmlspecialchars($tier_level) ?>">
                            <span class="base-tier">Base Tier: <?= htmlspecialchars($tier_level) ?> - <?= htmlspecialchars(getTierLabel($tier_num)) ?> (<?= $base_points ?> pts)</span>
                        </div>
                        
                        <div class="performance-section">
                            <div class="performance-label">Performance:</div>
                            <select class="performance-select" name="assessments[<?= $skill_name ?>][performance]" id="perf_<?= $safeId ?>" data-skill="<?= $safeId ?>" data-base="<?= $base_points ?>" onchange="onPerfChange(this)" <?= $gradedAlready ? 'disabled' : '' ?>>
                                <option value="0.0" <?= ($selectedMultiplier==0.0?'selected':'') ?>>Not performed (0%) = 0 pts</option>
                                <option value="0.8" <?= ($selectedMultiplier==0.8?'selected':'') ?>>Below Expectations (-20%) = <?= round($base_points*0.8) ?> pts</option>
                                <option value="1.0" <?= ($selectedMultiplier==1.0?'selected':'') ?>>Meets Expectations (+0%) = <?= $base_points ?> pts</option>
                                <option value="1.2" <?= ($selectedMultiplier==1.2?'selected':'') ?>>Exceeds Expectations (+20%) = <?= round($base_points*1.2) ?> pts</option>
                                <option value="1.4" <?= ($selectedMultiplier==1.4?'selected':'') ?>>Exceptional (+40%) = <?= round($base_points*1.4) ?> pts</option>
                            </select>

                            <div class="line">Adjusted: <strong><span class="adjusted-points" id="adj_<?= $safeId ?>"><?= (int)$adjustedPoints ?></span> pts</strong></div>
                        </div>
                        
                        <div class="notes-section">
                            <label for="notes_<?= $safeId ?>">Notes:</label>
                            <textarea 
                                name="assessments[<?= $skill_name ?>][notes]" 
                                id="notes_<?= $safeId ?>"
                                class="notes-textarea" 
                                placeholder="Add assessment notes..."
                                <?= $gradedAlready ? 'disabled' : '' ?>
                            ><?= $existingNotes ?></textarea>
                        </div>
                        
                        <input type="hidden" name="assessments[<?= $skill_name ?>][base_points]" value="<?= $base_points ?>">

                        <?php if ($gradedAlready && $existing): ?>
                            <div class="line"><small>Graded: <strong><?= htmlspecialchars($existing['performance_label'] ?? '') ?></strong> • Awarded <strong><?= (int)$adjustedPoints ?></strong> pts</small></div>
                            <?php if (trim($existingNotes) !== ''): ?>
                                <div class="line"><small>Note: <?= nl2br($existingNotes) ?></small></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <!-- Removed green Total Points box as requested -->
                
                <?php if (!$gradedAlready): ?>
                    <div class="form-actions form-actions-right">
                        <button type="submit" name="submit_assessment" class="btn btn-primary" <?= (!$user_id || !$quest_id) ? 'disabled' : '' ?> >
                            <i class="fas fa-trophy"></i> Grade &amp; Award XP
                        </button>
                    </div>
                <?php else: ?>
                    <div class="form-actions form-actions-right">
                        <a href="pending_reviews.php" class="btn btn-secondary"><i class="fas fa-eye"></i> Back to Submitted Quest</a>
                    </div>
                <?php endif; ?>
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
                                <?php elseif ($st === 'approved'): ?>Graded
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
            // Prefetch and convert before opening to avoid any lingering "Loading preview…" in the modal
            document.querySelectorAll('a.office-open').forEach((a) => {
                const activate = async (e) => {
                    e.preventDefault();
                    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
                    const fileUrl = a.getAttribute('data-file');
                    const inlineSel = a.getAttribute('data-inline');
                    const title = a.getAttribute('data-title') || 'Document';
                    const container = document.querySelector(inlineSel + ' .docx-html');
                    // Optional: reflect busy state on the button
                    const prevText = a.textContent; a.textContent = 'Opening…'; a.setAttribute('aria-busy', 'true'); a.disabled = true;
                    try {
                        const res = await fetch(fileUrl);
                        const buf = await res.arrayBuffer();
                        const result = await window.mammoth.convertToHtml({ arrayBuffer: buf });
                        if (container) container.innerHTML = result.value || '<em>Empty document</em>';
                        // Open after content is ready so there is no visible loading placeholder
                        lightbox.open({ href: inlineSel, type: 'inline', title: title });
                    } catch (err) {
                        if (container) {
                            container.innerHTML = '<em>Preview failed. </em><a href="' + fileUrl + '" target="_blank" rel="noopener">Open in new tab</a>';
                        } else {
                            // If we somehow don't have a container, open via Google Viewer as a hard fallback
                            const url = 'https://docs.google.com/gview?embedded=1&url=' + encodeURIComponent(fileUrl);
                            lightbox.open({ href: url, type: 'external', title: title });
                        }
                        console.error('DOCX preview error:', err);
                    } finally {
                        a.textContent = prevText; a.removeAttribute('aria-busy'); a.disabled = false;
                    }
                };
                a.addEventListener('click', activate, { capture: true });
                a.addEventListener('keydown', (ke) => {
                    if (ke.key === 'Enter' || ke.key === ' ') { activate(ke); }
                }, { capture: true });
            });

            // Smarter 'View in new tab' handling to avoid forced downloads
            document.querySelectorAll('a.view-newtab').forEach((a) => {
                a.addEventListener('click', async (e) => {
                    const ext = (a.getAttribute('data-ext') || '').toLowerCase();
                    const abs = a.getAttribute('data-abs') || a.href;
                    const gview = a.getAttribute('data-gview');
                    const host = location.host;
                    const isLocal = host.includes('localhost') || host.includes('127.0.0.1');

                    // Default behavior for images and PDFs is fine
                    if (['jpg','jpeg','png','gif','webp','svg','pdf','txt','md','csv'].includes(ext)) {
                        return; // let the browser open it
                    }

                    // Office types: DOCX uses Mammoth everywhere; others use Google Viewer (public) or raw (local)
                    if (['doc','docx','ppt','pptx','xls','xlsx'].includes(ext)) {
                        e.preventDefault();
                        if (ext === 'docx' && window.mammoth) {
                            try {
                                const res = await fetch(abs);
                                const buf = await res.arrayBuffer();
                                const result = await window.mammoth.convertToHtml({ arrayBuffer: buf });
                                const title = abs.split('/').pop();
                                const html = `<!doctype html><html><head><meta charset="utf-8"><title>${title}</title><style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:16px;color:#111827;background:#fff;} img{max-width:100%;height:auto}</style></head><body>${result.value}</body></html>`;
                                const blob = new Blob([html], { type: 'text/html' });
                                const url = URL.createObjectURL(blob);
                                window.open(url, '_blank');
                                setTimeout(() => URL.revokeObjectURL(url), 30000);
                            } catch (err) {
                                console.error('DOCX new-tab render failed:', err);
                                // Fallbacks: public -> Google Viewer; local -> raw URL
                                if (!isLocal) {
                                    const url = gview || ('https://docs.google.com/gview?embedded=1&url=' + encodeURIComponent(abs));
                                    window.open(url, '_blank');
                                } else {
                                    window.open(abs, '_blank');
                                }
                            }
                            return;
                        }
                        // Non-DOCX Officegi
                        if (!isLocal) {
                            const url = gview || ('https://docs.google.com/gview?embedded=1&url=' + encodeURIComponent(abs));
                            window.open(url, '_blank');
                        } else {
                            window.open(abs, '_blank');
                        }
                        return;
                    }
                    // Otherwise, allow default (may download if browser can't preview)
                });
            });
        });
    </script>
    <script>
        // Listen for submission updates from other tabs/windows and reload to show fresh DB state
        window.addEventListener('storage', function(e){
            try {
                if (!e) return;
                if (e.key === 'yqs_submission_updated') {
                    var payload = null;
                    try { payload = JSON.parse(e.newValue); } catch(err) { payload = e.newValue; }
                    // If payload has submission_id and it matches current view or no id provided, fetch partial update
                    var sid = payload && payload.submission_id ? payload.submission_id : null;
                    if (!sid || sid == <?= (int)$submission_id ?>) {
                        // fetch partial HTML and replace preview
                        fetch('ajax_get_submission.php?submission_id=' + <?= (int)$submission_id ?>)
                            .then(function(r){ if (!r.ok) throw new Error('fetch failed'); return r.text(); })
                            .then(function(html){
                                var cont = document.getElementById('submissionPreview');
                                if (cont) cont.innerHTML = html;
                            }).catch(function(err){ console.error('Partial refresh failed, reloading', err); setTimeout(function(){ location.reload(); }, 300); });
                    } else {
                        // submission update for different id — ignore
                    }
                }
            } catch(err) { console.error(err); }
        });

        // Also listen via BroadcastChannel for modern cross-tab notifications
        try {
            if (window.BroadcastChannel) {
                var bc = new BroadcastChannel('yqs_updates');
                bc.addEventListener('message', function(ev) {
                    try {
                        var d = ev && ev.data ? ev.data : null;
                        console.log('Received broadcast update', d);
                        // if message indicates submission_updated, reload to fetch latest DB row
                        if (!d || d.type === 'submission_updated') {
                            var sid = d && d.id ? d.id : null;
                            if (!sid || sid == <?= (int)$submission_id ?>) {
                                fetch('ajax_get_submission.php?submission_id=' + <?= (int)$submission_id ?>)
                                    .then(function(r){ if (!r.ok) throw new Error('fetch failed'); return r.text(); })
                                    .then(function(html){ var cont = document.getElementById('submissionPreview'); if (cont) cont.innerHTML = html; })
                                    .catch(function(err){ console.error('Broadcast partial refresh failed, reloading', err); setTimeout(function(){ location.reload(); }, 300); });
                            }
                        }
                    } catch(e) { console.error(e); }
                });
            }
        } catch(e) { console.error('BroadcastChannel not available', e); }
    </script>
    <script>
        // Reward animation + sound for successful grading
        (function(){
            function playRewardSound() {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const now = ctx.currentTime;
                    // short arpeggio: three notes (A4, C#5, E5) ascending
                    const freqs = [440, 554.37, 659.25];
                    freqs.forEach((f, i) => {
                        const o = ctx.createOscillator();
                        const g = ctx.createGain();
                        o.type = 'sine';
                        o.frequency.value = f;
                        g.gain.value = 0;
                        o.connect(g);
                        g.connect(ctx.destination);
                        const t0 = now + i * 0.06;
                        const dur = 0.28;
                        g.gain.setValueAtTime(0, t0);
                        g.gain.linearRampToValueAtTime(0.12, t0 + 0.01);
                        g.gain.exponentialRampToValueAtTime(0.001, t0 + dur);
                        o.start(t0);
                        o.stop(t0 + dur + 0.02);
                    });
                } catch (e) {
                    console.warn('Audio API unavailable', e);
                }
            }

            function burstConfetti(container) {
                if (!container) return;
                const colors = ['#FFD166','#06D6A0','#118AB2','#EF476F','#A78BFA'];
                const count = 18;
                const w = window.innerWidth;
                for (let i = 0; i < count; i++) {
                    const el = document.createElement('div');
                    el.className = 'confetti-piece';
                    el.style.background = colors[i % colors.length];
                    // randomize horizontal start
                    const left = Math.random() * (w * 0.9) + (w * 0.05);
                    el.style.left = left + 'px';
                    el.style.top = '-10vh';
                    el.style.transform = 'translateY(-10vh) rotate(' + (Math.random()*360) + 'deg)';
                    const delay = Math.random() * 0.2;
                    const duration = 1.6 + Math.random() * 0.8;
                    el.style.animation = 'confettiFall ' + duration + 's cubic-bezier(.2,.8,.2,1) ' + delay + 's both';
                    container.appendChild(el);
                    // remove after animation
                    setTimeout(() => { try { container.removeChild(el); } catch(e){} }, (delay + duration) * 1000 + 400);
                }
            }

            document.addEventListener('DOMContentLoaded', function(){
                const successEl = document.getElementById('successMessage');
                if (!successEl) return; // nothing to animate
                // Add pulse class for subtle pop
                successEl.classList.add('pulse-animate');
                // Play reward sound
                playRewardSound();
                // Confetti burst
                const confRoot = document.getElementById('confettiContainer');
                if (confRoot) {
                    burstConfetti(confRoot);
                }
            });
        })();
    </script>
</body>
</html>