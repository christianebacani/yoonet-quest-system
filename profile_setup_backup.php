<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// Get current user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        redirect('login.php');
    }
} catch (PDOException $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    $error = "Database error occurred.";
}

// Function to handle photo upload
function handlePhotoUpload($file, $user_id) {
    $upload_dir = 'uploads/profile_photos/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception("Invalid file type. Only JPEG, PNG, and GIF are allowed.");
    }
    
    if ($file['size'] > $max_size) {
        throw new Exception("File size too large. Maximum 5MB allowed.");
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'user_' . $user_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return $filepath;
    } else {
        throw new Exception("Failed to upload file.");
    }
}

// Handle form submissions for different steps
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['step']) {
        case '1': // Basic Info & Photo
            $full_name = sanitize_user_input($_POST['full_name'] ?? '');
            $bio = sanitize_user_input($_POST['bio'] ?? '');
            
            if ($full_name) {
                try {
                    // Handle photo upload if provided
                    $photo_path = null;
                    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                        $photo_path = handlePhotoUpload($_FILES['profile_photo'], $user_id);
                    }
                    
                    $sql = "UPDATE users SET full_name = ?, bio = ?";
                    $params = [$full_name, $bio];
                    
                    if ($photo_path) {
                        $sql .= ", profile_photo = ?";
                        $params[] = $photo_path;
                    }
                    
                    $sql .= " WHERE id = ?";
                    $params[] = $user_id;
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    
                    $success = "Profile updated successfully!";
                    header("Location: profile_setup.php?step=2");
                    exit;
                } catch (Exception $e) {
                    error_log("Error updating profile: " . $e->getMessage());
                    $error = "Failed to update profile.";
                }
            } else {
                $error = "Full name is required.";
            }
            break;
            
        case '2': // Skills
            $skills = $_POST['skills'] ?? [];
            
            try {
                // Delete existing skills
                $stmt = $pdo->prepare("DELETE FROM user_skills WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                // Insert selected skills
                if (!empty($skills)) {
                    $stmt = $pdo->prepare("INSERT INTO user_skills (user_id, skill_name, skill_level) VALUES (?, ?, ?)");
                    foreach ($skills as $skill => $level) {
                        if ($level > 0) {
                            $stmt->execute([$user_id, $skill, $level]);
                        }
                    }
                }
                
                $success = "Skills updated successfully!";
                header("Location: profile_setup.php?step=3");
                exit;
            } catch (PDOException $e) {
                error_log("Error updating skills: " . $e->getMessage());
                $error = "Failed to update skills.";
            }
            break;
            
        case '3': // Quest Interests & Availability
            $quest_interests = $_POST['quest_interests'] ?? [];
            $availability = sanitize_user_input($_POST['availability'] ?? '');
            
            try {
                $stmt = $pdo->prepare("UPDATE users SET quest_interests = ?, availability = ?, profile_completed = 1 WHERE id = ?");
                $stmt->execute([implode(',', $quest_interests), $availability, $user_id]);
                
                redirect('dashboard.php?welcome=1');
            } catch (PDOException $e) {
                error_log("Error completing profile: " . $e->getMessage());
                $error = "Failed to complete profile setup.";
            }
            break;
    }
}

