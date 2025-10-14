<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (!is_logged_in()) {
    redirect('login.php');
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : ($_SESSION['user_id'] ?? 0);
if (!$user_id) { redirect('dashboard.php'); }

try {
    $stmt = $pdo->prepare("SELECT full_name, bio, profile_photo, quest_interests, availability_status, job_position, employee_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) redirect('dashboard.php');
} catch (PDOException $e) {
    error_log("Error loading profile_view: " . $e->getMessage());
    redirect('dashboard.php');
}

// Load user's earned skills from quest completions
$user_skills = [];
try {
    // Fetch skills that users have earned through quest completions
    // Prefer dynamic earned skills if available; fall back to legacy progress table if present
    $skills = [];
    try {
        $stmt = $pdo->prepare("SELECT skill_name, total_points, current_level, current_stage, last_used, recent_points, status, updated_at FROM user_earned_skills WHERE user_id = ? ORDER BY total_points DESC");
        $stmt->execute([$user_id]);
        $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* table may not exist yet */ }

    if (!empty($skills)) {
        $user_skills = $skills;
    } else {
        // Legacy path (keep existing query if that table exists in your DB)
        try {
            $profile_employee_id = $profile['employee_id'] ?? ($_SESSION['employee_id'] ?? '');
            $stmt = $pdo->prepare("SELECT cs.skill_name, usp.total_points, usp.skill_level as current_level, usp.current_stage, usp.last_activity as last_used, usp.activity_status as status, usp.updated_at, sc.category_name FROM user_skill_progress usp JOIN comprehensive_skills cs ON usp.skill_id = cs.id JOIN skill_categories sc ON cs.category_id = sc.id WHERE usp.employee_id = ? AND usp.total_points > 0 ORDER BY usp.total_points DESC");
            $stmt->execute([$profile_employee_id]);
            $user_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            $user_skills = [];
        }
        // If still empty, derive from per-skill graded details as a last resort
        if (empty($user_skills)) {
            try {
                // Aggregate totals and most recent award per skill for this user
                $stmt = $pdo->prepare(
                    "SELECT agg.skill_natme,
                            agg.total_points,
                            agg.last_used,
                            det.adjusted_points AS recent_points
                     FROM (
                        SELECT qd.skill_name,
                               SUM(qd.adjusted_points) AS total_points,
                               MAX(qd.reviewed_at)     AS last_used
                        FROM quest_assessment_details qd
                        JOIN quest_submissions qs ON qs.id = qd.submission_id AND LOWER(qs.status) = 'approved'
                        WHERE qd.user_id = ?
                        GROUP BY qd.skill_name
                        HAVING SUM(qd.adjusted_points) > 0
                     ) agg
                     LEFT JOIN quest_assessment_details det
                       ON det.user_id = ?
                      AND det.skill_name = agg.skill_name
                      AND det.reviewed_at = agg.last_used
                     ORDER BY agg.total_points DESC"
                );
                $stmt->execute([$user_id, $user_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Map into the shape expected by the renderer
                $mapped = [];
                foreach ($rows as $r) {
                    $tp = (int)($r['total_points'] ?? 0);
                    $lvl = calculateLevelFromPoints($tp);
                    $stg = calculateStageFromLevel($lvl);
                    $last = $r['last_used'] ?? null;
                    $days = $last ? round((time() - strtotime($last)) / (60 * 60 * 24)) : 999;
                    $status = 'ACTIVE';
                    if ($days >= 90) { $status = 'RUSTY'; }
                    elseif ($days >= 30) { $status = 'STALE'; }

                    $mapped[] = [
                        'skill_name'    => (string)$r['skill_name'],
                        'total_points'  => $tp,
                        'current_level' => $lvl,
                        'current_stage' => $stg,
                        'last_used'     => $last,
                        'recent_points' => (int)($r['recent_points'] ?? 0),
                        'status'        => $status,
                        'updated_at'    => $last,
                    ];
                }
                $user_skills = $mapped;
            } catch (PDOException $e3) {
                // leave as empty
                error_log('profile_view derive skills failed: ' . $e3->getMessage());
            }
        }
    }
} catch (PDOException $e) {
    error_log("Error loading user earned skills: " . $e->getMessage());
    $user_skills = [];
}

// Calculate overall user level and stage
$total_user_points = array_sum(array_column($user_skills, 'total_points'));
$overall_level = calculateLevelFromPoints($total_user_points);
$overall_stage = calculateStageFromLevel($overall_level);

// Active skills count (for potential lightweight summaries)
$active_skills_count = count(array_filter($user_skills, fn($s) => ($s['status'] ?? '') === 'ACTIVE'));

// Helper functions for level and stage calculations
function calculateLevelFromPoints($points) {
    if ($points < 100) return 1;
    if ($points < 300) return 2;
    if ($points < 700) return 3;
    if ($points < 1500) return 4;
    if ($points < 3000) return 5;
    if ($points < 6000) return 6;
    return 7; // Master level
}

function calculateStageFromLevel($level) {
    if ($level <= 3) return 'Learning';
    if ($level <= 5) return 'Applying';
    if ($level <= 6) return 'Mastering';
    return 'Innovating';
}

function getLevelName($level) {
    $levels = [
        1 => "Beginner",
        2 => "Novice", 
        3 => "Competent",
        4 => "Proficient",
        5 => "Advanced",
        6 => "Expert",
        7 => "Master"
    ];
    return $levels[$level] ?? "Unknown";
}

function getStatusIcon($status) {
    switch($status) {
        case 'ACTIVE': return '🟢';
        case 'STALE': return '🟡';
        case 'RUSTY': return '🔴';
        default: return '⚪';
    }
}

function getProgressToNextStage($level, $current_points) {
    $stage_thresholds = [
        'Learning' => 700,    // To reach Applying (Level 4)
        'Applying' => 3000,   // To reach Mastering (Level 6) 
        'Mastering' => 6000,  // To reach Innovating (Level 7)
        'Innovating' => 6000  // Already at max
    ];
    
    $current_stage = calculateStageFromLevel($level);
    $next_threshold = $stage_thresholds[$current_stage] ?? 6000;
    
    if ($current_points >= $next_threshold) {
        return 100; // Already at or beyond threshold
    }
    
    // Calculate previous threshold
    $prev_threshold = 0;
    if ($current_stage === 'Applying') $prev_threshold = 700;
    if ($current_stage === 'Mastering') $prev_threshold = 3000;
    if ($current_stage === 'Innovating') $prev_threshold = 6000;
    
    $progress_in_stage = $current_points - $prev_threshold;
    $stage_range = $next_threshold - $prev_threshold;
    
    return $stage_range > 0 ? min(100, ($progress_in_stage / $stage_range) * 100) : 0;
}

$skill_categories = [];

$job_positions = [
    'software_developer' => 'Software Developer',
    'web_developer' => 'Web Developer',
    'ui_ux_designer' => 'UI/UX Designer',
    'project_manager' => 'Project Manager',
    'data_analyst' => 'Data Analyst',
    'qa_engineer' => 'QA Engineer',
    'devops_engineer' => 'DevOps Engineer',
    'product_manager' => 'Product Manager',
    'business_analyst' => 'Business Analyst',
    'designer' => 'Designer'
];

$profile_quest_interests = $profile['quest_interests'] ? explode(',', $profile['quest_interests']) : [];
$profile_availability = $profile['availability_status'] ?? '';
$profile_job_position = $profile['job_position'] ?? '';
$profile_full_name = $profile['full_name'] ?? '';
$profile_bio = $profile['bio'] ?? '';
$profile_photo = $profile['profile_photo'] ?? '';

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>View Profile - YooNet Quest System</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/buttons.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>
<style>
    /* Profile view specific tweaks to match dashboard theme */
    .profile-wrap { max-width: 1000px; margin: 36px auto; }
    .profile-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 8px 30px rgba(18, 20, 56, 0.06); }
    .profile-top { display:flex; gap:20px; align-items:flex-start; }
    .profile-photo { width:128px; height:128px; border-radius:12px; object-fit:cover; border:4px solid white; box-shadow:0 8px 24px rgba(67,56,202,0.08); }
    .profile-name { font-size:1.5rem; color: var(--dark-color); margin:0; }
    .profile-bio { color: #6b7280; margin-top:8px; }
    .profile-actions { margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; }

    .profile-body { display:grid; grid-template-columns: 1fr; gap:20px; margin-top:18px; }

    /* Skill Journey Styles - light card to match site */
    .skills-journey { background:#fff; color:#111827; padding: 16px; border-radius: 12px; border:1px solid #e5e7eb; box-shadow: 0 6px 22px rgba(18, 20, 56, 0.06); }
    .journey-header { text-align: left; margin-bottom: 12px; }
    .user-overall { font-size: 1.1rem; font-weight: 800; color:#111827; }
    .journey-title { font-size: 0.9rem; color: #6b7280; letter-spacing: 1px; margin-top: 2px; text-transform: uppercase; }
    .journey-divider { border: none; height: 1px; background: #e5e7eb; margin: 12px 0; }

    .skill-item { background:#f8fafc; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; margin-bottom: 12px; }
    .skill-header { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
    .skill-name { font-weight: 800; color:#111827; letter-spacing: 0.4px; }
    .skill-level { color: #374151; font-weight:600; }
    .skill-status { font-size: 0.85rem; color:#374151; }

    /* Badges */
    .badge { display:inline-flex; align-items:center; gap:4px; padding:4px 8px; border-radius:999px; font-size:0.65rem; font-weight:700; letter-spacing:.5px; text-transform:uppercase; border:1px solid transparent; }
    .badge-level { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    .badge-stage { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
    .badge-status-active { background:#d1fae5; color:#065f46; border-color:#10b981; }
    .badge-status-stale { background:#fef3c7; color:#92400e; border-color:#fbbf24; }
    .badge-status-rusty { background:#fee2e2; color:#991b1b; border-color:#fca5a5; }
    .badge-recent { background:#e0f2fe; color:#075985; border-color:#7dd3fc; }

    /* Skill color accents (hash-based categories) */
    .skill-accent { display:inline-block; width:10px; height:10px; border-radius:3px; }
    .sa-0 { background:#6366f1; }
    .sa-1 { background:#f59e0b; }
    .sa-2 { background:#10b981; }
    .sa-3 { background:#ec4899; }
    .sa-4 { background:#0ea5e9; }
    .sa-5 { background:#8b5cf6; }
    .sa-6 { background:#14b8a6; }
    .sa-7 { background:#f43f5e; }
    .sa-8 { background:#84cc16; }
    .sa-9 { background:#fb7185; }

    .skill-stage { margin-bottom: 6px; color: #374151; }
    .progress-bar { background: #e5e7eb; height: 8px; border-radius: 4px; overflow: hidden; margin: 8px 0; }
    .progress-fill { height: 100%; background: linear-gradient(90deg, #4f46e5, #22d3ee); transition: width 0.3s ease; }

    .skill-meta { display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; color: #6b7280; margin-top: 6px; }
    .skill-stats { display: flex; gap: 14px; align-items:center; }
    .skill-hint { font-style: italic; color:#6b7280; }

    .prefs-box { background: linear-gradient(180deg,#ffffff,#fbfdff); padding:18px; border-radius:10px; border:1px solid #e6eefb; }
    .pref-item { display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid rgba(15,23,42,0.03); }
    .pref-item:last-child { border-bottom: none; }
    .pref-label { font-size:0.95rem; color:#374151; font-weight:600; }
    .pref-value { margin-left:auto; font-weight:700; color:var(--primary-color); }
    .pref-badge { display:inline-block; background:linear-gradient(135deg,var(--primary-color),var(--secondary-color)); color:white; padding:6px 10px; border-radius:999px; font-weight:600; font-size:0.9rem; }

    /* Availability badge styles */
    .availability-badge { 
        display: inline-flex; 
        align-items: center; 
        padding: 8px 14px; 
        border-radius: 24px; 
        font-weight: 600; 
        font-size: 0.9rem; 
        border: 1px solid; 
        box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
        transition: all 0.3s ease; 
        backdrop-filter: blur(10px);
    }
    .availability-badge:hover { 
        transform: translateY(-1px); 
        box-shadow: 0 4px 12px rgba(0,0,0,0.12); 
    }
    .availability-full-time { 
        background: linear-gradient(135deg, #ecfdf5, #d1fae5); 
        color: #064e3b; 
        border-color: #34d399; 
    }
    .availability-part-time { 
        background: linear-gradient(135deg, #eff6ff, #dbeafe); 
        color: #1e3a8a; 
        border-color: #3b82f6; 
    }
    .availability-casual { 
        background: linear-gradient(135deg, #fffbeb, #fef3c7); 
        color: #78350f; 
        border-color: #f59e0b; 
    }
    .availability-project-based { 
        background: linear-gradient(135deg, #faf5ff, #f3e8ff); 
        color: #581c87; 
        border-color: #8b5cf6; 
    }
    .availability-badge i { 
        font-size: 0.85em; 
        margin-right: 8px; 
        opacity: 0.9; 
    }
    .availability-subtitle { 
        font-size: 0.75em; 
        margin-left: 6px; 
        opacity: 0.85; 
        font-weight: 500; 
        font-style: italic; 
    }

    .interest-list { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
    .interest-pill { background:#eef2ff; color:#1e3a8a; padding:6px 10px; border-radius:999px; font-weight:600; font-size:0.9rem; }

    @media (max-width: 880px) {
        .profile-body { grid-template-columns: 1fr; }
        .profile-top { flex-direction:row; }
    }
    /* Background canvas & overlay positioning */
    .profile-bg { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
    #bgCanvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: block; }
    .bg-gradient-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(99,102,241,0.06), rgba(16,185,129,0.03)); mix-blend-mode: overlay; pointer-events:none; }
    .profile-wrap { position: relative; z-index: 5; }
    .profile-card { position: relative; z-index: 6; }
</style>
</head>
<body>
<div class="profile-bg"> 
    <canvas id="bgCanvas" aria-hidden="true"></canvas>
    <div class="bg-gradient-overlay" aria-hidden="true"></div>
</div>
<div class="profile-wrap">
    <div class="profile-card">
        <h2 style="margin:0 0 10px 0;">View Profile</h2>
        <div class="profile-top">
            <div>
                <?php if (!empty($profile_photo) && file_exists($profile_photo)): ?>
                    <img src="<?= htmlspecialchars($profile_photo) ?>" alt="Profile Photo" class="profile-photo">
                <?php else: ?>
                    <div class="profile-photo" style="display:flex;align-items:center;justify-content:center;background:#f3f4f6;color:#9ca3af;font-size:2rem;"><i class="fas fa-user"></i></div>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <h3 class="profile-name"><?= htmlspecialchars($profile_full_name) ?></h3>
                <p class="profile-bio"><?= nl2br(htmlspecialchars($profile_bio)) ?></p>
                <div class="profile-actions">
                    <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    <a href="profile_setup.php?step=1" class="btn btn-primary">Edit Profile</a>
                </div>
            </div>
        </div>

        <div class="profile-body">
            <div class="prefs-box">
                <h4 style="margin:0 0 8px 0;color:var(--dark-color);">Preferences</h4>
                <div class="pref-item">
                    <div class="pref-label">Job Position</div>
                    <div class="pref-value pref-badge"><?= htmlspecialchars($job_positions[$profile_job_position] ?? $profile_job_position ?: '—') ?></div>
                </div>
                <div class="pref-item">
                    <div class="pref-label">Availability</div>
                    <div class="pref-value">
                        <?= format_availability($profile_availability) ?>
                    </div>
                </div>
                <div class="pref-item" style="flex-direction:column;align-items:flex-start;">
                    <div class="pref-label">Quest Interests</div>
                    <div class="interest-list">
                        <?php if (!empty($profile_quest_interests)): ?>
                            <?php foreach ($profile_quest_interests as $qi): ?>
                                <div class="interest-pill"><?= htmlspecialchars(trim($qi)) ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="color:#6b7280">—</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($user_skills)): ?>
                <div class="skills-journey" id="skills-section">
                    <div class="journey-header">
                        <div class="user-overall">Level <?= $overall_level ?> • <?= htmlspecialchars($overall_stage) ?> Stage</div>
                        <div class="journey-title">Skill Journey</div>
                        <hr class="journey-divider">
                    </div>

                    <?php
                        // Search filter
                        $skillQuery = isset($_GET['skill_q']) ? trim((string)$_GET['skill_q']) : '';
                        $filtered_skills = $user_skills;
                        if ($skillQuery !== '') {
                            $qLower = mb_strtolower($skillQuery);
                            $filtered_skills = array_values(array_filter($user_skills, function($s) use ($qLower) {
                                return strpos(mb_strtolower($s['skill_name']), $qLower) !== false;
                            }));
                        }

                        // Pagination setup (5 per page now)
                        $skillsPerPage = 5;
                        $page = isset($_GET['spage']) ? max(1, (int)$_GET['spage']) : 1;
                        $totalSkills = count($filtered_skills);
                        $totalPages = (int)max(1, ceil($totalSkills / $skillsPerPage));
                        if ($page > $totalPages) { $page = $totalPages; }
                        $offset = ($page - 1) * $skillsPerPage;
                        $skills_slice = array_slice($filtered_skills, $offset, $skillsPerPage);

                        // Simple deterministic hash for color accent
                        $skillHashClass = function($name) {
                            $h = 0; $len = strlen($name);
                            for ($i=0; $i<$len; $i++) { $h = ($h * 31 + ord($name[$i])) % 10; }
                            return 'sa-' . $h;
                        };
                    ?>
                    <form method="get" action="" style="margin:0 0 14px 0; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
                        <input name="skill_q" value="<?= htmlspecialchars($skillQuery) ?>" placeholder="Search skills..." style="flex:1; min-width:220px; padding:8px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;" />
                        <?php if ($skillQuery !== ''): ?>
                            <a href="?user_id=<?= (int)$user_id ?>#skills-section" class="btn btn-secondary" style="text-decoration:none;">Clear</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Search</button>
                    </form>
                    <?php if ($skillQuery !== '' && $totalSkills === 0): ?>
                        <div style="padding:12px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; font-size:0.8rem; color:#991b1b;">No skills matched "<?= htmlspecialchars($skillQuery) ?>".</div>
                    <?php endif; ?>
                    <?php foreach ($skills_slice as $skill): ?>
                        <?php 
                        $level_name = getLevelName($skill['current_level']);
                        $status_icon = getStatusIcon($skill['status']);
                        $progress_percent = getProgressToNextStage($skill['current_level'], $skill['total_points']);
                        $next_stage_points = 0;
                        
                        // Calculate next stage points needed
                        if ($skill['current_stage'] === 'Learning') $next_stage_points = 700;
                        elseif ($skill['current_stage'] === 'Applying') $next_stage_points = 3000;
                        elseif ($skill['current_stage'] === 'Mastering') $next_stage_points = 6000;
                        
                        $days_since_used = $skill['last_used'] ? floor((time() - strtotime($skill['last_used'])) / 86400) : 999;
                        $seconds_since_used = $skill['last_used'] ? (time() - strtotime($skill['last_used'])) : null;
                        $recent_usage_label = '';
                        if ($seconds_since_used !== null) {
                            if ($seconds_since_used < 60) {
                                $s = $seconds_since_used; $recent_usage_label = 'Used ' . $s . ' second' . ($s===1?'':'s') . ' ago';
                            } elseif ($seconds_since_used < 3600) {
                                $m = floor($seconds_since_used / 60); $recent_usage_label = 'Used ' . $m . ' minute' . ($m===1?'':'s') . ' ago';
                            } elseif ($seconds_since_used < 86400) {
                                $h = floor($seconds_since_used / 3600); $recent_usage_label = 'Used ' . $h . ' hour' . ($h===1?'':'s') . ' ago';
                            } else {
                                $recent_usage_label = 'Used ' . $days_since_used . ' day' . ($days_since_used===1?'':'s') . ' ago';
                            }
                        }
                        ?>
                        
                        <div class="skill-item">
                            <div class="skill-header">
                                <span class="skill-accent <?= $skillHashClass($skill['skill_name']) ?>" title="Skill color"></span>
                                <span class="skill-name"><?= strtoupper(htmlspecialchars($skill['skill_name'])) ?></span>
                                <span class="badge badge-level">Lvl <?= $skill['current_level'] ?> • <?= htmlspecialchars($level_name) ?></span>
                                <span class="badge badge-stage"><?= htmlspecialchars($skill['current_stage']) ?></span>
                                <span class="badge <?= $skill['status']==='ACTIVE'?'badge-status-active':($skill['status']==='STALE'?'badge-status-stale':'badge-status-rusty') ?>"><?= strtoupper($skill['status']) ?></span>
                            </div>
                            
                            <div class="skill-stage" style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                                <span>XP: <?= number_format($skill['total_points']) ?><?= $next_stage_points > 0 ? ' / ' . number_format($next_stage_points) : '' ?> pts</span>
                                <?php if ($progress_percent < 100 && $next_stage_points > 0): ?>
                                    <span style="font-size:0.7rem; letter-spacing:.5px; color:#6b7280; font-weight:600;"><?= round($progress_percent) ?>% to next stage</span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($progress_percent < 100 && $next_stage_points > 0): ?>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $progress_percent ?>%"></div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="skill-meta">
                                <div class="skill-stats">
                                    <?php if ($skill['recent_points'] > 0): ?>
                                        <span class="badge badge-recent">+<?= $skill['recent_points'] ?> pts</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($seconds_since_used !== null && $seconds_since_used < 86400): ?>
                                        <span>📈 Used <?= htmlspecialchars($recent_usage_label) ?></span>
                                    <?php elseif ($seconds_since_used !== null && $days_since_used < 30): ?>
                                        <span>📈 Used <?= htmlspecialchars($recent_usage_label) ?></span>
                                    <?php elseif ($seconds_since_used !== null && $days_since_used < 999): ?>
                                        <span>⏰ Last used: <?= htmlspecialchars($recent_usage_label) ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="skill-hint">
                                    <?php if ($skill['status'] === 'ACTIVE'): ?>Maintain momentum by applying this skill weekly<?php elseif ($skill['status'] === 'STALE'): ?>Re-engage with a quest to restore activity<?php elseif ($skill['status'] === 'RUSTY'): ?>Consider a refresher quest to regain edge<?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($totalPages > 1): ?>
                        <div style="display:flex; justify-content:center; gap:6px; margin-top:10px; flex-wrap:wrap;">
                            <?php
                                // Build base query string preserving search
                                $baseParams = ['user_id' => (int)$user_id];
                                if ($skillQuery !== '') { $baseParams['skill_q'] = $skillQuery; }
                                for ($p=1; $p<=$totalPages; $p++):
                                    $qs = http_build_query(array_merge($baseParams, ['spage'=>$p]));
                                    if ($p == $page): ?>
                                        <span style="padding:6px 10px; border-radius:8px; background:#4f46e5; color:#fff; font-weight:600; font-size:0.8rem;">Page <?= $p ?></span>
                                    <?php else: ?>
                                        <a href="?<?= htmlspecialchars($qs) ?>#skills-section" style="padding:6px 10px; border-radius:8px; background:#eef2ff; color:#3730a3; font-weight:600; font-size:0.8rem; text-decoration:none; border:1px solid #c7d2fe;">Page <?= $p ?></a>
                                    <?php endif;
                                endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
    <script>
    // Animated background - lightweight glowing particles
    (function() {
        const canvas = document.getElementById('bgCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');

        function resize() {
            const dpr = window.devicePixelRatio || 1;
            canvas.width = Math.floor(window.innerWidth * dpr);
            canvas.height = Math.floor(window.innerHeight * dpr);
            canvas.style.width = window.innerWidth + 'px';
            canvas.style.height = window.innerHeight + 'px';
            // reset transform and apply new scale cleanly
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }

        window.addEventListener('resize', resize);
        resize();

        const colors = ['#8b5cf6','#06b6d4','#f97316','#10b981','#f472b6'];
        const particles = Array.from({length: 28}).map(() => ({
            x: Math.random() * window.innerWidth,
            y: Math.random() * window.innerHeight,
            r: 8 + Math.random() * 18,
            vx: (Math.random() - 0.5) * 0.3,
            vy: (Math.random() - 0.5) * 0.3,
            color: colors[Math.floor(Math.random() * colors.length)],
            pulse: Math.random() * Math.PI * 2
        }));

        function draw() {
            ctx.clearRect(0,0,canvas.width,canvas.height);
            ctx.globalCompositeOperation = 'lighter';
            particles.forEach(p => {
                p.x += p.vx; p.y += p.vy;
                p.pulse += 0.02;
                const opacity = 0.35 + (Math.sin(p.pulse) + 1) * 0.15;
                const grd = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.r);
                grd.addColorStop(0, hexToRgba(p.color, opacity));
                grd.addColorStop(0.4, hexToRgba(p.color, opacity * 0.55));
                grd.addColorStop(1, 'rgba(255,255,255,0)');
                ctx.fillStyle = grd;
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
                ctx.fill();

                // wrap
                if (p.x < -50) p.x = window.innerWidth + 50;
                if (p.x > window.innerWidth + 50) p.x = -50;
                if (p.y < -50) p.y = window.innerHeight + 50;
                if (p.y > window.innerHeight + 50) p.y = -50;
            });
            requestAnimationFrame(draw);
        }

        function hexToRgba(hex, a) {
            const c = hex.replace('#','');
            const r = parseInt(c.substring(0,2),16);
            const g = parseInt(c.substring(2,4),16);
            const b = parseInt(c.substring(4,6),16);
            return `rgba(${r},${g},${b},${a})`;
        }

        // Pause animation when not visible to save CPU
        let running = true;
        document.addEventListener('visibilitychange', () => {
            running = !document.hidden;
            if (running) draw();
        });

        draw();
    })();
    </script>
</body>
</html>