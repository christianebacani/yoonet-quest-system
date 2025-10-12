<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (!is_logged_in()) {
    redirect('login.php');
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : ($_SESSION['user_id'] ?? 0);
if (!$user_id) { redirect('dashboard.php'); }

try {
    $stmt = $pdo->prepare("SELECT full_name, bio, profile_photo, quest_interests, availability_status, job_position FROM users WHERE id = ?");
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
            $stmt = $pdo->prepare("SELECT cs.skill_name, usp.total_points, usp.skill_level as current_level, usp.current_stage, usp.last_activity as last_used, usp.activity_status as status, usp.updated_at, sc.category_name FROM user_skill_progress usp JOIN comprehensive_skills cs ON usp.skill_id = cs.id JOIN skill_categories sc ON cs.category_id = sc.id WHERE usp.employee_id = ? ORDER BY usp.total_points DESC");
            $stmt->execute([$_SESSION['employee_id'] ?? '']);
            $user_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            $user_skills = [];
        }
    }
    $user_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading user earned skills: " . $e->getMessage());
    $user_skills = [];
}

// Calculate overall user level and stage
$total_user_points = array_sum(array_column($user_skills, 'total_points'));
$overall_level = calculateLevelFromPoints($total_user_points);
$overall_stage = calculateStageFromLevel($overall_level);

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

    /* Skill Journey Styles */
    .skills-journey { background: linear-gradient(135deg, #1e293b, #334155); color: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; position: relative; overflow: hidden; }
    .skills-journey::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="80" r="1" fill="rgba(255,255,255,0.1)"/></svg>') repeat; }
    .journey-header { text-align: center; margin-bottom: 20px; position: relative; z-index: 1; }
    .user-overall { font-size: 1.5rem; font-weight: bold; margin-bottom: 10px; }
    .journey-title { font-size: 1.2rem; color: #94a3b8; margin-bottom: 15px; letter-spacing: 2px; }
    .journey-divider { border: none; height: 2px; background: linear-gradient(90deg, transparent, #64748b, transparent); margin: 20px 0; }
    
    .skill-item { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 16px; margin-bottom: 16px; backdrop-filter: blur(10px); }
    .skill-header { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
    .skill-name { font-weight: bold; font-size: 1.1rem; }
    .skill-level { color: #94a3b8; }
    .skill-status { font-size: 0.9rem; }
    
    .skill-stage { margin-bottom: 8px; color: #cbd5e1; }
    .progress-bar { background: rgba(255,255,255,0.1); height: 8px; border-radius: 4px; overflow: hidden; margin: 8px 0; }
    .progress-fill { height: 100%; background: linear-gradient(90deg, #10b981, #34d399); transition: width 0.3s ease; }
    
    .skill-meta { display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; color: #94a3b8; margin-top: 8px; }
    .skill-stats { display: flex; gap: 16px; }
    .skill-hint { font-style: italic; }
    
    .journey-summary { background: rgba(255,255,255,0.05); border-radius: 8px; padding: 16px; margin-top: 20px; text-align: center; position: relative; z-index: 1; }
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; text-align: center; }
    .summary-value { font-size: 1.3rem; font-weight: bold; color: #10b981; }
    .summary-label { font-size: 0.85rem; color: #94a3b8; }

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
            <?php if (!empty($user_skills)): ?>
                <div class="skills-journey">
                    <div class="journey-header">
                        <div class="user-overall">
                            USER: <?= htmlspecialchars($profile_full_name) ?><br>
                            OVERALL: Level <?= $overall_level ?> | Stage: <?= htmlspecialchars($overall_stage) ?>
                        </div>
                        <div class="journey-title">SKILL JOURNEY:</div>
                        <hr class="journey-divider">
                    </div>
                    
                    <?php foreach ($user_skills as $skill): ?>
                        <?php 
                        $level_name = getLevelName($skill['current_level']);
                        $status_icon = getStatusIcon($skill['status']);
                        $progress_percent = getProgressToNextStage($skill['current_level'], $skill['total_points']);
                        $next_stage_points = 0;
                        
                        // Calculate next stage points needed
                        if ($skill['current_stage'] === 'Learning') $next_stage_points = 700;
                        elseif ($skill['current_stage'] === 'Applying') $next_stage_points = 3000;
                        elseif ($skill['current_stage'] === 'Mastering') $next_stage_points = 6000;
                        
                        $days_since_used = $skill['last_used'] ? round((time() - strtotime($skill['last_used'])) / (60 * 60 * 24)) : 999;
                        ?>
                        
                        <div class="skill-item">
                            <div class="skill-header">
                                <span class="skill-name"><?= strtoupper(htmlspecialchars($skill['skill_name'])) ?>:</span>
                                <span class="skill-level">Level <?= $skill['current_level'] ?> - <?= htmlspecialchars($level_name) ?></span>
                                <span class="skill-status"><?= $status_icon ?> <?= strtoupper($skill['status']) ?></span>
                            </div>
                            
                            <div class="skill-stage">
                                Stage: <?= htmlspecialchars($skill['current_stage']) ?> (<?= number_format($skill['total_points']) ?><?= $next_stage_points > 0 ? '/' . number_format($next_stage_points) : '' ?> pts)
                            </div>
                            
                            <?php if ($progress_percent < 100 && $next_stage_points > 0): ?>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?= $progress_percent ?>%"></div>
                                </div>
                                <div style="font-size: 0.85rem; color: #cbd5e1; margin-bottom: 8px;">
                                    <?= str_repeat('█', round($progress_percent / 5)) ?><?= str_repeat('░', 20 - round($progress_percent / 5)) ?> 
                                    <?= round($progress_percent) ?>% to <?= $skill['current_stage'] === 'Learning' ? 'Applying' : ($skill['current_stage'] === 'Applying' ? 'Mastering' : 'Innovating') ?> Stage
                                </div>
                            <?php endif; ?>
                            
                            <div class="skill-meta">
                                <div class="skill-stats">
                                    <?php if ($skill['recent_points'] > 0): ?>
                                        <span>🏆 Recent: +<?= $skill['recent_points'] ?> pts</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($days_since_used < 7): ?>
                                        <span>📈 Used <?= $days_since_used === 0 ? 'today' : $days_since_used . ' day' . ($days_since_used > 1 ? 's' : '') . ' ago' ?></span>
                                    <?php elseif ($days_since_used < 999): ?>
                                        <span>⏰ Last used: <?= $days_since_used ?> days ago</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="skill-hint">
                                    <?php if ($skill['status'] === 'ACTIVE'): ?>
                                        ⚡ Maintaining <?= htmlspecialchars($level_name) ?> status
                                    <?php elseif ($skill['status'] === 'STALE'): ?>
                                        💡 Complete any <?= htmlspecialchars($skill['skill_name']) ?> quest to reactivate!
                                    <?php elseif ($skill['status'] === 'RUSTY'): ?>
                                        🎯 Try "<?= htmlspecialchars($skill['skill_name']) ?> Refresher" for 2x points!
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="journey-summary">
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-value">12</div>
                                <div class="summary-label">QUESTS TO NEXT MILESTONE</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">3 weeks</div>
                                <div class="summary-label">ESTIMATED TIME</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value"><?= count(array_filter($user_skills, fn($s) => $s['status'] === 'ACTIVE')) ?>/<?= count($user_skills) ?> 🟢</div>
                                <div class="summary-label">ACTIVE SKILLS</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
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