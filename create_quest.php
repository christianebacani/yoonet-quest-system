<?php

require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

// Check if user has quest giver permissions
$role = $_SESSION['role'] ?? '';

// Simple role renaming
if ($role === 'hybrid') {
    $role = 'contributor';
} elseif ($role === 'quest_giver') {
    $role = 'contributor';
}

if (!in_array($role, ['contributor'])) { // was ['quest_giver', 'hybrid']
    header('Location: dashboard.php');
    exit();
}

// Handle AJAX request for group members
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_group_members' && isset($_GET['group_id'])) {
    header('Content-Type: application/json');
    
    try {
        $group_id = intval($_GET['group_id']);
        $current_user_id = $_SESSION['employee_id'];
        $members = [];
        
        $stmt = $pdo->prepare("SELECT u.employee_id, u.full_name 
                              FROM group_members gm
                              JOIN users u ON gm.employee_id = u.employee_id
                              WHERE gm.group_id = ? 
                              AND u.role IN ('participant', 'contributor')
                              AND u.employee_id != ?
                              ORDER BY u.full_name");
        $stmt->execute([$group_id, $current_user_id]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'members' => $members]);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// Create and populate quest_categories table if needed
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quest_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        icon VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Check if table is empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM quest_categories");
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        // Insert default categories
        $defaultCategories = [
            ['name' => 'Training', 'description' => 'Employee training and development', 'icon' => 'fa-graduation-cap'],
            ['name' => 'Project', 'description' => 'Work-related projects', 'icon' => 'fa-project-diagram'],
            ['name' => 'Team Building', 'description' => 'Team activities and bonding', 'icon' => 'fa-users'],
            ['name' => 'Innovation', 'description' => 'Creative and innovative tasks', 'icon' => 'fa-lightbulb'],
            ['name' => 'Administrative', 'description' => 'Office and administrative work', 'icon' => 'fa-file-alt']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO quest_categories (name, description, icon) VALUES (:name, :description, :icon)");
        
        foreach ($defaultCategories as $category) {
            $stmt->execute([
                ':name' => $category['name'],
                ':description' => $category['description'],
                ':icon' => $category['icon']
            ]);
        }
    }
} catch (PDOException $e) {
    error_log("Error setting up categories: " . $e->getMessage());
}

