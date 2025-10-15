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
    $role = 'quest_lead';
} elseif ($role === 'quest_giver') {
    $role = 'quest_lead';
} elseif ($role === 'contributor') {
    $role = 'quest_lead';
} elseif ($role === 'learning_architect') {
    // Normalize newer role name to internal quest_lead for consistent permission checks
    $role = 'quest_lead';
}

if (!in_array($role, ['quest_lead'])) { // was ['quest_giver', 'hybrid']
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
                              AND u.role IN ('skill_associate', 'quest_lead')
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
$quest_assignment_type = 'optional';
$due_date = null;
$assign_to = [];
$assign_group = null;
$selected_skills = [];

// Fetch employees, groups, and skills for assignment
$employees = [];
$groups = [];
$skills = [];
try {
    // Get all skill_associates and quest_leads EXCEPT the current user
    $current_user_id = $_SESSION['employee_id'];
    $stmt = $pdo->prepare("SELECT employee_id, full_name FROM users 
                          WHERE role IN ('skill_associate', 'quest_lead') 
                          AND employee_id != ?
                          ORDER BY full_name");
    $stmt->execute([$current_user_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all employee groups
    $stmt = $pdo->query("SELECT id, group_name FROM employee_groups ORDER BY group_name");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all skills organized by category
    $stmt = $pdo->query("SELECT cs.id as skill_id, cs.skill_name, sc.category_name, sc.id as category_id,
                                cs.tier_1_points, cs.tier_2_points, cs.tier_3_points, cs.tier_4_points, cs.tier_5_points
                        FROM comprehensive_skills cs 
                        JOIN skill_categories sc ON cs.category_id = sc.id 
                        ORDER BY sc.category_name, cs.skill_name");
    $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching data: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quest_assignment_type = $_POST['quest_assignment_type'] ?? 'optional';
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $assign_to = isset($_POST['assign_to']) ? $_POST['assign_to'] : [];
    $assign_group = isset($_POST['assign_group']) ? $_POST['assign_group'] : null;
    $status = 'active';
    
    // Handle selected skills with tiers
    $selected_skills = isset($_POST['quest_skills']) ? $_POST['quest_skills'] : [];
    $skill_tiers = isset($_POST['skill_tiers']) ? $_POST['skill_tiers'] : [];
    $custom_skill_names = isset($_POST['custom_skill_names']) ? $_POST['custom_skill_names'] : [];
    $custom_skill_categories = isset($_POST['custom_skill_categories']) ? $_POST['custom_skill_categories'] : [];
    
    // Separate custom and existing skills, and validate skill IDs
    $existing_skills = [];
    $custom_skills = [];
    
    foreach ($selected_skills as $skill_id) {
        if (is_string($skill_id) && strpos($skill_id, 'custom_') === 0) {
            // This is a custom skill
            $custom_skills[] = $skill_id;
        } else {
            // This should be an existing skill - convert to integer
            $int_id = intval($skill_id);
            if ($int_id > 0) {
                $existing_skills[] = $int_id;
            }
        }
    }
    
    // Update selected_skills to only include valid IDs for validation
    $selected_skills = array_merge($existing_skills, $custom_skills);
    
    // Debug logging
    error_log("DEBUG: Quest creation - POST data: " . json_encode($_POST));
    error_log("DEBUG: Quest creation - existing skills: " . json_encode($existing_skills));
    error_log("DEBUG: Quest creation - custom skills: " . json_encode($custom_skills));
    error_log("DEBUG: Quest creation - custom skill names: " . json_encode($custom_skill_names));

    // Validate input
    if (empty($title)) {
        $error = 'Title is required';
    } elseif (strlen($title) > 255) {
        $error = 'Title must be less than 255 characters';
    } elseif (empty($description)) {
        $error = 'Description is required';
    } elseif (strlen($description) > 2000) {
        $error = 'Description must be less than 2000 characters';
    } elseif (!in_array($quest_assignment_type, ['mandatory', 'optional'])) {
        $error = 'Quest assignment type must be either mandatory or optional';
    } elseif (empty($existing_skills) && empty($custom_skills)) {
        $error = 'At least one skill must be selected for this quest';
    } elseif (count($selected_skills) > 5) {
        $error = 'Maximum of 5 skills can be selected per quest';
    } elseif (!empty($due_date) && !strtotime($due_date)) {
        $error = 'Invalid due date format';
    } else {
        
        // Validate that all existing skills exist in comprehensive_skills table
        if (empty($error) && !empty($existing_skills)) {
            try {
                $skill_placeholders = implode(',', array_fill(0, count($existing_skills), '?'));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM comprehensive_skills WHERE id IN ($skill_placeholders)");
                $stmt->execute($existing_skills);
                $valid_skill_count = $stmt->fetchColumn();
                
                if ($valid_skill_count != count($existing_skills)) {
                    $error = 'One or more selected skills are invalid. Please refresh the page and try again.';
                }
            } catch (PDOException $e) {
                $error = 'Error validating selected skills: ' . $e->getMessage();
            }
        }
        
        // Validate custom skills have names
        if (empty($error) && !empty($custom_skills)) {
            foreach ($custom_skills as $custom_skill_id) {
                if (!isset($custom_skill_names[$custom_skill_id]) || empty(trim($custom_skill_names[$custom_skill_id]))) {
                    $error = 'All custom skills must have names';
                    break;
                }
            }
        }
        
        // Validate assigned employees exist and are quest takers
        if (empty($error) && !empty($assign_to)) {
            try {
                $placeholders = implode(',', array_fill(0, count($assign_to), '?'));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users 
                                      WHERE employee_id IN ($placeholders) 
                                      AND role IN ('skill_associate', 'quest_lead')");
                $stmt->execute($assign_to);
                $count = $stmt->fetchColumn();
                
                if ($count != count($assign_to)) {
                    $error = 'One or more assigned employees are invalid or not quest participants';
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
        
        // If no validation errors, proceed with database operations
        if (empty($error)) {
            try {
                $pdo->beginTransaction();
                
                // Create the quest
                $stmt = $pdo->prepare("INSERT INTO quests 
                    (title, description, status, due_date, created_by, quest_assignment_type, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $title, 
                    $description, 
                    $status, 
                    $due_date,
                    $_SESSION['employee_id'],
                    $quest_assignment_type
                ]);
                $quest_id = $pdo->lastInsertId();
                
                // Process skills: use the already separated existing and custom skills
                $final_skill_ids = [];
                
                // Add existing skills (already validated)
                $final_skill_ids = array_merge($final_skill_ids, $existing_skills);
                
                // Create custom skills and add their IDs
                foreach ($custom_skills as $temp_id) {
                    if (isset($custom_skill_names[$temp_id])) {
                        $skill_name = trim($custom_skill_names[$temp_id]);
                        $category_name = isset($custom_skill_categories[$temp_id]) ? trim($custom_skill_categories[$temp_id]) : 'General';
                        
                        if (!empty($skill_name)) {
                            // Find or create the skill category
                            $stmt = $pdo->prepare("SELECT id FROM skill_categories WHERE category_name = ?");
                            $stmt->execute([$category_name]);
                            $category_id = $stmt->fetchColumn();
                            
                            if (!$category_id) {
                                // Create new category
                                $stmt = $pdo->prepare("INSERT INTO skill_categories (category_name) VALUES (?)");
                                $stmt->execute([$category_name]);
                                $category_id = $pdo->lastInsertId();
                            }
                            
                            // Create the custom skill
                            $stmt = $pdo->prepare("INSERT INTO comprehensive_skills 
                                (skill_name, category_id, description, tier_1_points, tier_2_points, tier_3_points, tier_4_points, tier_5_points) 
                                VALUES (?, ?, ?, 5, 10, 15, 20, 25)");
                            $stmt->execute([$skill_name, $category_id, "Custom skill: $skill_name"]);
                            $new_skill_id = $pdo->lastInsertId();
                            $final_skill_ids[] = $new_skill_id;
                            
                            // Update skill_tiers mapping for the new ID
                            if (isset($skill_tiers[$temp_id])) {
                                $skill_tiers[$new_skill_id] = $skill_tiers[$temp_id];
                            }
                        }
                    }
                }
                
                // Add quest skills with tiers
                if (!empty($final_skill_ids)) {
                    $stmt = $pdo->prepare("INSERT INTO quest_skills 
                        (quest_id, skill_id, tier_level) VALUES (?, ?, ?)");
                    foreach ($final_skill_ids as $skill_id) {
                        $tier = isset($skill_tiers[$skill_id]) ? intval($skill_tiers[$skill_id]) : 1;
                        $stmt->execute([$quest_id, $skill_id, $tier]);
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

                if (empty($all_assignments)) {
                    throw new Exception('VALIDATION_NO_ASSIGNEES');
                }

                // Assign quest to selected employees
                foreach ($all_assignments as $employee_id) {
                    // First check if the employee exists
                    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE employee_id = ?");
                    $stmt->execute([$employee_id]);
                    $userExists = $stmt->fetch();
                    
                    if (!$userExists) {
                        error_log("Attempted to assign quest to non-existent user: " . $employee_id);
                        continue; // Skip this assignment
                    }

                    // Set initial status based on quest assignment type
                    $initial_status = ($quest_assignment_type === 'mandatory') ? 'in_progress' : 'assigned';
                    
                    $stmt = $pdo->prepare("INSERT INTO user_quests 
                        (employee_id, quest_id, status, assigned_at) 
                        VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$employee_id, $quest_id, $initial_status]);
                }
                
                $pdo->commit();
                
                $assignment_count = count($all_assignments);
                $success = 'Quest created successfully' . 
                           ($assignment_count > 0 ? " and assigned to $assignment_count employee(s)!" : '!');
                
                // Clear form on success
                if ($success) {
                    $title = $description = '';
                    $quest_assignment_type = 'optional';
                    $assign_to = [];
                    $assign_group = null;
                    $due_date = null;
                    $selected_skills = [];
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Database error: " . $e->getMessage());
                $error = 'Error creating quest: ' . $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getMessage() === 'VALIDATION_NO_ASSIGNEES') {
                    $error = 'Please assign this quest to at least one employee or group before saving.';
                } else {
                    $error = 'Error creating quest: ' . $e->getMessage();
                }
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
            width: 100%;
            max-width: 100%;
        }
        
        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.07), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        /* Ensure all form sections have consistent alignment */
        form .card {
            margin-left: 0;
            margin-right: 0;
            width: 100%;
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
            order:  2;
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
        
        .tab-content {
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

        <form method="post" class="space-y-6" enctype="multipart/form-data">
                <!-- Basic Information Section -->
                <div class="card p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-info-circle text-indigo-500 mr-2"></i> Basic Information
                    </h2>
                    
                    <div class="grid grid-cols-1 gap-6">
                        <!-- Quest Type Selection -->
                        <!-- Quest Type removed: All quests use the same realistic skill tier thresholds. -->
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
                                <label for="quest_assignment_type" class="block text-sm font-medium text-gray-700 mb-1">Assignment Type*</label>
                                <select name="quest_assignment_type" id="quest_assignment_type" 
                                        class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300" required>
                                    <option value="optional" <?php echo (isset($quest_assignment_type) && $quest_assignment_type == 'optional') ? 'selected' : ''; ?>>
                                        📋 Optional - Users can choose to accept or decline
                                    </option>
                                    <option value="mandatory" <?php echo (isset($quest_assignment_type) && $quest_assignment_type == 'mandatory') ? 'selected' : ''; ?>>
                                        ⚡ Mandatory - Automatically assigned to users
                                    </option>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">
                                    <span class="font-medium">Optional:</span> Users can accept/decline. 
                                    <span class="font-medium">Mandatory:</span> Automatically starts for assigned users.
                                </p>
                            </div>
                            <div>
                                <label for="due_date" class="block text-sm font-medium text-gray-700 mb-1">Due Date &amp; Time (Optional)</label>
                                <div class="relative">
                                    <!-- Date/Time Display Button -->
                                    <button type="button" 
                                            id="dueDateBtn"
                                            class="w-full px-4 py-2.5 border border-indigo-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300 shadow-sm transition duration-200 bg-white hover:bg-gray-50 text-left flex items-center justify-between"
                                            onclick="toggleCalendarBox()">
                                        <span id="dueDateDisplay" class="text-gray-500">
                                            <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i>
                                            Click to select due date and time
                                        </span>
                                        <i class="fas fa-chevron-down text-gray-400" id="chevronIcon"></i>
                                    </button>
                                    <input type="hidden" id="due_date" name="due_date" value="<?php echo htmlspecialchars($due_date ?? ''); ?>">
                                    
                                    <!-- Calendar Box (Initially Hidden) -->
                                    <div id="calendarBox" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-xl z-50 mt-1 hidden">
                                        <div class="p-4">
                                            <!-- Calendar Container -->
                                            <div id="calendarContainer" class="mb-4"></div>
                                            
                                            <!-- Time Selection -->
                                            <div class="border-t pt-4">
                                                <div class="flex items-center justify-between mb-3">
                                                    <label class="text-sm font-medium text-gray-700">Select Time</label>
                                                    <button type="button" 
                                                            onclick="clearDueDate()" 
                                                            class="text-xs text-red-600 hover:text-red-800 flex items-center">
                                                        <i class="fas fa-times mr-1"></i>Clear
                                                    </button>
                                                </div>
                                                
                                                <div class="grid grid-cols-3 gap-2 mb-3">
                                                    <div>
                                                        <input type="number" 
                                                               id="hourSelect" 
                                                               min="1" 
                                                               max="12" 
                                                               value="12"
                                                               oninput="clearFieldError(this)"
                                                               class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-center">
                                                        <label class="text-xs text-gray-500 mt-1 block text-center">Hour</label>
                                                    </div>
                                                    <div>
                                                        <input type="number" 
                                                               id="minuteSelect" 
                                                               min="0" 
                                                               max="59" 
                                                               value="00"
                                                               oninput="clearFieldError(this)"
                                                               class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-center">
                                                        <label class="text-xs text-gray-500 mt-1 block text-center">Min</label>
                                                    </div>
                                                    <div>
                                                        <select id="ampmSelect" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                                            <option value="AM">AM</option>
                                                            <option value="PM">PM</option>
                                                        </select>
                                                        <label class="text-xs text-gray-500 mt-1 block text-center">AM/PM</label>
                                                    </div>
                                                </div>
                                                
                                                <!-- Quick Time Buttons -->
                                                <div class="flex flex-wrap gap-1 mb-3">
                                                    <button type="button" onclick="setQuickTime('09:00 AM')" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors">9 AM</button>
                                                    <button type="button" onclick="setQuickTime('12:00 PM')" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors">12 PM</button>
                                                    <button type="button" onclick="setQuickTime('05:00 PM')" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors">5 PM</button>
                                                    <button type="button" onclick="setQuickTime('11:59 PM')" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded transition-colors">End of Day</button>
                                                </div>
                                                
                                                <!-- Apply Button -->
                                                <button type="button" 
                                                        onclick="applyDateTime()" 
                                                        class="w-full px-3 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 transition-colors">
                                                    Apply Date & Time
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <p class="mt-1 text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Click the button above to select both date and time for quest deadline
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reward & Settings Section -->
                <div class="card p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-gem text-indigo-500 mr-2"></i> Reward & Settings
                    </h2>
                    
                    <div class="grid grid-cols-1 gap-6">
                        <!-- Quest Skills Selection -->
                        <div id="skillsSection">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-gray-700">Required Skills* (Select or Add 1-8)</label>
                                <div class="text-sm text-gray-600">
                                    <span id="skill-counter" class="font-semibold text-indigo-600">0</span>/5 selected
                                </div>
                            </div>
                            
                            <!-- Selected Skills Display -->
                            <div id="selected-skills-display" class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg min-h-[50px]">
                                <div class="text-xs font-medium text-blue-800 mb-1">Selected Skills:</div>
                                <div id="selected-skills-badges" class="flex flex-wrap gap-2">
                                    <div class="text-xs text-blue-600 italic">No skills selected yet</div>
                                </div>
                            </div>

                            <!-- Category Buttons -->
                            <div class="mb-4">
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" 
                                            onclick="showCategorySkills('technical', 'Technical Skills')" 
                                            class="skill-category-btn px-4 py-2 bg-blue-100 hover:bg-blue-200 text-blue-800 rounded-lg border border-blue-300 transition-colors flex items-center">
                                        <i class="fas fa-code mr-2"></i>Technical Skills
                                    </button>
                                    <button type="button" 
                                            onclick="showCategorySkills('communication', 'Communication Skills')" 
                                            class="skill-category-btn px-4 py-2 bg-amber-100 hover:bg-amber-200 text-amber-800 rounded-lg border border-amber-300 transition-colors flex items-center">
                                        <i class="fas fa-comments mr-2"></i>Communication Skills
                                    </button>
                                    <button type="button" 
                                            onclick="showCategorySkills('soft', 'Soft Skills')" 
                                            class="skill-category-btn px-4 py-2 bg-rose-100 hover:bg-rose-200 text-rose-800 rounded-lg border border-rose-300 transition-colors flex items-center">
                                        <i class="fas fa-heart mr-2"></i>Soft Skills
                                    </button>
                                    <button type="button" 
                                            onclick="showCategorySkills('business', 'Business Skills')" 
                                            class="skill-category-btn px-4 py-2 bg-emerald-100 hover:bg-emerald-200 text-emerald-800 rounded-lg border border-emerald-300 transition-colors flex items-center">
                                        <i class="fas fa-briefcase mr-2"></i>Business Skills
                                    </button>
                                </div>
                            </div>

                            <!-- Skills Modal Container -->
                            <div id="skillsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
                                <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[80vh] flex flex-col">
                                    <!-- Modal Header -->
                                    <div class="p-6 border-b border-gray-200 flex items-center justify-between">
                                        <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                            <span id="modalCategoryIcon" class="mr-2"></span>
                                            <span id="modalCategoryTitle">Select Skills</span>
                                        </h3>
                                        <button type="button" onclick="closeSkillsModal()" class="text-gray-400 hover:text-gray-600">
                                            <i class="fas fa-times text-xl"></i>
                                        </button>
                                    </div>
                                    
                                    <!-- Search Bar -->
                                    <div class="p-4 border-b border-gray-200">
                                        <div class="relative">
                                            <input type="text" 
                                                   id="skillSearchInput" 
                                                   placeholder="Search skills..." 
                                                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                                   oninput="filterSkills()">
                                            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                                        </div>
                                    </div>
                                    
                                    <!-- Skills List -->
                                    <div class="flex-1 overflow-y-auto p-4">
                                        <div id="skillsList" class="space-y-2">
                                            <!-- Skills will be populated here -->
                                        </div>
                                        <div id="noSkillsFound" class="hidden text-center py-8 text-gray-500">
                                            <i class="fas fa-search text-3xl mb-2"></i>
                                            <p>No skills found matching your search.</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Modal Footer -->
                                    <div class="p-4 border-t border-gray-200 flex items-center justify-between">
                                        <button type="button" 
                                                onclick="showCustomSkillModal()"
                                                class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg border border-gray-300 transition-colors flex items-center">
                                            <i class="fas fa-plus mr-2"></i>Add Custom Skill
                                        </button>
                                        <div class="flex gap-3">
                                            <button type="button" 
                                                    onclick="closeSkillsModal()" 
                                                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                                                Cancel
                                            </button>
                                            <button type="button" 
                                                    onclick="applySelectedSkills()" 
                                                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                                                Apply Selected Skills
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <p class="mt-2 text-xs text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                Click on a category button to select skills from that category.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Custom Skill Modal -->
                <div id="customSkillModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
                    <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">
                                <i class="fas fa-plus-circle text-indigo-500 mr-2"></i>Add Custom Skill
                            </h3>
                            <button type="button" onclick="closeCustomSkillModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Category: <span id="modalCategoryName" class="text-indigo-600 font-semibold"></span>
                            </label>
                            <input type="hidden" id="modalCategoryId" value="">
                        </div>
                        
                        <div class="mb-4">
                            <label for="customSkillName" class="block text-sm font-medium text-gray-700 mb-1">Skill Name*</label>
                            <input type="text" 
                                   id="customSkillName" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                   placeholder="Enter skill name (e.g., Python Programming)"
                                   maxlength="100">
                        </div>
                        
                        <div class="mb-4">
                            <label for="customSkillTier" class="block text-sm font-medium text-gray-700 mb-1">Skill Tier*</label>
                            <select id="customSkillTier" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"></select>
                        </div>
                        
                        <div class="flex gap-3">
                            <button type="button" 
                                    onclick="closeCustomSkillModal()" 
                                    class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                                Cancel
                            </button>
                            <button type="button" 
                                    onclick="addCustomSkill()" 
                                    class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                                <i class="fas fa-plus mr-1"></i>Add Skill
                            </button>
                        </div>
                    </div>
                </div>


                                <p class="text-xs text-gray-500">PDF, JPG, PNG (Max 5MB each)</p>
                            </div>
                        </label>
                    </div>
                    <div id="fileList" class="mt-2 space-y-2"></div>
                </div>

                <!-- Assignment Section -->
                <div class="max-w-2xl mx-auto">
                    <div class="card p-6">
                        <h2 class="text-xl font-semibold text-gray-800 mb-3 pb-2 border-b border-gray-100">
                            <i class="fas fa-users text-indigo-500 mr-2"></i> Assignment (Required)
                        </h2>
                        
                        <div>
                            <!-- Assignment Type Selection -->
                            <div class="flex gap-4 border-b border-gray-200 pb-2 mb-3">
                            <label class="flex items-center cursor-pointer">
                                <input type="radio" name="assignment_type" value="individual" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500" checked onchange="toggleAssignmentType()">
                                <span class="ml-2 text-sm font-medium text-gray-700"><i class="fas fa-user mr-1"></i>Individuals</span>
                            </label>
                            <label class="flex items-center cursor-pointer">
                                <input type="radio" name="assignment_type" value="group" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500" onchange="toggleAssignmentType()">
                                <span class="ml-2 text-sm font-medium text-gray-700"><i class="fas fa-users mr-1"></i>Group</span>
                            </label>
                        </div>
                        
                        <!-- Individual Assignment -->
                        <div id="individualAssignment">
                            <!-- Search Box -->
                            <div class="mb-3">
                                <div class="relative">
                                    <input type="text" 
                                           id="employeeSearch" 
                                           placeholder="Search employees by name or ID..." 
                                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                                           oninput="filterEmployees()">
                                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                                    <button type="button" 
                                            onclick="clearEmployeeSearch()" 
                                            class="absolute right-3 top-3 text-gray-400 hover:text-gray-600"
                                            id="clearSearchBtn" 
                                            style="display: none;">
                                        <i class="fas fa-times text-xs"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Selected Employees Display -->
                            <div id="selectedEmployeesDisplay" class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg min-h-[40px]">
                                <div class="text-xs font-medium text-blue-800 mb-1">Selected Employees:</div>
                                <div id="selectedEmployeesBadges" class="flex flex-wrap gap-2">
                                    <div class="text-xs text-blue-600 italic">No employees selected</div>
                                </div>
                            </div>
                            
                            <!-- Employee List -->
                            <div class="border border-gray-200 rounded-lg max-h-20 overflow-y-auto bg-white">
                                <div id="employeeList">
                                    <?php foreach ($employees as $employee): ?>
                                        <label class="employee-item flex items-center p-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0" 
                                               data-name="<?php echo strtolower($employee['full_name']); ?>"
                                               data-id="<?php echo strtolower($employee['employee_id']); ?>">
                                            <input type="checkbox" 
                                                   name="assign_to[]" 
                                                   value="<?php echo $employee['employee_id']; ?>"
                                                   class="employee-checkbox h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                                   data-name="<?php echo htmlspecialchars($employee['full_name']); ?>"
                                                   data-employee-id="<?php echo htmlspecialchars($employee['employee_id']); ?>"
                                                   onchange="handleEmployeeSelection(this)"
                                                   <?php echo in_array($employee['employee_id'], $assign_to) ? 'checked' : ''; ?>>
                                            <div class="ml-2 flex-1">
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($employee['full_name']); ?></div>
                                                <div class="text-xs text-gray-500">ID: <?php echo htmlspecialchars($employee['employee_id']); ?></div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div id="noEmployeesFound" class="hidden p-4 text-center text-gray-500 text-sm">
                                    <i class="fas fa-search text-2xl mb-2"></i>
                                    <p>No employees found matching your search.</p>
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                Search and select employees to assign this quest. You cannot assign quests to yourself.
                            </p>
                        </div>
                        
                        <!-- Group Assignment -->
                        <div id="groupAssignment" class="hidden">
                            <select name="assign_group" id="assign_group" 
                                    class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 bg-white text-sm"
                                    onchange="loadGroupMembers(this.value)">
                                <option value="">-- Select a Group --</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>"
                                        <?php echo ($assign_group == $group['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group['group_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div id="groupMembersPreview" class="mt-2 p-2 bg-blue-50 border border-blue-200 rounded text-xs text-gray-700 hidden max-h-16 overflow-y-auto">
                                <strong class="text-blue-800">Members:</strong> <span id="groupMembersList"></span>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="mt-8 pt-6 border-t border-gray-100">
                    <div class="flex justify-center">
                        <button type="submit" class="btn-primary px-8 py-3 rounded-lg font-medium shadow-lg hover:shadow-xl transition-shadow duration-200 flex items-center">
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
                var modalHtml = [
                    '<div id="customRecurrenceModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">',
                        '<div class="bg-white rounded-lg shadow-lg p-8 max-w-md w-full relative flex flex-col" style="min-width:340px;">',
                            '<button class="absolute top-2 right-2 text-gray-400 hover:text-gray-700" onclick="$(\'#customRecurrenceModal\').remove()"><i class="fas fa-times"></i></button>',
                            '<h2 class="text-xl font-bold mb-6 text-indigo-700 flex items-center"><i class="fas fa-cog mr-2"></i> Custom Recurrence</h2>',
                            '<form id="customRecurrenceForm">',
                                '<div class="mb-4">',
                                    '<label class="block text-base font-semibold text-gray-700 mb-2">Step 1: How often should this quest repeat?</label>',
                                    '<div class="flex gap-2 items-center">',
                                        '<input type="number" min="1" max="365" value="1" id="customRepeatInterval" class="w-16 px-2 py-1 border border-gray-200 rounded focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300" />',
                                        '<select id="customRepeatUnit" class="px-2 py-1 border border-gray-200 rounded focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300">',
                                            '<option value="day">Day(s)</option>',
                                            '<option value="week">Week(s)</option>',
                                            '<option value="month">Month(s)</option>',
                                        '</select>',
                                    '</div>',
                                '</div>',
                                '<div class="mb-4" id="customWeekdaysSection" style="display:none;">',
                                    '<label class="block text-base font-semibold text-gray-700 mb-2">Step 2: Which days of the week?</label>',
                                    '<div class="flex flex-wrap gap-2 justify-center mb-2" style="max-width:100%;">',
                                        '<label class="day-select-label"><input type="checkbox" value="MO" class="custom-weekday hidden"><span class="day-pill">Mon</span></label>',
                                        '<label class="day-select-label"><input type="checkbox" value="TU" class="custom-weekday hidden"><span class="day-pill">Tue</span></label>',
                                        '<label class="day-select-label"><input type="checkbox" value="WE" class="custom-weekday hidden"><span class="day-pill">Wed</span></label>',
                                        '<label class="day-select-label"><input type="checkbox" value="TH" class="custom-weekday hidden"><span class="day-pill">Thu</span></label>',
                                        '<label class="day-select-label"><input type="checkbox" value="FR" class="custom-weekday hidden"><span class="day-pill">Fri</span></label>',
                                        '<label class="day-select-label"><input type="checkbox" value="SA" class="custom-weekday hidden"><span class="day-pill">Sat</span></label>',
                                        '<label class="day-select-label"><input type="checkbox" value="SU" class="custom-weekday hidden"><span class="day-pill">Sun</span></label>',
                                    '</div>',
                                    '<p class="text-xs text-gray-500">Select at least one day</p>',
                                '</div>',
                                '<div class="mb-4">',
                                    '<label class="block text-base font-semibold text-gray-700 mb-2">Step 3: When should this quest stop repeating?</label>',
                                    '<div class="flex flex-col gap-2">',
                                        '<label class="flex items-center">',
                                            '<input type="radio" name="customEndType" value="never" checked class="mr-2"> Never (repeat forever)',
                                        '</label>',
                                        '<label class="flex items-center">',
                                            '<input type="radio" name="customEndType" value="on" class="mr-2"> On a specific date',
                                            '<input type="text" id="customEndDate" class="ml-2 px-2 py-1 border border-indigo-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300 shadow-sm transition duration-200" placeholder="Select end date and time" style="display:none;">',
                                        '</label>',
                                        '<label class="flex items-center">',
                                            '<input type="radio" name="customEndType" value="after" class="mr-2"> After',
                                            '<input type="number" min="1" max="100" id="customEndOccurrences" class="ml-2 w-16 px-2 py-1 border border-gray-200 rounded" style="display:none;"> times',
                                        '</label>',
                                    '</div>',
                                '</div>',
                                '<div id="customRecurrenceFeedback" class="flex items-center text-green-600 text-base font-semibold mb-4" style="display:none;"></div>',
                                '<div class="flex justify-end mt-6">',
                                    '<button type="button" class="btn-primary px-6 py-2 rounded-lg font-medium shadow" id="saveCustomRecurrence" style="background:#6366f1;color:#fff;"><i class="fas fa-save mr-2"></i>Save</button>',
                                '</div>',
                            '</form>',
                        '</div>',
                    '</div>'
                ].join('');
                $('body').append(modalHtml);
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

            // Initialize with existing value if any
            const existingValue = document.getElementById('due_date').value;
            if (existingValue) {
                updateDateDisplay(existingValue);
            }
            
            // Initialize recurrence end date picker (keep Flatpickr for this)
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
                
                // Update active tab styling
                $('.assignment-tab').removeClass('active bg-white')
                    .removeClass('border-indigo-500 text-indigo-600')
                    .addClass('border-transparent text-gray-500');
                
                $(this).addClass('active bg-white')
                    .removeClass('border-transparent text-gray-500')
                    .addClass('border-indigo-500 text-indigo-600');
                
                // Update active content
                $('.tab-content').removeClass('active').addClass('hidden');
                $(`#${tabId}Tab`).addClass('active').removeClass('hidden');
            });

            // Employee search functionality
            function searchEmployees() {
                const searchTerm = document.getElementById('employeeSearch').value.toLowerCase();
                const employeeItems = document.querySelectorAll('.employee-item');
                const noResultsDiv = document.getElementById('noEmployeeResults');
                
                let visibleCount = 0;
                
                employeeItems.forEach(item => {
                    const name = item.getAttribute('data-name');
                    const id = item.getAttribute('data-id');
                    
                    if (name.includes(searchTerm) || id.includes(searchTerm)) {
                        item.style.display = 'flex';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });
                
                // Show/hide no results message
                if (visibleCount === 0 && searchTerm !== '') {
                    noResultsDiv.classList.remove('hidden');
                } else {
                    noResultsDiv.classList.add('hidden');
                }
            }

            // Make searchEmployees function globally available
            window.searchEmployees = searchEmployees;

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
                // Ensure hidden inputs are updated before submission
                updateHiddenInputs();
                
                let isValid = true;
                
                // Clear previous errors
                $('.is-invalid').removeClass('is-invalid');
                $('.error-message').remove();
                
                // Validate skills selection
                if (selectedSkills.size === 0) {
                    $('#skillsSection').addClass('is-invalid');
                    $('#skillsSection').append('<p class="error-message text-red-500 text-sm mt-1">Please select at least one skill for this quest</p>');
                    isValid = false;
                }
                
                console.log('DEBUG: Form validation - Selected skills:', selectedSkills.size);
                console.log('DEBUG: Form validation - Hidden inputs count:', 
                    document.querySelectorAll('input[name^="quest_skills"]').length);
                
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

        // Enhanced skill selection functionality
        let selectedSkills = new Set();
    const MAX_SKILLS = 5; // Reduced from 8 to 5 for focused mastery (Bundle 2)
        let customSkillCounter = 1000; // Start custom skill IDs from 1000
        let currentCategory = '';
        let currentCategoryName = '';
        let tempSelectedSkills = new Set(); // Temporary storage for modal selections
        
        // Skills data from PHP
        const allSkills = <?php echo json_encode($skills); ?>;

        // Insert focus efficiency helper after DOM loaded
        document.addEventListener('DOMContentLoaded', () => {
            const target = document.getElementById('selected-skills-badges') || document.querySelector('#selected-skills-badges');
            if (target && !document.getElementById('focus-efficiency-hint')) {
                const hint = document.createElement('div');
                hint.id = 'focus-efficiency-hint';
                hint.className = 'mt-2 text-xs text-indigo-600';
                hint.innerHTML = 'Tip: Selecting 1–2 skills = 100% XP each, 3 skills = 90%, 4 = 75%, 5 = 60%. Focus for faster mastery.';
                target.parentElement.insertBefore(hint, target.nextSibling);
            }
        });
        
        // Category configurations
        const categoryConfig = {
            'technical': {
                name: 'Technical Skills',
                icon: '<i class="fas fa-code"></i>',
                color: 'blue'
            },
            'communication': {
                name: 'Communication Skills', 
                icon: '<i class="fas fa-comments"></i>',
                color: 'amber'
            },
            'soft': {
                name: 'Soft Skills',
                icon: '<i class="fas fa-heart"></i>',
                color: 'rose'
            },
            'business': {
                name: 'Business Skills',
                icon: '<i class="fas fa-briefcase"></i>',
                color: 'emerald'
            }
        };
        
        // Function to show category skills modal
        function showCategorySkills(categoryId, categoryName) {
            currentCategory = categoryId;
            currentCategoryName = categoryName;
            
            // Update modal title and icon
            const config = categoryConfig[categoryId];
            document.getElementById('modalCategoryIcon').innerHTML = config.icon;
            document.getElementById('modalCategoryTitle').textContent = config.name;
            
            // Filter skills for this category
            const categorySkills = allSkills.filter(skill => {
                const catMap = {
                    'Technical Skills': 'technical',
                    'Communication Skills': 'communication', 
                    'Soft Skills': 'soft',
                    'Business Skills': 'business'
                };
                return catMap[skill.category_name] === categoryId;
            });
            
            // Copy current selections to temp
            tempSelectedSkills.clear();
            selectedSkills.forEach(skill => tempSelectedSkills.add(skill));
            
            // Populate skills list
            populateSkillsList(categorySkills);
            
            // Show modal
            document.getElementById('skillsModal').classList.remove('hidden');
            document.getElementById('skillSearchInput').value = '';
            document.getElementById('skillSearchInput').focus();
        }
        
        // Function to populate skills list in modal
        function populateSkillsList(skills) {
            const skillsList = document.getElementById('skillsList');
            skillsList.innerHTML = '';
            
            if (skills.length === 0) {
                skillsList.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                        <p>No skills available in this category.</p>
                        <button type="button" 
                                onclick="showCustomSkillModal()" 
                                class="mt-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                            <i class="fas fa-plus mr-2"></i>Add Custom Skill
                        </button>
                    </div>
                `;
                return;
            }
            
            // Define static tier points and names
            const tierPoints = [2, 5, 12, 25, 50];
            const tierNames = ['Beginner', 'Intermediate', 'Advanced', 'Expert', 'Master'];

            skills.forEach(skill => {
                const isSelected = Array.from(tempSelectedSkills).some(s => s.id == skill.skill_id);
                const skillHTML = [
                    '<div class="skill-item border border-gray-200 rounded-lg p-3 hover:bg-gray-50 transition-colors"',
                    '     data-skill-name="' + skill.skill_name.toLowerCase() + '"',
                    '     data-skill-id="' + skill.skill_id + '">',
                    '  <label class="flex items-start cursor-pointer">',
                    '    <input type="checkbox"',
                    '           class="skill-modal-checkbox mt-1 h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"',
                    '           data-skill-id="' + skill.skill_id + '"',
                    '           data-skill-name="' + skill.skill_name + '"',
                    '           data-category-name="' + skill.category_name + '"',
                    (isSelected ? '           checked' : ''),
                    '           onchange="handleModalSkillSelection(this)">',
                    '    <div class="ml-3 flex-1">',
                    '      <div class="text-sm font-medium text-gray-900">' + skill.skill_name + '</div>',
                    '      <!-- Tier Selector -->',
                    '      <div class="mt-2 ' + (isSelected ? '' : 'hidden') + '" id="tier-modal-' + skill.skill_id + '">',
                    '        <select class="tier-selector text-xs border border-gray-300 rounded px-2 py-1 focus:ring-2 focus:ring-indigo-500 bg-white" data-skill-id="' + skill.skill_id + '"></select>',
                    '        <div class="text-xs text-gray-500 mt-1" id="tier-basepoints-' + skill.skill_id + '"></div>',
                    '      </div>',
                    '    </div>',
                    '  </label>',
                    '</div>'
                ].join('\n');
                skillsList.insertAdjacentHTML('beforeend', skillHTML);

                // Populate the tier dropdown and base points for this skill
                const tierSelect = skillsList.querySelector(`.tier-selector[data-skill-id="${skill.skill_id}"]`);
                const basePointsDiv = skillsList.querySelector(`#tier-basepoints-${skill.skill_id}`);
                if (tierSelect) {
                    tierSelect.innerHTML = '';
                    tierPoints.forEach((points, idx) => {
                        const opt = document.createElement('option');
                        opt.value = (idx + 1).toString();
                        opt.textContent = `${tierNames[idx]} (${points} pts)`;
                        tierSelect.appendChild(opt);
                    });
                    // Set default to Intermediate (2nd tier)
                    tierSelect.value = '2';
                    if (basePointsDiv) {
                        basePointsDiv.textContent = `Base Points: ${tierPoints[1]} (Intermediate)`;
                    }
                    // Update base points display on change
                    tierSelect.addEventListener('change', function() {
                        const idx = parseInt(this.value, 10) - 1;
                        if (basePointsDiv) {
                            basePointsDiv.textContent = `Base Points: ${tierPoints[idx]} (${tierNames[idx]})`;
                        }
                    });
                }
            });
        }
        
        // Function to handle skill selection in modal
        function handleModalSkillSelection(checkbox) {
            const skillId = checkbox.dataset.skillId;
            const skillName = checkbox.dataset.skillName;
            const categoryName = checkbox.dataset.categoryName;
            const tierSelector = document.getElementById(`tier-modal-${skillId}`);
            
            if (checkbox.checked) {
                if (tempSelectedSkills.size >= MAX_SKILLS) {
                    alert(`You can only select up to ${MAX_SKILLS} skills per quest (focused mastery).`);
                    checkbox.checked = false;
                    return;
                }
                
                tempSelectedSkills.add({
                    id: skillId,
                    name: skillName,
                    category: categoryName,
                    isCustom: false
                });
                
                tierSelector.classList.remove('hidden');
            } else {
                // Remove from temp selected
                tempSelectedSkills.forEach(skill => {
                    if (skill.id == skillId) {
                        tempSelectedSkills.delete(skill);
                    }
                });
                
                tierSelector.classList.add('hidden');
            }
        }
        
        // Function to filter skills based on search
        function filterSkills() {
            const searchTerm = document.getElementById('skillSearchInput').value.toLowerCase();
            const skillItems = document.querySelectorAll('.skill-item');
            let visibleCount = 0;
            
            skillItems.forEach(item => {
                const skillName = item.dataset.skillName;
                if (skillName.includes(searchTerm)) {
                    item.style.display = 'block';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            const noResults = document.getElementById('noSkillsFound');
            if (visibleCount === 0 && searchTerm.length > 0) {
                noResults.classList.remove('hidden');
            } else {
                noResults.classList.add('hidden');
            }
        }
        
        // Function to apply selected skills from modal
        function applySelectedSkills() {
            // Clear current selections for this category
            selectedSkills.forEach(skill => {
                if (skill.category === currentCategoryName) {
                    selectedSkills.delete(skill);
                }
            });
            
            // Add new selections with tier values
            const selectedCheckboxes = document.querySelectorAll('.skill-modal-checkbox:checked');
            selectedCheckboxes.forEach(checkbox => {
                const skillId = checkbox.dataset.skillId;
                const skillName = checkbox.dataset.skillName;
                const categoryName = checkbox.dataset.categoryName;
                const tierSelector = document.querySelector(`.tier-selector[data-skill-id="${skillId}"]`);
                const tier = tierSelector ? tierSelector.value : '2';
                
                selectedSkills.add({
                    id: skillId,
                    name: skillName,
                    category: categoryName,
                    isCustom: false,
                    tier: tier
                });
            });
            
            updateSkillDisplay();
            updateHiddenInputs(); // Update hidden inputs when skills are applied
            closeSkillsModal();
        }
        
        // Function to close skills modal
        function closeSkillsModal() {
            document.getElementById('skillsModal').classList.add('hidden');
            tempSelectedSkills.clear();
        }
        
        // Function to show custom skill modal
        function showCustomSkillModal() {
            // Close skills modal if open
            closeSkillsModal();
            
            document.getElementById('customSkillModal').classList.remove('hidden');
            document.getElementById('modalCategoryId').value = currentCategory;
            document.getElementById('modalCategoryName').textContent = currentCategoryName;
            document.getElementById('customSkillName').value = '';
            document.getElementById('customSkillTier').value = '2';
            document.getElementById('customSkillName').focus();
        }
        
        // Function to close custom skill modal
        function closeCustomSkillModal() {
            document.getElementById('customSkillModal').classList.add('hidden');
        }
        
        // Function to add custom skill
        function addCustomSkill() {
            const skillName = document.getElementById('customSkillName').value.trim();
            const tier = document.getElementById('customSkillTier').value;
            const categoryId = document.getElementById('modalCategoryId').value;
            const categoryName = document.getElementById('modalCategoryName').textContent;
            
            if (!skillName) {
                alert('Please enter a skill name');
                return;
            }
            
            if (selectedSkills.size >= MAX_SKILLS) {
                alert(`You can only select up to ${MAX_SKILLS} skills per quest (focused mastery).`);
                return;
            }
            
            // Create unique ID for custom skill
            const customId = `custom_${customSkillCounter++}`;
            
            // Add to selected skills
            selectedSkills.add({
                id: customId,
                name: skillName,
                category: categoryName,
                isCustom: true,
                tier: tier
            });
            
            updateSkillDisplay();
            updateHiddenInputs(); // Update hidden inputs when custom skill is added
            closeCustomSkillModal();
        }
        
        // Function to get tier name
        function getTierName(tier) {
            const tierNames = {
                '1': 'Beginner',
                '2': 'Intermediate', 
                '3': 'Advanced',
                '4': 'Expert',
                '5': 'Master'
            };
            return tierNames[tier] || 'Unknown';
        }
        
        // Function to update skill display
        function updateSkillDisplay() {
            const badgesContainer = document.getElementById('selected-skills-badges');
            const counter = document.getElementById('skill-counter');
            
            // Update counter
            counter.textContent = selectedSkills.size;
            
            // Clear current badges
            badgesContainer.innerHTML = '';
            
            if (selectedSkills.size === 0) {
                badgesContainer.innerHTML = '<div class="text-xs text-blue-600 italic">No skills selected yet</div>';
            } else {
                // Create badges for selected skills
                selectedSkills.forEach(skill => {
                    const badgeHTML = `
                        <div class="inline-flex items-center px-3 py-1 bg-indigo-100 text-indigo-800 text-xs rounded-full border border-indigo-200">
                            <span class="font-medium">${skill.name}</span>
                            ${skill.isCustom ? '<span class="ml-1 text-yellow-600">(Custom)</span>' : ''}
                            <button type="button" 
                                    onclick="removeSkill('${skill.id}')" 
                                    class="ml-2 text-indigo-600 hover:text-indigo-800">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </div>
                    `;
                    badgesContainer.insertAdjacentHTML('beforeend', badgeHTML);
                });
            }
            
            // Update hidden inputs
            updateHiddenInputs();
        }
        
        // Unified function to remove a skill (custom or predefined)
        // Ensures: selectedSkills Set updated, related checkbox unchecked, tier selector hidden, custom label removed, hidden inputs & display refreshed
        function removeSkill(skillId) {
            // Remove from the Set (works for object-based entries)
            selectedSkills.forEach(skill => {
                if (skill.id == skillId) {
                    selectedSkills.delete(skill);
                }
            });

            // Uncheck corresponding checkbox if it exists (predefined or dynamically added custom)
            const checkbox = document.querySelector(`.skill-checkbox[value="${skillId}"]`);
            if (checkbox) {
                if (checkbox.checked) {
                    checkbox.checked = false;
                }
                // Hide tier selector if present for predefined skills
                const container = checkbox.closest('.skill-item') || checkbox.closest('label');
                if (container) {
                    const tierSelector = container.querySelector('.tier-select')?.closest('.skill-tier-selector');
                    if (tierSelector) tierSelector.classList.add('hidden');
                }
                // If it's a custom skill inside the selection list, remove its DOM element (checkbox wrapper)
                if (checkbox.dataset.isCustom === 'true') {
                    const labelEl = checkbox.closest('label');
                    if (labelEl && labelEl.parentElement) {
                        // Don't remove from the master list if you prefer keeping it; current pattern removes it.
                        labelEl.remove();
                    }
                }
            }

            updateSkillDisplay();
            updateHiddenInputs();
        }
        
        // Function to update hidden inputs for form submission
        function updateHiddenInputs() {
            // Remove existing hidden inputs
            const existingInputs = document.querySelectorAll('input[name^="quest_skills"], input[name^="skill_tiers"], input[name^="custom_skill"]');
            existingInputs.forEach(input => input.remove());
            
            // Find the main form
            const form = document.querySelector('form[method="post"]');
            if (!form) {
                console.error('Main form not found!');
                return;
            }
            
            console.log('DEBUG: Updating hidden inputs for', selectedSkills.size, 'skills');
            
            // Add hidden inputs for each selected skill
            selectedSkills.forEach(skill => {
                console.log('DEBUG: Creating hidden inputs for skill:', skill);
                
                // Create skill ID input
                const skillInput = document.createElement('input');
                skillInput.type = 'hidden';
                skillInput.name = 'quest_skills[]';
                skillInput.value = skill.id;
                form.appendChild(skillInput);
                
                // Create tier input
                const tierInput = document.createElement('input');
                tierInput.type = 'hidden';
                tierInput.name = `skill_tiers[${skill.id}]`;
                tierInput.value = skill.tier || '2';
                form.appendChild(tierInput);
                
                // Add custom skill data if applicable
                if (skill.isCustom) {
                    const nameInput = document.createElement('input');
                    nameInput.type = 'hidden';
                    nameInput.name = `custom_skill_names[${skill.id}]`;
                    nameInput.value = skill.name;
                    form.appendChild(nameInput);
                    
                    const categoryInput = document.createElement('input');
                    categoryInput.type = 'hidden';
                    categoryInput.name = `custom_skill_categories[${skill.id}]`;
                    categoryInput.value = skill.category;
                    form.appendChild(categoryInput);
                }
            });
            
            // DEBUG: Show current state
            console.log('DEBUG: Created', document.querySelectorAll('input[name^="quest_skills"]').length, 'hidden quest_skills inputs');
        }
        
        // Function to handle skill selection (keeping for compatibility)
    // Legacy checkbox handler retained because some checkbox markup still calls handleSkillSelection();
    // When refactoring, unify with object-based selectedSkills logic similar to toggleSkillSelection in edit_quest.
    function handleSkillSelection(checkbox, skillId) {
            const skillName = checkbox.getAttribute('data-skill-name');
            const categoryName = checkbox.getAttribute('data-category-name');
            const isCustom = checkbox.getAttribute('data-is-custom') === 'true';
            const tierSelector = document.getElementById(`tier-selector-${skillId}`);
            
            if (checkbox.checked) {
                // Check if we've reached the maximum number of skills
                if (selectedSkills.size >= MAX_SKILLS) {
                    checkbox.checked = false;
                    alert(`You can only select up to ${MAX_SKILLS} skills per quest (focused mastery). Please deselect a skill first.`);
                    return;
                }
                
                // Add to selected skills
                selectedSkills.add({
                    id: skillId,
                    name: skillName,
                    category: categoryName,
                    isCustom: isCustom
                });
                
                // Show tier selector for predefined skills
                if (tierSelector && !isCustom) {
                    tierSelector.classList.remove('hidden');
                }
                
            } else {
                // Remove from selected skills
                selectedSkills.forEach(skill => {
                    if (skill.id == skillId) {
                        selectedSkills.delete(skill);
                    }
                });
                
                // Hide tier selector for predefined skills
                if (tierSelector && !isCustom) {
                    tierSelector.classList.add('hidden');
                }
                
                // Remove custom skill element
                if (isCustom) {
                    checkbox.closest('label').remove();
                }
            }
            
            updateSkillDisplay();
        }
        
        
        // Employee search and selection functionality
        let selectedEmployees = new Set();
        
        // Function to filter employees based on search
        function filterEmployees() {
            const searchTerm = document.getElementById('employeeSearch').value.toLowerCase();
            const employeeItems = document.querySelectorAll('.employee-item');
            const clearBtn = document.getElementById('clearSearchBtn');
            let visibleCount = 0;
            
            // Show/hide clear button
            if (searchTerm.length > 0) {
                clearBtn.style.display = 'block';
            } else {
                clearBtn.style.display = 'none';
            }
            
            employeeItems.forEach(item => {
                const name = item.dataset.name;
                const id = item.dataset.id;
                
                if (name.includes(searchTerm) || id.includes(searchTerm)) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            const noResults = document.getElementById('noEmployeesFound');
            if (visibleCount === 0 && searchTerm.length > 0) {
                noResults.classList.remove('hidden');
            } else {
                noResults.classList.add('hidden');
            }
        }
        
        // Function to clear employee search
        function clearEmployeeSearch() {
            document.getElementById('employeeSearch').value = '';
            document.getElementById('clearSearchBtn').style.display = 'none';
            filterEmployees();
        }
        
        // Function to handle employee selection
        function handleEmployeeSelection(checkbox) {
            const employeeName = checkbox.dataset.name;
            const employeeId = checkbox.dataset.employeeId;
            
            if (checkbox.checked) {
                selectedEmployees.add({
                    id: employeeId,
                    name: employeeName
                });
            } else {
                selectedEmployees.forEach(emp => {
                    if (emp.id === employeeId) {
                        selectedEmployees.delete(emp);
                    }
                });
            }
            
            updateSelectedEmployeesDisplay();
        }
        
        // Function to update selected employees display
        function updateSelectedEmployeesDisplay() {
            const badgesContainer = document.getElementById('selectedEmployeesBadges');
            
            if (selectedEmployees.size === 0) {
                badgesContainer.innerHTML = '<div class="text-xs text-blue-600 italic">No employees selected</div>';
                return;
            }
            
            let badgesHTML = '';
            selectedEmployees.forEach(employee => {
                badgesHTML += `
                    <div class="inline-flex items-center px-3 py-1 bg-indigo-100 text-indigo-800 text-xs rounded-full border border-indigo-200">
                        <span class="font-medium">${employee.name}</span>
                        <span class="ml-1 text-indigo-600">(${employee.id})</span>
                        <button type="button" 
                                onclick="removeEmployee('${employee.id}')" 
                                class="ml-2 text-indigo-600 hover:text-indigo-800">
                            <i class="fas fa-times text-xs"></i>
                        </button>
                    </div>
                `;
            });
            
            badgesContainer.innerHTML = badgesHTML;
        }
        
        // Function to remove employee from selection
        function removeEmployee(employeeId) {
            const checkbox = document.querySelector(`.employee-checkbox[value="${employeeId}"]`);
            if (checkbox) {
                checkbox.checked = false;
                handleEmployeeSelection(checkbox);
            }
        }
        
        // Initialize selected employees on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check for pre-selected employees (for edit mode)
            const checkedEmployees = document.querySelectorAll('.employee-checkbox:checked');
            checkedEmployees.forEach(checkbox => {
                handleEmployeeSelection(checkbox);
            });
        });
        
        // Modern Date/Time Picker Functions
        let calendarBoxOpen = false;
        let calendarInstance = null;
        let selectedDate = null;
        
        function toggleCalendarBox() {
            const calendarBox = document.getElementById('calendarBox');
            const chevronIcon = document.getElementById('chevronIcon');
            
            if (calendarBoxOpen) {
                closeCalendarBox();
            } else {
                openCalendarBox();
            }
        }
        
        function openCalendarBox() {
            const calendarBox = document.getElementById('calendarBox');
            const chevronIcon = document.getElementById('chevronIcon');
            
            calendarBox.classList.remove('hidden');
            chevronIcon.classList.remove('fa-chevron-down');
            chevronIcon.classList.add('fa-chevron-up');
            calendarBoxOpen = true;
            
            // Initialize calendar if not already done
            if (!calendarInstance) {
                const calendarContainer = document.getElementById('calendarContainer');
                calendarInstance = flatpickr(calendarContainer, {
                    inline: true,
                    dateFormat: "Y-m-d",
                    minDate: "today",
                    defaultDate: "today", // Always default to today
                    onChange: function(selectedDates, dateStr, instance) {
                        selectedDate = dateStr;
                    }
                });
                // Set today as default selected date
                selectedDate = new Date().toISOString().split('T')[0];
            }
            
            // Set current time if no date is selected
            setDefaultTime();
            
            // Close calendar when clicking outside
            setTimeout(() => {
                document.addEventListener('click', handleOutsideClick);
            }, 100);
        }
        
        function closeCalendarBox() {
            const calendarBox = document.getElementById('calendarBox');
            const chevronIcon = document.getElementById('chevronIcon');
            
            calendarBox.classList.add('hidden');
            chevronIcon.classList.remove('fa-chevron-up');
            chevronIcon.classList.add('fa-chevron-down');
            calendarBoxOpen = false;
            
            document.removeEventListener('click', handleOutsideClick);
        }
        
        function handleOutsideClick(event) {
            const calendarBox = document.getElementById('calendarBox');
            const dueDateBtn = document.getElementById('dueDateBtn');
            
            if (!calendarBox.contains(event.target) && !dueDateBtn.contains(event.target)) {
                closeCalendarBox();
            }
        }
        
        function setDefaultTime() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = Math.ceil(now.getMinutes() / 15) * 15; // Round to nearest 15 minutes
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12; // Handle midnight
            
            document.getElementById('hourSelect').value = hours;
            document.getElementById('minuteSelect').value = minutes;
            document.getElementById('ampmSelect').value = ampm;
        }
        
        function setQuickTime(timeStr) {
            const [time, ampm] = timeStr.split(' ');
            const [hours, minutes] = time.split(':');
            
            document.getElementById('hourSelect').value = parseInt(hours);
            document.getElementById('minuteSelect').value = parseInt(minutes);
            document.getElementById('ampmSelect').value = ampm;
        }
        
        function applyDateTime() {
            // Ensure we have a date (should always be today by default)
            if (!selectedDate) {
                selectedDate = new Date().toISOString().split('T')[0];
            }
            
            const hourInput = document.getElementById('hourSelect');
            const minuteInput = document.getElementById('minuteSelect');
            const hour = parseInt(hourInput.value);
            const minute = parseInt(minuteInput.value);
            const ampm = document.getElementById('ampmSelect').value;
            
            // Clear any previous error styles
            hourInput.classList.remove('border-red-500', 'bg-red-50');
            minuteInput.classList.remove('border-red-500', 'bg-red-50');
            
            // Validation with better error messages and styling
            if (isNaN(hour) || hour < 1 || hour > 12) {
                hourInput.classList.add('border-red-500', 'bg-red-50');
                hourInput.focus();
                showErrorMessage('Please enter a valid hour between 1 and 12');
                return;
            }
            
            if (isNaN(minute) || minute < 0 || minute > 59) {
                minuteInput.classList.add('border-red-500', 'bg-red-50');
                minuteInput.focus();
                showErrorMessage('Please enter a valid minute between 0 and 59');
                return;
            }
            
            // Convert to 24-hour format for storage
            let hour24 = hour;
            if (ampm === 'PM' && hour24 !== 12) {
                hour24 += 12;
            } else if (ampm === 'AM' && hour24 === 12) {
                hour24 = 0;
            }
            
            const minuteStr = minute.toString().padStart(2, '0');
            const dateTimeStr = `${selectedDate} ${hour24.toString().padStart(2, '0')}:${minuteStr}:00`;
            document.getElementById('due_date').value = dateTimeStr;
            
            updateDateDisplay(dateTimeStr);
            closeCalendarBox();
        }
        
        function showErrorMessage(message) {
            // Remove any existing error message
            const existingError = document.getElementById('time-error-message');
            if (existingError) {
                existingError.remove();
            }
            
            // Create and show new error message
            const errorDiv = document.createElement('div');
            errorDiv.id = 'time-error-message';
            errorDiv.className = 'mt-2 px-3 py-2 bg-red-100 border border-red-300 text-red-700 rounded-lg text-sm flex items-center';
            errorDiv.innerHTML = `
                <i class="fas fa-exclamation-triangle mr-2"></i>
                ${message}
            `;
            
            // Insert after the time selection grid
            const timeGrid = document.querySelector('#calendarBox .grid');
            timeGrid.parentNode.insertBefore(errorDiv, timeGrid.nextSibling);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (errorDiv.parentNode) {
                    errorDiv.remove();
                }
            }, 5000);
        }
        
        function updateDateDisplay(dateTimeStr) {
            const displaySpan = document.getElementById('dueDateDisplay');
            
            if (dateTimeStr) {
                const date = new Date(dateTimeStr);
                const options = { 
                    weekday: 'short',
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                };
                
                displaySpan.innerHTML = `
                    <i class="fas fa-calendar-check mr-2 text-green-500"></i>
                    ${date.toLocaleDateString('en-US', options)}
                `;
                displaySpan.classList.remove('text-gray-500');
                displaySpan.classList.add('text-gray-900', 'font-medium');
            } else {
                displaySpan.innerHTML = `
                    <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i>
                    Click to select due date and time
                `;
                displaySpan.classList.remove('text-gray-900', 'font-medium');
                displaySpan.classList.add('text-gray-500');
            }
        }
        
        function clearFieldError(input) {
            input.classList.remove('border-red-500', 'bg-red-50');
            const errorMessage = document.getElementById('time-error-message');
            if (errorMessage) {
                errorMessage.remove();
            }
        }
        
        function clearDueDate() {
            document.getElementById('due_date').value = '';
            selectedDate = null;
            if (calendarInstance) {
                calendarInstance.clear();
            }
            updateDateDisplay('');
            closeCalendarBox();
        }
        
        // (Duplicate removeSkill removed; unified version defined earlier.)
        
        // Function to toggle assignment type
        function toggleAssignmentType() {
            const individualAssignment = document.getElementById('individualAssignment');
            const groupAssignment = document.getElementById('groupAssignment');
            const selectedType = document.querySelector('input[name="assignment_type"]:checked').value;
            
            if (selectedType === 'individual') {
                individualAssignment.classList.remove('hidden');
                groupAssignment.classList.add('hidden');
            } else {
                individualAssignment.classList.add('hidden');
                groupAssignment.classList.remove('hidden');
            }
        }
        
        // Function to load group members
        function loadGroupMembers(groupId) {
            if (!groupId) {
                document.getElementById('groupMembersPreview').classList.add('hidden');
                return;
            }
            
            // Make AJAX call to get group members
            fetch(`?ajax=get_group_members&group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.members.length > 0) {
                        const memberNames = data.members.map(m => m.full_name).join(', ');
                        document.getElementById('groupMembersList').textContent = memberNames;
                        document.getElementById('groupMembersPreview').classList.remove('hidden');
                    } else {
                        document.getElementById('groupMembersPreview').classList.add('hidden');
                    }
                })
                .catch(error => {
                    console.error('Error loading group members:', error);
                });
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('customSkillModal');
            if (event.target === modal) {
                closeCustomSkillModal();
            }
        });
        
        // Allow Enter key to add skill
        document.addEventListener('keypress', function(event) {
            if (event.key === 'Enter' && document.getElementById('customSkillName') === document.activeElement) {
                event.preventDefault();
                addCustomSkill();
            }
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