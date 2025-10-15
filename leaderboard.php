<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}
// ...existing code...

// --- Job + Skill Leaderboard Logic ---
$leaderboard = [];
$selected_job = $_GET['job_position'] ?? '';
$selected_skill = $_GET['skill_name'] ?? '';

// Fetch all job positions (static list for now, can be dynamic)
$job_positions = [
    '' => 'All Jobs',
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

// Fetch all skills from user_earned_skills (distinct skill_name)
$skills = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT skill_name FROM user_earned_skills ORDER BY skill_name ASC");
    $skills = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('Skill fetch error: ' . $e->getMessage());
    $skills = [];
}

// Build leaderboard query: sum all skill points for each user
$params = [];
$where = [];
if ($selected_job !== '' && isset($job_positions[$selected_job])) {
    $where[] = 'u.job_position = ?';
    $params[] = $selected_job;
}
if ($selected_skill !== '' && in_array($selected_skill, $skills)) {
    $where[] = 'ues.skill_name = ?';
    $params[] = $selected_skill;
}

if ($selected_skill !== '') {
    // Leaderboard for a specific skill
    $sql = "SELECT u.employee_id, u.full_name, u.job_position, u.email, ues.skill_name, ues.total_points
            FROM users u
            JOIN user_earned_skills ues ON u.id = ues.user_id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY ues.total_points DESC, u.full_name ASC
            LIMIT 50";
} else {
    // Leaderboard for all skills: sum all skill points per user
    $sql = "SELECT u.employee_id, u.full_name, u.job_position, u.email, SUM(ues.total_points) as total_xp
            FROM users u
            JOIN user_earned_skills ues ON u.id = ues.user_id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
            GROUP BY u.employee_id, u.full_name, u.job_position, u.email
            ORDER BY total_xp DESC, u.full_name ASC
            LIMIT 50";
}
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Leaderboard query error: ' . $e->getMessage());
    $leaderboard = [];
}

// If no skill selected, fallback to global XP leaderboard (legacy)
// ...legacy global XP leaderboard code removed...

// Set default values if not set
$current_theme = $_SESSION['theme'] ?? 'default';
$dark_mode = $_SESSION['dark_mode'] ?? false;
$font_size = $_SESSION['font_size'] ?? 'medium';

// Function to get the body class based on theme
function getBodyClass() {
    global $current_theme, $dark_mode;
    
    $classes = [];
    
    if ($dark_mode) {
        $classes[] = 'dark-mode';
    }
    
    if ($current_theme !== 'default') {
        $classes[] = $current_theme . '-theme';
    }
    
    return implode(' ', $classes);
}