// Get user's current skills
$user_skills = [];
try {
    if ($user_id) {
        $stmt = $pdo->prepare("SELECT skill_name, skill_level FROM user_skills WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_skills = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} catch (PDOException $e) {
    error_log("Error loading user skills: " . $e->getMessage());
    $user_skills = [];
}

// Predefined skills with categories
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Setup - YooNet Quest System</title>
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
        
        .setup-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .setup-header {
            background: linear-gradient(135deg, #4338ca, #6366f1);
            color: white;
            padding: 40px;
            text-align: center;
        }
        
        .setup-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0 0 10px 0;
        }
        
        .setup-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin: 0;
        }
        
        .progress-bar {
            background: rgba(255, 255, 255, 0.2);
            height: 8px;
            border-radius: 4px;
            margin: 30px 0 0 0;
            overflow: hidden;
        }
        
        .progress-fill {
            background: white;
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .step-indicators {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }
        
        .step-indicator {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .step-indicator.active {
            background: white;
            color: #4338ca;
        }
        
        .step-indicator.completed {
            background: #10b981;
            color: white;
        }
        
        .setup-content {
            padding: 40px;
        }
        
        .step-content {
            display: none;
        }
        
        .step-content.active {
            display: block;
        }
        
        .step-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .step-description {
            color: #6b7280;
            margin: 0 0 30px 0;
            line-height: 1.6;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s ease;
            box-sizing: border-box;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #4338ca;
            box-shadow: 0 0 0 3px rgba(67, 56, 202, 0.1);
        }
        
        .photo-upload {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            background: #f9fafb;
        }
        
        .photo-upload:hover {
            border-color: #4338ca;
            background: #f3f4f6;
        }
        
        .photo-upload.dragover {
            border-color: #4338ca;
            background: #eef2ff;
        }
        
        .photo-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 15px;
        }
        
        .skills-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }
        
        .skill-category {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .category-title {
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .skills-list {
            display: grid;
            gap: 10px;
        }
        
        .skill-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .skill-item:hover {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .skill-name {
            font-weight: 500;
            color: #374151;
        }
        
        .skill-level {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        
        .level-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #e5e7eb;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .level-dot.active {
            background: #4338ca;
        }
        
        .level-dot:hover {
            transform: scale(1.2);
        }
        
        .add-skill-btn {
            background: none;
            border: 2px dashed #cbd5e1;
            color: #64748b;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .add-skill-btn:hover {
            border-color: #4338ca;
            color: #4338ca;
        }
        
        .quest-interests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .quest-interest-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .quest-interest-item:hover {
            border-color: #4338ca;
            background: #eef2ff;
        }
        
        .quest-interest-item input[type="checkbox"] {
            margin-right: 12px;
            transform: scale(1.2);
        }
        
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid #e5e7eb;
        }
        
        .btn {
            background: linear-gradient(135deg, #4338ca, #6366f1);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(67, 56, 202, 0.3);
        }
        
        .btn-secondary {
            background: #6b7280;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #fecaca;
        }
        
        .success-message {
            background: #f0fdf4;
            color: #166534;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #bbf7d0;
        }
        
        @media (max-width: 768px) {
            .setup-container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .setup-header {
                padding: 30px 20px;
            }
            
            .setup-title {
                font-size: 2rem;
            }
            
            .setup-content {
                padding: 30px 20px;
            }
            
            .skills-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .quest-interests-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-buttons {
                flex-direction: column;
                gap: 15px;
            }
        }
        
        /* Modal Styles */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            animation: fadeIn 0.2s ease-out;
        }
        
        .modal-container {
            background: white;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s ease-out;
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #4338ca, #6366f1);
            color: white;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f9fafb;
        }
        
        .form-error {
            color: #dc2626;
            font-size: 0.875rem;
            margin-top: 4px;
        }
        
        .form-input.error {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <h1 class="setup-title">Profile Setup</h1>
            <p class="setup-subtitle">Let's get your profile ready for your quest journey!</p>
            
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= ($step / 3) * 100 ?>%"></div>
            </div>
            
            <div class="step-indicators">
                <div class="step-indicator <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">
                    <?= $step > 1 ? '<i class="fas fa-check"></i>' : '1' ?>
                </div>
                <div class="step-indicator <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">
                    <?= $step > 2 ? '<i class="fas fa-check"></i>' : '2' ?>
                </div>
                <div class="step-indicator <?= $step >= 3 ? 'active' : '' ?>">3</div>
            </div>
        </div>
        
        <div class="setup-content">
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <!-- Step 1: Basic Information -->
            <div class="step-content <?= $step == 1 ? 'active' : '' ?>">
                <h2 class="step-title"><i class="fas fa-user"></i> Basic Information</h2>
                <p class="step-description">Tell us about yourself to personalize your quest experience.</p>
                
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="step" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Profile Photo</label>
                        <div class="photo-upload" onclick="document.getElementById('photo-input').click()">
                            <div class="photo-display">
                                <?php if (!empty($user['profile_photo']) && file_exists($user['profile_photo'])): ?>
                                    <img src="<?= htmlspecialchars($user['profile_photo']) ?>" alt="Profile" class="photo-preview">
                                <?php else: ?>
                                    <i class="fas fa-camera" style="font-size: 2rem; color: #9ca3af; margin-bottom: 10px;"></i>
                                <?php endif; ?>
                                <p style="margin: 0; color: #6b7280;">Click to upload or drag and drop</p>
                                <small style="color: #9ca3af;">PNG, JPG, GIF up to 5MB</small>
                            </div>
                        </div>
                        <input type="file" id="photo-input" name="profile_photo" accept="image/*" style="display: none;" onchange="previewPhoto(this)">
                    </div>
                    
                    <div class="form-group">
                        <label for="full_name" class="form-label">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" class="form-input" 
                               value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="bio" class="form-label">Bio</label>
                        <textarea id="bio" name="bio" class="form-textarea" rows="4" 
                                  placeholder="Tell us a bit about yourself, your interests, and what motivates you..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="nav-buttons">
                        <div></div>
                        <button type="submit" class="btn">Next Step <i class="fas fa-arrow-right"></i></button>
                    </div>
                </form>
            </div>
            
            <!-- Step 2: Skills -->
            <div class="step-content <?= $step == 2 ? 'active' : '' ?>">
                <h2 class="step-title"><i class="fas fa-tools"></i> Skills & Expertise</h2>
                <p class="step-description">Select your skills and rate your proficiency level to help us match you with relevant quests.</p>
                
                <form method="post">
                    <input type="hidden" name="step" value="2">
                    
                    <div class="skills-grid">
                        <?php foreach ($skill_categories as $category => $skills): ?>
                            <div class="skill-category">
                                <h3 class="category-title">
                                    <?= htmlspecialchars($category) ?>
                                    <button type="button" class="add-skill-btn" onclick="addOtherSkill('<?= htmlspecialchars($category) ?>')">
                                        <i class="fas fa-plus"></i> Other
                                    </button>
                                </h3>
                                <div class="skills-list" id="skills-<?= strtolower(str_replace(' ', '-', $category)) ?>">
                                    <?php foreach ($skills as $skill): ?>
                                        <div class="skill-item">
                                            <span class="skill-name"><?= htmlspecialchars($skill) ?></span>
                                            <div class="skill-level" data-skill="<?= htmlspecialchars($skill) ?>">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <div class="level-dot <?= ($user_skills[$skill] ?? 0) >= $i ? 'active' : '' ?>" 
                                                         data-level="<?= $i ?>" 
                                                         onclick="setSkillLevel('<?= htmlspecialchars($skill) ?>', <?= $i ?>)"></div>
                                                <?php endfor; ?>
                                            </div>
                                            <input type="hidden" name="skills[<?= htmlspecialchars($skill) ?>]" value="<?= $user_skills[$skill] ?? 0 ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="nav-buttons">
                        <a href="?step=1" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Previous</a>
                        <button type="submit" class="btn">Next Step <i class="fas fa-arrow-right"></i></button>
                    </div>
                </form>
            </div>
            
            <!-- Step 3: Quest Preferences -->
            <div class="step-content <?= $step == 3 ? 'active' : '' ?>">
                <h2 class="step-title"><i class="fas fa-compass"></i> Quest Preferences</h2>
                <p class="step-description">Let us know your interests and availability to customize your quest experience.</p>
                
                <form method="post">
                    <input type="hidden" name="step" value="3">
                    
                    <div class="form-group">
                        <label class="form-label">Quest Interests (Select all that apply)</label>
                        <div class="quest-interests-grid">
                            <?php 
                            $quest_types = ['Development Projects', 'Design Challenges', 'Research Tasks', 'Learning Goals', 'Team Collaboration', 'Innovation Projects'];
                            $user_interests = explode(',', $user['quest_interests'] ?? '');
                            ?>
                            <?php foreach ($quest_types as $type): ?>
                                <div class="quest-interest-item">
                                    <input type="checkbox" id="interest_<?= md5($type) ?>" name="quest_interests[]" value="<?= $type ?>" 
                                           <?= in_array($type, $user_interests) ? 'checked' : '' ?>>
                                    <label for="interest_<?= md5($type) ?>"><?= $type ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="availability" class="form-label">Availability *</label>
                        <select id="availability" name="availability" class="form-select" required>
                            <option value="">Select your availability</option>
                            <option value="Full-time" <?= ($user['availability'] ?? '') == 'Full-time' ? 'selected' : '' ?>>Full-time (40+ hours/week)</option>
                            <option value="Part-time" <?= ($user['availability'] ?? '') == 'Part-time' ? 'selected' : '' ?>>Part-time (20-40 hours/week)</option>
                            <option value="Limited" <?= ($user['availability'] ?? '') == 'Limited' ? 'selected' : '' ?>>Limited (10-20 hours/week)</option>
                            <option value="Weekends" <?= ($user['availability'] ?? '') == 'Weekends' ? 'selected' : '' ?>>Weekends only</option>
                        </select>
                    </div>
                    
                    <div class="nav-buttons">
                        <a href="?step=2" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Previous</a>
                        <button type="submit" class="btn">Complete Setup <i class="fas fa-check"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function previewPhoto(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const photoDisplay = document.querySelector('.photo-display');
                    photoDisplay.innerHTML = `
                        <img src="${e.target.result}" alt="Profile Preview" class="photo-preview">
                        <p style="margin: 0; color: #6b7280;">Click to change photo</p>
                    `;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function setSkillLevel(skill, level) {
            const skillLevelDiv = document.querySelector(`[data-skill="${skill}"]`);
            const hiddenInput = document.querySelector(`input[name="skills[${skill}]"]`);
            const dots = skillLevelDiv.querySelectorAll('.level-dot');
            
            // Update visual dots
            dots.forEach((dot, index) => {
                if (index < level) {
                    dot.classList.add('active');
                } else {
                    dot.classList.remove('active');
                }
            });
            
            // Update hidden input value
            hiddenInput.value = level;
        }
        
        function addOtherSkill(category) {
            showAddSkillModal(category);
        }
        
        function showAddSkillModal(category) {
            // Create modal backdrop
            const modalBackdrop = document.createElement('div');
            modalBackdrop.className = 'modal-backdrop';
            modalBackdrop.innerHTML = `
                <div class="modal-container">
                    <div class="modal-header">
                        <h3>Add ${category} Skill</h3>
                        <button type="button" class="modal-close" onclick="closeAddSkillModal()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="custom-skill-input" class="form-label">Skill Name</label>
                            <input type="text" id="custom-skill-input" class="form-input" placeholder="Enter your ${category.toLowerCase()} skill" maxlength="50" required>
                            <div class="form-error" id="skill-error" style="display: none;"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeAddSkillModal()">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="confirmAddSkill('${category}')">Add Skill</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modalBackdrop);
            
            // Focus on input
            setTimeout(() => {
                document.getElementById('custom-skill-input').focus();
            }, 100);
            
            // Close modal on backdrop click
            modalBackdrop.addEventListener('click', function(e) {
                if (e.target === modalBackdrop) {
                    closeAddSkillModal();
                }
            });
            
            // Handle Enter key
            document.getElementById('custom-skill-input').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    confirmAddSkill(category);
                }
            });
        }
        
        function closeAddSkillModal() {
            const modalBackdrop = document.querySelector('.modal-backdrop');
            if (modalBackdrop) {
                modalBackdrop.remove();
            }
        }
        
        function confirmAddSkill(category) {
            const skillInput = document.getElementById('custom-skill-input');
            const errorDiv = document.getElementById('skill-error');
            const skillName = skillInput.value.trim();
            
            // Clear previous errors
            errorDiv.style.display = 'none';
            skillInput.classList.remove('error');
            
            // Validate input
            if (!skillName) {
                showSkillError('Please enter a skill name');
                return;
            }
            
            if (skillName.length < 2) {
                showSkillError('Skill name must be at least 2 characters long');
                return;
            }
            
            // Check if skill already exists
            const existingSkill = document.querySelector(`[data-skill="${skillName}"]`);
            if (existingSkill) {
                showSkillError('This skill already exists!');
                return;
            }
            
            // Get the container for this category
            const categoryId = category.replace(' ', '-').toLowerCase();
            const skillsList = document.getElementById(`skills-${categoryId}`);
            
            // Create new skill item
            const skillItem = document.createElement('div');
            skillItem.className = 'skill-item custom-skill-item';
            skillItem.innerHTML = `
                <span class="skill-name">${skillName}</span>
                <div class="skill-level" data-skill="${skillName}">
                    ${Array.from({length: 5}, (_, i) => 
                        `<div class="level-dot" data-level="${i + 1}" onclick="setSkillLevel('${skillName}', ${i + 1})"></div>`
                    ).join('')}
                </div>
                <input type="hidden" name="skills[${skillName}]" value="0">
            `;
            
            // Add to the skills list
            skillsList.appendChild(skillItem);
            
            // Close modal
            closeAddSkillModal();
        }
        
        function showSkillError(message) {
            const errorDiv = document.getElementById('skill-error');
            const skillInput = document.getElementById('custom-skill-input');
            
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
            skillInput.classList.add('error');
            skillInput.focus();
        }
        
        // Drag and drop functionality for photo upload
        const photoUpload = document.querySelector('.photo-upload');
        
        if (photoUpload) {
            photoUpload.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            photoUpload.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            photoUpload.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    document.getElementById('photo-input').files = files;
                    previewPhoto(document.getElementById('photo-input'));
                }
            });
        }
    </script>
</body>
</html>