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
$error = '';
$success = '';

if (!$quest_id || !$user_id) {
    redirect('dashboard.php');
}

// Get quest details
try {
    $stmt = $pdo->prepare("SELECT * FROM quests WHERE id = ?");
    $stmt->execute([$quest_id]);
    $quest = $stmt->fetch();
    
    if (!$quest) {
        redirect('dashboard.php');
    }
} catch (PDOException $e) {
    error_log("Error fetching quest: " . $e->getMessage());
    redirect('dashboard.php');
}

// Get user details
try {
    $stmt = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        redirect('dashboard.php');
    }
} catch (PDOException $e) {
    error_log("Error fetching user: " . $e->getMessage());
    redirect('dashboard.php');
}

// Get quest skills
$skillManager = new SkillProgression($pdo);
$quest_skills = $skillManager->getQuestSkills($quest_id);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {
    $assessments = $_POST['assessments'] ?? [];
    $total_points = 0;
    $awarded_skills = [];
    
    try {
        $pdo->beginTransaction();
        
        foreach ($assessments as $skill_name => $assessment) {
            $performance = (float)$assessment['performance'];
            $base_points = (int)$assessment['base_points'];
            $notes = sanitize_user_input($assessment['notes'] ?? '');
            
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
        case 'T1': return 25;
        case 'T2': return 40;
        case 'T3': return 55;
        case 'T4': return 70;
        case 'T5': return 85;
        default: return 25;
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
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        
        .assessment-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .assessment-header {
            background: linear-gradient(135deg, #1e293b, #334155);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .quest-title { font-size: 1.8rem; font-weight: bold; margin-bottom: 10px; }
        .user-info { font-size: 1.2rem; color: #cbd5e1; }
        
        .assessment-content { padding: 30px; }
        
        .section-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: #1e293b;
            margin: 30px 0 20px 0;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
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
            font-size: 1.2rem;
            font-weight: bold;
            color: #1e293b;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .skill-checkbox {
            width: 20px;
            height: 20px;
            border: 2px solid #64748b;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
        }
        
        .tier-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #10b981;
        }
        
        .base-tier { font-weight: bold; color: #059669; }
        .base-points { font-weight: bold; color: #1e293b; }
        
        .performance-section {
            margin: 15px 0;
        }
        
        .performance-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 10px;
        }
        
        .performance-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .performance-option {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
        }
        
        .performance-option:hover {
            border-color: #4338ca;
            background: #f0f9ff;
        }
        
        .performance-option.selected {
            border-color: #10b981;
            background: #ecfdf5;
        }
        
        .performance-option input[type="radio"] {
            display: none;
        }
        
        .option-label { font-weight: 600; color: #374151; }
        .option-multiplier { color: #6b7280; font-size: 0.9rem; }
        .option-points { color: #059669; font-weight: bold; }
        
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
        
        .total-section {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin: 30px 0;
            text-align: center;
        }
        
        .total-points {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .points-breakdown {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
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
    </style>
</head>
<body>
    <div class="assessment-container">
        <div class="assessment-header">
            <div class="quest-title">QUEST: <?= htmlspecialchars($quest['title']) ?></div>
            <div class="user-info">USER: <?= htmlspecialchars($user['full_name']) ?></div>
        </div>
        
        <div class="assessment-content">
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
            
            <form method="post" id="assessmentForm">
                <?php foreach ($quest_skills as $skill): ?>
                    <?php 
                    $base_points = getTierPoints($skill['tier_level']);
                    ?>
                    <div class="skill-assessment">
                        <div class="skill-name">
                            <div class="skill-checkbox">[ ]</div>
                            <?= htmlspecialchars($skill['skill_name']) ?>
                        </div>
                        
                        <div class="tier-info">
                            <span class="base-tier">Base Tier: <?= htmlspecialchars($skill['tier_level']) ?> (<?= $base_points ?> pts)</span>
                        </div>
                        
                        <div class="performance-section">
                            <div class="performance-label">Performance:</div>
                            <div class="performance-options">
                                <label class="performance-option" onclick="selectPerformance(this, '<?= $skill['skill_name'] ?>', 0.7, <?= $base_points ?>)">
                                    <input type="radio" name="assessments[<?= $skill['skill_name'] ?>][performance]" value="0.7">
                                    <div class="option-label">Below Expectations (-30%)</div>
                                    <div class="option-points"><?= round($base_points * 0.7) ?> pts</div>
                                </label>
                                
                                <label class="performance-option selected" onclick="selectPerformance(this, '<?= $skill['skill_name'] ?>', 1.0, <?= $base_points ?>)">
                                    <input type="radio" name="assessments[<?= $skill['skill_name'] ?>][performance]" value="1.0" checked>
                                    <div class="option-label">Meets Expectations (+0%)</div>
                                    <div class="option-points"><?= $base_points ?> pts</div>
                                </label>
                                
                                <label class="performance-option" onclick="selectPerformance(this, '<?= $skill['skill_name'] ?>', 1.25, <?= $base_points ?>)">
                                    <input type="radio" name="assessments[<?= $skill['skill_name'] ?>][performance]" value="1.25">
                                    <div class="option-label">Exceeds Expectations (+25%)</div>
                                    <div class="option-points"><?= round($base_points * 1.25) ?> pts</div>
                                </label>
                                
                                <label class="performance-option" onclick="selectPerformance(this, '<?= $skill['skill_name'] ?>', 1.5, <?= $base_points ?>)">
                                    <input type="radio" name="assessments[<?= $skill['skill_name'] ?>][performance]" value="1.5">
                                    <div class="option-label">Exceptional (+50%)</div>
                                    <div class="option-points"><?= round($base_points * 1.5) ?> pts</div>
                                </label>
                            </div>
                            
                            <div class="skill-result">
                                Adjusted: <span class="adjusted-points" data-skill="<?= $skill['skill_name'] ?>"><?= $base_points ?></span> pts
                            </div>
                        </div>
                        
                        <div class="notes-section">
                            <label for="notes_<?= $skill['skill_name'] ?>">Notes:</label>
                            <textarea 
                                name="assessments[<?= $skill['skill_name'] ?>][notes]" 
                                id="notes_<?= $skill['skill_name'] ?>"
                                class="notes-textarea" 
                                placeholder="Add assessment notes..."
                            ></textarea>
                        </div>
                        
                        <input type="hidden" name="assessments[<?= $skill['skill_name'] ?>][base_points]" value="<?= $base_points ?>">
                    </div>
                <?php endforeach; ?>
                
                <div class="total-section">
                    <div class="total-points">TOTAL POINTS: <span id="totalPoints"><?= array_sum(array_map(fn($s) => getTierPoints($s['tier_level']), $quest_skills)) ?></span></div>
                    <div class="points-breakdown" id="pointsBreakdown">
                        (<?= implode(' + ', array_map(fn($s) => getTierPoints($s['tier_level']), $quest_skills)) ?>)
                    </div>
                </div>
                
                <div class="submit-section">
                    <button type="submit" name="submit_assessment" class="btn-submit">
                        <i class="fas fa-trophy"></i> Submit Assessment & Award Points
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function selectPerformance(element, skillName, multiplier, basePoints) {
            // Remove selected class from siblings
            element.parentNode.querySelectorAll('.performance-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            element.classList.add('selected');
            
            // Update adjusted points
            const adjustedPoints = Math.round(basePoints * multiplier);
            document.querySelector(`[data-skill="${skillName}"]`).textContent = adjustedPoints;
            
            // Update total
            updateTotal();
        }
        
        function updateTotal() {
            let total = 0;
            const breakdown = [];
            
            document.querySelectorAll('.adjusted-points').forEach(span => {
                const points = parseInt(span.textContent);
                total += points;
                breakdown.push(points);
            });
            
            document.getElementById('totalPoints').textContent = total;
            document.getElementById('pointsBreakdown').textContent = `(${breakdown.join(' + ')})`;
        }
        
        // Initialize
        updateTotal();
    </script>
</body>
</html>