// Function to get font size CSS
function getFontSize() {
    global $font_size;
    
    switch ($font_size) {
        case 'small': return '14px';
        case 'large': return '18px';
        default: return '16px';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yoonet - Quest Leaderboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .title-expert { background-color: #8b5cf6; color: white; }
        .title-adventurer { background-color: #3b82f6; color: white; }
        .title-explorer { background-color: #10b981; color: white; }
        .title-newbie { background-color: #64748b; color: white; }
        .avatar {
            width: 40px; height: 40px; border-radius: 50%; background-color: #3b82f6; color: white;
            display: flex; align-items: center; justify-content: center; font-weight: bold; text-transform: uppercase;
        }
        .section-header { position: relative; padding-left: 1.25rem; }
        .section-header:before {
            content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; border-radius: 2px;
            background: linear-gradient(to bottom, #4f46e5, #10b981);
        }
        :root {
            --primary-color: #4285f4; --secondary-color: #34a853; --background-color: #ffffff; --text-color: #333333;
            --card-bg: #f8f9fa; --border-color: #e0e0e0; --shadow-color: rgba(0, 0, 0, 0.1); --transition-speed: 0.4s;
        }
        .dark-mode {
            --primary-color: #8ab4f8; --secondary-color: #81c995; --background-color: #121212; --text-color: #e0e0e0;
            --card-bg: #1e1e1e; --border-color: #333333; --shadow-color: rgba(0, 0, 0, 0.3);
        }
        .ocean-theme {
            --primary-color: #00a1f1; --secondary-color: #00c1d4; --background-color: #f0f8ff; --text-color: #003366;
            --card-bg: #e1f0fa; --border-color: #b3d4ff;
        }
        .forest-theme {
            --primary-color: #228B22; --secondary-color: #2E8B57; --background-color: #f0fff0; --text-color: #013220;
            --card-bg: #e1fae1; --border-color: #98fb98;
        }
        .sunset-theme {
            --primary-color: #FF6B6B; --secondary-color: #FFA07A; --background-color: #FFF5E6; --text-color: #8B0000;
            --card-bg: #FFE8D6; --border-color: #FFB347;
        }
        @keyframes fadeIn { from { opacity: 0.8; } to { opacity: 1; } }
        .theme-change { animation: fadeIn var(--transition-speed) ease; }
        body {
            background-color: var(--background-color); color: var(--text-color);
            transition: background-color var(--transition-speed) ease, color var(--transition-speed) ease;
        }
        .card, .btn-primary, .btn-secondary, .assignment-section, .section-header, .user-card, .progress-bar, .rank-badge, .status-badge, .xp-badge {
            transition: all var(--transition-speed) ease;
        }
    </style>
</head>

<script language="javascript" type="text/javascript">
function DisableBackButton() {
    window.history.forward();
}
DisableBackButton();
window.onload = DisableBackButton;
window.onpageshow = function(evt) { if (evt.persisted) DisableBackButton(); }
window.onunload = function() { void (0); }
</script>

<body class="<?php echo getBodyClass(); ?>" style="font-size: <?php echo getFontSize(); ?>;">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Header -->
        <header class="flex flex-col sm:flex-row justify-between items-center mb-8">
            <div class="flex items-center gap-4 mb-4 sm:mb-0">
                <img src="assets/images/yoonet-logo.jpg" alt="Yoonet Logo" class="h-12 w-auto">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Quest Leaderboard</h1>
                    <p class="text-sm text-gray-600">Global rankings based on XP earned</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="dashboard.php" class="btn btn-navigation btn-back">
                    <i class="fas fa-arrow-left btn-icon"></i>
                    <span class="btn-text">Back to Dashboard</span>
                </a>
                <a href="logout.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="btn-text">Logout</span>
                </a>
            </div>
        </header>
        
        <!-- User Stats removed for clarity and to avoid confusion/errors -->
        
        <!-- Leaderboard + Filters -->
        <div class="section-header mb-6">
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-trophy text-yellow-500"></i>
                Top Performers
            </h2>
        </div>
        <!-- Filter Form -->
        <form method="get" class="flex flex-wrap gap-4 mb-6 items-end">
            <div>
                <label for="job_position" class="block text-xs font-semibold text-gray-600 mb-1">Job Position</label>
                <select name="job_position" id="job_position" class="form-input rounded-md border-gray-300">
                    <?php foreach ($job_positions as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $selected_job === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="skill_name" class="block text-xs font-semibold text-gray-600 mb-1">Skill</label>
                <select name="skill_name" id="skill_name" class="form-input rounded-md border-gray-300">
                    <option value="">All Skills (Global XP)</option>
                    <?php foreach ($skills as $skill): ?>
                        <option value="<?= htmlspecialchars($skill) ?>" <?= $selected_skill === $skill ? 'selected' : '' ?>><?= htmlspecialchars($skill) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>

        <?php if (!empty($leaderboard)): ?>
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Position</th>
                                <?php if ($selected_skill !== ''): ?><th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Skill</th><?php endif; ?>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total XP</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($leaderboard as $index => $user): ?>
                                <?php 
                                    $is_current_user = isset($_SESSION['employee_id']) && isset($user['employee_id']) && $user['employee_id'] === $_SESSION['employee_id'];
                                    $points = $selected_skill !== '' ? (isset($user['total_points']) ? $user['total_points'] : 0) : (isset($user['total_xp']) ? $user['total_xp'] : 0);
                                ?>
                                <tr class="<?php echo $is_current_user ? 'current-user' : ''; ?> hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($index < 3): ?>
                                            <div class="rank-badge rank-<?php echo $index + 1; ?>">
                                                <?php echo $index + 1; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-700 font-medium"><?php echo $index + 1; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="avatar mr-3" style="background-color: <?php echo $is_current_user ? '#3b82f6' : '#e2e8f0'; ?>; color: <?php echo $is_current_user ? 'white' : '#64748b'; ?>;">
                                                <?php echo isset($user['full_name']) ? strtoupper(substr($user['full_name'], 0, 1)) : '?'; ?>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium <?php echo $is_current_user ? 'text-blue-600' : 'text-gray-900'; ?>">
                                                    <?php echo isset($user['full_name']) ? htmlspecialchars($user['full_name']) : 'Unknown'; ?>
                                                    <?php if ($is_current_user): ?>
                                                        <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full">You</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-sm text-gray-500"><?php echo isset($user['employee_id']) ? htmlspecialchars($user['employee_id']) : ''; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo isset($user['job_position']) && isset($job_positions[$user['job_position']]) ? htmlspecialchars($job_positions[$user['job_position']]) : 'N/A'; ?></td>
                                    <?php if ($selected_skill !== ''): ?><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo isset($user['skill_name']) ? htmlspecialchars($user['skill_name']) : ''; ?></td><?php endif; ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-green-700 font-bold"><?php echo number_format($points); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Gamification: Add a motivational message and badge for top 3 -->
            <div class="my-6 text-center">
                <h3 class="text-lg font-bold text-purple-700">Keep progressing! Earn more XP by completing quests and mastering new skills.</h3>
                <p class="text-gray-500">Top 3 users earn a special badge and recognition on the leaderboard.</p>
            </div>
            <!-- Optional: Progress bar for XP if level logic exists -->
            <?php if ($selected_skill === '' && !empty($leaderboard)): ?>
            <div class="my-4">
                <table class="min-w-full">
                    <tr>
                        <td colspan="5" class="text-center text-xs text-gray-500 pb-2">Progress to next level (based on 100 XP per level):</td>
                    </tr>
                    <?php foreach ($leaderboard as $index => $user): ?>
                        <?php $xp = isset($user['total_xp']) ? $user['total_xp'] : 0;
                              $level = floor($xp / 100) + 1;
                              $progress = $xp % 100;
                              $percent = min(100, ($progress / 100) * 100);
                        ?>
                        <tr>
                            <td class="px-2 py-1 text-xs text-gray-700 text-right" style="width: 120px;"><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td style="width: 200px;">
                                <div class="progress-bar" style="background: #e5e7eb; border-radius: 6px; height: 10px; width: 100%;">
                                    <div class="progress-fill" style="background: #34a853; height: 10px; border-radius: 6px; width: <?php echo $percent; ?>%; transition: width 0.6s;"></div>
                                </div>
                            </td>
                            <td class="px-2 py-1 text-xs text-gray-500">Level <?php echo $level; ?></td>
                            <td class="px-2 py-1 text-xs text-gray-500"><?php echo $progress; ?>/100 XP</td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                <div class="mx-auto max-w-md">
                    <i class="fas fa-trophy text-gray-300 text-5xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No data available</h3>
                    <p class="text-gray-500 mb-6">Complete quests to appear on the leaderboard.</p>
                    <a href="dashboard.php" class="btn btn-primary">
                        <i class="fas fa-rocket"></i>
                        Start Your First Quest
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Animation for progress bars
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.progress-fill');
            
            progressBars.forEach(bar => {
                // Store original width
                const originalWidth = bar.style.width;
                // Set to 0 for animation
                bar.style.width = '0';
                // Trigger reflow
                bar.offsetHeight;
                // Animate to original width
                bar.style.width = originalWidth;
            });
            
            // Highlight current user in the leaderboard
            const currentUserId = "<?php echo $_SESSION['employee_id']; ?>";
            const currentUserRow = document.querySelector(`tr[data-user-id="${currentUserId}"]`);
            
            if (currentUserRow) {
                currentUserRow.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        });
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add theme change animation class
        document.body.classList.add('theme-change');
        
        // Remove animation class after animation completes
        setTimeout(() => {
            document.body.classList.remove('theme-change');
        }, 400);
    });
</script>
</body>
</html>