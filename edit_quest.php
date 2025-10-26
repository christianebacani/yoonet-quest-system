<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

$role = $_SESSION['role'] ?? '';

// Simple role renaming
if ($role === 'hybrid') {
    $role = 'learning_architect';
} elseif ($role === 'quest_giver') {
    $role = 'learning_architect';
} elseif ($role === 'contributor') {
    $role = 'learning_architect';
}

// Allow learning_architects to edit any quest. Creators (the user who created the quest)
// should also be allowed to edit their own quests regardless of role. We'll perform
// a creator check after loading the quest record below.



$error = '';
$success = '';
$quest = null;
$quest_id = $_GET['id'] ?? 0;

// Fetch quest data with all new fields
try {
    // Fetch quest by id first (do not restrict by created_by here) so we can
    // verify ownership using the multiple created_by formats the app uses.
    $stmt = $pdo->prepare("SELECT q.*, 
                          GROUP_CONCAT(DISTINCT uq.employee_id) as assigned_employees
                          FROM quests q
                          LEFT JOIN user_quests uq ON q.id = uq.quest_id
                          LEFT JOIN users u ON uq.employee_id = u.employee_id
                          WHERE q.id = ?
                          GROUP BY q.id");
    $stmt->execute([$quest_id]);
    $quest = $stmt->fetch();
    
    if (!$quest) {
        header('Location: dashboard.php');
        exit();
    }

    // Permission check: allow if current user is the creator (several stored formats)
    $createdBy = $quest['created_by'] ?? null;
    $employee_id = $_SESSION['employee_id'] ?? null;
    // Get current users.id for comparison (if present)
    $current_user_id = null;
    try {
        if ($employee_id) {
            $uStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? LIMIT 1");
            $uStmt->execute([$employee_id]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            $current_user_id = $uRow['id'] ?? null;
        }
    } catch (PDOException $e) {
        // ignore, we'll fallback to role check
    }

    $is_creator = false;
    if ($createdBy !== null) {
        $cb = (string)$createdBy;
        if ($employee_id && $cb === (string)$employee_id) { $is_creator = true; }
        if ($current_user_id && $cb === (string)$current_user_id) { $is_creator = true; }
        if ($current_user_id && strtolower(trim($cb)) === strtolower('user_' . (string)$current_user_id)) { $is_creator = true; }
    }

    // If not creator and not a learning_architect, block access
    if (!$is_creator && $role !== 'learning_architect') {
        header('Location: dashboard.php');
        exit();
    }
    
    // Fetch subtasks
    $stmt = $pdo->prepare("SELECT * FROM quest_subtasks WHERE quest_id = ?");
    $stmt->execute([$quest_id]);
    $subtasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch attachments
    $stmt = $pdo->prepare("SELECT * FROM quest_attachments WHERE quest_id = ?");
    $stmt->execute([$quest_id]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch quest skills for editing
    $stmt = $pdo->prepare("
        SELECT qs.skill_id, qs.tier, cs.skill_name, cs.category_id, sc.category_name 
        FROM quest_skills qs 
        JOIN comprehensive_skills cs ON qs.skill_id = cs.id 
        JOIN skill_categories sc ON cs.category_id = sc.id 
        WHERE qs.quest_id = ?
    ");
    $stmt->execute([$quest_id]);
    $quest_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all available skills grouped by category for the selection interface
    $stmt = $pdo->prepare("
        SELECT cs.id as skill_id, cs.skill_name, cs.category_id, sc.category_name 
        FROM comprehensive_skills cs 
        JOIN skill_categories sc ON cs.category_id = sc.id 
        ORDER BY sc.category_name, cs.skill_name
    ");
    $stmt->execute();
    $all_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group skills by category
    $skills_by_category = [];
    foreach ($all_skills as $skill) {
        $skills_by_category[$skill['category_name']][] = $skill;
    }
    
} catch (PDOException $e) {
    // Log and surface the DB error here temporarily to help debugging
    error_log("Database error fetching quest: " . $e->getMessage());
    $error = 'Error loading quest data: ' . $e->getMessage();
}

// Initialize form variables with quest data
$title = $quest['title'] ?? '';
$description = $quest['description'] ?? '';
$xp = $quest['xp'] ?? 10;
$quest_assignment_type = $quest['quest_assignment_type'] ?? 'optional';
$due_date = $quest['due_date'] ?? null;
$status = $quest['status'] ?? 'active';
$assign_to = !empty($quest['assigned_employees']) ? explode(',', $quest['assigned_employees']) : [];
$assign_group = null;
$quest_type = $quest['quest_type'] ?? 'single';
$visibility = $quest['visibility'] ?? 'public';
$recurrence_pattern = $quest['recurrence_pattern'] ?? '';
$recurrence_end_date = $quest['recurrence_end_date'] ?? '';
$publish_at = $quest['publish_at'] ?? '';

// Fetch employees for assignment
$employees = [];
try {
    // Get all skill_associates and quest_leads EXCEPT the current user
    $current_user_id = $_SESSION['employee_id'];
    $stmt = $pdo->prepare("SELECT employee_id, full_name FROM users 
                          WHERE role IN ('skill_associate', 'quest_lead') 
                          AND employee_id != ?
                          ORDER BY full_name");
    $stmt->execute([$current_user_id]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching data: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quest_assignment_type = isset($_POST['quest_assignment_type']) ? $_POST['quest_assignment_type'] : 'optional';
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;

    // If client sent an ISO timestamp (with timezone) convert it to server local time string
    if (!empty($due_date) && (strpos($due_date, 'T') !== false || strpos($due_date, 'Z') !== false)) {
        try {
            $dt = new DateTime($due_date);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $due_date = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            // leave as-is; validation will catch invalid formats
        }
    }

    // Final validation: ensure due_date parses to a valid timestamp; otherwise null it
    if (!empty($due_date)) {
        $ts = strtotime($due_date);
        if ($ts === false || $ts <= 0) {
            $due_date = null;
        } else {
            // normalize format
            $due_date = date('Y-m-d H:i:s', $ts);
        }
    }
    $assign_to = isset($_POST['assign_to']) ? $_POST['assign_to'] : [];
    $assign_group = null;
    $status = $_POST['status'] ?? 'active';
    
    // Handle quest skills
    $quest_skills_data = $_POST['quest_skills'] ?? '';
    $quest_skills = [];
    if (!empty($quest_skills_data)) {
        $quest_skills = json_decode($quest_skills_data, true) ?: [];
    }

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
        $error = 'Please select a valid assignment type (mandatory or optional)';
    } elseif (!empty($due_date) && !strtotime($due_date)) {
        $error = 'Invalid due date format';
    } elseif (count($quest_skills) > 5) {
        $error = 'Maximum 5 skills allowed per quest (focused mastery)';
    } elseif (empty($quest_skills)) {
        $error = 'At least one skill must be selected';
    } else {
        // Validate assigned employees exist and are quest participants
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
            // Server-side lock: do not allow editing if there are approved submissions
            try {
                $acStmt = $pdo->prepare("SELECT COUNT(*) FROM quest_submissions WHERE quest_id = ? AND status = 'approved'");
                $acStmt->execute([$quest_id]);
                $approvedCount = (int)$acStmt->fetchColumn();
                if ($approvedCount > 0) {
                    $error = 'This quest cannot be edited because it has approved submissions.';
                }
            } catch (PDOException $e) {
                // Log and continue; if this check fails we'll be conservative and allow the edit to proceed
                error_log('Failed to check approved submissions before edit: ' . $e->getMessage());
            }
        }

        if (empty($error)) {
            try {
                $pdo->beginTransaction();
                
                // Determine visibility based on assignments (use $assign_to from form)
                $visibility_value = !empty($assign_to) ? 'private' : 'public';

                // Update the quest (include visibility)
                $stmt = $pdo->prepare("UPDATE quests SET 
                    title = ?, 
                    description = ?, 
                    status = ?, 
                    due_date = ?, 
                    quest_assignment_type = ?,
                    visibility = ?,
                    updated_at = NOW()
                    WHERE id = ?");
                $stmt->execute([
                    $title, 
                    $description, 
                    $status, 
                    $due_date,
                    $quest_assignment_type,
                    $visibility_value,
                    $quest_id
                ]);
                
                // Delete existing quest skills
                $stmt = $pdo->prepare("DELETE FROM quest_skills WHERE quest_id = ?");
                $stmt->execute([$quest_id]);
                
                // Add new quest skills
                if (!empty($quest_skills)) {
                    $stmt = $pdo->prepare("INSERT INTO quest_skills (quest_id, skill_id, tier) VALUES (?, ?, ?)");
                    foreach ($quest_skills as $skill) {
                        $stmt->execute([$quest_id, $skill['skill_id'], $skill['tier']]);
                    }
                }
                
                // Handle file uploads
                if (!empty($_FILES['attachments']['name'][0])) {
                    $uploadDir = 'uploads/quests/' . $quest_id . '/';
                    if (!file_exists($uploadDir)) {
                        if (!mkdir($uploadDir, 0755, true)) {
                            throw new Exception("Failed to create upload directory");
                        }
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
                
                // Handle assignment changes
                // First, remove all existing assignments
                $stmt = $pdo->prepare("DELETE FROM user_quests WHERE quest_id = ?");
                $stmt->execute([$quest_id]);
                
                // Only assign to selected employees
                $all_assignments = !empty($assign_to) ? array_unique($assign_to) : [];
                
                // Assign quest to selected employees
                if (!empty($all_assignments)) {
                    foreach ($all_assignments as $employee_id) {
                        // First check if the employee exists in users table
                        $stmt = $pdo->prepare("SELECT id, employee_id FROM users WHERE employee_id = ?");
                        $stmt->execute([$employee_id]);
                        $user = $stmt->fetch();
                        
                        if (!$user) {
                            error_log("Warning: Attempted to assign quest to non-existent user: " . $employee_id);
                            continue; // Skip this assignment
                        }

                        // Determine quest status based on assignment type
                        $quest_status = ($quest_assignment_type === 'mandatory') ? 'in_progress' : 'assigned';
                        
                        // Assign quest to user
                        $stmt = $pdo->prepare("INSERT INTO user_quests 
                            (employee_id, quest_id, status, assigned_at) 
                            VALUES (?, ?, ?, NOW())");
                        $stmt->execute([$employee_id, $quest_id, $quest_status]);
                        
                        // Record in XP history using the user's id (not employee_id) for the foreign key constraint
                        $assignment_description = ($quest_assignment_type === 'mandatory') ? 
                            "Mandatory quest auto-assigned: $title" : 
                            "Optional quest assigned: $title";
                        $stmt = $pdo->prepare("INSERT INTO xp_history 
                            (employee_id, xp_change, source_type, source_id, description, created_at)
                            VALUES (?, ?, 'quest_assigned', ?, ?, NOW())");
                        $stmt->execute([
                            $user['id'], // Use the user's id (primary key) instead of employee_id
                            0, // No XP change on assignment
                            $quest_id,
                            $assignment_description
                        ]);
                    }
                }
                
                $pdo->commit();
                
                $assignment_count = count($all_assignments);
                $success = 'Quest updated successfully' . 
                           ($assignment_count > 0 ? " and assigned to $assignment_count employee(s)!" : '!');
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Database error in edit_quest.php: " . $e->getMessage());
                error_log("SQL State: " . $e->getCode());
                $error = 'Error updating quest: ' . $e->getMessage();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error: " . $e->getMessage());
                $error = 'Error updating quest: ' . $e->getMessage();
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
    <title>Edit Quest</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        /* Save button for Flatpickr calendar */
        .flatpickr-save-btn {
            display: block;
            width: 90%;
            margin: 12px auto 0 auto;
            padding: 10px 0;
            background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(99,102,241,0.10);
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
        }
        .flatpickr-save-btn:hover {
            background: linear-gradient(90deg, #4f46e5 0%, #6366f1 100%);
            box-shadow: 0 4px 16px rgba(99,102,241,0.18);
        }
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
        
        .tab-content {
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
            border-radius: 14px;
            border: none;
            padding: 16px 16px 32px 16px;
            z-index: 100002 !important;
            min-width: 360px !important;
            width: 400px !important;
            max-width: 460px !important;
            margin-bottom: 32px !important;
        }

        .flatpickr-innerContainer {
            overflow: visible !important;
            min-width: 360px !important;
            width: 400px !important;
            max-width: 460px !important;
        }

        .flatpickr-time {
            z-index: 100003 !important;
            background: #fff !important;
            border-radius: 10px !important;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            padding: 12px 0 12px 0 !important;
            margin-top: 8px !important;
            margin-bottom: 8px !important;
        }
        
        .flatpickr-day {
            color: #1e293b !important;
            border-radius: 6px;
            font-weight: 500;
            border: none;
            max-width: none !important;
            width: calc(100% / 7) !important;
            height: 36px !important;
            line-height: 36px !important;
            margin: 2px 0 !important;
            opacity: 1 !important;
            visibility: visible !important;
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
            min-width: 340px !important;
            max-width: 420px !important;
            display: block !important;
        }

        .dayContainer {
            display: grid !important;
            grid-template-columns: repeat(7, 1fr) !important;
            gap: 0 !important;
            width: 100% !important;
            min-width: 340px !important;
            max-width: 420px !important;
        }
        
        .recurring-options.visible {
            display: block;
        }
        
        .recurrence-patterns {
            display: flex;
            flex-direction: row;
            justify-content: center;
            align-items: center;
            gap: 1.5rem;
            margin-top: 1rem;
        }
        
        .recurrence-pattern {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .recurrence-pattern:hover {
            border-color: #c7d2fe;
            background-color: #f8fafc;
        }
        
        .recurrence-pattern.selected {
            border-color: #6366f1;
            background-color: #e0e7ff;
        }
        
        .recurrence-pattern i {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #64748b;
        }
        
        .recurrence-pattern.selected i {
            color: #4f46e5;
        }
        
        .recurrence-pattern span {
            font-size: 0.875rem;
            text-align: center;
            color: #64748b;
        }
        
        .recurrence-pattern.selected span {
            color: #4f46e5;
            font-weight: 500;
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
    </style>
</head>
<body class="<?php echo getBodyClass(); ?>" style="font-size: <?php echo getFontSize(); ?>;">
    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Edit Quest</h1>
                <p class="text-gray-500 mt-1">Update an existing challenge for your team</p>
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

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <!-- Basic Information Section -->
                <div class="card p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-info-circle text-indigo-500 mr-2"></i> Basic Information
                    </h2>
                    
                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Quest Title*</label>
                            <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($title); ?>" 
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                                   placeholder="Enter quest title" required maxlength="255">
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description*</label>
                            <textarea id="description" name="description" rows="4"
                                      class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                                      placeholder="Describe the quest requirements and objectives" required maxlength="2000"><?php echo htmlspecialchars($description); ?></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="quest_assignment_type" class="block text-sm font-medium text-gray-700 mb-1">Assignment Type*</label>
                                <select name="quest_assignment_type" id="quest_assignment_type" 
                                        class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300" required>
                                    <option value="optional" <?php echo $quest_assignment_type === 'optional' ? 'selected' : ''; ?>>
                                        Optional - Users can accept or decline this quest
                                    </option>
                                    <option value="mandatory" <?php echo $quest_assignment_type === 'mandatory' ? 'selected' : ''; ?>>
                                        Mandatory - Automatically assigned to users
                                    </option>
                                </select>
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
                                    <input type="hidden" id="due_date" name="due_date" value="<?php echo $due_date ? htmlspecialchars($due_date) : ''; ?>">
                                    
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

                <!-- Quest Type Selection -->
                <div class="card p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-layer-group text-indigo-500 mr-2"></i> Quest Type
                    </h2>
                    <div class="flex gap-4 mb-4">
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="quest_type" value="routine" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500" checked>
                            <span class="ml-2 text-sm font-medium text-gray-700">Routine Task</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="quest_type" value="minor" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500">
                            <span class="ml-2 text-sm font-medium text-gray-700">Minor Quest</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="quest_type" value="standard" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500">
                            <span class="ml-2 text-sm font-medium text-gray-700">Standard Quest</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="quest_type" value="major" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500">
                            <span class="ml-2 text-sm font-medium text-gray-700">Major Quest</span>
                        </label>
                        <label class="flex items-center cursor-pointer">
                            <input type="radio" name="quest_type" value="project" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500">
                            <span class="ml-2 text-sm font-medium text-gray-700">Major Project</span>
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-gray-500"><i class="fas fa-info-circle mr-1"></i>Quest type affects skill tier base points and recurrence options.</p>
                </div>
                <!-- Skills & Assessment Section -->
                <div class="card p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-brain text-indigo-500 mr-2"></i> Skills & Assessment
                    </h2>
                    
                    <!-- Selected Skills Summary -->
                    <div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg min-h-[50px]">
                        <div class="flex iteclss-center justify-between mb-2">
                            <div class="text-xs font-medium text-blue-800">Selected Skills:</div>
                            <div class="text-sm text-gray-600">
                                <span id="skillCount" class="font-semibold text-indigo-600">0</span>/5 selected
                            </div>
                        </div>
                        <div id="selectedSkillsBadges" class="flex flex-wrap gap-2">
                            <span class="text-xs text-blue-600 italic" id="noSkillsMessage">No skills selected yet</span>
                        </div>
                    </div>

                    <!-- Skill Categories with Add Custom Skill -->
                    <div class="border border-gray-200 rounded-lg max-h-96 overflow-y-auto bg-white">
                        <?php 
                        $category_colors = [
                            'Technical Skills' => 'bg-blue-50 border-l-4 border-blue-400',
                            'Communication Skills' => 'bg-green-50 border-l-4 border-green-400',
                            'Soft Skills' => 'bg-purple-50 border-l-4 border-purple-400',
                            'Business Skills' => 'bg-orange-50 border-l-4 border-orange-400'
                        ];
                        
                        $category_ids = [
                            'Technical Skills' => 'technical',
                            'Communication Skills' => 'communication',
                            'Soft Skills' => 'soft',
                            'Business Skills' => 'business'
                        ];
                        
                        // If no skills exist, show all categories with add custom skill option
                        if (empty($skills_by_category)):
                            foreach ($category_ids as $category_name => $cat_id):
                        ?>
                            <!-- Category Header (Always Visible) -->
                            <div class="<?php echo $category_colors[$category_name] ?? 'bg-gray-50'; ?> p-2 font-semibold text-sm text-gray-700 sticky top-0 z-10 flex items-center justify-between">
                                <div>
                                    <?php echo htmlspecialchars($category_name); ?> 
                                    <span class="text-xs font-normal text-gray-500">(0 skills)</span>
                                </div>
                                <button type="button" 
                                        onclick="showCustomSkillModal('<?php echo $cat_id; ?>', '<?php echo htmlspecialchars($category_name); ?>')"
                                        class="px-3 py-1 text-xs bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors shadow-sm">
                                    <i class="fas fa-plus mr-1"></i>Add Custom Skill
                                </button>
                            </div>
                            
                            <!-- No predefined skills message -->
                            <div class="p-3 text-center text-gray-500 text-xs italic bg-gray-50">
                                No predefined skills. Click "Add Custom Skill" to add your own.
                            </div>
                            
                            <!-- Container for Custom Skills -->
                            <div id="custom-skills-<?php echo $cat_id; ?>" class="divide-y divide-gray-100"></div>
                        <?php 
                            endforeach;
                        else:
                            foreach ($skills_by_category as $category_name => $skills): 
                                $cat_id = $category_ids[$category_name] ?? strtolower(str_replace(' ', '_', $category_name));
                        ?>
                        
                        <!-- Category Header (Always Visible) -->
                        <div class="<?php echo $category_colors[$category_name] ?? 'bg-gray-50'; ?> p-2 font-semibold text-sm text-gray-700 sticky top-0 z-10 flex items-center justify-between">
                            <div>
                                <?php echo htmlspecialchars($category_name); ?>
                                <span class="text-xs font-normal text-gray-500">(<?php echo count($skills); ?> skills)</span>
                            </div>
                            <button type="button" 
                                    onclick="showCustomSkillModal('<?php echo $cat_id; ?>', '<?php echo htmlspecialchars($category_name); ?>')"
                                    class="px-3 py-1 text-xs bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors shadow-sm">
                                <i class="fas fa-plus mr-1"></i>Add Custom Skill
                            </button>
                        </div>
                        
                        <!-- Skills List -->
                        <div class="divide-y divide-gray-100">
                            <?php foreach ($skills as $skill): ?>
                                <label class="flex items-start p-2 hover:bg-gray-50 cursor-pointer transition-colors skill-item"
                                       data-skill-id="<?php echo $skill['skill_id']; ?>"
                                       data-skill-name="<?php echo htmlspecialchars($skill['skill_name']); ?>"
                                       data-category="<?php echo $cat_id; ?>"
                                       data-category-name="<?php echo htmlspecialchars($category_name); ?>">
                                    <input type="checkbox" 
                                           class="skill-checkbox mt-1 h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                           data-skill-id="<?php echo $skill['skill_id']; ?>"
                                           data-skill-name="<?php echo htmlspecialchars($skill['skill_name']); ?>"
                                           data-category-name="<?php echo htmlspecialchars($category_name); ?>"
                                           data-is-custom="false"
                                           onchange="toggleSkillSelection(this)">
                                    <div class="ml-2 flex-1">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($skill['skill_name']); ?></div>
                                        
                                        <!-- Tier Selector (Shown when checked) -->
                                        <div class="skill-tier-selector hidden mt-1">
                                            <select class="tier-select text-xs border border-gray-300 rounded px-2 py-1 focus:ring-2 focus:ring-indigo-500 bg-white"
                                                    onchange="updateSkillTier(this)">
                                                <!-- Dynamic options will be injected by JS -->
                                            </select>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Container for Custom Skills -->
                        <div id="custom-skills-<?php echo $cat_id; ?>" class="divide-y divide-gray-100"></div>
                        
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <p class="mt-2 text-xs text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        Select from predefined skills or add your own custom skills for each category.
                    </p>
                    
                    <!-- Hidden input to store selected skills -->
                    <input type="hidden" name="quest_skills" id="questSkillsInput" value="">
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
                            <select id="customSkillTier" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    onchange="updateCustomSkillTierInfo()">
                                <!-- Dynamic options will be injected by JS -->
                            </select>
                            <div id="customSkillTierInfo" class="mt-2 text-xs text-gray-600 font-semibold flex items-center">
                                <!-- Tier info will be injected by JS -->
                            </div>
        // --- DYNAMIC TIER DROPDOWNS BASED ON QUEST TYPE ---
        // Central mapping for base points per tier by quest type
        const questTypeTierPoints = {
            routine:  [2, 4, 6, 8, 10],      // Routine Task
            minor:    [5, 10, 15, 20, 25],   // Minor Quest
            standard: [10, 20, 30, 40, 50],  // Standard Quest
            major:    [20, 40, 60, 80, 100], // Major Quest
            project:  [40, 80, 120, 160, 200]// Major Project
        };

        // Helper to get current quest type (radio)
        function getCurrentQuestType() {
            const el = document.querySelector('input[name="quest_type"]:checked');
            return el ? el.value : 'routine';
        }

        // Helper to get points for a given tier (1-based) and quest type
        function getTierPoints(tier, questType) {
            const arr = questTypeTierPoints[questType] || questTypeTierPoints['routine'];
            return arr[tier-1] || 0;
        }

        // Generate tier <option>s for a dropdown
        function generateTierOptions(selectedTier, questType) {
            const names = ['Beginner', 'Intermediate', 'Advanced', 'Expert', 'Master'];
            let opts = '';
            for (let i=1; i<=5; ++i) {
                const pts = getTierPoints(i, questType);
                opts += '<option value="' + i + '"' + (selectedTier==i ? ' selected' : '') + '>Tier ' + i + ' - ' + names[i-1] + ' (' + pts + ' pts)</option>';
            }
            return opts;
        }

        // Update all tier dropdowns (modals, custom, etc.)
        function updateAllTierDropdowns() {
            const questType = getCurrentQuestType();
            // Update all .tier-select dropdowns
            document.querySelectorAll('.tier-select').forEach(sel => {
                const selected = parseInt(sel.value) || 2;
                sel.innerHTML = generateTierOptions(selected, questType);
            });
            // Update custom skill modal dropdown
            const customSel = document.getElementById('customSkillTier');
            if (customSel) {
                const selected = parseInt(customSel.value) || 2;
                customSel.innerHTML = generateTierOptions(selected, questType);
                updateCustomSkillTierInfo();
            }
        }

        // Show tier and base points for custom skill modal
        function updateCustomSkillTierInfo() {
            const questType = getCurrentQuestType();
            const tier = parseInt(document.getElementById('customSkillTier').value) || 2;
            const names = ['Beginner', 'Intermediate', 'Advanced', 'Expert', 'Master'];
            const pts = getTierPoints(tier, questType);
            const infoDiv = document.getElementById('customSkillTierInfo');
            if (infoDiv) {
                infoDiv.innerHTML = `<span class="inline-block px-2 py-1 rounded bg-indigo-50 text-indigo-700 border border-indigo-200 mr-2">Tier ${tier} - ${names[tier-1]}</span> <span class="inline-block px-2 py-1 rounded bg-green-50 text-green-700 border border-green-200">${pts} base points</span>`;
            }
        }

        // On quest type change, update all dropdowns
        document.querySelectorAll('input[name="quest_type"]').forEach(radio => {
            radio.addEventListener('change', updateAllTierDropdowns);
        });

        // When modals open, ensure dropdowns are updated
        window.showCustomSkillModal = function(categoryId, categoryName) {
            document.getElementById('customSkillModal').classList.remove('hidden');
            document.getElementById('modalCategoryId').value = categoryId;
            document.getElementById('modalCategoryName').textContent = categoryName;
            document.getElementById('customSkillName').value = '';
            // Always populate the dropdown before setting value
            updateAllTierDropdowns();
            document.getElementById('customSkillTier').value = '2';
            updateCustomSkillTierInfo();
            document.getElementById('customSkillName').focus();
        }

        // On DOM ready, initialize all tier dropdowns
        document.addEventListener('DOMContentLoaded', updateAllTierDropdowns);
                            </select>
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

                <!-- Attachments Section -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                        <i class="fas fa-paperclip text-indigo-500 mr-2"></i> Attachments (Optional)
                    </h2>
                    
                    <!-- Existing Attachments -->
                    <?php if (!empty($attachments)): ?>
                        <div class="mb-4">
                            <h3 class="text-md font-medium text-gray-700 mb-2">Current Attachments</h3>
                            <div class="space-y-2">
                                <?php foreach ($attachments as $attachment): ?>
                                    <div class="flex items-center justify-between p-2 bg-gray-50 rounded">
                                        <div class="flex items-center">
                                            <?php
                                            $file_icon = 'fa-file';
                                            if (strpos($attachment['file_type'], 'image/') === 0) {
                                                $file_icon = 'fa-file-image';
                                            } elseif ($attachment['file_type'] === 'application/pdf') {
                                                $file_icon = 'fa-file-pdf';
                                            }
                                            ?>
                                            <i class="fas <?php echo $file_icon; ?> text-indigo-500 mr-2"></i>
                                            <span class="text-sm"><?php echo htmlspecialchars($attachment['file_name']); ?></span>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                           target="_blank" 
                                           class="text-indigo-600 hover:text-indigo-800 ml-2">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- New Attachments -->
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
                <div class="max-w-2xl mx-auto">
                    <div class="card p-6">
                        <h2 class="text-xl font-semibold text-gray-800 mb-3 pb-2 border-b border-gray-100">
                            <i class="fas fa-users text-indigo-500 mr-2"></i> Assignment
                        </h2>
                        
                        <div>
                        <!-- Assignment Tabs -->
                        <div class="flex border-b border-gray-200 mb-3">
                            <div class="assignment-tab active flex-1 text-center py-2 px-3 border-b-2 border-indigo-500 text-indigo-600 text-sm font-medium cursor-pointer" data-tab="individual">
                                <i class="fas fa-user mr-1"></i>Individuals
                            </div>
                            <div class="assignment-tab flex-1 text-center py-2 px-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700 text-sm font-medium cursor-pointer" data-tab="group">
                                <i class="fas fa-users mr-1"></i>Group
                            </div>
                        </div>
                        
                        <!-- Individual Assignment Tab Content -->
                        <div class="tab-content active" id="individualTab">
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
                            
                            <!-- Employee Selection -->
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
                        

                    </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="mt-8 pt-6 border-t border-gray-100">
                    <div class="flex justify-center">
                        <button type="submit" class="btn-primary px-8 py-3 rounded-lg font-medium shadow-lg hover:shadow-xl transition-shadow duration-200 flex items-center">
                            <i class="fas fa-save mr-2"></i> Update Quest
                        </button>
                    </div>
                </div>
        </form>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js"></script>
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
                $('input[name="recurrence_pattern"]').prop('checked', false);
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

        // Custom recurrence modal logic (copied from create_quest.php for consistency)
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
                // Remove any previous Flatpickr instance before initializing
                setTimeout(function() {
                    if (window.flatpickr) {
                        var customEndDateInput = document.getElementById('customEndDate');
                        if (customEndDateInput) {
                            if (customEndDateInput._flatpickr) {
                                customEndDateInput._flatpickr.destroy();
                            }
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
                }, 200);
            }
        }

            // Initialize Flatpickr for custom recurrence end date (date only)
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
                $('#customEndDate').css('display', 'inline-block');
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

            // Initialize date pickers with time selection for due date
            // --- COPIED FROM create_quest.php for calendar consistency ---
            const dateConfig = {
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
            };
            
            // Initialize modern date/time picker (due_date now uses calendar box)
            
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
            // --- END COPIED ---

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
                    $('.recurrence-patterns').css('display', 'grid');
                } else {
                    $('#recurringOptions').removeClass('visible');
                    $('.recurrence-patterns').css('display', 'none');
                }
            });

            // On page load, ensure recurringOptions and recurrence-patterns visibility matches selected quest_type
            if ($('input[name="quest_type"]:checked').val() === 'recurring') {
                $('#recurringOptions').addClass('visible');
                $('.recurrence-patterns').css('display', 'grid');
            } else {
                $('#recurringOptions').removeClass('visible');
                $('.recurrence-patterns').css('display', 'none');
            }

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

<!-- Enhanced Skill Selection JavaScript -->
<script>
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

// Enhanced Skill Management System
let selectedSkills = new Set(); // Using Set for skill IDs
let skillTiers = {}; // Store tiers separately: {skillId: tier}
let customSkillCounter = 1000; // Counter for custom skill IDs
const MAX_SKILLS = 5; // Reduced from 8 to 5 for focused mastery (Bundle 2)

// Load existing quest skills on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize selected employees (for edit mode)
    const checkedEmployees = document.querySelectorAll('.employee-checkbox:checked');
    checkedEmployees.forEach(checkbox => {
        handleEmployeeSelection(checkbox);
    });
    
    // Initialize existing due date if present
    const existingDueDate = document.getElementById('due_date').value;
    if (existingDueDate) {
        updateDateDisplay(existingDueDate);
        // Parse existing date for calendar
        selectedDate = existingDueDate.split(' ')[0]; // Get date part
    }
    
    // Load existing skills from PHP
    <?php if (!empty($quest_skills)): ?>
    const existingSkills = <?php echo json_encode($quest_skills); ?>;
    existingSkills.forEach(skill => {
        const skillElement = document.querySelector(`[data-skill-id="${skill.skill_id}"]`);
        if (skillElement) {
            const checkbox = skillElement.querySelector('.skill-checkbox');
            if (checkbox) {
                checkbox.checked = true;
                selectedSkills.add(String(skill.skill_id));
                skillTiers[String(skill.skill_id)] = skill.tier;
                
                // Show tier selector and set value
                const tierSelector = skillElement.querySelector('.skill-tier-selector');
                const tierSelect = tierSelector.querySelector('.tier-select');
                if (tierSelector && tierSelect) {
                    tierSelector.classList.remove('hidden');
                    tierSelect.value = skill.tier;
                }
            }
        }
    });
    <?php endif; ?>
    
    updateSkillDisplay();

    // Add focus efficiency hint
    const target = document.getElementById('selectedSkillsBadges');
    if (target && !document.getElementById('focus-efficiency-hint')) {
        const hint = document.createElement('div');
        hint.id = 'focus-efficiency-hint';
        hint.className = 'mt-2 text-xs text-indigo-600';
        hint.innerHTML = 'Tip: Selecting 1–2 skills = 100% XP each, 3 skills = 90%, 4 = 75%, 5 = 60%. Focus for faster mastery.';
        target.parentElement.insertBefore(hint, target.nextSibling);
    }
});

// Toggle skill selection (for checkboxes)
function toggleSkillSelection(checkbox) {
    const skillItem = checkbox.closest('.skill-item');
    const skillId = checkbox.dataset.skillId;
    const skillName = checkbox.dataset.skillName;
    const categoryName = checkbox.dataset.categoryName;
    const isCustom = checkbox.dataset.isCustom === 'true';
    
    if (checkbox.checked) {
        // Check skill limit
        if (selectedSkills.size >= MAX_SKILLS) {
            checkbox.checked = false;
            alert(`You can only select up to ${MAX_SKILLS} skills per quest (focused mastery). Please deselect a skill first.`);
            return;
        }
        
        // Add skill
        selectedSkills.add(String(skillId));
        skillTiers[String(skillId)] = 2; // Default tier
        
        // Show tier selector
        const tierSelector = skillItem.querySelector('.skill-tier-selector');
        if (tierSelector) {
            tierSelector.classList.remove('hidden');
        }
    } else {
        // Remove skill
        selectedSkills.delete(String(skillId));
        delete skillTiers[String(skillId)];
        
        // Hide tier selector
        const tierSelector = skillItem.querySelector('.skill-tier-selector');
        if (tierSelector) {
            tierSelector.classList.add('hidden');
            const tierSelect = tierSelector.querySelector('.tier-select');
            if (tierSelect) {
                tierSelect.value = 2; // Reset to default
            }
        }
    }
    
    updateSkillDisplay();
}

// Update skill tier when dropdown changes
function updateSkillTier(select) {
    const skillItem = select.closest('.skill-item');
    const checkbox = skillItem.querySelector('.skill-checkbox');
    const skillId = checkbox.dataset.skillId;
    
    skillTiers[String(skillId)] = parseInt(select.value);
    updateSkillDisplay();
}

// Custom Skill Modal Functions
function showCustomSkillModal(categoryId, categoryName) {
    document.getElementById('modalCategoryId').value = categoryId;
    document.getElementById('modalCategoryName').textContent = categoryName;
    document.getElementById('customSkillName').value = '';
    document.getElementById('customSkillTier').value = 2;
    document.getElementById('customSkillModal').classList.remove('hidden');
    document.getElementById('customSkillName').focus();
}

function closeCustomSkillModal() {
    document.getElementById('customSkillModal').classList.add('hidden');
}

function addCustomSkill() {
    const skillName = document.getElementById('customSkillName').value.trim();
    const tier = parseInt(document.getElementById('customSkillTier').value);
    const categoryId = document.getElementById('modalCategoryId').value;
    const categoryName = document.getElementById('modalCategoryName').textContent;
    
    if (!skillName) {
        alert('Please enter a skill name');
        return;
    }
    
    if (selectedSkills.size >= maxSkills) {
        alert(`Maximum ${maxSkills} skills allowed per quest`);
        return;
    }
    
    // Create unique ID for custom skill
    const customSkillId = `custom_${customSkillCounter++}`;
    
    // Add to selected skills
    selectedSkills.add(customSkillId);
    skillTiers[customSkillId] = tier;
    
    // Create skill item in the category
    const customSkillsContainer = document.getElementById(`custom-skills-${categoryId}`);
    const skillItem = document.createElement('label');
    skillItem.className = 'flex items-start p-2 bg-yellow-50 border border-yellow-200 cursor-pointer transition-colors skill-item';
    skillItem.dataset.skillId = customSkillId;
    skillItem.dataset.skillName = skillName;
    skillItem.dataset.category = categoryId;
    skillItem.dataset.categoryName = categoryName;
    
    skillItem.innerHTML = `
        <input type="checkbox" 
               class="skill-checkbox mt-1 h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
               data-skill-id="${customSkillId}"
               data-skill-name="${skillName}"
               data-category-name="${categoryName}"
               data-is-custom="true"
               checked
               onchange="toggleSkillSelection(this)">
        <div class="ml-2 flex-1">
            <div class="text-sm font-medium text-gray-900">
                ${skillName}
                <span class="ml-2 px-2 py-0.5 bg-yellow-400 text-yellow-900 text-xs font-semibold rounded">Custom</span>
            </div>
            <div class="skill-tier-selector mt-1">
                <select class="tier-select text-xs border border-gray-300 rounded px-2 py-1 focus:ring-2 focus:ring-indigo-500 bg-white"
                        onchange="updateSkillTier(this)">
                    <option value="1" ${tier === 1 ? 'selected' : ''}>Tier 1 - Beginner</option>
                    <option value="2" ${tier === 2 ? 'selected' : ''}>Tier 2 - Intermediate</option>
                    <option value="3" ${tier === 3 ? 'selected' : ''}>Tier 3 - Advanced</option>
                    <option value="4" ${tier === 4 ? 'selected' : ''}>Tier 4 - Expert</option>
                    <option value="5" ${tier === 5 ? 'selected' : ''}>Tier 5 - Master</option>
                </select>
            </div>
            <input type="hidden" name="custom_skill_names[]" value="${skillName}">
            <input type="hidden" name="custom_skill_categories[]" value="${categoryId}">
        </div>
    `;
    
    customSkillsContainer.appendChild(skillItem);
    
    // Update display
    updateSkillDisplay();
    
    // Close modal
    closeCustomSkillModal();
}

// Close modal on click outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('customSkillModal');
    if (e.target === modal) {
        closeCustomSkillModal();
    }
});

// Close modal on Enter key in skill name input
document.addEventListener('DOMContentLoaded', function() {
    const skillNameInput = document.getElementById('customSkillName');
    if (skillNameInput) {
        skillNameInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addCustomSkill();
            }
        });
    }
});

// Helper function to get tier name
function getTierName(tier) {
    const tierNames = {
        1: 'Beginner',
        2: 'Intermediate',
        3: 'Advanced',
        4: 'Expert',
        5: 'Master'
    };
    return tierNames[tier] || 'Intermediate';
}

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
    // Build local Date then send ISO (UTC) to server; server will convert to local timezone
    const localDate = new Date(`${selectedDate}T${hour24.toString().padStart(2, '0')}:${minuteStr}:00`);
    document.getElementById('due_date').value = localDate.toISOString();
    updateDateDisplay(localDate.toString());
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

function clearFieldError(input) {
    input.classList.remove('border-red-500', 'bg-red-50');
    const errorMessage = document.getElementById('time-error-message');
    if (errorMessage) {
        errorMessage.remove();
    }
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

function clearDueDate() {
    document.getElementById('due_date').value = '';
    selectedDate = null;
    if (calendarInstance) {
        calendarInstance.clear();
    }
    updateDateDisplay('');
    closeCalendarBox();
}

// Update skill display and counter
function updateSkillDisplay() {
    const skillCount = document.getElementById('skillCount');
    const selectedBadges = document.getElementById('selectedSkillsBadges');
    const questSkillsInput = document.getElementById('questSkillsInput');
    
    skillCount.textContent = selectedSkills.size;
    
    if (selectedSkills.size === 0) {
        selectedBadges.innerHTML = '<span class="text-xs text-blue-600 italic" id="noSkillsMessage">No skills selected yet</span>';
    } else {
        const badges = Array.from(selectedSkills).map(skillId => {
            const skillItem = document.querySelector(`[data-skill-id="${skillId}"]`);
            const skillName = skillItem?.dataset.skillName || 'Unknown Skill';
            const tier = skillTiers[skillId] || 2;
            const isCustom = skillItem?.querySelector('.skill-checkbox')?.dataset.isCustom === 'true';
            
            const tierColors = {
                1: 'bg-gray-100 text-gray-800 border-gray-300',
                2: 'bg-blue-100 text-blue-800 border-blue-300', 
                3: 'bg-green-100 text-green-800 border-green-300',
                4: 'bg-yellow-100 text-yellow-800 border-yellow-300',
                5: 'bg-red-100 text-red-800 border-red-300'
            };
            
            const customBadge = isCustom ? '<span class="ml-1 px-1.5 py-0.5 bg-yellow-400 text-yellow-900 text-xs font-semibold rounded">Custom</span>' : '';
            
            return `<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border ${tierColors[tier]}">
                        ${skillName} 
                        <span class="ml-1 bg-white px-1.5 py-0.5 rounded-full text-xs font-bold">T${tier}</span>
                        ${customBadge}
                        <button type="button" class="ml-2 text-gray-400 hover:text-red-500" onclick="removeSkill('${skillId}')">
                            <i class="fas fa-times text-xs"></i>
                        </button>
                    </span>`;
        }).join('');
        
        selectedBadges.innerHTML = badges;
    }
    
    // Create skills data for submission
    const skillsData = Array.from(selectedSkills).map(skillId => ({
        skill_id: skillId,
        tier: skillTiers[skillId] || 2
    }));
    
    // Update hidden input with selected skills data
    questSkillsInput.value = JSON.stringify(skillsData);
}

// Remove skill from selection
// Unified skill removal: update Sets, tiers, checkbox state, UI & hidden data
function removeSkill(skillId) {
    const idStr = String(skillId);
    if (selectedSkills.has(idStr)) {
        selectedSkills.delete(idStr);
        if (skillTiers[idStr]) delete skillTiers[idStr];
    }

    // Uncheck checkbox if present
    const skillItem = document.querySelector(`[data-skill-id="${idStr}"]`);
    if (skillItem) {
        const checkbox = skillItem.querySelector('.skill-checkbox');
        if (checkbox && checkbox.checked) {
            checkbox.checked = false;
        }
        // Hide tier selector
        const tierSelector = skillItem.querySelector('.skill-tier-selector');
        if (tierSelector) {
            tierSelector.classList.add('hidden');
            const tierSelect = tierSelector.querySelector('.tier-select');
            if (tierSelect) tierSelect.value = 2;
        }
        // Remove custom skill DOM label if it was a custom skill (identified via data-is-custom)
        const checkboxEl = skillItem.querySelector('.skill-checkbox');
        if (checkboxEl && checkboxEl.dataset.isCustom === 'true') {
            skillItem.remove();
        }
    }

    updateSkillDisplay();
}

// Form validation before submit
document.querySelector('form').addEventListener('submit', function(e) {
    if (selectedSkills.size === 0) {
        e.preventDefault();
        alert('Please select at least one skill for this quest.');
        return false;
    }
    
    if (selectedSkills.size > maxSkills) {
        e.preventDefault();
        alert(`Maximum ${maxSkills} skills allowed per quest.`);
        return false;
    }
});
</script>
</body>
</html>