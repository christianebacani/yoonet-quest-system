<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (!is_logged_in()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT full_name, bio, profile_photo, quest_interests, availability, job_position FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) redirect('dashboard.php');
} catch (PDOException $e) {
    error_log("Error loading profile_view: " . $e->getMessage());
    redirect('dashboard.php');
}

// Load user skills
$user_skills = [];
try {
    $stmt = $pdo->prepare("SELECT skill_name, skill_level FROM user_skills WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_skills = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    error_log("Error loading user skills in profile_view: " . $e->getMessage());
}

$skill_categories = [
    'Technical Skills' => [
        'PHP', 'JavaScript', 'Python', 'Java', 'C++', 'HTML/CSS', 'SQL', 'React', 'Vue.js', 'Node.js',
        'Laravel', 'WordPress', 'Git', 'Docker', 'AWS', 'Linux', 'MongoDB', 'MySQL'
    ],
    'Design Skills' => [
        'UI/UX Design', 'Graphic Design', 'Adobe Photoshop', 'Adobe Illustrator', 'Figma', 'Sketch',
        'InDesign', 'After Effects', 'Blender', '3D Modeling', 'Typography', 'Branding'
    ],
    'Business Skills' => [
        'Project Management', 'Team Leadership', 'Strategic Planning', 'Data Analysis', 'Marketing',
        'Sales', 'Customer Service', 'Public Speaking', 'Negotiation', 'Financial Analysis'
    ],
    'Soft Skills' => [
        'Communication', 'Problem Solving', 'Critical Thinking', 'Creativity', 'Adaptability',
        'Time Management', 'Teamwork', 'Leadership', 'Emotional Intelligence', 'Mentoring'
    ]
];

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
$profile_availability = $profile['availability'] ?? '';
$profile_job_position = $profile['job_position'] ?? '';
$profile_full_name = $profile['full_name'] ?? '';
$profile_bio = $profile['bio'] ?? '';
$profile_photo = $profile['profile_photo'] ?? '';

// Build selected skills grouped by category. Include any custom skills under 'Other Skills'
$selected_skills_by_category = [];
foreach ($user_skills as $skill_name => $skill_level) {
    $found = false;
    foreach ($skill_categories as $cat => $skills) {
        if (in_array($skill_name, $skills, true)) {
            $selected_skills_by_category[$cat][$skill_name] = (int)$skill_level;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $selected_skills_by_category['Other Skills'][$skill_name] = (int)$skill_level;
    }
}

// Flatten selected skills for pagination
$flat_selected_skills = [];
foreach ($selected_skills_by_category as $cat => $skills) {
    foreach ($skills as $s => $lvl) {
        $flat_selected_skills[] = ['category' => $cat, 'skill' => $s, 'level' => $lvl];
    }
}

// Pagination settings for skills
// Show pagination controls when selected skills exceed 7 (page size = 7)
$skills_per_page = 7;
$skill_page = isset($_GET['skill_page']) ? max(1, (int)$_GET['skill_page']) : 1;
$total_skills = count($flat_selected_skills);
$total_skill_pages = $total_skills ? (int)ceil($total_skills / $skills_per_page) : 1;
if ($skill_page > $total_skill_pages) $skill_page = $total_skill_pages;
$skill_offset = ($skill_page - 1) * $skills_per_page;
$page_slice = array_slice($flat_selected_skills, $skill_offset, $skills_per_page);

// Group the paginated slice back by category for display
$page_grouped_skills = [];
foreach ($page_slice as $entry) {
    $page_grouped_skills[$entry['category']][$entry['skill']] = $entry['level'];
}

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

    .profile-body { display:grid; grid-template-columns: 1fr 320px; gap:20px; margin-top:18px; }

    .skills-box { background: var(--light-color); padding:16px; border-radius:10px; border:1px solid #e9edf7; }
    .skills-box h4 { margin:0 0 8px 0; color:var(--dark-color); }
    .skill-row { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px dashed #eef2f7; }
    .skill-row:last-child { border-bottom: none; }

    .prefs-box { background: linear-gradient(180deg,#ffffff,#fbfdff); padding:18px; border-radius:10px; border:1px solid #e6eefb; }
    .pref-item { display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid rgba(15,23,42,0.03); }
    .pref-item:last-child { border-bottom: none; }
    .pref-label { font-size:0.95rem; color:#374151; font-weight:600; }
    .pref-value { margin-left:auto; font-weight:700; color:var(--primary-color); }
    .pref-badge { display:inline-block; background:linear-gradient(135deg,var(--primary-color),var(--secondary-color)); color:white; padding:6px 10px; border-radius:999px; font-weight:600; font-size:0.9rem; }

    .interest-list { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
    .interest-pill { background:#eef2ff; color:#1e3a8a; padding:6px 10px; border-radius:999px; font-weight:600; font-size:0.9rem; }

    .level-dots { display:flex; gap:6px; }
    .level-dots .dot { width:10px; height:10px; border-radius:50%; background:#e6e9ef; }
    .level-dots .dot.filled { background: var(--primary-color); box-shadow:0 2px 6px rgba(67,56,202,0.18); }

    /* Pagination controls for skills */
    .skills-pager { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:12px; }
    .pager-pages { display:flex; gap:6px; }
    .pager-pages a, .pager-prev, .pager-next { display:inline-block; padding:6px 10px; background:#fff; border:1px solid #e6eefb; border-radius:8px; color:#374151; text-decoration:none; font-weight:600; }
    .pager-pages a.active { background:var(--primary-color); color:#fff; border-color:transparent; }
    .pager-disabled { opacity:0.45; pointer-events:none; }
    .page-info { color:#6b7280; font-size:0.95rem; }

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
            <div class="skills-box">
                <h4>Skills</h4>
                <?php if (empty($flat_selected_skills)): ?>
                    <p style="color:#6b7280;">No skills selected yet.</p>
                <?php else: ?>
                    <div class="page-info">Showing <?= $skill_offset + 1 ?> to <?= min($skill_offset + count($page_slice), $total_skills) ?> of <?= $total_skills ?> skills</div>
                    <?php foreach ($page_grouped_skills as $cat => $skills): ?>
                        <strong style="display:block;margin-top:12px;color:#374151;"><?= htmlspecialchars($cat) ?></strong>
                        <?php foreach ($skills as $s => $lvl): ?>
                            <div class="skill-row">
                                <div><?= htmlspecialchars($s) ?></div>
                                <div class="level-dots">
                                    <?php for ($i=1;$i<=5;$i++): ?>
                                        <span class="dot <?= $i <= $lvl ? 'filled' : '' ?>" title="<?= $i <= $lvl ? 'Level '.$lvl : '' ?>"></span>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <?php if ($total_skill_pages > 1): ?>
                        <div class="skills-pager">
                            <div>
                                <a class="pager-prev <?= $skill_page <= 1 ? 'pager-disabled' : '' ?>" href="?skill_page=<?= max(1, $skill_page - 1) ?>">&larr; Prev</a>
                            </div>
                            <div class="pager-pages">
                                <?php for ($p = 1; $p <= $total_skill_pages; $p++): ?>
                                    <a href="?skill_page=<?= $p ?>" class="<?= $p === $skill_page ? 'active' : '' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                            </div>
                            <div>
                                <a class="pager-next <?= $skill_page >= $total_skill_pages ? 'pager-disabled' : '' ?>" href="?skill_page=<?= min($total_skill_pages, $skill_page + 1) ?>">Next &rarr;</a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <aside class="prefs-box">
                <h4 style="margin:0 0 8px 0;color:var(--dark-color);">Preferences</h4>
                <div class="pref-item">
                    <div class="pref-label">Job Position</div>
                    <div class="pref-value pref-badge"><?= htmlspecialchars($job_positions[$profile_job_position] ?? $profile_job_position ?: '—') ?></div>
                </div>
                <div class="pref-item">
                    <div class="pref-label">Availability</div>
                    <div class="pref-value"><?= htmlspecialchars($profile_availability ?: '—') ?></div>
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
            </aside>
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