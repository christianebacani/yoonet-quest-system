<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

// Initialize variables with default values
$leaderboard = [];
$user_stats = [
    'total_xp' => 0,
    'level' => 1,
    'rank' => 'Newbie',
    'progress_percent' => 0,
    'progress_text' => '0/50 XP'
];
$user_position = 'N/A';

// Get leaderboard data
try {
    // Main leaderboard query
    $stmt = $pdo->query("
        SELECT 
            u.employee_id, 
            u.full_name, 
            u.email,
            COALESCE(SUM(xh.xp_change), 0) as total_xp,
            FLOOR(COALESCE(SUM(xh.xp_change), 0) / 50 + 1) as level,
            CASE 
                WHEN COALESCE(SUM(xh.xp_change), 0) >= 200 THEN 'Expert'
                WHEN COALESCE(SUM(xh.xp_change), 0) >= 100 THEN 'Adventurer'
                WHEN COALESCE(SUM(xh.xp_change), 0) >= 50 THEN 'Explorer'
                ELSE 'Newbie'
            END as rank
        FROM users u
        LEFT JOIN xp_history xh ON u.employee_id = xh.employee_id
        GROUP BY u.employee_id, u.full_name, u.email
        ORDER BY total_xp DESC
        LIMIT 50
    ");
    $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get current user's XP total
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(xp_change), 0) as total_xp
        FROM xp_history
        WHERE employee_id = ?
    ");
    $stmt->execute([$_SESSION['employee_id']]);
    $total_xp = $stmt->fetchColumn();

    // Calculate user stats
    $level = floor($total_xp / 50) + 1;
    $xp_for_next_level = $total_xp % 50;
    $progress_percent = ($xp_for_next_level / 50) * 100;
    
    $user_stats = [
        'total_xp' => $total_xp,
        'level' => $level,
        'rank' => ($total_xp >= 200 ? 'Expert' : 
                 ($total_xp >= 100 ? 'Adventurer' : 
                 ($total_xp >= 50 ? 'Explorer' : 'Newbie'))),
        'progress_percent' => $progress_percent,
        'progress_text' => "$xp_for_next_level/50 XP"
    ];

    // Get user's position
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 as position
        FROM (
            SELECT employee_id, SUM(xp_change) as total_xp 
            FROM xp_history 
            GROUP BY employee_id
            HAVING SUM(xp_change) > ?
        ) as ranked_users
    ");
    $stmt->execute([$total_xp]);
    $user_position = $stmt->fetchColumn() ?: 'N/A';

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error = "Error loading leaderboard data";
}

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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
        }
        
        .rank-badge {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-weight: bold;
            color: white;
        }
        
        .rank-1 {
            background: linear-gradient(135deg, #FFD700 0%, #FFC600 100%);
            box-shadow: 0 4px 6px rgba(251, 191, 36, 0.3);
        }
        
        .rank-2 {
            background: linear-gradient(135deg, #C0C0C0 0%, #D3D3D3 100%);
            box-shadow: 0 4px 6px rgba(209, 213, 219, 0.3);
        }
        
        .rank-3 {
            background: linear-gradient(135deg, #CD7F32 0%, #B87333 100%);
            box-shadow: 0 4px 6px rgba(180, 83, 9, 0.3);
        }
        
        .progress-bar {
            height: 8px;
            border-radius: 4px;
            background-color: #e2e8f0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, #4f46e5, #10b981);
            transition: width 0.5s ease-in-out;
        }
        
        .user-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
        
        .current-user {
            border-left: 4px solid #3b82f6;
            background-color: #f8fafc;
        }
        
        .rank-title {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .title-expert {
            background-color: #8b5cf6;
            color: white;
        }
        
        .title-adventurer {
            background-color: #3b82f6;
            color: white;
        }
        
        .title-explorer {
            background-color: #10b981;
            color: white;
        }
        
        .title-newbie {
            background-color: #64748b;
            color: white;
        }
        
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #3b82f6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .section-header {
            position: relative;
            padding-left: 1.25rem;
        }
        
        .section-header:before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            border-radius: 2px;
            background: linear-gradient(to bottom, #4f46e5, #10b981);
        }
    :root {
        --primary-color: #4285f4;
        --secondary-color: #34a853;
        --background-color: #ffffff;
        --text-color: #333333;
        --card-bg: #f8f9fa;
        --border-color: #e0e0e0;
        --shadow-color: rgba(0, 0, 0, 0.1);
        --transition-speed: 0.4s;
    }

    /* Dark Mode */
    .dark-mode {
        --primary-color: #8ab4f8;
        --secondary-color: #81c995;
        --background-color: #121212;
        --text-color: #e0e0e0;
        --card-bg: #1e1e1e;
        --border-color: #333333;
        --shadow-color: rgba(0, 0, 0, 0.3);
    }

    /* Ocean Theme */
    .ocean-theme {
        --primary-color: #00a1f1;
        --secondary-color: #00c1d4;
        --background-color: #f0f8ff;
        --text-color: #003366;
        --card-bg: #e1f0fa;
        --border-color: #b3d4ff;
    }

    /* Forest Theme */
    .forest-theme {
        --primary-color: #228B22;
        --secondary-color: #2E8B57;
        --background-color: #f0fff0;
        --text-color: #013220;
        --card-bg: #e1fae1;
        --border-color: #98fb98;
    }

    /* Sunset Theme */
    .sunset-theme {
        --primary-color: #FF6B6B;
        --secondary-color: #FFA07A;
        --background-color: #FFF5E6;
        --text-color: #8B0000;
        --card-bg: #FFE8D6;
        --border-color: #FFB347;
    }

    /* Animation for theme change */
    @keyframes fadeIn {
        from { opacity: 0.8; }
        to { opacity: 1; }
    }

    .theme-change {
        animation: fadeIn var(--transition-speed) ease;
    }

    /* Apply transitions to elements that change with theme */
    body {
        background-color: var(--background-color);
        color: var(--text-color);
        transition: background-color var(--transition-speed) ease, 
                    color var(--transition-speed) ease;
    }

    /* Add this to any element that uses theme colors */
    .card, .btn-primary, .btn-secondary, 
    .assignment-section, .section-header, 
    .user-card, .progress-bar, .rank-badge,
    .status-badge, .xp-badge {
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
        
        <!-- User Stats -->
        <div class="user-card p-6 mb-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="flex items-center gap-4">
                    <div class="avatar bg-blue-100 text-blue-600">
                        <?php echo substr($_SESSION['full_name'], 0, 1); ?>
                    </div>
                    <div>
                        <h2 class="font-bold text-lg text-gray-900"><?php echo htmlspecialchars($_SESSION['full_name']); ?></h2>
                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($_SESSION['employee_id']); ?></p>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 w-full md:w-auto">
                    <div class="text-center">
                        <p class="text-sm text-gray-500 mb-1">Rank</p>
                        <p class="text-xl font-bold text-purple-600">#<?php echo $user_position; ?></p>
                    </div>
                    <div class="text-center">
                        <p class="text-sm text-gray-500 mb-1">Total XP</p>
                        <p class="text-xl font-bold text-green-600"><?php echo number_format($user_stats['total_xp']); ?></p>
                    </div>
                    <div class="text-center">
                        <p class="text-sm text-gray-500 mb-1">Level</p>
                        <p class="text-xl font-bold text-blue-600"><?php echo $user_stats['level']; ?></p>
                    </div>
                    <div class="text-center">
                        <p class="text-sm text-gray-500 mb-1">Title</p>
                        <p class="text-xl font-bold <?php 
                            echo $user_stats['rank'] === 'Expert' ? 'text-purple-600' : 
                                 ($user_stats['rank'] === 'Adventurer' ? 'text-blue-600' : 
                                 ($user_stats['rank'] === 'Explorer' ? 'text-green-600' : 'text-gray-600')); 
                        ?>">
                            <?php echo $user_stats['rank']; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="mt-6">
                <div class="flex justify-between text-sm mb-2">
                    <span class="text-gray-600">Progress to level <?php echo $user_stats['level'] + 1; ?></span>
                    <span class="font-medium"><?php echo $user_stats['progress_text']; ?></span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $user_stats['progress_percent']; ?>%"></div>
                </div>
            </div>
        </div>
        
        <!-- Leaderboard -->
        <div class="section-header mb-6">
            <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-trophy text-yellow-500"></i>
                Top Performers
            </h2>
        </div>
        
        <?php if (!empty($leaderboard)): ?>
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">XP</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Level</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progress</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($leaderboard as $index => $user): ?>
                                <?php 
                                    $is_current_user = $user['employee_id'] === $_SESSION['employee_id'];
                                    $xp_for_level = $user['total_xp'] % 50;
                                    $progress_percent = ($xp_for_level / 50) * 100;
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
                                            <div class="avatar mr-3" style="background-color: <?php echo $is_current_user ? '#3b82f6' : '#e2e8f0'; ?>; color: <?php echo $is_current_user ? 'white' : '#64748b'; ?>">
                                                <?php echo substr($user['full_name'], 0, 1); ?>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium <?php echo $is_current_user ? 'text-blue-600' : 'text-gray-900'; ?>">
                                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                                    <?php if ($is_current_user): ?>
                                                        <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full">You</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['employee_id']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo number_format($user['total_xp']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $user['level']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="rank-title <?php 
                                            echo $user['rank'] === 'Expert' ? 'title-expert' : 
                                                 ($user['rank'] === 'Adventurer' ? 'title-adventurer' : 
                                                 ($user['rank'] === 'Explorer' ? 'title-explorer' : 'title-newbie')); 
                                        ?>">
                                            <?php echo $user['rank']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-3">
                                            <div class="w-full max-w-xs">
                                                <div class="flex justify-between text-xs mb-1">
                                                    <span class="text-gray-500">Lvl <?php echo $user['level'] + 1; ?></span>
                                                    <span class="font-medium"><?php echo $xp_for_level; ?>/50</span>
                                                </div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo $progress_percent; ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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