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
$step = isset($_GET['step']) ? (int)$_GET['step'] : null;

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
            $job_position = sanitize_user_input($_POST['job_position'] ?? '');
            
            // Validate required fields
            if (empty($quest_interests)) {
                $error = "Please select at least one quest interest.";
                break;
            }
            
            if (empty($availability)) {
                $error = "Please select your availability.";
                break;
            }
            
            try {
                // First ensure the required columns exist
                $columns_check = $pdo->query("SHOW COLUMNS FROM users LIKE 'job_position'");
                if ($columns_check->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN job_position VARCHAR(50) AFTER availability");
                }
                
                // Now perform the update
                $stmt = $pdo->prepare("UPDATE users SET quest_interests = ?, availability = ?, job_position = ?, profile_completed = 1 WHERE id = ?");
                if ($stmt->execute([implode(',', $quest_interests), $availability, $job_position, $user_id])) {
                    redirect('dashboard.php?welcome=1');
                } else {
                    $error = "Failed to save your preferences. Please try again.";
                }
            } catch (PDOException $e) {
                error_log("Error completing profile: " . $e->getMessage());
                $error = "A database error occurred. Please try again.";
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

// If the step wasn't specified in the URL, infer the most relevant step from saved data
if ($step === null) {
    // Default to step 1
    $inferred = 1;
    // If there are skills saved, user likely is on step 2
    if (!empty($user_skills)) {
        $inferred = 2;
    }
    // If quest preferences / availability or job_position were already set, move to step 3
    if (!empty($user['quest_interests']) || !empty($user['availability']) || !empty($user['job_position']) || (!empty($user) && ($user['profile_completed'] ?? 0))) {
        $inferred = 3;
    }
    $step = $inferred;
}

// If view mode is requested, also load quest interests and availability
// view handled in separate profile_view.php

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

// Job positions mapping (value => label)
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
            position: relative;
        }
        
        .setup-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>') repeat;
        }
        
        .setup-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0 0 10px 0;
            position: relative;
            z-index: 1;
        }
        
        .setup-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin: 0;
            position: relative;
            z-index: 1;
        }
        
        .progress-bar {
            background: rgba(255, 255, 255, 0.2);
            height: 6px;
            border-radius: 3px;
            margin-top: 30px;
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        
        .progress-fill {
            background: white;
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .step-indicators {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
            position: relative;
            z-index: 1;
        }
        .unselect-skill-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 1.0rem;
        }

        .removed-note {
            background: rgba(0,0,0,0.04);
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 0.9rem;
            color: #333;
        }
        
        .step-indicator {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .step-indicator.active {
            background: white;
            color: #4338ca;
            transform: scale(1.1);
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
            animation: fadeInUp 0.5s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .step-title {
            font-size: 1.8rem;
            color: #1f2937;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .step-description {
            color: #6b7280;
            margin-bottom: 30px;
            font-size: 1rem;
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
        
        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        
        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: #4338ca;
            box-shadow: 0 0 0 3px rgba(67, 56, 202, 0.1);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        /* Photo Upload Styling */
        .photo-upload {
            border: 2px dashed #d1d5db;
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            background: #f9fafb;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .photo-upload:hover {
            border-color: #4338ca;
            background: #f0f4ff;
        }
        
        .photo-upload.dragover {
            border-color: #4338ca;
            background: #e0e7ff;
        }
        
        .photo-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 20px;
            border: 4px solid white;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .upload-icon {
            font-size: 3rem;
            color: #9ca3af;
            margin-bottom: 15px;
        }
        
        .upload-text {
            color: #6b7280;
            font-size: 1rem;
        }
        
        /* Skills Grid */
        .skills-grid {
            display: grid;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .skill-category {
            background: #f8fafc;
            border-radius: 15px;
            padding: 25px;
            border: 1px solid #e2e8f0;
        }
        
        .category-title {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Group icon + name on the left and push actions to the right */
        .category-title .category-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .category-title .category-name {
            display: inline-block;
        }

        .category-title .category-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
        }

    /* ...existing styles... */
        .skill-category.clickable { cursor: pointer; }
        
        .add-other-skill-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .add-other-skill-btn:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .skills-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .skill-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s ease;
        }
        
        .skill-item:hover {
            border-color: #4338ca;
            box-shadow: 0 2px 8px rgba(67, 56, 202, 0.1);
            transform: translateY(-1px);
        }
        
        .skill-item:hover .skill-name {
            color: #4338ca;
        }
        
        .skill-item:hover .level-dot {
            transform: scale(1.05);
        }
        
        /* Add cursor pointer to skill items to indicate they're interactive */
        .skill-level {
            cursor: pointer;
        }
        
        /* Show different cursor when skill is selected (can be unselected) */
        .skill-item.skill-selected {
            cursor: pointer;
            border-color: #10b981;
        }
        
        .skill-item.skill-selected:hover {
            border-color: #ef4444;
            background-color: #fef2f2;
        }
        
        .skill-item.skill-selected:hover .skill-name {
            color: #ef4444;
        }
        
        .skill-item.skill-selected:hover .skill-name::after {
            content: " (click to unselect)";
            font-size: 0.8em;
            color: #ef4444;
            font-weight: normal;
        }

    /* ...existing styles... */
        
        .skill-name {
            font-weight: 500;
            color: #374151;
        }
        
        .skill-level {
            display: flex;
            gap: 4px;
        }
        
        .level-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d1d5db;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        
        .level-dot.active {
            background: #4338ca;
        }
        
        .level-dot:hover {
            background: #6366f1;
        }
        
        /* Quest Interests Grid */
        .quest-interests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .quest-interest-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: white;
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .quest-interest-item:hover {
            border-color: #4338ca;
            box-shadow: 0 4px 12px rgba(67, 56, 202, 0.1);
            transform: translateY(-2px);
        }
        
        .quest-interest-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #4338ca;
            cursor: pointer;
        }
        
        .quest-interest-item label {
            margin: 0;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            flex: 1;
        }
        
        .quest-interest-item:has(input:checked) {
            border-color: #4338ca;
            background: linear-gradient(135deg, #f0f4ff, #e0e7ff);
        }
        
        /* Custom Skills for Other button */
        .custom-skill-item {
            position: relative;
        }
        
        .remove-skill-btn {
            background: #ef4444;
            color: white;
            border: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            margin-left: 8px;
        }
        
        .remove-skill-btn:hover {
            background: #dc2626;
            transform: scale(1.1);
        }
        
        /* Navigation Buttons */
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            gap: 20px;
        }
        
        .btn {
            padding: 14px 28px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4338ca, #6366f1);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(67, 56, 202, 0.3);
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        
        /* Success state for unselect button feedback */
        .btn-success {
            background: #10b981 !important;
            color: white !important;
            border: 1px solid #10b981 !important;
            transition: all 0.3s ease;
        }
        
        .btn-success:hover {
            background: #059669 !important;
        }
        
        /* Skill item hover effect for better UX */
        .skill-item:hover .level-dot {
            transform: scale(1.1);
        }
        
        .level-dot {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .level-dot:hover {
            transform: scale(1.2);
            background: #6366f1 !important;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 10px;
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
            
            .skills-list {
                grid-template-columns: 1fr;
            }
            
            .quest-interests-grid {
                grid-template-columns: 1fr;
            }
            
            .category-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .add-other-skill-btn {
                align-self: flex-start;
                font-size: 0.8rem;
                padding: 5px 10px;
            }
            
            .nav-buttons {
                flex-direction: column;
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

        /* Autocomplete styles for Job Position */
        .autocomplete {
            position: relative;
        }

        .autocomplete-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-top: none;
            max-height: 220px;
            overflow-y: auto;
            z-index: 50;
            box-shadow: 0 6px 20px rgba(67,56,202,0.08);
            display: none;
        }

        .autocomplete-item {
            padding: 10px 12px;
            cursor: pointer;
            font-size: 0.95rem;
            color: #374151;
        }

        .autocomplete-item:hover, .autocomplete-item.active {
            background: linear-gradient(135deg, #f0f4ff, #e8edff);
            color: #1f2937;
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
            <h1 class="setup-title">Welcome to YooNet!</h1>
            <p class="setup-subtitle">Let's set up your profile to get you started</p>
            
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
            
            <!-- Step 1: Basic Info & Photo -->
            <div class="step-content <?= $step == 1 ? 'active' : '' ?>">
                <h2 class="step-title"><i class="fas fa-user-circle"></i> Basic Information</h2>
                <p class="step-description">Tell us about yourself and add a profile photo to personalize your account.</p>
                
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="step" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Profile Photo</label>
                        <div class="photo-upload" onclick="document.getElementById('photo-input').click()">
                            <input type="file" id="photo-input" name="profile_photo" accept="image/*" style="display: none;" onchange="previewPhoto(this)">
                            <div id="photo-preview-container">
                                <?php if (!empty($user['profile_photo']) && file_exists($user['profile_photo'])): ?>
                                    <img src="<?= htmlspecialchars($user['profile_photo']) ?>" alt="Profile" class="photo-preview">
                                <?php else: ?>
                                    <div class="upload-icon">
                                        <i class="fas fa-camera-retro"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="upload-text">
                                    <strong>Click to upload</strong> or drag and drop<br>
                                    <small>PNG, JPG, GIF up to 5MB</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="full_name" class="form-label">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-input" 
                               value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="bio" class="form-label">Bio (Optional)</label>
                        <textarea id="bio" name="bio" class="form-textarea" 
                                  placeholder="Tell us a bit about yourself, your interests, and what motivates you..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="nav-buttons">
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Skip Setup
                        </a>
                        <button type="submit" class="btn btn-primary">
                            Next: Add Skills <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Step 2: Skills -->
            <div class="step-content <?= $step == 2 ? 'active' : '' ?>">
                <h2 class="step-title"><i class="fas fa-tools"></i> Your Skills</h2>
                <p class="step-description">Select your skills and rate your proficiency level. This helps us match you with suitable quests.</p>
                <!-- global skill actions removed -->
                
                <form method="post">
                    <input type="hidden" name="step" value="2">
                    
                    <div class="skills-grid">
                        <?php foreach ($skill_categories as $category => $skills): ?>
                            <div class="skill-category">
                                <div class="category-title">
                                    <div class="category-left">
                                        <i class="fas fa-<?= $category == 'Technical Skills' ? 'code' : ($category == 'Design Skills' ? 'palette' : ($category == 'Business Skills' ? 'chart-line' : 'heart')) ?>"></i>
                                        <span class="category-name"><?= $category ?></span>
                                    </div>
                                    <?php if (in_array($category, ['Technical Skills', 'Design Skills', 'Business Skills', 'Soft Skills'])): ?>
                                        <div class="category-actions">
                                            <button type="button" class="btn btn-secondary" id="unselect-toggle-<?= str_replace(' ', '-', strtolower($category)) ?>" onclick="toggleCategoryUnselectMode('<?= $category ?>')" title="Unselect all skills in this category">
                                                <i class="fas fa-minus-circle"></i> Unselect All
                                            </button>
                                            <button type="button" class="add-other-skill-btn" onclick="addOtherSkill('<?= $category ?>')">
                                                <i class="fas fa-plus"></i> Other
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="skills-list" id="skills-<?= str_replace(' ', '-', strtolower($category)) ?>">
                                    <?php foreach ($skills as $skill): ?>
                                        <div class="skill-item" onclick="handleSkillBoxClick(event, '<?= $skill ?>')">
                                            <span class="skill-name"><?= $skill ?></span>
                                            <div class="skill-level" data-skill="<?= $skill ?>">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <div class="level-dot <?= (isset($user_skills[$skill]) && $user_skills[$skill] >= $i) ? 'active' : '' ?>" 
                                                         data-level="<?= $i ?>" onclick="event.stopPropagation(); setSkillLevel('<?= $skill ?>', <?= $i ?>)"></div>
                                                <?php endfor; ?>
                                            </div>
                                            <input type="hidden" name="skills[<?= $skill ?>]" value="<?= $user_skills[$skill] ?? 0 ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="nav-buttons">
                        <a href="profile_setup.php?step=1" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Previous
                        </a>
                        <button type="submit" class="btn btn-primary">
                            Next: Role Preferences <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Step 3: Quest Interests & Availability -->
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
                    
                   

                        <label for="availability" class="form-label">Availability</label>
                        <select id="availability" name="availability" class="form-select">
                            <option value="">Select your availability</option>
                            <option value="full_time" <?= ($user['availability'] ?? '') == 'full_time' ? 'selected' : '' ?>>Full-time (40+ hours/week)</option>
                            <option value="part_time" <?= ($user['availability'] ?? '') == 'part_time' ? 'selected' : '' ?>>Part-time (20-40 hours/week)</option>
                            <option value="casual" <?= ($user['availability'] ?? '') == 'casual' ? 'selected' : '' ?>>Casual (Less than 20 hours/week)</option>
                            <option value="project_based" <?= ($user['availability'] ?? '') == 'project_based' ? 'selected' : '' ?>>Project-based (Flexible timing)</option>
                        </select>
                        <div class="form-group">
                        <label for="job_position_input" class="form-label">Job Position</label>
                        <div class="autocomplete" style="display:flex;align-items:flex-start;gap:8px;">
                            <div style="flex:1;min-width:0;">
                                <input type="text" id="job_position_input" class="form-input" placeholder="Start typing to search..." autocomplete="off" value="<?= htmlspecialchars($job_positions[$user['job_position']] ?? '') ?>">
                                <input type="hidden" id="job_position" name="job_position" value="<?= htmlspecialchars($user['job_position'] ?? '') ?>">
                                <div class="autocomplete-list" id="job-position-list" role="listbox" aria-label="Job position suggestions"></div>
                            </div>
                            <button type="button" id="job-unselect-btn" class="btn btn-secondary" style="white-space:nowrap;display:none;" onclick="unselectRole()">
                                <i class="fas fa-times"></i> Unselect
                            </button>
                        </div>
                    </div>
                    
                    <div class="nav-buttons">
                        <a href="profile_setup.php?step=2" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Previous
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> Complete Setup
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Job position autocomplete data populated from PHP
        const JOB_POSITIONS = <?= json_encode($job_positions) ?>;

        (function() {
            const input = document.getElementById('job_position_input');
            const hidden = document.getElementById('job_position');
            const list = document.getElementById('job-position-list');
            let items = [];
            let activeIndex = -1;

            function buildList(filter) {
                const value = (filter || '').toLowerCase();
                const entries = Object.entries(JOB_POSITIONS)
                    .filter(([key, label]) => label.toLowerCase().includes(value))
                    .slice(0, 20);

                list.innerHTML = '';
                if (entries.length === 0) {
                    list.style.display = 'none';
                    return;
                }

                entries.forEach(([key, label], idx) => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-item';
                    div.setAttribute('role', 'option');
                    div.dataset.value = key;
                    div.innerHTML = label;
                    div.addEventListener('mousedown', function(e) {
                        e.preventDefault(); // prevent blur + re-open
                        selectItem(idx);
                    });
                    list.appendChild(div);
                });

                items = Array.from(list.querySelectorAll('.autocomplete-item'));
                activeIndex = -1;
                list.style.display = 'block';
            }

            function selectItem(index) {
                if (index < 0 || index >= items.length) return;
                const el = items[index];
                const key = el.dataset.value;
                input.value = el.textContent;
                hidden.value = key;
                closeList();
                showHideJobUnselect();
            }

            function closeList() {
                list.innerHTML = '';
                list.style.display = 'none';
                items = [];
                activeIndex = -1;
            }

            input.addEventListener('input', function() {
                const v = this.value.trim();
                if (!v) {
                    hidden.value = '';
                    closeList();
                    showHideJobUnselect();
                    return;
                }
                buildList(v);
            });

            input.addEventListener('keydown', function(e) {
                if (!items.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, items.length - 1);
                    updateActive();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, 0);
                    updateActive();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIndex >= 0) selectItem(activeIndex);
                } else if (e.key === 'Escape') {
                    closeList();
                }
            });

            function updateActive() {
                items.forEach((it, i) => it.classList.toggle('active', i === activeIndex));
                if (activeIndex >= 0 && items[activeIndex]) {
                    items[activeIndex].scrollIntoView({ block: 'nearest' });
                }
            }

            // Close list when clicking outside
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !list.contains(e.target)) {
                    closeList();
                }
            });

            // If the page loads with a preselected value, show the label in input
            (function initValue() {
                const pre = hidden.value || '';
                if (pre && JOB_POSITIONS[pre]) {
                    input.value = JOB_POSITIONS[pre];
                }
                showHideJobUnselect();
            })();

            // Show/hide the job unselect button based on current selection
            function showHideJobUnselect() {
                const btn = document.getElementById('job-unselect-btn');
                if (!btn) return;
                const has = !!(hidden.value && JOB_POSITIONS[hidden.value]);
                btn.style.display = has ? 'inline-flex' : 'none';
            }

            // Clear selected job position
            window.unselectRole = function() {
                hidden.value = '';
                input.value = '';
                closeList();
                showHideJobUnselect();
                input.focus();
            };
        })();
        function previewPhoto(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('photo-preview-container').innerHTML = `
                        <img src="${e.target.result}" alt="Profile Preview" class="photo-preview">
                        <div class="upload-text">
                            <strong>Click to change photo</strong><br>
                            <small>PNG, JPG, GIF up to 5MB</small>
                        </div>
                    `;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function setSkillLevel(skill, level) {
            const skillContainer = document.querySelector(`[data-skill="${skill}"]`);
            const dots = skillContainer.querySelectorAll('.level-dot');
            const hiddenInput = document.querySelector(`input[name="skills[${skill}]"]`);
            
            // Get current level for toggle functionality
            const currentLevel = parseInt(hiddenInput.value) || 0;
            
            // Toggle functionality: if clicking the same level, unselect it
            if (currentLevel === level) {
                // Unselect - set to 0
                dots.forEach(dot => dot.classList.remove('active'));
                hiddenInput.value = 0;
                updateSkillItemState(skill);
                return;
            }
            
            // Reset all dots
            dots.forEach(dot => dot.classList.remove('active'));
            
            // Activate dots up to selected level
            for (let i = 0; i < level; i++) {
                dots[i].classList.add('active');
            }
            
            // Update hidden input
            hiddenInput.value = level;
            updateSkillItemState(skill);
        }
        
        // Update visual state of skill item based on selection
        function updateSkillItemState(skill) {
            const skillItem = document.querySelector(`[data-skill="${skill}"]`).closest('.skill-item');
            const hiddenInput = document.querySelector(`input[name="skills[${skill}]"]`);
            const currentLevel = parseInt(hiddenInput.value) || 0;
            
            if (currentLevel > 0) {
                skillItem.classList.add('skill-selected');
                skillItem.title = 'Click to unselect this skill';
            } else {
                skillItem.classList.remove('skill-selected');
                skillItem.title = 'Click rating dots to select skill level';
            }
        }
        
        // Function to unselect skill when clicking on the skill box
        function unselectSkillBox(skill) {
            const skillContainer = document.querySelector(`[data-skill="${skill}"]`);
            const dots = skillContainer.querySelectorAll('.level-dot');
            const hiddenInput = document.querySelector(`input[name="skills[${skill}]"]`);
            
            // Only unselect if the skill is currently selected
            const currentLevel = parseInt(hiddenInput.value) || 0;
            if (currentLevel > 0) {
                // Clear all dots
                dots.forEach(dot => dot.classList.remove('active'));
                hiddenInput.value = 0;
                
                // Add visual feedback
                const skillItem = skillContainer.closest('.skill-item');
                skillItem.style.transform = 'scale(0.95)';
                skillItem.style.background = '#fef2f2';
                
                // Reset visual feedback after brief moment
                setTimeout(() => {
                    skillItem.style.transform = '';
                    skillItem.style.background = '';
                }, 150);
                
                // Update skill item state
                updateSkillItemState(skill);
            }
        }
        
        // Handle click on skill box (but not on rating dots)
        function handleSkillBoxClick(event, skill) {
            // Don't trigger if clicking on rating dots or buttons
            if (event.target.closest('.level-dot') || event.target.closest('button')) {
                return;
            }
            
            // Only unselect if skill is currently selected
            const hiddenInput = document.querySelector(`input[name="skills[${skill}]"]`);
            const currentLevel = parseInt(hiddenInput.value) || 0;
            
            if (currentLevel > 0) {
                unselectSkillBox(skill);
            }
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
                <button type="button" class="remove-skill-btn" onclick="removeCustomSkill('${skillName}')" title="Remove skill">
                    <i class="fas fa-times"></i>
                </button>
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
        
        function removeCustomSkill(skillName) {
            if (confirm(`Are you sure you want to remove "${skillName}"?`)) {
                const skillItem = document.querySelector(`[data-skill="${skillName}"]`).closest('.skill-item');
                skillItem.remove();
            }
        }

        // Unselect a single skill (used for Soft Skills): clears level and offers a brief undo
        (function() {
            const _undoStore = {};
            window.unselectSkill = function(skillName) {
                const skillContainer = document.querySelector(`[data-skill="${skillName}"]`);
                if (!skillContainer) return;
                const item = skillContainer.closest('.skill-item');
                if (!item) return;
                const hidden = item.querySelector(`input[name="skills[${skillName}]"]`);
                // store previous value for undo
                const prev = hidden ? hidden.value : '0';
                _undoStore[skillName] = prev;
                // clear dots
                skillContainer.querySelectorAll('.level-dot').forEach(d => d.classList.remove('active'));
                if (hidden) hidden.value = 0;
                // show small undo note inline
                const undoNote = document.createElement('div');
                undoNote.className = 'removed-note';
                undoNote.style.display = 'inline-block';
                undoNote.style.marginLeft = '8px';
                undoNote.innerHTML = `Removed <strong>${skillName}</strong>. <button type="button" class="btn btn-link" onclick="restoreSkill('${skillName.replace(/'/g, "\\'")}')">Undo</button>`;
                // append undo note temporarily
                item.appendChild(undoNote);
                setTimeout(() => {
                    if (undoNote.parentNode) undoNote.remove();
                    delete _undoStore[skillName];
                }, 7000); // undo available for 7s
            };

            window.restoreSkill = function(skillName) {
                const skillContainer = document.querySelector(`[data-skill="${skillName}"]`);
                if (!skillContainer) return;
                const item = skillContainer.closest('.skill-item');
                if (!item) return;
                const hidden = item.querySelector(`input[name="skills[${skillName}]"]`);
                const prev = _undoStore[skillName] || 0;
                // restore dots according to previous value
                const val = parseInt(prev, 10) || 0;
                skillContainer.querySelectorAll('.level-dot').forEach((d, i) => d.classList.toggle('active', i < val));
                if (hidden) hidden.value = val;
                // remove any undo notes and show the button
                const undoNote = item.querySelector('.removed-note');
                if (undoNote) undoNote.remove();
                const btn = item.querySelector('.unselect-skill-btn');
                if (btn) btn.style.display = '';
                delete _undoStore[skillName];
            };
        })();

        // Category unselect function: unselects all selected skills in the category
        window.toggleCategoryUnselectMode = function(category) {
            const catId = category.replace(/\s+/g, '-').toLowerCase();
            const wrapper = document.getElementById(`skills-${catId}`);
            const toggleBtn = document.getElementById(`unselect-toggle-${catId}`);
            if (!wrapper || !toggleBtn) return;
            
            // Find all skills in this category that have a level > 0
            const skillItems = wrapper.querySelectorAll('.skill-item');
            let hasSelectedSkills = false;
            let unselectedCount = 0;
            
            skillItems.forEach(item => {
                const hiddenInput = item.querySelector('input[type="hidden"]');
                const skillLevel = item.querySelector('.skill-level');
                
                if (hiddenInput && parseInt(hiddenInput.value) > 0) {
                    hasSelectedSkills = true;
                    // Unselect this skill
                    const dots = skillLevel.querySelectorAll('.level-dot');
                    dots.forEach(dot => dot.classList.remove('active'));
                    hiddenInput.value = 0;
                    unselectedCount++;
                    
                    // Update skill item state
                    const skill = skillLevel.getAttribute('data-skill');
                    if (skill) {
                        updateSkillItemState(skill);
                    }
                }
            });
            
            if (hasSelectedSkills) {
                // Show feedback message
                toggleBtn.innerHTML = `<i class="fas fa-check"></i> Unselected ${unselectedCount} skill${unselectedCount > 1 ? 's' : ''}`;
                toggleBtn.classList.add('btn-success');
                
                // Reset button after 2 seconds
                setTimeout(() => {
                    toggleBtn.innerHTML = '<i class="fas fa-minus-circle"></i> Unselect All';
                    toggleBtn.classList.remove('btn-success');
                }, 2000);
            } else {
                // No skills selected in this category
                toggleBtn.innerHTML = '<i class="fas fa-info-circle"></i> No skills selected';
                toggleBtn.classList.add('btn-secondary');
                
                // Reset button after 2 seconds
                setTimeout(() => {
                    toggleBtn.innerHTML = '<i class="fas fa-minus-circle"></i> Unselect All';
                    toggleBtn.classList.remove('btn-secondary');
                }, 2000);
            }
        };

        // Select/unselect helpers removed per request to simplify UI
        
        // Drag and drop functionality for photo upload
        const photoUpload = document.querySelector('.photo-upload');
        
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
        
        // Initialize skill item states when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize all skill items with their current state
            document.querySelectorAll('.skill-level').forEach(skillLevel => {
                const skill = skillLevel.getAttribute('data-skill');
                if (skill) {
                    updateSkillItemState(skill);
                }
            });
        });
    </script>
</body>
</html>