// Create new tables for our enhancements
try {
    // Add quest_type and visibility to quests table
    $pdo->exec("ALTER TABLE quests 
                ADD COLUMN IF NOT EXISTS quest_type ENUM('single', 'recurring') DEFAULT 'single'");
    $pdo->exec("ALTER TABLE quests 
                ADD COLUMN IF NOT EXISTS visibility ENUM('public', 'private') DEFAULT 'public'");
    $pdo->exec("ALTER TABLE quests 
                ADD COLUMN IF NOT EXISTS recurrence_pattern VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE quests 
                ADD COLUMN IF NOT EXISTS recurrence_end_date DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE quests 
                ADD COLUMN IF NOT EXISTS publish_at DATETIME DEFAULT NULL");

    // Create subtasks table
    $pdo->exec("CREATE TABLE IF NOT EXISTS quest_subtasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quest_id INT NOT NULL,
        description TEXT NOT NULL,
        is_completed BOOLEAN DEFAULT false,
        FOREIGN KEY (quest_id) REFERENCES quests(id) ON DELETE CASCADE
    )");

    // Create attachments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS quest_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quest_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        file_size INT NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (quest_id) REFERENCES quests(id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {
    error_log("Error setting up new tables: " . $e->getMessage());
}

$error = '';
$success = '';

// Initialize form variables
$title = '';
$description = '';
$xp = 10;
$category_id = null;
$due_date = null;
$assign_to = [];
$assign_group = null;
$quest_type = 'single';
$visibility = 'public';
$subtasks = [];
$recurrence_pattern = '';
$recurrence_end_date = '';
$publish_at = '';

// Fetch employees, categories, and groups for assignment
$employees = [];
$categories = [];
$groups = [];
try {
    // Get all participants and contributors EXCEPT the current user
    $current_user_id = $_SESSION['employee_id'];
    $stmt = $pdo->prepare("SELECT employee_id, full_name FROM users 
                          WHERE role IN ('participant', 'contributor') 
                          AND employee_id != ?
                          ORDER BY full_name");
    $stmt->execute([$current_user_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all quest categories with icons
    $stmt = $pdo->query("SELECT id, name, icon FROM quest_categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all employee groups
    $stmt = $pdo->query("SELECT id, group_name FROM employee_groups ORDER BY group_name");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching data: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $xp = intval($_POST['xp'] ?? 0);
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $assign_to = isset($_POST['assign_to']) ? $_POST['assign_to'] : [];
    $assign_group = isset($_POST['assign_group']) ? $_POST['assign_group'] : null;
    $status = 'active';
    
    // New fields
    $quest_type = $_POST['quest_type'] ?? 'single';
    $visibility = $_POST['visibility'] ?? 'public';
    $subtasks = isset($_POST['subtasks']) ? array_filter($_POST['subtasks']) : [];
    $recurrence_pattern = $_POST['recurrence_pattern'] ?? '';
    $recurrence_end_date = $_POST['recurrence_end_date'] ?? '';
    $publish_at = $_POST['publish_at'] ?? '';

    // Validate input
    if (empty($title)) {
        $error = 'Title is required';
    } elseif (strlen($title) > 255) {
        $error = 'Title must be less than 255 characters';
    } elseif (empty($description)) {
        $error = 'Description is required';
    } elseif (strlen($description) > 2000) {
        $error = 'Description must be less than 2000 characters';
    } elseif ($xp < 1 || $xp > 100) {
        $error = 'XP must be between 1 and 100';
    } elseif (empty($category_id)) {
        $error = 'Category is required';
    } elseif (!empty($due_date) && !strtotime($due_date)) {
        $error = 'Invalid due date format';
    } elseif ($quest_type == 'recurring' && empty($recurrence_pattern)) {
        $error = 'Recurrence pattern is required for recurring quests';
    } elseif ($quest_type == 'recurring' && !empty($recurrence_end_date) && !strtotime($recurrence_end_date)) {
        $error = 'Invalid recurrence end date format';
    } elseif (!empty($publish_at) && !strtotime($publish_at)) {
        $error = 'Invalid publish date/time format';
    } elseif (empty($assign_to) && empty($assign_group)) {
        $error = 'You must assign the quest to at least one employee or group';
    } else {
        // Validate category exists
        try {
            $stmt = $pdo->prepare("SELECT id FROM quest_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            if (!$stmt->fetch()) {
                $error = 'Selected category does not exist';
            }
        } catch (PDOException $e) {
            $error = 'Error validating category: ' . $e->getMessage();
        }
        
        // Validate assigned employees exist and are quest takers
        if (empty($error) && !empty($assign_to)) {
            try {
                $placeholders = implode(',', array_fill(0, count($assign_to), '?'));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users 
                                      WHERE employee_id IN ($placeholders) 
                                      AND role IN ('participant', 'contributor')");
                $stmt->execute($assign_to);
                $count = $stmt->fetchColumn();
                
                if ($count != count($assign_to)) {
                    $error = 'One or more assigned employees are invalid or not quest takers';
                }
            } catch (PDOException $e) {
                $error = 'Error validating assigned employees: ' . $e->getMessage();
            }
        }
        
        // Validate group exists if selected
        if (empty($error) && !empty($assign_group)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM employee_groups WHERE id = ?");
                $stmt->execute([$assign_group]);
                if (!$stmt->fetch()) {
                    $error = 'Selected group does not exist';
                }
            } catch (PDOException $e) {
                $error = 'Error validating group: ' . $e->getMessage();
            }
        }
        
        // Validate file uploads
        if (empty($error) && !empty($_FILES['attachments']['name'][0])) {
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            $maxTotalSize = 20 * 1024 * 1024; // 20MB total
            $totalSize = 0;
            
            foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileType = $_FILES['attachments']['type'][$key];
                    $fileSize = $_FILES['attachments']['size'][$key];
                    $totalSize += $fileSize;
                    
                    if (!in_array($fileType, $allowedTypes)) {
                        $error = 'Only PDF, JPG, and PNG files are allowed';
                        break;
                    }
                    
                    if ($fileSize > $maxSize) {
                        $error = 'Each file must be less than 5MB';
                        break;
                    }
                } else {
                    $error = 'Error uploading one or more files';
                    break;
                }
            }
            
            if (empty($error) && $totalSize > $maxTotalSize) {
                $error = 'Total attachments size must be less than 20MB';
            }
        }
        
        // Validate subtasks
        if (empty($error) && !empty($subtasks)) {
            foreach ($subtasks as $subtask) {
                if (strlen(trim($subtask)) > 500) {
                    $error = 'Each subtask must be less than 500 characters';
                    break;
                }
            }
        }
        
        // If no validation errors, proceed with database operations
        if (empty($error)) {
            try {
                $pdo->beginTransaction();
                
                // Create the quest
                // Get user id from employee_id
                $stmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
                $stmt->execute([$_SESSION['employee_id']]);
                $user = $stmt->fetch();
                $user_id = $user['id'];
                $stmt = $pdo->prepare("INSERT INTO quests 
                    (title, description, xp, status, due_date, created_by, category_id, created_at,
                     quest_type, visibility, recurrence_pattern, recurrence_end_date, publish_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $title, 
                    $description, 
                    $xp, 
                    $status, 
                    $due_date,
                    $user_id,
                    $category_id,
                    $quest_type,
                    $visibility,
                    $recurrence_pattern,
                    !empty($recurrence_end_date) ? $recurrence_end_date : null,
                    !empty($publish_at) ? $publish_at : null
                ]);
                $quest_id = $pdo->lastInsertId();
                
                // Add subtasks if any
                if (!empty($subtasks)) {
                    $stmt = $pdo->prepare("INSERT INTO quest_subtasks 
                        (quest_id, description) VALUES (?, ?)");
                    foreach ($subtasks as $subtask) {
                        if (!empty(trim($subtask))) {
                            $stmt->execute([$quest_id, trim($subtask)]);
                        }
                    }
                }
                
                // Handle file uploads
                if (!empty($_FILES['attachments']['name'][0])) {
                    $uploadDir = 'uploads/quests/' . $quest_id . '/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
                    $maxSize = 5 * 1024 * 1024; // 5MB
                    
                    foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                            $fileType = $_FILES['attachments']['type'][$key];
                            $fileSize = $_FILES['attachments']['size'][$key];
                            $originalName = basename($_FILES['attachments']['name'][$key]);
                            
                            if (in_array($fileType, $allowedTypes) && $fileSize <= $maxSize) {
                                $newFilename = uniqid() . '.' . pathinfo($originalName, PATHINFO_EXTENSION);
                                $destination = $uploadDir . $newFilename;
                                
                                if (move_uploaded_file($tmp_name, $destination)) {
                                    $stmt = $pdo->prepare("INSERT INTO quest_attachments 
                                        (quest_id, file_name, file_path, file_size, file_type) 
                                        VALUES (?, ?, ?, ?, ?)");
                                    $stmt->execute([
                                        $quest_id,
                                        $originalName,
                                        $destination,
                                        $fileSize,
                                        $fileType
                                    ]);
                                }
                            }
                        }
                    }
                }
                
                // Get employees from group if group is selected
                $group_employees = [];
                if ($assign_group) {
                    $stmt = $pdo->prepare("SELECT employee_id FROM group_members WHERE group_id = ?");
                    $stmt->execute([$assign_group]);
                    $group_employees = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                }
                
                // Combine individual assignments with group assignments
                $all_assignments = array_unique(array_merge($assign_to, $group_employees));
                
                // Assign quest to selected employees
                if (!empty($all_assignments)) {
                    foreach ($all_assignments as $employee_id) {
                        // First check if the employee exists
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                        $stmt->execute([$employee_id]);
                        $userExists = $stmt->fetch();
                        
                        if (!$userExists) {
                            error_log("Attempted to assign quest to non-existent user: " . $employee_id);
                            continue; // Skip this assignment
                        }

                        $stmt = $pdo->prepare("INSERT INTO user_quests 
                            (employee_id, quest_id, status, assigned_at) 
                            VALUES (?, ?, 'assigned', NOW())");
                        $stmt->execute([$employee_id, $quest_id]);
                        
                        // Record in XP history only if user exists
                        $stmt = $pdo->prepare("INSERT INTO xp_history 
                            (employee_id, xp_change, source_type, source_id, description, created_at)
                            VALUES (?, ?, 'quest_assigned', ?, ?, NOW())");
                        $stmt->execute([
                            $employee_id,
                            0, // No XP change on assignment
                            $quest_id,
                            "Quest assigned: $title"
                        ]);
                    }
                }
                
                $pdo->commit();
                
                $assignment_count = count($all_assignments);
                $success = 'Quest created successfully' . 
                           ($assignment_count > 0 ? " and assigned to $assignment_count employee(s)!" : '!');
                
                // Clear form on success
                if ($success) {
                    $title = $description = '';
                    $xp = 10;
                    $assign_to = [];
                    $assign_group = null;
                    $category_id = null;
                    $due_date = null;
                    $subtasks = [];
                    $recurrence_pattern = '';
                    $recurrence_end_date = '';
                    $publish_at = '';
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Database error: " . $e->getMessage());
                $error = 'Error creating quest: ' . $e->getMessage();
            }
        }
    }
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
    <title>Create New Quest</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        /* Flatpickr input alignment and style for due date and custom recurrence */
        #due_date, #customEndDate {
            font-size: 1rem !important;
            font-weight: 500 !important;
            color: #4338ca !important;
            background: #f3f4f6 !important;
            border-radius: 8px !important;
            border: 1.2px solid #c7d2fe !important;
            padding: 8px 14px !important;
            margin-top: 2px !important;
            margin-bottom: 2px !important;
            box-shadow: 0 1px 4px rgba(99,102,241,0.06);
            transition: border 0.2s, box-shadow 0.2s;
        }
        #due_date:focus, #customEndDate:focus {
            border-color: #6366f1 !important;
            box-shadow: 0 0 0 2px #6366f133;
        }
        .flatpickr-calendar {
            min-width: 260px !important;
            width: 320px !important;
            max-width: 340px !important;
            padding: 16px 16px 24px 16px !important;
        }
        .flatpickr-innerContainer {
            min-width: 260px !important;
            width: 320px !important;
            max-width: 340px !important;
        }
        .flatpickr-time {
            padding: 8px 0 8px 0 !important;
            margin-top: 6px !important;
            margin-bottom: 6px !important;
            width: 98% !important;
            left: 1% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
        }
        .flatpickr-time input, .flatpickr-time .flatpickr-am-pm {
            font-size: 1rem !important;
            font-weight: 500 !important;
            color: #1e293b !important; /* Match calendar date color */
            background: #f3f4f6 !important;
            border-radius: 6px !important;
            border: none !important;
            padding: 4px 8px !important;
            margin: 0 1px !important;
            vertical-align: middle !important;
        }
        .flatpickr-time .numInputWrapper {
            background: #f3f4f6 !important;
            border-radius: 6px !important;
        }
        .flatpickr-time .flatpickr-am-pm {
            margin-left: 4px !important;
            display: inline-flex !important;
            align-items: center !important;
            height: 28px !important;
        }
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.07), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .btn-primary {
            background-color: #6366f1;
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            background-color: #4f46e5;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background-color: #e0e7ff;
            color: #4f46e5;
            transition: all 0.2s ease;
        }
        
        .btn-secondary:hover {
            background-color: #c7d2fe;
        }
        
        .assignment-section {
            transition: all 0.3s ease;
            overflow: hidden;
            max-height: 0;
            opacity: 0;
        }
        
        .assignment-section.visible {
            max-height: 1000px;
            opacity: 1;
            margin-top: 1rem;
        }
        
        .select2-container--default .select2-selection--multiple {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.5rem;
            min-height: 42px;
        }
        
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #818cf8;
            box-shadow: 0 0 0 2px rgba(129, 140, 248, 0.2);
        }
        
        /* Updated Select2 styles */
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #e0e7ff;
            border: 1px solid #c7d2fe;
            border-radius: 6px;
            padding: 0.25rem 0.5rem;
            color: #4f46e5;
            display: flex;
            align-items: center;
            flex-direction: row-reverse;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            order: 1;
            margin-left: 5px;
            border-left: none;
            border-right: 1px solid #c7d2fe;
            padding-right: 5px;
            padding-left: 0;
            color: #4f46e5;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice__display {
            order: 2;
        }

        /* Updated clear button styles */
        .select2-container--default .select2-selection--multiple .select2-selection__clear {
            display: none !important; /* Hide the clear button */
        }

        /* Adjust padding to accommodate dropdown arrow */
        .select2-container--default .select2-selection--multiple {
            padding-right: 30px;
        }

        /* Make sure dropdown arrow doesn't overlap */
        .select2-container--default .select2-selection--multiple .select2-selection__arrow {
            right: 10px;
        }
        
        .assignment-tabs {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 1rem;
        }
        
        .assignment-tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            font-weight: 500;
            color: #64748b;
            transition: all 0.2s ease;
        }
        
        .assignment-tab:hover {
            color: #4f46e5;
        }
        
        .assignment-tab.active {
            border-bottom-color: #6366f1;
            color: #6366f1;
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .tab-content.active {
            display: block;
        }
        
        .category-icon {
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
            color: #6366f1;
        }
        
        .xp-input-container {
            position: relative;
            width: 120px;
        }
        
        .xp-input-container input {
            padding-right: 40px;
        }
        
        .xp-badge {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background-color: #e0e7ff;
            color: #4f46e5;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .subtask-item {
            transition: all 0.2s ease;
        }
        
        .subtask-item:hover {
            transform: translateX(2px);
        }
        
        .remove-subtask {
            transition: all 0.2s ease;
        }
        
        .remove-subtask:hover {
            transform: scale(1.1);
            color: #ef4444;
        }

        .flatpickr-calendar {
            font-family: 'Inter', sans-serif;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            border-radius: 12px;
            border: none;
            padding: 12px;
            z-index: 99999 !important;
            min-width: 320px !important;
            width: auto !important;
            max-width: 400px !important;
        }

        .flatpickr-innerContainer {
            overflow: visible !important;
            min-width: 320px !important;
            width: auto !important;
            max-width: 400px !important;
        }

        .flatpickr-days {
            width: 100% !important;
            min-width: 320px !important;
            max-width: 400px !important;
            display: block !important;
        }

        .dayContainer {
            display: grid !important;
            grid-template-columns: repeat(7, 1fr) !important;
            gap: 0 !important;
            width: 100% !important;
            min-width: 320px !important;
            max-width: 400px !important;
        }

        .flatpickr-day {
            color: #1e293b !important;
            border-radius: 6px;
            font-weight: 500;
            border: none;
            width: 40px !important;
            height: 36px !important;
            line-height: 36px !important;
            margin: 2px 0 !important;
            opacity: 1 !important;
            visibility: visible !important;
            box-sizing: border-box !important;
        }
        
        .flatpickr-day.selected, 
        .flatpickr-day.selected:hover {
            background-color: #6366f1 !important;
            color: white !important;
            border-color: #6366f1 !important;
        }
        
        .flatpickr-day.today {
            border-color: #6366f1 !important;
        }
        
        .flatpickr-day.today:hover {
            background-color: #e0e7ff !important;
        }
        
        .flatpickr-day.flatpickr-disabled,
        .flatpickr-day.prevMonthDay,
        .flatpickr-day.nextMonthDay {
            color: #94a3b8 !important;
            opacity: 0.5 !important;
            visibility: visible !important;
        }
        
        .flatpickr-day:hover {
            background: #e0e7ff !important;
        }
        
        .flatpickr-weekday {
            color: #64748b !important;
            font-weight: 500;
            width: calc(100% / 7) !important;
            flex: none !important;
        }
        
        .flatpickr-weekdays,
        .flatpickr-days {
            width: 100% !important;
        }
        
        .flatpickr-days {
            width: 100% !important;
            overflow: visible !important;
        }
        
        .dayContainer {
            width: 100% !important;
            min-width: 100% !important;
            max-width: 100% !important;
            padding: 0 !important;
            justify-content: space-between !important;
        }
        
        .flatpickr-rContainer {
            width: 100% !important;
        }

        /* Recurring options styling */
        .recurring-options {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .recurring-options.visible {
            display: block;
        }

        /* Force horizontal recurrence pattern layout */
        .recurrence-row {
            display: flex !important;
            flex-direction: row !important;
            gap: 1.5rem !important;
            justify-content: center !important;
            align-items: center !important;
            width: 100% !important;
            min-width: 400px !important;
            flex-wrap: wrap !important;
        }
        .recurrence-pattern {
            min-width: 140px !important;
            max-width: 180px !important;
            height: 80px !important;
            flex: 1 1 140px !important;
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.75rem !important;
            padding: 0.5rem 1rem !important;
            margin: 0 !important;
            box-sizing: border-box !important;
        }
        .recurrence-pattern i {
            margin: 0 !important;
        }
        .recurrence-pattern span {
            margin: 0 !important;
            font-size: 1.1rem !important;
            font-weight: 500 !important;
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

        /* Add these new animation styles */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        @keyframes bounceIn {
            0% {
                opacity: 0;
                transform: scale(0.5);
            }
            60% {
                opacity: 1;
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
            }
        }
        
        /* Notification animations */
        .notification-enter {
            animation: slideIn 0.3s ease-out forwards;
        }
        
        .notification-exit {
            animation: fadeOut 0.3s ease-out forwards;
        }
        
        /* Form section animations */
        .form-section {
            opacity: 0;
            transform: translateY(20px);
            animation: slideIn 0.4s ease-out forwards;
        }
        
        /* Delay each section */
        .form-section:nth-child(1) { animation-delay: 0.1s; }
        .form-section:nth-child(2) { animation-delay: 0.2s; }
        .form-section:nth-child(3) { animation-delay: 0.3s; }
        .form-section:nth-child(4) { animation-delay: 0.4s; }
        .form-section:nth-child(5) { animation-delay: 0.5s; }
        .form-section:nth-child(6) { animation-delay: 0.6s; }
        
        /* Button hover effects */
        .btn-animate:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .btn-animate:active {
            transform: translateY(1px);
        }
        
        /* Subtask animations */
        .subtask-enter {
            animation: bounceIn 0.3s ease-out forwards;
        }
        
        .subtask-exit {
            animation: fadeOut 0.2s ease-out forwards;
        }
        
        /* File upload animation */
        .file-upload-hover {
            transition: all 0.3s ease;
        }
        
        .file-upload-hover:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        /* Pulse animation for important elements */
        .pulse-animate {
            animation: pulse 2s infinite;
        }
        
        /* Tab content transition */
        .tab-content {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        
        .tab-content:not(.active) {
            display: none;
            opacity: 0;
            transform: translateX(10px);
        }
        
        .tab-content.active {
            display: block;
            opacity: 1;
            transform: translateX(0);
        }
        
        /* Loading spinner */
        .spin {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* File preview styles */
        .file-preview {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #f8fafc;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .file-preview-info {
            display: flex;
            align-items: center;
            overflow: hidden;
        }
        
        .file-preview-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        
        .remove-file {
            color: #ef4444;
            cursor: pointer;
            margin-left: 8px;
            transition: all 0.2s ease;
        }
        
        .remove-file:hover {
            transform: scale(1.1);
        }
        
        /* Adjust file list container */
        #fileList {
            max-height: 300px;
            overflow-y: auto;
        }

        /* Validation error styles */
        .is-invalid {
            border-color: #ef4444 !important;
        }
        
        .error-message {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body class="<?php echo getBodyClass(); ?>" style="font-size: <?php echo getFontSize(); ?>;">
    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Create New Quest</h1>
                <p class="text-gray-500 mt-1">Design an engaging challenge for your team</p>
            </div>
            <a href="dashboard.php" class="btn btn-navigation btn-back">
                <i class="fas fa-arrow-left btn-icon"></i>
                <span class="btn-text">Back to Dashboard</span>
            </a>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-lg">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-500 mt-1 mr-3"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-red-800">Error</h3>
                        <div class="mt-1 text-sm text-red-700">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6 rounded-lg">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-green-800">Success</h3>
                        <div class="mt-1 text-sm text-green-700">
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" class="card p-8" enctype="multipart/form-data">
            <div class="space-y-6">
                <!-- Basic Information Section -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-info-circle text-indigo-500 mr-2"></i> Basic Information
                    </h2>
                    
                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Quest Title*</label>
                            <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title ?? ''); ?>" 
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                                   placeholder="Enter quest title" required maxlength="255">
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description*</label>
                            <textarea id="description" name="description" rows="4"
                                      class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                                      placeholder="Describe the quest requirements and objectives" required maxlength="2000"><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="category_id" class="block text-sm font-medium text-gray-700 mb-1">Category*</label>
                                <select name="category_id" id="category_id" 
                                        class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300" required>
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo (isset($category_id) && $category_id == $category['id']) ? 'selected' : ''; ?>>
                                            <?php if ($category['icon']): ?>
                                                <i class="<?php echo htmlspecialchars($category['icon']); ?> category-icon"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="due_date" class="block text-sm font-medium text-gray-700 mb-1">Due Date &amp; Time (Optional)</label>
                                <input type="text" id="due_date" name="due_date" 
                                       class="w-full px-4 py-2.5 border border-indigo-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300 shadow-sm transition duration-200"
                                       placeholder="Select due date and time"
                                       value="<?php echo htmlspecialchars($due_date ?? ''); ?>">
                                <p class="mt-1 text-xs text-gray-500">Choose both date and time for quest deadline</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reward & Settings Section -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-gem text-indigo-500 mr-2"></i> Reward & Settings
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="xp" class="block text-sm font-medium text-gray-700 mb-1">XP Reward*</label>
                            <div class="xp-input-container">
                                <input type="number" id="xp" name="xp" value="<?php echo htmlspecialchars($xp ?? 10); ?>"
                                       class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                                       min="1" max="100" required>
                                <span class="xp-badge">XP</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">XP value between 1-100 based on quest difficulty</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Quest Type</label>
                            <div class="flex space-x-4">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="quest_type" value="single" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500" 
                                           <?php echo ($quest_type === 'single' || empty($quest_type)) ? 'checked' : ''; ?>>
                                    <span class="ml-2 text-gray-700">Single Quest</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="quest_type" value="recurring" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500"
                                           <?php echo ($quest_type === 'recurring') ? 'checked' : ''; ?>>
                                    <span class="ml-2 text-gray-700">Recurring Quest</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Recurring Options (Hidden by default) -->
                    <div id="recurringOptions" class="mt-4 recurring-options <?php echo ($quest_type === 'recurring') ? 'visible' : ''; ?>">
                        <div style="width: 100%; display: flex; justify-content: center; align-items: center;">
                            <div style="min-width: 400px; max-width: 500px; width: 100%;">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Recurrence Pattern*</label>
                                <div class="recurrence-patterns recurrence-center">
                                    <div class="recurrence-patterns-inner">
                                        <div class="recurrence-row">
                                            <label class="recurrence-pattern <?php echo $recurrence_pattern == 'daily' ? 'selected' : ''; ?>" style="display: flex; align-items: center; justify-content: center; padding: 1.5rem; border-radius: 12px; border: 1.5px solid #e5e7eb; cursor: pointer; background: <?php echo $recurrence_pattern == 'daily' ? '#eef2ff' : '#fff'; ?>; color: <?php echo $recurrence_pattern == 'daily' ? '#4338ca' : '#374151'; ?>; font-weight: 500; flex-direction: row; gap: 0.75rem;">
                                                <input type="radio" name="recurrence_pattern" value="daily" class="hidden" <?php echo $recurrence_pattern == 'daily' ? 'checked' : ''; ?>><i class="fas fa-redo" style="font-size:2rem;"></i><span>Daily</span>
                                            </label>
                                            <label class="recurrence-pattern <?php echo $recurrence_pattern == 'weekly' ? 'selected' : ''; ?>" style="display: flex; align-items: center; justify-content: center; padding: 1.5rem; border-radius: 12px; border: 1.5px solid #e5e7eb; cursor: pointer; background: <?php echo $recurrence_pattern == 'weekly' ? '#eef2ff' : '#fff'; ?>; color: <?php echo $recurrence_pattern == 'weekly' ? '#4338ca' : '#374151'; ?>; font-weight: 500; flex-direction: row; gap: 0.75rem;">
                                                <input type="radio" name="recurrence_pattern" value="weekly" class="hidden" <?php echo $recurrence_pattern == 'weekly' ? 'checked' : ''; ?>><i class="fas fa-calendar-week" style="font-size:2rem;"></i><span>Weekly</span>
                                            </label>
                                            <label class="recurrence-pattern <?php echo $recurrence_pattern == 'monthly' ? 'selected' : ''; ?>" style="display: flex; align-items: center; justify-content: center; padding: 1.5rem; border-radius: 12px; border: 1.5px solid #e5e7eb; cursor: pointer; background: <?php echo $recurrence_pattern == 'monthly' ? '#eef2ff' : '#fff'; ?>; color: <?php echo $recurrence_pattern == 'monthly' ? '#4338ca' : '#374151'; ?>; font-weight: 500; flex-direction: row; gap: 0.75rem;">
                                                <input type="radio" name="recurrence_pattern" value="monthly" class="hidden" <?php echo $recurrence_pattern == 'monthly' ? 'checked' : ''; ?>><i class="fas fa-calendar-alt" style="font-size:2rem;"></i><span>Monthly</span>
                                            </label>
                                            <label class="recurrence-pattern <?php echo $recurrence_pattern == 'custom' ? 'selected' : ''; ?>" style="display: flex; align-items: center; justify-content: center; padding: 1.5rem; border-radius: 12px; border: 1.5px solid #4338ca; cursor: pointer; background: <?php echo $recurrence_pattern == 'custom' ? '#eef2ff' : '#fff'; ?>; color: <?php echo $recurrence_pattern == 'custom' ? '#4338ca' : '#374151'; ?>; font-weight: 500; flex-direction: row; gap: 0.75rem;">
                                                <input type="radio" name="recurrence_pattern" value="custom" class="hidden" <?php echo $recurrence_pattern == 'custom' ? 'checked' : ''; ?>><i class="fas fa-cog" style="font-size:2rem;"></i><span>Custom</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                    <!-- Custom recurrence settings -->
                                    <div id="customRecurrenceSettings" style="display: <?php echo $recurrence_pattern == 'custom' ? 'block' : 'none'; ?>; margin-top: 1rem;">
                                        <textarea name="custom_recurrence_data" id="customRecurrenceData" rows="3" class="w-full px-4 py-2 border border-gray-200 rounded-lg" placeholder="Describe custom recurrence..."><?php echo htmlspecialchars($custom_recurrence_data ?? ''); ?></textarea>
                                    </div>
                                    <input type="hidden" name="custom_recurrence_data_hidden" id="customRecurrenceDataHidden" value="<?php echo htmlspecialchars($custom_recurrence_data ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Visibility</label>
                        <div class="flex space-x-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="visibility" value="public" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500"
                                       <?php echo ($visibility === 'public' || empty($visibility)) ? 'checked' : ''; ?>>
                                <span class="ml-2 text-gray-700">Public (Visible to all)</span>
                            </label>    
                            <label class="inline-flex items-center">
                                <input type="radio" name="visibility" value="private" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500"
                                       <?php echo ($visibility === 'private') ? 'checked' : ''; ?>>
                                <span class="ml-2 text-gray-700">Private (Only assigned users)</span>
                            </label>
                        </div>
                    </div>

                    <div class="mt-4 schedule-options">
                        <label for="publish_at" class="block text-sm font-medium text-gray-700 mb-1">Schedule Publish (Optional)</label>
                        <input type="text" id="publish_at" name="publish_at" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                               placeholder="Select publish date/time"
                               value="<?php echo htmlspecialchars($publish_at ?? ''); ?>">
                        <p class="mt-1 text-xs text-gray-500">Quest will be automatically published at this time</p>
                    </div>
                </div>

                <!-- Subtasks Section -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-tasks text-indigo-500 mr-2"></i> Subtasks (Optional)
                    </h2>
                    
                    <div id="subtasksContainer" class="space-y-2 mb-4">
                        <?php if (!empty($subtasks)): ?>
                            <?php foreach ($subtasks as $index => $subtask): ?>
                                <?php if (!empty(trim($subtask))): ?>
                                    <div class="subtask-item flex items-center">
                                        <input type="text" name="subtasks[]" value="<?php echo htmlspecialchars(trim($subtask)); ?>" 
                                               class="flex-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                                               placeholder="Enter subtask description" maxlength="500">
                                        <button type="button" class="remove-subtask ml-2 text-gray-400 hover:text-red-500">
                                            <i class="fas fa-times-circle"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <button type="button" id="addSubtask" class="btn-secondary px-4 py-2 rounded-lg text-sm font-medium">
                        <i class="fas fa-plus mr-2"></i> Add Subtask
                    </button>
                </div>

                <!-- Attachments Section -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-paperclip text-indigo-500 mr-2"></i> Attachments (Optional)
                    </h2>
                    
                    <div class="border-2 border-dashed border-gray-200 rounded-lg p-6 text-center file-upload-hover">
                        <input type="file" id="attachments" name="attachments[]" multiple 
                               class="hidden" accept=".pdf,.jpg,.jpeg,.png">
                        <label for="attachments" class="cursor-pointer">
                            <div class="flex flex-col items-center justify-center">
                                <i class="fas fa-cloud-upload-alt text-3xl text-indigo-500 mb-2"></i>
                                <p class="text-sm text-gray-600 mb-1">
                                    <span class="font-medium text-indigo-600">Click to upload</span> or drag and drop
                                </p>
                                <p class="text-xs text-gray-500">PDF, JPG, PNG (Max 5MB each)</p>
                            </div>
                        </label>
                    </div>
                    <div id="fileList" class="mt-2 space-y-2"></div>
                </div>

                <!-- Assignment Section -->
                <div id="assignmentSection">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100 flex justify-between items-center">
                        <span>
                            <i class="fas fa-users text-indigo-500 mr-2"></i> Assignment (Required)
                        </span>
                        <button type="button" id="removeAllEmployees" class="text-sm text-red-500 hover:text-red-700 hidden">
                            <i class="fas fa-times-circle mr-1"></i> Remove all items
                        </button>
                    </h2>
                    
                    <div class="assignment-tabs">
                        <div class="assignment-tab active" data-tab="individual">Assign to Individuals</div>
                        <div class="assignment-tab" data-tab="group">Assign to Group</div>
                    </div>
                    
                    <div class="tab-content active" id="individualTab">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Select Employees*</label>
                        <select name="assign_to[]" id="assign_to" multiple="multiple" 
                                class="w-full">
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo $employee['employee_id']; ?>"
                                    <?php echo in_array($employee['employee_id'], $assign_to) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($employee['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="tab-content" id="groupTab">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Select Group*</label>
                        <select name="assign_group" id="assign_group" 
                                class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300">
                            <option value="">-- Select Group --</option>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>"
                                    <?php echo ($assign_group == $group['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($group['group_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <div id="groupMembersContainer" class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Group Members</label>
                            <div id="groupMembersList" class="bg-gray-50 p-3 rounded-lg min-h-20">
                                <p class="text-sm text-gray-500">Select a group to view members</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-4">
                    <button type="submit" class="btn-primary px-6 py-3 rounded-lg font-medium w-full">
                        <i class="fas fa-plus-circle mr-2"></i> Create Quest
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Recurrence pattern selection visual cue and dynamic End Date box
        $(document).on('click', '.recurrence-pattern', function(e) {
            e.preventDefault();
            var $radio = $(this).find('input[type="radio"]');
            var val = $radio.val();
            // Remove selected from all
            $('.recurrence-pattern').removeClass('selected');
            $('input[name="recurrence_pattern"]').prop('checked', false);
            // Add selected to clicked
            $(this).addClass('selected');
            $radio.prop('checked', true);
            // Show/hide End Date box
            if (val === 'daily' || val === 'weekly' || val === 'monthly') {
                $('#recurrenceEndDateBox').show();
            } else {
                $('#recurrenceEndDateBox').hide();
            }
            // Open custom modal if custom selected
            if (val === 'custom') {
                openCustomRecurrenceModal();
            }
        });

        // Hide End Date and clear value if no pattern is selected
        $(document).on('change', 'input[name="quest_type"]', function() {
            if ($(this).val() !== 'recurring') {
                $('#recurrence_end_date').val('');
                $('#recurrence_end_date').closest('div').hide();
                $('input[name="recurrence_pattern"]').prop('chec    ked', false);
                $('.recurrence-pattern').removeClass('selected');
            }
        });

        // On page load, hide End Date box unless a pattern is selected
        $(document).ready(function() {
            if ($('input[name="recurrence_pattern"]:checked').length) {
                $('#recurrence_end_date').closest('div').show();
            } else {
                $('#recurrence_end_date').closest('div').hide();
                $('#recurrence_end_date').val('');
            }
        });

        function openCustomRecurrenceModal() {
            if ($('#customRecurrenceModal').length === 0) {
                $('body').append(`
                    <div id="customRecurrenceModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
                        <div class="bg-white rounded-lg shadow-lg p-8 max-w-md w-full relative flex flex-col" style="min-width:340px;">
                            <button class="absolute top-2 right-2 text-gray-400 hover:text-gray-700" onclick="$('#customRecurrenceModal').remove()"><i class="fas fa-times"></i></button>
                            <h2 class="text-xl font-bold mb-6 text-indigo-700 flex items-center"><i class="fas fa-cog mr-2"></i> Custom Recurrence</h2>
                            <form id="customRecurrenceForm">
                                <div class="mb-4">
                                    <label class="block text-base font-semibold text-gray-700 mb-2">Step 1: How often should this quest repeat?</label>
                                    <div class="flex gap-2 items-center">
                                        <input type="number" min="1" max="365" value="1" id="customRepeatInterval" class="w-16 px-2 py-1 border border-gray-200 rounded focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300" />
                                        <select id="customRepeatUnit" class="px-2 py-1 border border-gray-200 rounded focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300">
                                            <option value="day">Day(s)</option>
                                            <option value="week">Week(s)</option>
                                            <option value="month">Month(s)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-4" id="customWeekdaysSection" style="display:none;">
                                    <label class="block text-base font-semibold text-gray-700 mb-2">Step 2: Which days of the week?</label>
                                    <div class="flex flex-wrap gap-2 justify-center mb-2" style="max-width:100%;">
                                        <label class="day-select-label"><input type="checkbox" value="MO" class="custom-weekday hidden"><span class="day-pill">Mon</span></label>
                                        <label class="day-select-label"><input type="checkbox" value="TU" class="custom-weekday hidden"><span class="day-pill">Tue</span></label>
                                        <label class="day-select-label"><input type="checkbox" value="WE" class="custom-weekday hidden"><span class="day-pill">Wed</span></label>
                                        <label class="day-select-label"><input type="checkbox" value="TH" class="custom-weekday hidden"><span class="day-pill">Thu</span></label>
                                        <label class="day-select-label"><input type="checkbox" value="FR" class="custom-weekday hidden"><span class="day-pill">Fri</span></label>
                                        <label class="day-select-label"><input type="checkbox" value="SA" class="custom-weekday hidden"><span class="day-pill">Sat</span></label>
                                        <label class="day-select-label"><input type="checkbox" value="SU" class="custom-weekday hidden"><span class="day-pill">Sun</span></label>
                                    </div>
                                    <p class="text-xs text-gray-500">Select at least one day</p>
                                </div>
                                <div class="mb-4">
                                    <label class="block text-base font-semibold text-gray-700 mb-2">Step 3: When should this quest stop repeating?</label>
                                    <div class="flex flex-col gap-2">
                                        <label class="flex items-center">
                                            <input type="radio" name="customEndType" value="never" checked class="mr-2"> Never (repeat forever)
                                        </label>
                                        <label class="flex items-center">
                                            <input type="radio" name="customEndType" value="on" class="mr-2"> On a specific date
                                            <input type="text" id="customEndDate" class="ml-2 px-2 py-1 border border-indigo-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300 shadow-sm transition duration-200" placeholder="Select end date and time" style="display:none;">
                                        </label>
                                        <label class="flex items-center">
                                            <input type="radio" name="customEndType" value="after" class="mr-2"> After
                                            <input type="number" min="1" max="100" id="customEndOccurrences" class="ml-2 w-16 px-2 py-1 border border-gray-200 rounded" style="display:none;"> times
                                        </label>
                                    </div>
                                </div>
                                <div id="customRecurrenceFeedback" class="flex items-center text-green-600 text-base font-semibold mb-4" style="display:none;"></div>
                                <div class="flex justify-end mt-6">
                                    <button type="button" class="btn-primary px-6 py-2 rounded-lg font-medium shadow" id="saveCustomRecurrence" style="background:#6366f1;color:#fff;"><i class="fas fa-save mr-2"></i>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                `);
                // Style day pills
                $('.day-pill').css({
                    'display': 'inline-block',
                    'padding': '0.5em 1em',
                    'min-width': '48px',
                    'text-align': 'center',
                    'border-radius': '9999px',
                    'background': '#f3f4f6',
                    'color': '#374151',
                    'font-weight': '500',
                    'cursor': 'pointer',
                    'transition': 'background 0.2s, color 0.2s',
                    'border': '1.5px solid #e5e7eb',
                    'font-size': '1rem',
                    'margin-right': '0',
                    'margin-bottom': '0.5em',
                });
                $('.day-select-label').css({
                    'display': 'inline-flex',
                    'align-items': 'center',
                    'gap': '0.25em',
                });
            }
        }

            // Initialize Flatpickr for custom recurrence end date (date and time, same as due date)
            setTimeout(function() {
                if (window.flatpickr) {
                    var customEndDateInput = document.getElementById('customEndDate');
                    if (customEndDateInput) {
                        flatpickr(customEndDateInput, {
                            enableTime: true,
                            dateFormat: "Y-m-d h:i K",
                            minDate: "today",
                            static: true,
                            monthSelectorType: 'static',
                            time_24hr: false,
                            onReady: function(selectedDates, dateStr, instance) {
                                if (instance.yearNav) {
                                    instance.yearNav.addEventListener('click', function(e) {
                                        e.stopPropagation();
                                    });
                                }
                            }
                        });
                    }
                }
            }, 100);

        // Custom recurrence logic: show/hide Step 2 and After times
        $(document).on('change', '#customRepeatUnit', function() {
            if ($(this).val() === 'week') {
                $('#customWeekdaysSection').show();
            } else {
                $('#customWeekdaysSection').hide();
            }
        });
        $(document).on('change', 'input[name="customEndType"]', function() {
            if ($(this).val() === 'on') {
                $('#customEndDate').show();
                $('#customEndOccurrences').hide();
            } else if ($(this).val() === 'after') {
                $('#customEndOccurrences').show();
                $('#customEndDate').hide();
            } else {
                $('#customEndDate').hide();
                $('#customEndOccurrences').hide();
            }
        });
        // Day pill selection logic (toggle)
        $(document).on('click', '.day-pill', function(e) {
            e.preventDefault();
            var $label = $(this).closest('label');
            var $checkbox = $label.find('input[type="checkbox"]');
            $checkbox.prop('checked', !$checkbox.prop('checked'));
            if ($checkbox.prop('checked')) {
                $(this).css({'background':'#6366f1','color':'#fff','border':'1px solid #6366f1'});
            } else {
                $(this).css({'background':'#f3f4f6','color':'#374151','border':'1.5px solid #e5e7eb'});
            }
        });

        // Save custom recurrence and provide feedback
        $(document).on('click', '#saveCustomRecurrence', function() {
            var interval = $('#customRepeatInterval').val();
            var unit = $('#customRepeatUnit').val();
            var weekdays = [];
            if (unit === 'week') {
                $('.custom-weekday:checked').each(function() {
                    weekdays.push($(this).val());
                });
            }
            var endType = $('input[name="customEndType"]:checked').val();
            var endDate = $('#customEndDate').val();
            var endOccurrences = $('#customEndOccurrences').val();

            // Build summary
            var summary = `Repeats every ${interval} ${unit}${interval > 1 ? 's' : ''}`;
            if (unit === 'week' && weekdays.length > 0) {
                summary += ` on ${weekdays.join(', ')}`;
            }
            if (endType === 'on' && endDate) {
                summary += `, ends on ${endDate}`;
            } else if (endType === 'after' && endOccurrences) {
                summary += `, ends after ${endOccurrences} times`;
            } else {
                summary += ', repeats forever';
            }

            // Show feedback
            $('#customRecurrenceFeedback').html('<i class="fas fa-check-circle mr-2"></i>Successfully saved!').show();

            // Optionally, store summary in a hidden input for backend
            if ($('#customRecurrenceSummary').length === 0) {
                $('<input>').attr({
                    type: 'hidden',
                    id: 'customRecurrenceSummary',
                    name: 'custom_recurrence_summary',
                    value: summary
                }).appendTo('form');
            } else {
                $('#customRecurrenceSummary').val(summary);
            }

            // Close modal after short delay and reset feedback
            setTimeout(function() {
                $('#customRecurrenceModal').fadeOut(200, function() {
                    $(this).remove();
                    $('#customRecurrenceFeedback').hide().text('');
                });
            }, 1200);
    });

        // Custom recurrence logic: show/hide Step 2 and After times
        $(document).on('change', '#customRepeatUnit', function() {
            if ($(this).val() === 'week') {
                $('#customWeekdaysSection').show();
            } else {
                $('#customWeekdaysSection').hide();
            }
        });
        $(document).on('change', 'input[name="customEndType"]', function() {
            if ($(this).val() === 'on') {
                $('#customEndDate').show();
                $('#customEndOccurrences').hide();
            } else if ($(this).val() === 'after') {
                $('#customEndOccurrences').show();
                $('#customEndDate').hide();
            } else {
                $('#customEndDate').hide();
                $('#customEndOccurrences').hide();
            }
        });

        // Save custom recurrence and provide feedback
        $(document).on('click', '#saveCustomRecurrence', function() {
            var interval = $('#customRepeatInterval').val();
            var unit = $('#customRepeatUnit').val();
            var weekdays = [];
            if (unit === 'week') {
                $('.custom-weekday:checked').each(function() {
                    weekdays.push($(this).val());
                });
            }
            var endType = $('input[name="customEndType"]:checked').val();
            var endDate = $('#customEndDate').val();
            var endOccurrences = $('#customEndOccurrences').val();

            // Build summary
            var summary = `Repeats every ${interval} ${unit}${interval > 1 ? 's' : ''}`;
            if (unit === 'week' && weekdays.length > 0) {
                summary += ` on ${weekdays.join(', ')}`;
            }
            if (endType === 'on' && endDate) {
                summary += `, ends on ${endDate}`;
            } else if (endType === 'after' && endOccurrences) {
                summary += `, ends after ${endOccurrences} times`;
            } else {
                summary += ', repeats forever';
            }

            // Show feedback
            $('#customRecurrenceFeedback').text('Saved! ' + summary).show();

            // Optionally, store summary in a hidden input for backend
            if ($('#customRecurrenceSummary').length === 0) {
                $('<input>').attr({
                    type: 'hidden',
                    id: 'customRecurrenceSummary',
                    name: 'custom_recurrence_summary',
                    value: summary
                }).appendTo('form');
            } else {
                $('#customRecurrenceSummary').val(summary);
            }

            // Close modal after short delay and reset feedback
            setTimeout(function() {
                $('#customRecurrenceModal').fadeOut(200, function() {
                    $(this).remove();
                    $('#customRecurrenceFeedback').hide().text('');
                });
            }, 1200);
        });

        // Visual feedback for main save button
        $(document).on('submit', 'form', function(e) {
            var $btn = $(this).find('button[type="submit"]');
            if ($btn.length) {
                $btn.prop('disabled', true);
                var origHtml = $btn.html();
                $btn.html('<span class="spinner-border spinner-border-sm mr-2"></span>Saving...');
                setTimeout(function() {
                    $btn.prop('disabled', false);
                    $btn.html(origHtml);
                }, 3000); // fallback in case not replaced by server
            }
        });
        $(document).ready(function() {
            // Initialize Select2 for employee selection with enhanced search
            $('#assign_to').select2({
                placeholder: "Search by name or employee ID...",
                allowClear: false,
                width: '100%',
                templateResult: formatEmployee,
                templateSelection: formatEmployeeSelection,
                escapeMarkup: function(m) { return m; },
                matcher: function(params, data) {
                    // Always return the object if there's no search term
                    if ($.trim(params.term) === '') {
                        return data;
                    }
                    
                    // Convert search term to lowercase for case-insensitive matching
                    var term = params.term.toLowerCase();
                    
                    // Check if the term matches either employee ID or name
                    var idMatch = data.element.value.toLowerCase().indexOf(term) > -1;
                    var textMatch = data.text.toLowerCase().indexOf(term) > -1;
                    
                    return idMatch || textMatch;
                },
                closeOnSelect: false
            }).on('select2:select select2:unselect', function() {
                updateRemoveAllButton();
            });

            // Function to update the remove all button text and visibility
            function updateRemoveAllButton() {
                var selectedCount = $('#assign_to').select2('data').length;
                var $removeAll = $('#removeAllEmployees');
                
                if (selectedCount > 0) {
                    $removeAll.show();
                } else {
                    $removeAll.hide();
                }
            }

            // Handle remove all button click
            $('#removeAllEmployees').click(function() {
                $('#assign_to').val(null).trigger('change');
                $(this).hide();
            });

            // Call it initially in case there are pre-selected items
            updateRemoveAllButton();




            // Format how each employee appears in the dropdown results
            function formatEmployee(employee) {
                if (!employee.id) return employee.text;
                
                // Create a container for the employee info
                var $employee = $('<span class="flex items-center"></span>');
                
                // Add the employee name (bold) and ID (gray)
                $employee.append('<span class="font-medium">' + employee.text + '</span>');
                $employee.append('<span class="text-gray-500 text-sm ml-2">ID: ' + employee.id + '</span>');
                
                return $employee;
            }

            // Format how selected employees appear in the input field
            function formatEmployeeSelection(employee) {
                if (!employee.id) return employee.text;
                
                // Create a clean container for the selected employee
                var $container = $('<span class="selected-employee"></span>');
                
                // Add the employee name (bold) and ID (gray)
                $container.append('<span class="font-medium">' + employee.text + '</span>');
                $container.append('<span class="text-gray-500 text-sm ml-2">ID: ' + employee.id + '</span>');
                
                return $container;
            }

            // Initialize Select2 for category selection with icons
            function formatCategory(category) {
                if (!category.id) return category.text;
                var $icon = $(category.element).find('.category-icon').clone();
                var $category = $('<span></span>');
                $category.append($icon);
                $category.append(' ' + category.text);
                return $category;
            }
            
            $('#category_id').select2({
                templateResult: formatCategory,
                templateSelection: formatCategory,
                escapeMarkup: function(m) { return m; },
                width: '100%'
            });

            // Initialize date pickers with time selection for due date
            const dateConfig = {
                enableTime: true,
                dateFormat: "Y-m-d h:i K",
                minDate: "today",
                static: true,
                monthSelectorType: 'static',
                time_24hr: false,
                onReady: function(selectedDates, dateStr, instance) {
                    // Fix for year dropdown
                    if (instance.yearNav) {
                        instance.yearNav.addEventListener('click', function(e) {
                            e.stopPropagation();
                        });
                    }
                }
            };

            $('#due_date').flatpickr(dateConfig);
            $('#recurrence_end_date').flatpickr({
                enableTime: false,
                dateFormat: "Y-m-d",
                minDate: "today",
                static: true,
                monthSelectorType: 'static',
                onReady: function(selectedDates, dateStr, instance) {
                    if (instance.yearNav) {
                        instance.yearNav.addEventListener('click', function(e) {
                            e.stopPropagation();
                        });
                    }
                }
            });

            // Initialize datetime picker for publish_at
            $('#publish_at').flatpickr({
                enableTime: true,
                dateFormat: "Y-m-d h:i K",
                minDate: "today",
                static: true,
                monthSelectorType: 'static',
                time_24hr: false,
                onReady: function(selectedDates, dateStr, instance) {
                    if (instance.yearNav) {
                        instance.yearNav.addEventListener('click', function(e) {
                            e.stopPropagation();
                        });
                    }
                }
            });

            // Tab switching functionality
            $('.assignment-tab').click(function() {
                const tabId = $(this).data('tab');
                
                // Update active tab
                $('.assignment-tab').removeClass('active');
                $(this).addClass('active');
                
                // Update active content
                $('.tab-content').removeClass('active');
                $(`#${tabId}Tab`).addClass('active');
            });

            // Show/hide recurring options based on quest type
            $('input[name="quest_type"]').change(function() {
                if ($(this).val() === 'recurring') {
                    $('#recurringOptions').addClass('visible');
                } else {
                    $('#recurringOptions').removeClass('visible');
                }
            });

            // Subtask management
            $('#addSubtask').click(function() {
                const subtaskHtml = `
                    <div class="subtask-item flex items-center mt-2">
                        <input type="text" name="subtasks[]" 
                               class="flex-1 px-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                               placeholder="Enter subtask description" maxlength="500">
                        <button type="button" class="remove-subtask ml-2 text-gray-400 hover:text-red-500">
                            <i class="fas fa-times-circle"></i>
                        </button>
                    </div>
                `;
                $('#subtasksContainer').append(subtaskHtml);
            });

            $(document).on('click', '.remove-subtask', function() {
                $(this).closest('.subtask-item').remove();
            });

            // Group member loading
            $('#assign_group').change(function() {
                const groupId = $(this).val();
                if (!groupId) {
                    $('#groupMembersList').html('<p class="text-sm text-gray-500">Select a group to view members</p>');
                    return;
                }

                $('#groupMembersList').html('<p class="text-sm text-gray-500">Loading members...</p>');

                $.ajax({
                    url: 'create_quest.php?ajax=get_group_members&group_id=' + groupId,
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success && response.members.length > 0) {
                            let html = '<ul class="space-y-1">';
                            response.members.forEach(member => {
                                html += `<li class="text-sm text-gray-700">${member.full_name} <span class="text-gray-500">(ID: ${member.employee_id})</span></li>`;
                            });
                            html += '</ul>';
                            $('#groupMembersList').html(html);
                        } else {
                            $('#groupMembersList').html('<p class="text-sm text-gray-500">No members in this group</p>');
                        }
                    },
                    error: function() {
                        $('#groupMembersList').html('<p class="text-sm text-red-500">Error loading members</p>');
                    }
                });
            });

            // File upload preview with validation and remove functionality
            const fileList = $('#fileList');
            let filesToUpload = [];
            
            $('#attachments').change(function() {
                handleFiles(this.files);
            });

            function handleFiles(files) {
                const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
                const maxSize = 5 * 1024 * 1024; // 5MB
                let hasInvalidFiles = false;

                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const isValidType = allowedTypes.includes(file.type);
                    const isValidSize = file.size <= maxSize;
                    
                    if (!isValidType || !isValidSize) {
                        hasInvalidFiles = true;
                        fileList.append(`
                            <div class="flex items-center justify-between bg-red-50 p-2 rounded border border-red-200">
                                <div class="flex items-center">
                                    <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
                                    <span class="text-sm text-red-700 truncate max-w-xs">${file.name}</span>
                                </div>
                                <div class="text-xs text-red-500">
                                    ${!isValidType ? 'Invalid file type' : ''}
                                    ${!isValidType && !isValidSize ? ' • ' : ''}
                                    ${!isValidSize ? 'File too large' : ''}
                                </div>
                            </div>
                        `);
                    } else {
                        // Add to filesToUpload array if not already there
                        if (!filesToUpload.some(f => f.name === file.name && f.size === file.size)) {
                            filesToUpload.push(file);
                            renderFileList();
                        }
                    }
                }

                if (hasInvalidFiles) {
                    fileList.prepend(`
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-2 rounded">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <span>Some files are invalid and won't be uploaded</span>
                            </div>
                        </div>
                    `);
                }

                // Reset the file input to allow selecting the same file again
                $('#attachments').val('');
            }

            function renderFileList() {
                fileList.empty();
                
                if (filesToUpload.length === 0) {
                    return;
                }
                
                filesToUpload.forEach((file, index) => {
                    const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                    const fileIcon = file.type === 'application/pdf' ? 'fa-file-pdf' : 
                                    file.type.startsWith('image/') ? 'fa-file-image' : 'fa-file';
                    
                    fileList.append(`
                        <div class="file-preview" data-index="${index}">
                            <div class="file-preview-info">
                                <i class="fas ${fileIcon} text-gray-500 mr-2"></i>
                                <span class="file-preview-name text-sm text-gray-700" title="${file.name}">${file.name}</span>
                                <span class="text-xs text-gray-500 ml-2">${fileSizeMB} MB</span>
                            </div>
                            <i class="fas fa-times remove-file" data-index="${index}"></i>
                        </div>
                    `);
                });
            }

            // Handle file removal
            fileList.on('click', '.remove-file', function(e) {
                e.stopPropagation();
                const index = $(this).data('index');
                filesToUpload.splice(index, 1);
                renderFileList();
            });

            // Form validation
            $('form').submit(function(e) {
                let isValid = true;
                
                // Clear previous errors
                $('.is-invalid').removeClass('is-invalid');
                $('.error-message').remove();
                
                // Validate at least one assignment method
                const assignTo = $('#assign_to').val();
                const assignGroup = $('#assign_group').val();
                if ((!assignTo || assignTo.length === 0) && !assignGroup) {
                    $('#assign_to').addClass('is-invalid');
                    $('#assign_group').addClass('is-invalid');
                    $('#assignmentSection').append('<p class="error-message text-red-500 text-sm mt-1">You must assign to at least one employee or group</p>');
                    isValid = false;
                }
                
                // Validate recurring quest options
                if ($('input[name="quest_type"]:checked').val() === 'recurring' && !$('input[name="recurrence_pattern"]:checked').val()) {
                    $('#recurringOptions').addClass('is-invalid');
                    $('#recurringOptions').append('<p class="error-message text-red-500 text-sm mt-1">Please select a recurrence pattern</p>');
                    isValid = false;
                }
                
                // Validate subtasks length
                $('input[name="subtasks[]"]').each(function() {
                    if ($(this).val().length > 500) {
                        $(this).addClass('is-invalid');
                        $(this).after('<p class="error-message text-red-500 text-sm mt-1">Subtask must be less than 500 characters</p>');
                        isValid = false;
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    // Scroll to first error
                    $('html, body').animate({
                        scrollTop: $('.is-invalid').first().offset().top - 100
                    }, 500);
                } else {
                    // Update form submission to use our filesToUpload array
                    if (filesToUpload.length > 0) {
                        // Create a DataTransfer object to hold our files
                        const dataTransfer = new DataTransfer();
                        filesToUpload.forEach(file => {
                            dataTransfer.items.add(file);
                        });
                        
                        // Replace the file input files with our DataTransfer files
                        $('#attachments')[0].files = dataTransfer.files;
                    }
                }
            });

            // Style for invalid fields
            $(document).on('input change', 'input, select, textarea', function() {
                if ($(this).hasClass('is-invalid')) {
                    $(this).removeClass('is-invalid');
                    $(this).next('.error-message').remove();
                }
            });

            // Drag and drop file upload
            const fileUploadArea = $('.file-upload-hover');
            
            fileUploadArea.on('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('border-indigo-400 bg-indigo-50');
            });
            
            fileUploadArea.on('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('border-indigo-400 bg-indigo-50');
            });
            
            fileUploadArea.on('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('border-indigo-400 bg-indigo-50');
                
                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    handleFiles(files);
                }
            });
        });
    </script>

    <!-- Recurrence Pattern Visual Feedback Script -->
    <script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.recurrence-pattern').forEach(function(label) {
        label.addEventListener('click', function() {
            document.querySelectorAll('.recurrence-pattern').forEach(function(l) {
                l.classList.remove('selected');
                l.style.background = '#fff';
                l.style.color = '#374151';
                l.style.borderColor = '#e5e7eb';
            });
            this.classList.add('selected');
            this.style.background = '#eef2ff';
            this.style.color = '#4338ca';
            this.style.borderColor = '#4338ca';
            var radio = this.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        });
    });
});
</script>
</body>
</html>