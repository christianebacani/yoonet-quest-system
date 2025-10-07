<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not logged in
if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

// Load user settings from database or cookies
if (!isset($_COOKIE['theme'])) {
    try {
        // Get user settings from database
        $stmt = $pdo->prepare("SELECT us.theme, us.dark_mode, us.font_size 
                              FROM user_settings us
                              JOIN users u ON us.user_id = u.id
                              WHERE u.employee_id = ?");
        $stmt->execute([$_SESSION['employee_id']]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($settings) {
            // Set cookies to persist theme across sessions (1 year expiration)
            setcookie('theme', $settings['theme'], time() + (86400 * 365), "/");
            setcookie('dark_mode', $settings['dark_mode'] ? '1' : '0', time() + (86400 * 365), "/");
            setcookie('font_size', $settings['font_size'], time() + (86400 * 365), "/");
            
            // Also set session for current request
            $_SESSION['theme'] = $settings['theme'];
            $_SESSION['dark_mode'] = (bool)$settings['dark_mode'];
            $_SESSION['font_size'] = $settings['font_size'];
        } else {
            // Set default theme settings in cookies and database
            $default_theme = 'default';
            $default_dark_mode = 0;
            $default_font_size = 'medium';
            
            // Insert default settings into database
            $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, theme, dark_mode, font_size) 
                                 SELECT id, ?, ?, ? FROM users WHERE employee_id = ?");
            $stmt->execute([
                $default_theme,
                $default_dark_mode,
                $default_font_size,
                $_SESSION['employee_id']
            ]);
            
            // Set cookies with long expiration
            setcookie('theme', $default_theme, time() + (86400 * 365), "/");
            setcookie('dark_mode', $default_dark_mode, time() + (86400 * 365), "/");
            setcookie('font_size', $default_font_size, time() + (86400 * 365), "/");
            
            // Set session for current request
            $_SESSION['theme'] = $default_theme;
            $_SESSION['dark_mode'] = (bool)$default_dark_mode;
            $_SESSION['font_size'] = $default_font_size;
        }
    } catch (PDOException $e) {
        error_log("Error loading user settings: " . $e->getMessage());
        // Fallback to default settings in cookies
        $default_theme = 'default';
        $default_dark_mode = 0;
        $default_font_size = 'medium';
        
        setcookie('theme', $default_theme, time() + (86400 * 365), "/");
        setcookie('dark_mode', $default_dark_mode, time() + (86400 * 365), "/");
        setcookie('font_size', $default_font_size, time() + (86400 * 365), "/");
        
        // Set session for current request
        $_SESSION['theme'] = $default_theme;
        $_SESSION['dark_mode'] = (bool)$default_dark_mode;
        $_SESSION['font_size'] = $default_font_size;
    }
} else {
    // If cookies exist but session vars don't, set session vars from cookies
    if (!isset($_SESSION['theme'])) {
        $_SESSION['theme'] = $_COOKIE['theme'];
        $_SESSION['dark_mode'] = $_COOKIE['dark_mode'] === '1';
        $_SESSION['font_size'] = $_COOKIE['font_size'];
    }
}

// Handle theme settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_theme_settings'])) {
    $theme = $_POST['theme'] ?? 'default';
    $dark_mode = isset($_POST['dark_mode']) ? 1 : 0;
    $font_size = $_POST['font_size'] ?? 'medium';
    
    try {
        // Update database
        $stmt = $pdo->prepare("UPDATE user_settings 
                              SET theme = ?, dark_mode = ?, font_size = ?
                              WHERE user_id = (SELECT id FROM users WHERE employee_id = ?)");
        $stmt->execute([
            $theme,
            $dark_mode,
            $font_size,
            $_SESSION['employee_id']
        ]);
        
        // Update session variables
        $_SESSION['theme'] = $theme;
        $_SESSION['dark_mode'] = (bool)$dark_mode;
        $_SESSION['font_size'] = $font_size;
        
        // Update cookies with long expiration (1 year)
        setcookie('theme', $theme, time() + (86400 * 365), "/");
        setcookie('dark_mode', $dark_mode ? '1' : '0', time() + (86400 * 365), "/");
        setcookie('font_size', $font_size, time() + (86400 * 365), "/");
        
        $success = "Theme settings updated successfully!";
    } catch (PDOException $e) {
        error_log("Error updating theme settings: " . $e->getMessage());
        $error = "Error updating theme settings";
    }
}

$full_name = $_SESSION['full_name'] ?? 'User';
// Get both employee_id and user_id
$employee_id = $_SESSION['employee_id'] ?? null;
$user_id = null;

// Validate session data
if (!$employee_id) {
    // Session is invalid, redirect to login
    session_destroy();
    header('Location: login.php?error=session_expired');
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $user = $stmt->fetch();
    if ($user && isset($user['id'])) {
        $user_id = $user['id'];
    } else {
        // User not found in database, redirect to login
        session_destroy();
        header('Location: login.php?error=user_not_found');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error validating user: " . $e->getMessage());
    session_destroy();
    header('Location: login.php?error=database_error');
    exit();
}
$role = $_SESSION['role'] ?? 'quest_taker';
$email = $_SESSION['email'] ?? '';
$current_theme = $_SESSION['theme'] ?? 'default';
$dark_mode = $_SESSION['dark_mode'] ?? false;
$font_size = $_SESSION['font_size'] ?? 'medium';

// Check whether this user's profile is marked completed in the database
$profile_completed = false;
try {
    $stmt = $pdo->prepare("SELECT profile_completed FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $profile_completed = (bool)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching profile_completed: " . $e->getMessage());
    $profile_completed = false;
}

// Set permissions based on role
$is_taker = in_array($role, ['quest_taker', 'hybrid']);
$is_giver = in_array($role, ['quest_giver', 'hybrid']);

// Pagination settings
$items_per_page = 5; // Number of items to show per page
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle accepting a quest (for takers and hybrid)
    if ($is_taker && isset($_POST['quest_id']) && !isset($_POST['submit_quest'])) {
        $quest_id = (int)$_POST['quest_id'];
        try {
            // Check quest assignment status
            $stmt = $pdo->prepare("SELECT status FROM user_quests WHERE employee_id = ? AND quest_id = ?");
            $stmt->execute([$employee_id, $quest_id]);
            $current_status = $stmt->fetchColumn();
            if ($current_status === false) {
                // Not assigned yet, create as assigned (quest giver assigns, taker must accept)
                $stmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at) VALUES (?, ?, 'assigned', NOW())");
                $stmt->execute([$employee_id, $quest_id]);
                $success = "Quest assigned. Please accept to start.";
            } elseif ($current_status === 'assigned') {
                // Update status to in_progress when taker accepts
                $stmt = $pdo->prepare("UPDATE user_quests SET status = 'in_progress', assigned_at = NOW() WHERE employee_id = ? AND quest_id = ?");
                $stmt->execute([$employee_id, $quest_id]);
                $success = "Quest successfully accepted.";
            } elseif (in_array($current_status, ['in_progress', 'submitted', 'completed'])) {
                $error = "You have already accepted this quest.";
            } else {
                $error = "You cannot accept this quest.";
            }
        } catch (PDOException $e) {
            error_log("Database error accepting quest: " . $e->getMessage());
            $error = "Error accepting quest.";
        }
    }
    $error = '';
    $success = '';
    
    // Handle group creation
    if ($is_taker && isset($_POST['create_group'])) {
        $group_name = trim($_POST['group_name'] ?? '');
        $group_description = trim($_POST['group_description'] ?? '');
        
        if (empty($group_name)) {
            $error = "Group name is required";
        } else {
            try {
                // Check if user is already in a group
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE employee_id = ?");
                $stmt->execute([$employee_id]);
                $in_group = $stmt->fetchColumn();
                
                if ($in_group > 0) {
                    $error = "You can only be in one group at a time";
                } else {
                    $pdo->beginTransaction();
                    
                    // Create the group
                    $stmt = $pdo->prepare("INSERT INTO employee_groups 
                                         (group_name, description, created_by) 
                                         VALUES (?, ?, ?)");
                    $stmt->execute([$group_name, $group_description, $employee_id]);
                    $group_id = $pdo->lastInsertId();
                    
                    // Add creator to the group
                    $stmt = $pdo->prepare("INSERT INTO group_members 
                                         (group_id, employee_id) 
                                         VALUES (?, ?)");
                    $stmt->execute([$group_id, $employee_id]);
                    
                    $pdo->commit();
                    $success = "Group created successfully!";
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Database error creating group: " . $e->getMessage());
                $error = "Error creating group";
            }
        }
    }
    
    // Handle joining a group
    if ($is_taker && isset($_POST['join_group'])) {
        $group_id = $_POST['group_id'] ?? 0;
        
        try {
            // Check if user is already in a group
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            $in_group = $stmt->fetchColumn();
            
            if ($in_group > 0) {
                $error = "You can only be in one group at a time";
            } else {
                // Add user to the group
                $stmt = $pdo->prepare("INSERT INTO group_members 
                                     (group_id, employee_id) 
                                     VALUES (?, ?)");
                $stmt->execute([$group_id, $employee_id]);
                
                $success = "You have joined the group successfully!";
            }
        } catch (PDOException $e) {
            error_log("Database error joining group: " . $e->getMessage());
            $error = "Error joining group";
        }
    }
    
    // Handle leaving a group
    if ($is_taker && isset($_POST['leave_group'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM group_members WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            
            // Check if group is now empty and delete it if so
            $stmt = $pdo->prepare("SELECT group_id FROM group_members WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            $group_id = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ?");
            $stmt->execute([$group_id]);
            $member_count = $stmt->fetchColumn();
            
            if ($member_count == 0) {
                $stmt = $pdo->prepare("DELETE FROM employee_groups WHERE id = ?");
                $stmt->execute([$group_id]);
            }
            
            $success = "You have left the group";
        } catch (PDOException $e) {
            error_log("Database error leaving group: " . $e->getMessage());
            $error = "Error leaving group";
        }
    }
    
    // Handle quest submission (for takers)
    if ($is_taker && isset($_POST['submit_quest'])) {
        $quest_id = $_POST['quest_id'] ?? 0;
        $submissionType = $_POST['submission_type'] ?? '';
        
        if ($submissionType === 'file' && isset($_FILES['quest_file'])) {
            // File upload handling
            $uploadDir = 'uploads/quest_submissions/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = basename($_FILES['quest_file']['name']);
            $fileTmp = $_FILES['quest_file']['tmp_name'];
            $fileSize = $_FILES['quest_file']['size'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt', 'zip'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($fileExt, $allowedExtensions)) {
                $error = "Invalid file type. Allowed types: " . implode(', ', $allowedExtensions);
            } elseif ($fileSize > $maxFileSize) {
                $error = "File too large. Max size: 5MB";
            } else {
                $newFileName = $employee_id . '_' . time() . '.' . $fileExt;
                $filePath = $uploadDir . $newFileName;
                
                if (move_uploaded_file($fileTmp, $filePath)) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO quest_submissions 
                                            (employee_id, quest_id, submission_type, file_path, status, submitted_at)
                                            VALUES (?, ?, 'file', ?, 'pending', NOW())");
                        $stmt->execute([$employee_id, $quest_id, $filePath]);
                        
                        // Update quest status to submitted
                        $stmt = $pdo->prepare("UPDATE user_quests SET status = 'submitted' 
                                             WHERE employee_id = ? AND quest_id = ?");
                        $stmt->execute([$employee_id, $quest_id]);
                        
                        // Get XP value from quests table
                        $stmt = $pdo->prepare("SELECT xp FROM quests WHERE id = ?");
                        $stmt->execute([$quest_id]);
                        $quest_xp = $stmt->fetchColumn();
                        if ($quest_xp === false) {
                            $quest_xp = 0;
                        }
                        // Record XP gain for submitting quest
                        $stmt = $pdo->prepare("INSERT INTO xp_history 
                                             (employee_id, xp_change, source_type, source_id, description)
                                             VALUES (?, ?, 'quest_submit', ?, 'Quest submission reward')");
                        $stmt->execute([$employee_id, $quest_xp, $quest_id]);
                        
                        $success = "Quest submitted successfully! +" . $quest_xp . " XP";
                    } catch (PDOException $e) {
                        error_log("Database error submitting quest: " . $e->getMessage());
                        $error = "Error submitting quest";
                        unlink($filePath); // Remove uploaded file if DB insert failed
                    }
                } else {
                    $error = "Error uploading file";
                }
            }
        } elseif ($submissionType === 'link') {
            // Google Drive link handling
            $drive_link = $_POST['drive_link'] ?? '';
            
            if (filter_var($drive_link, FILTER_VALIDATE_URL) === false) {
                $error = "Invalid URL format";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO quest_submissions 
                                          (employee_id, quest_id, submission_type, drive_link, status, submitted_at)
                                          VALUES (?, ?, 'link', ?, 'pending', NOW())");
                    $stmt->execute([$employee_id, $quest_id, $drive_link]);
                    
                    // Update quest status to submitted
                    $stmt = $pdo->prepare("UPDATE user_quests SET status = 'submitted' 
                                         WHERE employee_id = ? AND quest_id = ?");
                    $stmt->execute([$employee_id, $quest_id]);
                    
                    // Get XP value from quests table
                    $stmt = $pdo->prepare("SELECT xp FROM quests WHERE id = ?");
                    $stmt->execute([$quest_id]);
                    $quest_xp = $stmt->fetchColumn();
                    if ($quest_xp === false) {
                        $quest_xp = 0;
                    }
                    // Record XP gain for submitting quest
                    $stmt = $pdo->prepare("INSERT INTO xp_history 
                                         (employee_id, xp_change, source_type, source_id, description)
                                         VALUES (?, ?, 'quest_submit', ?, 'Quest submission reward')");
                    $stmt->execute([$employee_id, $quest_xp, $quest_id]);
                    
                    $success = "Quest submitted successfully! +" . $quest_xp . " XP";
                } catch (PDOException $e) {
                    error_log("Database error submitting quest: " . $e->getMessage());
                    $error = "Error submitting quest";
                }
            }
        }
    }
    
    // Handle quest deletion
if ($is_giver && isset($_POST['delete_quest'])) {
    $quest_id = $_POST['quest_id'] ?? 0;
    
    try {
        $pdo->beginTransaction();
        
        // First delete submissions related to this quest
        $stmt = $pdo->prepare("DELETE FROM quest_submissions WHERE quest_id = ?");
        $stmt->execute([$quest_id]);
        
        // Then delete user quest assignments
        $stmt = $pdo->prepare("DELETE FROM user_quests WHERE quest_id = ?");
        $stmt->execute([$quest_id]);
        
        // Then delete XP history related to this quest
        $stmt = $pdo->prepare("DELETE FROM xp_history WHERE source_type = 'quest_complete' AND source_id = ?");
        $stmt->execute([$quest_id]);
        
        // Finally delete the quest itself
        $stmt = $pdo->prepare("DELETE FROM quests WHERE id = ? AND created_by = ?");
        $stmt->execute([$quest_id, $employee_id]);
        
        $affected_rows = $stmt->rowCount();
        
        if ($affected_rows > 0) {
            $pdo->commit();
            $success = "Quest deleted successfully!";
        } else {
            $pdo->rollBack();
            $error = "Quest not found or you don't have permission to delete it";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error deleting quest: " . $e->getMessage());
        $error = "Error deleting quest";
    }
}
    // Handle quest approval/rejection (for givers)
    if ($is_giver && isset($_POST['review_submission'])) {
        $submission_id = $_POST['submission_id'] ?? 0;
        $action = $_POST['action'] ?? '';
        $feedback = $_POST['feedback'] ?? '';
        $additional_xp = intval($_POST['additional_xp'] ?? 0);
        
        if (in_array($action, ['approve', 'reject'])) {
            try {
                // Update submission status
                $stmt = $pdo->prepare("UPDATE quest_submissions 
                                      SET status = ?, feedback = ?, reviewed_by = ?, reviewed_at = NOW(), additional_xp = ?
                                      WHERE id = ?");
                $new_status = ($action === 'approve') ? 'approved' : 'rejected';
                $stmt->execute([$new_status, $feedback, $employee_id, $additional_xp, $submission_id]);
                
                // If approved, mark quest as completed and award XP
                if ($action === 'approve') {
                    // Get submission details
                    $stmt = $pdo->prepare("SELECT quest_id, employee_id FROM quest_submissions WHERE id = ?");
                    $stmt->execute([$submission_id]);
                    $submission = $stmt->fetch();
                    
                    if ($submission) {
                        // Update user_quests
                        $stmt = $pdo->prepare("UPDATE user_quests 
                                             SET status = 'completed', completed_at = NOW()
                                             WHERE quest_id = ? AND employee_id = ?");
                        $stmt->execute([$submission['quest_id'], $submission['employee_id']]);
                        
                        // Get XP value
                        $stmt = $pdo->prepare("SELECT xp FROM quests WHERE id = ?");
                        $stmt->execute([$submission['quest_id']]);
                        $xp = $stmt->fetchColumn();
                        
                        // Total XP including additional XP from reviewer
                        $total_xp = $xp + $additional_xp;
                        
                        // Record XP gain for quest taker
                        $stmt = $pdo->prepare("INSERT INTO xp_history 
                                             (employee_id, xp_change, source_type, source_id, description)
                                             VALUES (?, ?, 'quest_complete', ?, 'Quest completion reward')");
                        $stmt->execute([$submission['employee_id'], $total_xp, $submission['quest_id']]);
                        
                        // Record XP gain for quest giver (reviewer)
                        $stmt = $pdo->prepare("INSERT INTO xp_history 
                                             (employee_id, xp_change, source_type, source_id, description)
                                             VALUES (?, ?, 'quest_review', ?, 'Quest review reward')");
                        $stmt->execute([$employee_id, 15, $submission_id]);
                    }
                }
                
                $success = "Submission $new_status successfully!";
                if ($action === 'approve') {
                    $success .= " +15 XP for reviewing";
                }
            } catch (PDOException $e) {
                error_log("Database error reviewing submission: " . $e->getMessage());
                $error = "Error processing submission";
            }
        }
    }
}

// Fetch data based on role
$available_quests = [];
$active_quests = [];
$submissions = [];
$pending_submissions = [];
$all_quests = [];
$assigned_quests = [];
$user_group = null;
$available_groups = [];
$pending_submissions = [];

// Pagination settings
$items_per_page = 5; // Number of items to show per page
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;

try {
    // For quest takers
    if ($is_taker) {
        // Get user's current group
        $stmt = $pdo->prepare("SELECT g.id, g.group_name, g.description 
                              FROM employee_groups g
                              JOIN group_members gm ON g.id = gm.group_id
                              WHERE gm.employee_id = ?");
        $stmt->execute([$employee_id]);
        $user_group = $stmt->fetch(PDO::FETCH_ASSOC);
        
    // Always fetch pending submissions for quest creators
    if ($is_giver) {
        $offset = ($current_page - 1) * $items_per_page;
        $stmt = $pdo->prepare("SELECT qs.*, e.full_name as employee_name, q.title as quest_title, 
                              q.xp as base_xp, q.description as quest_description
                              FROM quest_submissions qs
                              JOIN users e ON qs.employee_id = e.id
                              JOIN quests q ON qs.quest_id = q.id
                              WHERE qs.status = 'pending'
                              AND q.created_by = ?
                              ORDER BY qs.submitted_at DESC
                              LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $user_id, PDO::PARAM_INT); // Ensure user_id is int
        $stmt->bindValue(2, $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $pending_submissions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Ensure file path is set for file submissions
            if ($row['submission_type'] === 'file' && empty($row['file_path'])) {
                $row['file_path'] = '';
            }
            // Fallback if employee_name is missing
            if (empty($row['employee_name'])) {
                $row['employee_name'] = 'Unknown User';
            }
            $pending_submissions[] = $row;
        }
        // If no pending submissions, show a message for quest creator
        if (empty($pending_submissions)) {
            $pending_submissions = [];
        }
    }
    // ...existing code...
        if ($user_group) {
            $stmt = $pdo->prepare("SELECT u.employee_id, u.full_name 
                                 FROM group_members gm
                                 JOIN users u ON gm.employee_id = u.employee_id
                                 WHERE gm.group_id = ?
                                 GROUP BY u.employee_id");
            $stmt->execute([$user_group['id']]);
            $group_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        }
        
        // Get available groups to join (excluding user's current group)
        $stmt = $pdo->prepare("SELECT g.id, g.group_name, g.description, 
                              COUNT(gm.employee_id) as member_count
                              FROM employee_groups g
                              LEFT JOIN group_members gm ON g.id = gm.group_id
                              WHERE g.id NOT IN (
                                  SELECT group_id FROM group_members WHERE employee_id = ?
                              )
                              GROUP BY g.id
                              HAVING member_count < 10
                              ORDER BY g.group_name");
        $stmt->execute([$employee_id]);
        $available_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        

    // Get total count of available quests
    // Show quests that are either unassigned or assigned to this user (with status 'assigned')
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests q 
                  LEFT JOIN user_quests uq ON q.id = uq.quest_id AND uq.employee_id = ?
                  WHERE q.status = 'active'
                  AND q.created_by != ?
                  AND (uq.quest_id IS NULL OR (uq.employee_id = ? AND uq.status = 'assigned'))");
    $stmt->execute([$employee_id, $employee_id, $employee_id]);
    $total_available_quests = $stmt->fetchColumn();
    $total_pages_available_quests = ceil($total_available_quests / $items_per_page);

    // Get paginated available quests
    $offset_available = ($current_page - 1) * $items_per_page;
    // Show quests that are either unassigned or assigned to this user with status 'assigned'
    $stmt = $pdo->prepare("SELECT q.*, uq.status as user_status FROM quests q 
                  LEFT JOIN user_quests uq ON q.id = uq.quest_id AND uq.employee_id = ?
                  WHERE q.status = 'active'
                  AND q.created_by != ?
                  AND (uq.quest_id IS NULL OR (uq.employee_id = ? AND uq.status = 'assigned'))
                  ORDER BY q.created_at DESC
                  LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $employee_id);
    $stmt->bindValue(2, $employee_id);
    $stmt->bindValue(3, $employee_id);
    $stmt->bindValue(4, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(5, $offset_available, PDO::PARAM_INT);
    $stmt->execute();
    $available_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        

    // Get total count of active quests
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests q 
                  JOIN user_quests uq ON q.id = uq.quest_id 
                  JOIN users u ON uq.employee_id = u.id
                  WHERE u.employee_id = ? 
                  AND (uq.status = 'in_progress' OR uq.status = 'submitted')");
    $stmt->execute([$employee_id]);
    $total_active_quests = $stmt->fetchColumn();
    $total_pages_active_quests = ceil($total_active_quests / $items_per_page);

    // Get paginated active quests
    $offset_active = ($current_page - 1) * $items_per_page;

    // FIX: Use correct employee_id reference for user_quests
    // Show only quests accepted or submitted by user
    $stmt = $pdo->prepare("SELECT q.*, uq.status as user_status FROM quests q 
                  JOIN user_quests uq ON q.id = uq.quest_id 
                  WHERE uq.employee_id = ? 
                  AND (uq.status = 'in_progress' OR uq.status = 'submitted')
                  ORDER BY q.created_at DESC
                  LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $employee_id);
    $stmt->bindValue(2, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset_active, PDO::PARAM_INT);
    $stmt->execute();
    $active_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get user's submissions with pagination
        // First get total count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quest_submissions qs
                              JOIN quests q ON qs.quest_id = q.id
                              LEFT JOIN users u ON qs.reviewed_by = u.id
                              WHERE qs.employee_id = ?");
        $stmt->execute([$employee_id]);
        $total_submissions = $stmt->fetchColumn();
        $total_pages = ceil($total_submissions / $items_per_page);
        
        // Then get paginated results
        $offset = ($current_page - 1) * $items_per_page;
        $stmt = $pdo->prepare("SELECT qs.*, q.title as quest_title, qs.status as submission_status, 
                              q.xp as quest_xp, qs.additional_xp, u.full_name as reviewer_name
                              FROM quest_submissions qs
                              JOIN quests q ON qs.quest_id = q.id
                              LEFT JOIN users u ON qs.reviewed_by = u.id
                              WHERE qs.employee_id = ?
                              ORDER BY qs.submitted_at DESC
                              LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $employee_id);
        $stmt->bindValue(2, $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // For quest givers
    if ($is_giver) {
        // Get all quests for management (created by this user) with pagination
        // First get total count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests q
                             LEFT JOIN user_quests uq ON q.id = uq.quest_id
                             WHERE q.created_by = ?");
        $stmt->execute([$user_id]);
        $total_quests = $stmt->fetchColumn();
        $total_pages_quests = ceil($total_quests / $items_per_page);
        
        // Then get paginated results
        $offset = ($current_page - 1) * $items_per_page;
        $stmt = $pdo->prepare("SELECT q.*, 
                             (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'approved') as approved_count,
                             (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'pending') as pending_count,
                             (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'rejected') as rejected_count,
                             COUNT(uq.employee_id) as assigned_count 
                             FROM quests q
                             LEFT JOIN user_quests uq ON q.id = uq.quest_id
                             WHERE q.created_by = ?
                             GROUP BY q.id
                             ORDER BY q.status, q.created_at DESC
                             LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $employee_id);
        $stmt->bindValue(2, $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $all_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get pending submissions for quests created by this user with pagination
        // First get total count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quest_submissions qs
                             JOIN users e ON qs.employee_id = e.id
                             JOIN quests q ON qs.quest_id = q.id
                             WHERE qs.status = 'pending'
                             AND q.created_by = ?
        ");
        $stmt->execute([$user_id]);
        $total_pending = $stmt->fetchColumn();
        $total_pages_pending = ceil($total_pending / $items_per_page);
        
        // Then get paginated results
        $offset = ($current_page - 1) * $items_per_page;
        $stmt = $pdo->prepare("SELECT qs.*, e.full_name as employee_name, q.title as quest_title, 
                              q.xp as base_xp, q.description as quest_description
                              FROM quest_submissions qs
                              JOIN users e ON qs.employee_id = e.id
                              JOIN quests q ON qs.quest_id = q.id
                              WHERE qs.status = 'pending'
                              AND q.created_by = ?
                              ORDER BY qs.submitted_at DESC
                              LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $pending_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all submissions for quests created by this giver with pagination
        // First get total count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quest_submissions qs
                             JOIN quests q ON qs.quest_id = q.id
                             JOIN users u ON qs.employee_id = u.id
                             LEFT JOIN users rev ON qs.reviewed_by = rev.id
                             WHERE q.created_by = ?
        ");
        $stmt->execute([$user_id]);
        $total_all_submissions = $stmt->fetchColumn();
        $total_pages_all_submissions = ceil($total_all_submissions / $items_per_page);
        
        // Then get paginated results
        $offset = ($current_page - 1) * $items_per_page;
        $stmt = $pdo->prepare("SELECT qs.*, q.title as quest_title, u.full_name as employee_name, 
                              q.xp as base_xp, qs.additional_xp, rev.full_name as reviewer_name
                              FROM quest_submissions qs
                              JOIN quests q ON qs.quest_id = q.id
                              JOIN users u ON qs.employee_id = u.id
                              LEFT JOIN users rev ON qs.reviewed_by = rev.id
                              WHERE q.created_by = ?
                              ORDER BY qs.submitted_at DESC
                              LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $all_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get quests assigned to this giver (if any)
        $stmt = $pdo->prepare("SELECT q.*, uq.status as user_status 
                              FROM quests q
                              JOIN user_quests uq ON q.id = uq.quest_id
                              JOIN users u ON uq.employee_id = u.id
                              WHERE u.employee_id = ?");
        $stmt->execute([$employee_id]);
        $assigned_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get user stats
    // Get total XP from xp_history
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(xp_change), 0) as total_xp FROM xp_history WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $total_xp = $stmt->fetchColumn();

    // Calculate level and rank
    $level = floor($total_xp / 50) + 1;
    $rank = ($total_xp >= 200 ? 'Expert' : ($total_xp >= 100 ? 'Adventurer' : ($total_xp >= 50 ? 'Explorer' : 'Newbie')));

    // Quests completed
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_quests WHERE employee_id = ? AND status = 'completed'");
    $stmt->execute([$employee_id]);
    $completed_quests = $stmt->fetchColumn();

    // Quests created
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests WHERE created_by = ?");
    $stmt->execute([$employee_id]);
    $created_quests = $stmt->fetchColumn();

    // Submissions reviewed
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quest_submissions WHERE reviewed_by = ?");
    $stmt->execute([$employee_id]);
    $reviewed_submissions = $stmt->fetchColumn();

    // Stats array for template
    $stats = [
        'total_xp' => $total_xp,
        'completed_quests' => $completed_quests,
        'created_quests' => $created_quests,
        'reviewed_submissions' => $reviewed_submissions
    ];
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    // Initialize default values on error
    $stats = [
        'total_xp' => 0,
        'completed_quests' => 0,
        'created_quests' => 0,
        'reviewed_submissions' => 0
    ];
    $level = 1;
    $rank = 'Newbie';
    $available_quests = [];
    $active_quests = [];
    $submissions = [];
    $pending_submissions = [];
    $all_quests = [];
    $assigned_quests = [];
    $user_group = null;
    $available_groups = [];
}

// Role-based styling
$role_badge_class = [
    'quest_taker' => 'bg-green-100 text-green-800',
    'quest_giver' => 'bg-blue-100 text-blue-800',
    'hybrid' => 'bg-purple-100 text-purple-800'
][$role] ?? 'bg-gray-100 text-gray-800';

$role_icon_color = [
    'quest_taker' => 'text-green-500',
    'quest_giver' => 'text-blue-500',
    'hybrid' => 'text-purple-500'
][$role] ?? 'text-gray-500';

// Function to get the body class based on theme
function getBodyClass() {
    $theme = 'default';
    $dark_mode = false;
    
    // Always use cookies for theme selection (persists after logout)
    if (isset($_COOKIE['theme'])) {
        $theme = $_COOKIE['theme'];
    }
    if (isset($_COOKIE['dark_mode'])) {
        $dark_mode = $_COOKIE['dark_mode'] === '1';
    }
    
    $classes = [];
    
    if ($dark_mode) {
        $classes[] = 'dark-mode';
    }
    
    if ($theme !== 'default') {
        $classes[] = $theme . '-theme';
    }
    
    return implode(' ', $classes);
}

// Function to get font size CSS
function getFontSize() {
    // Always use cookies for font size (persists after logout)
    if (isset($_COOKIE['font_size'])) {
        $font_size = $_COOKIE['font_size'];
    }
    else {
        $font_size = 'medium';
    }
    
    switch ($font_size) {
        case 'small': return '14px';
        case 'large': return '18px';
        default: return '16px';
    }
}

// Replace the existing generatePagination function with this updated version
function generatePagination($total_pages, $current_page, $base_url = '') {
    // Only show pagination if there are multiple pages OR if there's at least one item
    if ($total_pages <= 1 && $current_page == 1) {
        return '';
    }
    
    $pagination = '<div class="flex justify-center mt-4">';
    $pagination .= '<nav class="inline-flex rounded-md shadow">';
    
    // Previous button
    if ($current_page > 1) {
        $pagination .= '<a href="' . $base_url . '?page=' . ($current_page - 1) . '" class="px-3 py-1 rounded-l-md border border-gray-300 bg-white text-gray-500 hover:bg-gray-50">Previous</a>';
    } else {
        $pagination .= '<span class="px-3 py-1 rounded-l-md border border-gray-300 bg-gray-100 text-gray-400">Previous</span>';
    }
    
    // Page numbers
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $pagination .= '<a href="' . $base_url . '?page=1" class="px-3 py-1 border-t border-b border-gray-300 bg-white text-gray-500 hover:bg-gray-50">1</a>';
        if ($start_page > 2) {
            $pagination .= '<span class="px-3 py-1 border-t border-b border-gray-300 bg-white text-gray-500">...</span>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $pagination .= '<span class="px-3 py-1 border-t border-b border-gray-300 bg-blue-500 text-white">' . $i . '</span>';
        } else {
            $pagination .= '<a href="' . $base_url . '?page=' . $i . '" class="px-3 py-1 border-t border-b border-gray-300 bg-white text-gray-500 hover:bg-gray-50">' . $i . '</a>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $pagination .= '<span class="px-3 py-1 border-t border-b border-gray-300 bg-white text-gray-500">...</span>';
        }
        $pagination .= '<a href="' . $base_url . '?page=' . $total_pages . '" class="px-3 py-1 border-t border-b border-gray-300 bg-white text-gray-500 hover:bg-gray-50">' . $total_pages . '</a>';
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $pagination .= '<a href="' . $base_url . '?page=' . ($current_page + 1) . '" class="px-3 py-1 rounded-r-md border border-gray-300 bg-white text-gray-500 hover:bg-gray-50">Next</a>';
    } else {
        $pagination .= '<span class="px-3 py-1 rounded-r-md border border-gray-300 bg-gray-100 text-gray-400">Next</span>';
    }
    
    $pagination .= '</nav>';
    $pagination .= '</div>';
    
    return $pagination;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yoonet - Quest Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism.min.css" rel="stylesheet" />
    <style>
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

        .section-header {
            position: relative;
            padding-left: 1.5rem;
        }
        .section-header:before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            border-radius: 2px;
        }
        .taker-section:before {
            background-color: #10b981;
        }
        .giver-section:before {
            background-color: #3b82f6;
        }
        .hybrid-section:before {
            background: linear-gradient(to bottom, #3b82f6, #10b981);
        }
        .file-preview {
            max-width: 100%;
            max-height: 200px;
            object-fit: contain;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            margin-top: 0.5rem;
        }
        .file-item {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            margin-top: 0.5rem;
        }
        .file-icon {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        .tab-button {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .tab-button.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .hidden {
            display: none;
        }
        .role-icon {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.5rem;
        }
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        .status-approved {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-rejected {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .status-in_progress {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .status-submitted {
            background-color: #f0f9ff;
            color: #0369a1;
        }
        .preview-container {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            padding: 1rem;
            margin-top: 0.5rem;
            background-color: var(--card-bg);
        }
        .xp-badge {
            background-color: #f0f9ff;
            color: #0369a1;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .xp-details {
            font-size: 0.75rem;
            color: #6b7280;
        }
        .group-member {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        .group-member-avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background-color: var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.5rem;
            font-weight: bold;
            color: #4b5563;
        }
        .hamburger {
            display: flex;
            flex-direction: column;
            justify-content: space-around;
            width: 2rem;
            height: 2rem;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0;
            z-index: 10;
            margin-right: 1rem;
        }
        .hamburger div {
            width: 2rem;
            height: 0.25rem;
            background: var(--text-color);
            border-radius: 10px;
            transition: all 0.3s linear;
            position: relative;
            transform-origin: 1px;
        }
        .mobile-menu {
            position: fixed;
            top: 0;
            left: -300px;
            width: 300px;
            height: 100%;
            background: var(--background-color);
            z-index: 1000;
            transition: left 0.3s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            padding: 20px;
            overflow-y: auto;
        }

         .mobile-menu.open {
            left: 0;
        }
        #backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 900;
            display: none;
        }
        .close-menu {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            z-index: 1010;
            color: var(--text-color);
        }
        .mobile-menu nav {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 3rem;
        }
        .mobile-menu nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            background-color: var(--card-bg);
            transition: background-color 0.2s;
            color: var(--text-color);
        }
        .mobile-menu nav a:hover {
            background-color: var(--primary-color);
            color: white;
        }
        .mobile-menu nav a svg {
            margin-right: 0.75rem;
            width: 1.5rem;
            height: 1.5rem;
        }
        @media (max-width: 768px) {
            .mobile-menu {
                width: 80%;
            }
            .header-actions {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-end;
            }
        }

        /* Animation Classes */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideDown {
            from { 
                opacity: 0;
                transform: translateY(-20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInRight {
            from { 
                opacity: 0;
                transform: translateX(20px);
            }
            to { 
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes slideInLeft {
            from { 
                opacity: 0;
                transform: translateX(-20px);
            }
            to { 
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Apply animations */
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        .animate-slide-down {
            animation: slideDown 0.5s ease-out forwards;
        }
        
        .animate-slide-up {
            animation: slideUp 0.5s ease-out forwards;
        }
        
        .animate-slide-right {
            animation: slideInRight 0.5s ease-out forwards;
        }
        
        .animate-slide-left {
            animation: slideInLeft 0.5s ease-out forwards;
        }
        
        /* Interactive elements */
        .interactive-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .interactive-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .interactive-button {
            transition: all 0.2s ease;
        }
        
        .interactive-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .interactive-button:active {
            transform: translateY(1px);
        }
        
        /* Notification animations */
        .notification {
            animation: slideUp 0.3s ease-out forwards;
        }
        
        .notification-exit {
            animation: fadeIn 0.3s ease-out forwards;
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
        .animate-spin {
            animation: spin 1s linear infinite;
        }
        
        /* Mobile menu animations */
        .mobile-menu {
            transition: transform 0.3s ease;
        }
        
        .mobile-menu.open {
            transform: translateX(0);
        }
        
        /* File preview modal */
        .modal-enter {
            animation: fadeIn 0.3s ease-out forwards;
        }
        
        /* Section animations with delays */
        .section-animation {
            opacity: 0;
            transform: translateY(20px);
            animation: slideUp 0.5s ease-out forwards;
        }
        
        .section-animation:nth-child(1) { animation-delay: 0.1s; }
        .section-animation:nth-child(2) { animation-delay: 0.2s; }
        .section-animation:nth-child(3) { animation-delay: 0.3s; }
        .section-animation:nth-child(4) { animation-delay: 0.4s; }
        .section-animation:nth-child(5) { animation-delay: 0.5s; }
        .section-animation:nth-child(6) { animation-delay: 0.6s; }
        
        /* Success animation */
        .animate-success {
            animation: bounce 0.5s ease, pulse 1s ease 0.5s;
        }
        
        /* Pagination styles */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 1rem;
        }
        
        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            margin: 0 0.25rem;
            border: 1px solid var(--border-color);
            border-radius: 0.25rem;
            text-decoration: none;
            color: var(--text-color);
            background-color: var(--card-bg);
            transition: all 0.2s ease;
        }
        
        .pagination a:hover {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination .current {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination .disabled {
            color: #6b7280;
            background-color: #f3f4f6;
            cursor: not-allowed;
        }
    </style>
</head>

<body class="<?php echo getBodyClass(); ?>" style="font-size: <?php echo getFontSize(); ?>;">
    <div class="max-w-6xl mx-auto px-4 py-2">
        <!-- Header -->
        <header class="flex flex-col sm:flex-row justify-between items-center py-4 border-b animate-slide-down" style="border-color: var(--border-color);">
            <div class="flex items-center gap-3">
                <!-- Hamburger Menu Button -->
                <button class="hamburger" id="hamburger">
                    <div class="line1"></div>
                    <div class="line2"></div>
                    <div class="line3"></div>
                </button>
                
                <img src="assets/images/yoonet-logo.jpg" alt="Yoonet Logo" class="h-10 w-auto">
                <div>
                    <h1 class="text-xl font-bold">Quest Dashboard</h1>
                    <span class="text-xs px-2 py-1 rounded-full <?php echo $role_badge_class; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $role)); ?>
                    </span>
                </div>
                <?php if ($role === 'hybrid' || $role === 'quest_giver'): ?>
                <!-- Removed unnecessary Create New Account button -->
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-4 mt-3 sm:mt-0">
                <span class="hidden sm:block">Welcome, <?php echo htmlspecialchars($full_name); ?></span>
                <?php if ($is_giver): ?>
                    <a href="create_quest.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center transition-colors interactive-button">
                        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Create Quest
                    </a>
                <?php endif; ?>
            </div>
        </header>
        
        <!-- Mobile Menu -->
        <div class="mobile-menu" id="mobileMenu">
            <button class="close-menu" id="closeMenu">✕</button>
            <nav>
                <a href="landing.php" class="flex items-center">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    Home
                </a>
                <a href="leaderboard.php" class="flex items-center">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A.75.75 0 003 5.48v10.018a.75.75 0 00.784.713 45.455 45.455 0 012.07-.352M19.5 4.236c.982.143 1.954.317 2.916.52A.75.75 0 0021 5.48v10.018a.75.75 0 00-.784.713 45.456 45.456 0 01-2.07-.352"></path></svg>
                    Leaderboard
                </a>
                <a href="profile_view.php" class="flex items-center">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    View profile
                </a>
                <?php if ($role === 'hybrid' || $role === 'quest_giver'): ?>
                <a href="create_account.php" class="flex items-center">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    Create New Account
                </a>
                <?php endif; ?>
                <a href="settings.php" class="flex items-center">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    Settings
                </a>
                <a href="logout.php" class="flex items-center">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    Logout
                </a>
            </nav>
        </div>
        <div id="backdrop" class="fixed inset-0 bg-black bg-opacity-50 z-900 hidden"></div>
        
        <!-- Main Content -->
        <main class="py-6">
            <!-- Welcome message for new users -->
            <?php if (isset($_GET['welcome']) && $_GET['welcome'] == '1'): ?>
                <div class="bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-lg shadow-lg p-6 mb-6 animate-slide-down">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="h-8 w-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-semibold">🎉 Welcome to YooNet Quest System!</h3>
                            <p class="mt-1 text-blue-100">
                                Congratulations! Your profile is now complete. You're ready to start your quest journey and showcase your skills!
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="bg-white bg-opacity-20 text-white text-sm px-3 py-1 rounded-full">
                                    ✓ Profile Complete
                                </span>
                                <span class="bg-white bg-opacity-20 text-white text-sm px-3 py-1 rounded-full">
                                    ✓ Skills Added
                                </span>
                                <span class="bg-white bg-opacity-20 text-white text-sm px-3 py-1 rounded-full">
                                    ✓ Ready for Quests
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Display messages -->
            <?php if (isset($error) && $error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 notification" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($success) && $success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4 notification" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <!-- User Info Card -->
            <div class="bg-white rounded-lg shadow-sm p-4 mb-6 flex flex-wrap gap-4 items-center section-animation" style="background-color: var(--card-bg);">
                <div>
                    <h2 class="font-medium text-gray-500">Employee ID</h2>
                    <p class="font-semibold"><?php echo htmlspecialchars($employee_id); ?></p>
                </div>
                <div>
                    <h2 class="font-medium text-gray-500">Email</h2>
                    <p class="font-semibold"><?php echo htmlspecialchars($email); ?></p>
                </div>
                <div>
                    <h2 class="font-medium text-gray-500">Permissions</h2>
                    <p class="font-semibold">
                        <?php 
                        $permissions = [];
                        if ($is_giver) $permissions[] = "Create Quests";
                        if ($is_taker) $permissions[] = "Take Quests";
                        echo implode(", ", $permissions) ?: "Basic Access";
                        ?>
                    </p>
                </div>
            </div>

            <!-- Group Management Section (for takers) -->
            <?php if ($is_taker): ?>
                <div class="bg-white rounded-lg shadow-sm p-4 mb-6 section-animation" style="background-color: var(--card-bg);">
                    <div class="section-header flex items-center mb-4">
                        <svg class="role-icon text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <h2 class="text-lg font-bold">Group Management</h2>
                    </div>
                    
                    <?php if ($user_group): ?>
                        <!-- Current Group Info -->
                        <div class="mb-6">
                            <h3 class="font-medium mb-2">Your Group: <?php echo htmlspecialchars($user_group['group_name']); ?></h3>
                            <p class="text-sm text-gray-600 mb-3"><?php echo htmlspecialchars($user_group['description']); ?></p>
                            
                            <h4 class="font-medium mb-2">Group Members</h4>
                            <div class="space-y-2">
                                <?php foreach ($group_members as $member): ?>
                                    <div class="group-member interactive-card">
                                        <div class="group-member-avatar">
                                            <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <p class="font-medium"><?php echo htmlspecialchars($member['full_name']); ?></p>
                                            <p class="text-xs text-gray-500">ID: <?php echo htmlspecialchars($member['employee_id']); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <form method="post" class="mt-4">
                                <input type="hidden" name="leave_group" value="1">
                                <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded text-sm font-medium interactive-button">
                                    Leave Group
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <!-- Group Creation Form -->
                        <div class="mb-6">
                            <h3 class="font-medium mb-2">Create a New Group</h3>
                            <form method="post" class="space-y-3">
                                <div>
                                    <label for="group_name" class="block text-sm font-medium text-gray-700 mb-1">Group Name*</label>
                                    <input type="text" id="group_name" name="group_name" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="Enter group name" required>
                                </div>
                                <div>
                                    <label for="group_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                    <textarea id="group_description" name="group_description" rows="2"
                                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                              placeholder="Optional group description"></textarea>
                                </div>
                                <button type="submit" name="create_group" class="bg-indigo-500 hover:indigo-600 text-white px-4 py-2 rounded text-sm font-medium interactive-button">
                                    Create Group
                                </button>
                            </form>
                        </div>
                        
                        <!-- Available Groups to Join -->
                        <?php if (!empty($available_groups)): ?>
                            <div>
                                <h3 class="font-medium mb-2">Available Groups to Join</h3>
                                <div class="space-y-3">
                                    <?php foreach ($available_groups as $group): ?>
                                        <div class="border rounded p-3 interactive-card" style="border-color: var(--border-color);">
                                            <div class="flex justify-between">
                                                <h4 class="font-medium"><?php echo htmlspecialchars($group['group_name']); ?></h4>
                                                <span class="text-xs bg-gray-100 text-gray-800 px-2 py-1 rounded">
                                                    <?php echo $group['member_count']; ?> members
                                                </span>
                                            </div>
                                            <?php if (!empty($group['description'])): ?>
                                                <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($group['description']); ?></p>
                                            <?php endif; ?>
                                            <form method="post" class="mt-2">
                                                <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                <input type="hidden" name="join_group" value="1">
                                                <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm interactive-button">
                                                    Join Group
                                                </button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500">No groups available to join at the moment.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Role-Based Content Grid -->
            <div class="grid gap-6 <?php echo ($is_giver && $is_taker) ? 'md:grid-cols-2' : 'grid-cols-1'; ?>">

                <!-- Quest Taker Section (if permitted) -->
                <?php if ($is_taker): ?>
                    <div class="bg-white rounded-lg shadow-sm p-4 taker-section section-animation" style="background-color: var(--card-bg);">
                        <div class="section-header flex items-center mb-4">
                            <svg class="role-icon text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                            <h2 class="text-lg font-bold">Quest Taker</h2>
                        </div>
                        
                        <!-- Available Quests -->
                        <div class="mb-6">
                            <h3 class="font-medium mb-3">Available Quests</h3>
                            <?php if (!empty($available_quests)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($available_quests as $quest): ?>
                                        <div class="border rounded p-3 interactive-card" style="border-color: var(--border-color);">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <h4 class="font-medium"><?php echo htmlspecialchars($quest['title']); ?></h4>
                                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($quest['description']); ?></p>
                                                </div>
                                                <span class="xp-badge">+<?php echo $quest['xp']; ?> XP</span>
                                            </div>
                                            <div class="mt-2 flex justify-between items-center">
                                                <form method="post" name="accept_quest_form">
                                                    <input type="hidden" name="quest_id" value="<?php echo $quest['id']; ?>">
                                                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm interactive-button">
                                                        Accept Quest
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <!-- Pagination for available quests -->
                                <?php if ($total_available_quests > $items_per_page): ?>
                                    <div class="mt-4">
                                        <?php echo generatePagination($total_pages_available_quests, $current_page); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-gray-500">No quests available at the moment.</p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Active Quests -->
                        <div class="mb-6">
                            <h3 class="font-medium mb-3">Your Active Quests</h3>
                            <?php if (!empty($active_quests)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($active_quests as $quest): ?>
                                        <div class="border rounded p-3 interactive-card" style="border-color: var(--border-color);">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <h4 class="font-medium"><?php echo htmlspecialchars($quest['title']); ?></h4>
                                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($quest['description']); ?></p>
                                                </div>
                                                <span class="status-badge status-<?php echo $quest['user_status']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $quest['user_status'])); ?>
                                                </span>
                                            </div>
                                            <div class="mt-2">
                                                <span class="xp-badge">+<?php echo $quest['xp']; ?> XP</span>
                                            </div>
                                            <?php if ($quest['user_status'] === 'in_progress'): ?>
                                                <div class="mt-3">
                                                    <h4 class="font-medium text-sm mb-1">Submit Quest</h4>
                                                    <form method="post" enctype="multipart/form-data">
                                                        <input type="hidden" name="quest_id" value="<?php echo $quest['id']; ?>">
                                                        <div class="flex gap-2 mb-2">
                                                            <div class="flex items-center">
                                                                <input type="radio" id="file_<?php echo $quest['id']; ?>" name="submission_type" value="file" checked class="mr-1">
                                                                <label for="file_<?php echo $quest['id']; ?>" class="text-sm">Upload File</label>
                                                            </div>
                                                            <div class="flex items-center">
                                                                <input type="radio" id="link_<?php echo $quest['id']; ?>" name="submission_type" value="link" class="mr-1">
                                                                <label for="link_<?php echo $quest['id']; ?>" class="text-sm">Google Drive Link</label>
                                                            </div>
                                                        </div>
                                                        <!-- File Upload -->
                                                        <div id="file-section_<?php echo $quest['id']; ?>">
                                                            <input type="file" name="quest_file" class="text-sm">
                                                            <p class="text-xs text-gray-500 mt-1">Allowed: PDF, DOC, JPG, PNG, TXT, ZIP (Max 5MB)</p>
                                                        </div>
                                                        <!-- Drive Link -->
                                                        <div id="link-section_<?php echo $quest['id']; ?>" class="hidden mt-2">
                                                            <input type="text" name="drive_link" placeholder="https://drive.google.com/..." 
                                                                   class="w-full px-3 py-1 border border-gray-300 rounded text-sm">
                                                        </div>
                                                        <button type="submit" name="submit_quest" class="mt-2 bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm interactive-button">
                                                            Submit Quest
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <!-- Pagination for active quests -->
                                <?php if ($total_active_quests > $items_per_page): ?>
                                    <div class="mt-4">
                                        <?php echo generatePagination($total_pages_active_quests, $current_page); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-gray-500">You don't have any active quests.</p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Submission History -->
                        <div>
                            <h3 class="font-medium mb-3">Submission History</h3>
                            <?php if (!empty($submissions)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($submissions as $submission): ?>
                                        <div class="border rounded p-3 interactive-card" style="border-color: var(--border-color);">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <h4 class="font-medium"><?php echo htmlspecialchars($submission['quest_title']); ?></h4>
                                                    <p class="text-sm text-gray-600">
                                                        Submitted: <?php echo date('M d, Y H:i', strtotime($submission['submitted_at'])); ?>
                                                    </p>
                                                </div>
                                                <span class="status-badge status-<?php echo $submission['submission_status']; ?>">
                                                    <?php echo ucfirst($submission['submission_status']); ?>
                                                </span>
                                            </div>
                                            
                                            <?php if ($submission['submission_type'] === 'file'): ?>
                                                <div class="mt-2">
                                                    <p class="text-sm">File: 
                                                        <a href="<?php echo htmlspecialchars($submission['file_path']); ?>" 
                                                           target="_blank" class="text-blue-500 hover:underline">
                                                            <?php echo basename($submission['file_path']); ?>
                                                        </a>
                                                        <button class="view-file ml-2 text-sm text-blue-500 hover:underline"
                                                                data-file="<?php echo htmlspecialchars($submission['file_path']); ?>">
                                                            Preview
                                                        </button>
                                                    </p>
                                                </div>
                                            <?php else: ?>
                                                <div class="mt-2">
                                                    <p class="text-sm">Drive Link: 
                                                        <a href="<?php echo htmlspecialchars($submission['drive_link']); ?>" 
                                                           target="_blank" class="text-blue-500 hover:underline">
                                                            View Submission
                                                        </a>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($submission['submission_status'] === 'approved' || $submission['submission_status'] === 'rejected'): ?>
                                                <div class="mt-2 p-2 bg-gray-50 rounded">
                                                    <p class="text-sm font-medium">Reviewer: <?php echo htmlspecialchars($submission['reviewer_name'] ?? 'Admin'); ?></p>
                                                    <p class="text-sm">
                                                        <span class="font-medium">Feedback:</span> 
                                                        <?php echo htmlspecialchars($submission['feedback'] ?? 'No feedback provided'); ?>
                                                    </p>
                                                    <p class="text-sm mt-1">
                                                        <span class="font-medium">XP Earned:</span> 
                                                        <?php echo $submission['quest_xp'] + ($submission['additional_xp'] ?? 0); ?> 
                                                        (Base: <?php echo $submission['quest_xp']; ?> + Bonus: <?php echo $submission['additional_xp'] ?? 0; ?>)
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Pagination for submissions -->
                                <?php if ($total_submissions > $items_per_page): ?>
                                    <div class="mt-4">
                                        <?php echo generatePagination($total_pages, $current_page); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-gray-500">No submission history.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                
                <!-- Quest Giver Section (if permitted) -->
                <?php if ($is_giver): ?>
                    <div class="bg-white rounded-lg shadow-sm p-4 giver-section section-animation" style="background-color: var(--card-bg);">
                        <div class="section-header flex items-center mb-4">
                            <svg class="role-icon text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            <h2 class="text-lg font-bold">Quest Giver</h2>
                        </div>
                        
                        <!-- Pending Submissions -->
                        <div class="mb-6">
                            <h3 class="font-medium mb-3">Pending Submissions</h3>
                            <?php if (!empty($pending_submissions)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($pending_submissions as $submission): ?>
                                        <div class="border rounded p-3 interactive-card" style="border-color: var(--border-color); background: #ffeede;">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <h4 class="font-medium" style="font-size:1.2em;">task <?php echo htmlspecialchars($submission['quest_title']); ?></h4>
                                                    <p class="text-sm"><span class="font-medium">Submitted by:</span> <?php echo htmlspecialchars($submission['employee_name']); ?></p>
                                                    <p class="text-sm text-gray-600">Submitted: <?php echo date('M d, Y H:i', strtotime($submission['submitted_at'])); ?></p>
                                                </div>
                                                <span class="status-badge status-pending" style="background:#fff7c2;color:#b59f00;">Pending</span>
                                            </div>
                                            <?php if ($submission['submission_type'] === 'file' && !empty($submission['file_path'])): ?>
                                                <div class="mt-2">
                                                    <p class="text-sm">File: <a href="<?php echo htmlspecialchars($submission['file_path']); ?>" target="_blank" class="text-blue-500 hover:underline"><?php echo basename($submission['file_path']); ?></a> <a href="<?php echo htmlspecialchars($submission['file_path']); ?>" target="_blank" class="text-blue-500 hover:underline ml-2">Preview</a></p>
                                                </div>
                                            <?php elseif ($submission['submission_type'] === 'link' && !empty($submission['drive_link'])): ?>
                                                <div class="mt-2">
                                                    <p class="text-sm">Drive Link: <a href="<?php echo htmlspecialchars($submission['drive_link']); ?>" target="_blank" class="text-blue-500 hover:underline">View Submission</a></p>
                                                </div>
                                            <?php endif; ?>
                                            <div class="mt-3">
                                                <h4 class="font-medium text-sm mb-1">Quest Details</h4>
                                                <p class="text-sm"><?php echo htmlspecialchars($submission['quest_description']); ?></p>
                                                <p class="text-sm mt-1"><span class="font-medium">XP Reward:</span> <?php echo $submission['base_xp']; ?> XP</p>
                                            </div>
                                            <form method="post" class="mt-3">
                                                <input type="hidden" name="submission_id" value="<?php echo $submission['id']; ?>">
                                                <input type="hidden" name="review_submission" value="1">
                                                <div class="mb-2">
                                                    <label for="feedback_<?php echo $submission['id']; ?>" class="block text-sm font-medium mb-1">Feedback</label>
                                                    <textarea id="feedback_<?php echo $submission['id']; ?>" name="feedback" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Provide feedback for the submission"></textarea>
                                                </div>
                                                <div class="mb-2">
                                                    <label for="additional_xp_<?php echo $submission['id']; ?>" class="block text-sm font-medium mb-1">Bonus XP (Optional)</label>
                                                    <input type="number" id="additional_xp_<?php echo $submission['id']; ?>" name="additional_xp" min="0" max="20" class="w-20 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="0">
                                                </div>
                                                <div class="flex gap-2">
                                                    <button type="submit" name="action" value="approve" class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm interactive-button">Approve (+<?php echo $submission['base_xp']; ?> XP)</button>
                                                    <button type="submit" name="action" value="reject" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm interactive-button">Reject</button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Pagination for pending submissions -->
                                <?php if ($total_pending > $items_per_page): ?>
                                    <div class="mt-4">
                                        <?php echo generatePagination($total_pages_pending, $current_page); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-gray-500">No pending submissions to review.</p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Your Created Quests -->
                        <div class="mb-6">
                            <div class="flex justify-between items-center mb-3">
                                <h3 class="font-medium">Your Created Quests</h3>
                                <a href="create_quest.php" class="bg-indigo-500 hover:bg-indigo-600 text-white px-3 py-1 rounded text-sm interactive-button">
                                    + Create New Quest
                                </a>
                            </div>
                            
                            <?php if (!empty($all_quests)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($all_quests as $quest): ?>
                                        <div class="border rounded p-3 interactive-card" style="border-color: var(--border-color);">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <h4 class="font-medium"><?php echo htmlspecialchars($quest['title']); ?></h4>
                                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($quest['description']); ?></p>
                                                </div>
                                                <span class="status-badge status-<?php echo $quest['status']; ?>">
                                                    <?php echo ucfirst($quest['status']); ?>
                                                </span>
                                            </div>
                                            <div class="mt-2 flex justify-between items-center">
                                                <span class="xp-badge">+<?php echo $quest['xp']; ?> XP</span>
                                                <span class="text-xs bg-gray-100 text-gray-800 px-2 py-1 rounded">
                                                    <?php echo $quest['assigned_count']; ?> submissions
                                                </span>
                                            </div>
                                            
                                            <!-- Quest Management Buttons -->
                                            <div class="mt-3 flex gap-2">
                                                <a href="edit_quest.php?id=<?php echo $quest['id']; ?>" 
                                                   class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded text-sm interactive-button">
                                                    Edit
                                                </a>
                                               <button type="button" onclick="showDeleteModal(<?php echo $quest['id']; ?>)" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm interactive-button">
                                                Delete
                                                </button>
                                                <a href="view_submissions.php?quest_id=<?php echo $quest['id']; ?>" 
                                                   class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm interactive-button">
                                                    View Submissions
                                                </a>
                                            </div>
                                            
                                            <!-- Quick Stats -->
                                            <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                                                <div class="bg-green-100 text-green-800 p-1 rounded">
                                                    <div class="font-medium"><?php echo $quest['approved_count'] ?? 0; ?></div>
                                                    <div>Approved</div>
                                                </div>
                                                <div class="bg-yellow-100 text-yellow-800 p-1 rounded">
                                                    <div class="font-medium"><?php echo $quest['pending_count'] ?? 0; ?></div>
                                                    <div>Pending</div>
                                                </div>
                                                <div class="bg-red-100 text-red-800 p-1 rounded">
                                                    <div class="font-medium"><?php echo $quest['rejected_count'] ?? 0; ?></div>
                                                    <div>Rejected</div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Pagination for quests -->
                                <?php if ($total_quests > $items_per_page): ?>
                                    <div class="mt-4">
                                        <?php echo generatePagination($total_pages_quests, $current_page); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-gray-500">You haven't created any quests yet.</p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Recent Submissions -->
                        <div>
                            <h3 class="font-medium mb-3">Recent Submissions</h3>
                            <?php if (!empty($all_submissions)): ?>
                                <div class="space-y-3">
                                    <?php foreach ($all_submissions as $submission): ?>
                                        <div class="border rounded p-3 interactive-card" style="border-color: var(--border-color);">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <h4 class="font-medium"><?php echo htmlspecialchars($submission['quest_title']); ?></h4>
                                                    <p class="text-sm">
                                                        <span class="font-medium">Employee:</span> 
                                                        <?php echo htmlspecialchars($submission['employee_name']); ?>
                                                    </p>
                                                    <p class="text-sm text-gray-600">
                                                        Submitted: <?php echo date('M d, Y H:i', strtotime($submission['submitted_at'])); ?>
                                                    </p>
                                                </div>
                                                <span class="status-badge status-<?php echo $submission['status']; ?>">
                                                    <?php echo ucfirst($submission['status']); ?>
                                                </span>
                                            </div>
                                            
                                            <?php if ($submission['submission_type'] === 'file'): ?>
                                                <div class="mt-2">
                                                    <p class="text-sm">File: 
                                                        <a href="<?php echo htmlspecialchars($submission['file_path']); ?>" 
                                                           target="_blank" class="text-blue-500 hover:underline">
                                                            <?php echo basename($submission['file_path']); ?>
                                                        </a>
                                                        <button class="view-file ml-2 text-sm text-blue-500 hover:underline"
                                                                data-file="<?php echo htmlspecialchars($submission['file_path']); ?>">
                                                            Preview
                                                        </button>
                                                    </p>
                                                </div>
                                            <?php else: ?>
                                                <div class="mt-2">
                                                    <p class="text-sm">Drive Link: 
                                                        <a href="<?php echo htmlspecialchars($submission['drive_link']); ?>" 
                                                           target="_blank" class="text-blue-500 hover:underline">
                                                            View Submission
                                                        </a>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($submission['status'] === 'approved' || $submission['status'] === 'rejected'): ?>
                                                <div class="mt-2 p-2 bg-gray-50 rounded">
                                                    <p class="text-sm font-medium">Reviewed by: <?php echo htmlspecialchars($submission['reviewer_name'] ?? 'You'); ?></p>
                                                    <p class="text-sm">
                                                        <span class="font-medium">Feedback:</span> 
                                                        <?php echo htmlspecialchars($submission['feedback'] ?? 'No feedback provided'); ?>
                                                    </p>
                                                    <p class="text-sm mt-1">
                                                        <span class="font-medium">XP Awarded:</span> 
                                                        <?php echo $submission['base_xp'] + ($submission['additional_xp'] ?? 0); ?> 
                                                        (Base: <?php echo $submission['base_xp']; ?> + Bonus: <?php echo $submission['additional_xp'] ?? 0; ?>)
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Pagination for all submissions -->
                                <?php if ($total_all_submissions > $items_per_page): ?>
                                    <div class="mt-4">
                                        <?php echo generatePagination($total_pages_all_submissions, $current_page); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mt-3 text-center">
                                    <a href="view_all_submissions.php" class="text-blue-500 hover:underline text-sm">
                                        View All Submissions →
                                    </a>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-500">No submissions yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- User Stats -->
            <div class="bg-white rounded-lg shadow-sm p-4 mt-6 section-animation" style="background-color: var(--card-bg);">
                <h2 class="text-lg font-bold mb-4">Your Stats</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="border rounded p-3 text-center interactive-card" style="border-color: var(--border-color);">
                        <h3 class="text-sm font-medium text-gray-500">Level</h3>
                        <p class="text-3xl font-bold"><?php echo $level; ?></p>
                        <p class="text-xs text-gray-500"><?php echo $rank; ?></p>
                    </div>
                    <div class="border rounded p-3 text-center interactive-card" style="border-color: var(--border-color);">
                        <h3 class="text-sm font-medium text-gray-500">Total XP</h3>
                        <p class="text-3xl font-bold"><?php echo $stats['total_xp'] ?? 0; ?></p>
                        <p class="text-xs text-gray-500"><?php echo ($stats['total_xp'] ?? 0) % 50; ?>/50 to next level</p>
                    </div>
                    <?php if ($is_taker): ?>
                        <div class="border rounded p-3 text-center interactive-card" style="border-color: var(--border-color);">
                            <h3 class="text-sm font-medium text-gray-500">Quests Completed</h3>
                            <p class="text-3xl font-bold"><?php echo $stats['completed_quests'] ?? 0; ?></p>
                            <p class="text-xs text-gray-500"><?php echo count($active_quests); ?> in progress</p>
                        </div>
                    <?php endif; ?>
                    <?php if ($is_giver): ?>
                        <div class="border rounded p-3 text-center interactive-card" style="border-color: var(--border-color);">
                            <h3 class="text-sm font-medium text-gray-500">Quests Created</h3>
                            <p class="text-3xl font-bold"><?php echo $stats['created_quests'] ?? 0; ?></p>
                            <p class="text-xs text-gray-500"><?php echo $stats['reviewed_submissions'] ?? 0; ?> reviewed</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- File Preview Modal -->
    <div id="fileModal" class="fixed inset-0 z-50 hidden overflow-auto bg-black bg-opacity-50">
        <div class="relative w-full max-w-4xl mx-auto my-8 bg-white rounded-lg shadow-xl" style="background-color: var(--card-bg);">
            <div class="flex justify-between items-center p-4 border-b" style="border-color: var(--border-color);">
                <h3 class="text-lg font-semibold">File Preview</h3>
                <button id="closeModal" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-4">
                <div id="filePreviewContent" class="flex justify-center items-center min-h-[400px]">
                    <p>Loading preview...</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden overflow-auto bg-black bg-opacity-50">
        <div class="relative w-full max-w-md mx-auto my-8 bg-white rounded-lg shadow-xl" style="background-color: var(--card-bg);">
            <div class="flex justify-between items-center p-4 border-b" style="border-color: var(--border-color);">
                <h3 class="text-lg font-semibold">Confirm Deletion</h3>
                <button id="closeDeleteModal" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-4">
                <p class="mb-4">Are you sure you want to delete this quest? This action cannot be undone.</p>
                <div class="flex justify-end gap-3">
                    <button id="cancelDelete" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Cancel
                    </button>
                    <form id="deleteForm" method="post" class="inline">
                        <input type="hidden" name="quest_id" id="deleteQuestId" value="">
                        <button type="submit" name="delete_quest" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                            Delete Quest
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.min.js"></script>
    <script>
        // Set PDF.js worker path
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js';

        // Mobile menu toggle
        const hamburger = document.getElementById('hamburger');
        const mobileMenu = document.getElementById('mobileMenu');
        const backdrop = document.getElementById('backdrop');
        const closeMenu = document.getElementById('closeMenu');

        hamburger.addEventListener('click', () => {
            mobileMenu.classList.add('open');
            backdrop.style.display = 'block';
            document.body.style.overflow = 'hidden';
        });

        closeMenu.addEventListener('click', () => {
            mobileMenu.classList.remove('open');
            backdrop.style.display = 'none';
            document.body.style.overflow = '';
        });

        backdrop.addEventListener('click', () => {
            mobileMenu.classList.remove('open');
            backdrop.style.display = 'none';
            document.body.style.overflow = '';
        });

        // File preview modal
        const fileModal = document.getElementById('fileModal');
        const closeModal = document.getElementById('closeModal');
        const filePreviewContent = document.getElementById('filePreviewContent');
        const viewFileButtons = document.querySelectorAll('.view-file');

        closeModal.addEventListener('click', () => {
            fileModal.classList.add('hidden');
        });

        viewFileButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                const filePath = e.target.getAttribute('data-file');
                const fileExt = filePath.split('.').pop().toLowerCase();
                
                filePreviewContent.innerHTML = '<p>Loading preview...</p>';
                fileModal.classList.remove('hidden');
                
                // Display based on file type
                if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExt)) {
                    filePreviewContent.innerHTML = `<img src="${filePath}" alt="Preview" class="max-w-full max-h-[80vh]">`;
                } else if (fileExt === 'pdf') {
                    // Load PDF using PDF.js
                    pdfjsLib.getDocument(filePath).promise.then(pdf => {
                        pdf.getPage(1).then(page => {
                            const viewport = page.getViewport({ scale: 1.0 });
                            const canvas = document.createElement('canvas');
                            const context = canvas.getContext('2d');
                            canvas.height = viewport.height;
                            canvas.width = viewport.width;
                            
                            filePreviewContent.innerHTML = '';
                            filePreviewContent.appendChild(canvas);
                            
                            page.render({
                                canvasContext: context,
                                viewport: viewport
                            });
                        });
                    }).catch(err => {
                        filePreviewContent.innerHTML = `<p>Could not load PDF preview. <a href="${filePath}" target="_blank" class="text-blue-500">Download file</a></p>`;
                    });
                } else if (['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'].includes(fileExt)) {
                    filePreviewContent.innerHTML = `<p>Office files can't be previewed. <a href="${filePath}" target="_blank" class="text-blue-500">Download file</a></p>`;
                } else if (fileExt === 'txt') {
                    fetch(filePath)
                        .then(response => response.text())
                        .then(text => {
                            filePreviewContent.innerHTML = `<pre class="whitespace-pre-wrap bg-gray-100 p-4 rounded">${text}</pre>`;
                        })
                        .catch(() => {
                            filePreviewContent.innerHTML = `<p>Could not load text file. <a href="${filePath}" target="_blank" class="text-blue-500">Download file</a></p>`;
                        });
                } else {
                    filePreviewContent.innerHTML = `<p>No preview available. <a href="${filePath}" target="_blank" class="text-blue-500">Download file</a></p>`;
                }
            });
        });

        // Toggle between file and link submission
        document.querySelectorAll('input[type="radio"][name="submission_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const questId = this.id.split('_')[1];
                const fileSection = document.getElementById(`file-section_${questId}`);
                const linkSection = document.getElementById(`link-section_${questId}`);
                
                if (this.value === 'file') {
                    fileSection.classList.remove('hidden');
                    linkSection.classList.add('hidden');
                } else {
                    fileSection.classList.add('hidden');
                    linkSection.classList.remove('hidden');
                }
            });
        });

        // Theme settings form submission
        document.getElementById('themeSettingsForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                if (response.ok) {
                    window.location.reload();
                }
            });
        });

        // Animation for theme change
        document.body.addEventListener('click', function(e) {
            if (e.target.matches('[data-theme]') || e.target.closest('[data-theme]')) {
                document.body.classList.add('theme-change');
                setTimeout(() => {
                    document.body.classList.remove('theme-change');
                }, 400);
            }
        });

        // Handle quest acceptance
        document.querySelectorAll('form[name="accept_quest_form"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                
                fetch('accept_quest.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message and reload the page
                        alert('Quest accepted successfully!');
                        window.location.reload();
                    } else {
                        alert(data.error || 'Error accepting quest');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while accepting the quest');
                });
            });
        });
         // Delete Confirmation Modal
        const deleteModal = document.getElementById('deleteModal');
        const closeDeleteModal = document.getElementById('closeDeleteModal');
        const cancelDelete = document.getElementById('cancelDelete');
        const deleteForm = document.getElementById('deleteForm');
        const deleteQuestId = document.getElementById('deleteQuestId');

        function showDeleteModal(questId) {
            deleteQuestId.value = questId;
            deleteModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function hideDeleteModal() {
            deleteModal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        closeDeleteModal.addEventListener('click', hideDeleteModal);
        cancelDelete.addEventListener('click', hideDeleteModal);

        // Close modal when clicking outside
        deleteModal.addEventListener('click', (e) => {
            if (e.target === deleteModal) {
                hideDeleteModal();
            }
        });

        // Handle form submission
        deleteForm.addEventListener('submit', function(e) {
            // The form will submit normally since it's a POST form
            hideDeleteModal();
            
        });
    </script>
</body>
</html>