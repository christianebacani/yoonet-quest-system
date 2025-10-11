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
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $user = $stmt->fetch();
    if ($user && isset($user['id'])) {
        $user_id = $user['id'];
        
        // Refresh role in session if it has changed in database
        if (isset($user['role']) && $user['role'] !== $_SESSION['role']) {
            $_SESSION['role'] = $user['role'];
        }
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
$role = $_SESSION['role'] ?? 'skill_associate';

// Simple role renaming (keeping all functionality the same)
if ($role === 'quest_taker') {
    $role = 'skill_associate';
} elseif ($role === 'hybrid') {
    $role = 'quest_lead';
} elseif ($role === 'quest_giver') {
    $role = 'quest_lead'; // Quest givers become quest leads
} elseif ($role === 'participant') {
    $role = 'skill_associate'; // Update old participant to skill_associate
} elseif ($role === 'contributor') {
    $role = 'quest_lead'; // Update old contributor to quest_lead
} elseif ($role === 'learning_architect') {
    // Normalize newer role name to internal quest_lead for consistent permission checks
    $role = 'quest_lead';
}
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

// Redirect to profile setup if profile is not completed
if (!$profile_completed) {
    header('Location: profile_setup.php');
    exit();
}

// Set permissions based on role (keeping original logic)
$is_taker = in_array($role, ['skill_associate', 'quest_lead']); // skill_associate + quest_lead
$is_giver = in_array($role, ['quest_lead']); // quest_lead only

// Pagination settings - Separate for each section to avoid conflicts
$items_per_page = 8; // Increased from 5 to 8 for better content density

// Separate pagination parameters for each quest section
$available_page = isset($_GET['available_page']) ? (int)$_GET['available_page'] : 1;
$active_page = isset($_GET['active_page']) ? (int)$_GET['active_page'] : 1;
$created_page = isset($_GET['created_page']) ? (int)$_GET['created_page'] : 1;

// Ensure all page numbers are valid
if ($available_page < 1) $available_page = 1;
if ($active_page < 1) $active_page = 1;
if ($created_page < 1) $created_page = 1;

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle accepting/declining a quest (for skill_associates and quest_leads)
    if ($is_taker && isset($_POST['quest_id']) && !isset($_POST['submit_quest'])) {
        $quest_id = (int)($_POST['quest_id'] ?? 0);
        $quest_action = $_POST['quest_action'] ?? 'accept';
        try {
            // Current assignment record (if any)
            $stmt = $pdo->prepare("SELECT uq.status, q.quest_assignment_type 
                                   FROM user_quests uq 
                                   JOIN quests q ON uq.quest_id = q.id 
                                   WHERE uq.employee_id = ? AND uq.quest_id = ?");
            $stmt->execute([$employee_id, $quest_id]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_status = $assignment && isset($assignment['status'])
                ? strtolower(trim($assignment['status']))
                : false;
            $assignment_type = $assignment['quest_assignment_type'] ?? null;

            $can_proceed = true;
            if ($assignment === false) {
                $stmt = $pdo->prepare("SELECT quest_assignment_type FROM quests WHERE id = ? AND status = 'active'");
                $stmt->execute([$quest_id]);
                $assignment_type = $stmt->fetchColumn();
                if ($assignment_type === false) {
                    $error = "This quest is no longer available.";
                    $can_proceed = false;
                }
            }

            $assignment_type_normalized = strtolower(trim((string)$assignment_type));
            $is_mandatory = in_array($assignment_type_normalized, ['mandatory', 'required'], true);

            if ($can_proceed) {
                if ($quest_action === 'decline') {
                    if ($current_status === 'assigned' && !$is_mandatory) {
                        $stmt = $pdo->prepare("DELETE FROM user_quests WHERE employee_id = ? AND quest_id = ?");
                        $stmt->execute([$employee_id, $quest_id]);
                        $success = "Quest declined. You can pick it up again later from Available Quests.";
                    } elseif ($is_mandatory) {
                        $error = "Mandatory quests can't be declined.";
                    } else {
                        $error = "There is no pending assignment to decline.";
                    }
                } else { // accept action
                    if ($current_status === false) {
                        // Accepting a public optional quest
                        $stmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at, started_at) VALUES (?, ?, 'in_progress', NOW(), NOW())");
                        $stmt->execute([$employee_id, $quest_id]);
                        $success = "Quest accepted! It's now in your active list.";
                    } elseif ($current_status === 'assigned') {
                        $stmt = $pdo->prepare("UPDATE user_quests SET status = 'in_progress', started_at = COALESCE(started_at, NOW()), assigned_at = COALESCE(assigned_at, NOW()) WHERE employee_id = ? AND quest_id = ?");
                        $stmt->execute([$employee_id, $quest_id]);
                        $success = "Quest successfully accepted.";
                    } elseif (in_array($current_status, ['in_progress', 'submitted', 'completed'], true)) {
                        $error = "You have already accepted this quest.";
                    } else {
                        $error = "You cannot accept this quest.";
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Database error processing quest action: " . $e->getMessage());
            $error = "We're unable to update this quest right now.";
        }
    }
    
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
        $quest_id = isset($_POST['quest_id']) ? (int)$_POST['quest_id'] : 0;
        $submissionType = $_POST['submission_type'] ?? '';

        if ($quest_id <= 0) {
            $error = "Invalid quest selection. Please refresh and try again.";
        } elseif ($submissionType === 'file' && isset($_FILES['quest_file'])) {
            $fileData = $_FILES['quest_file'];
            $uploadError = $fileData['error'] ?? UPLOAD_ERR_NO_FILE;

            if ($uploadError === UPLOAD_ERR_NO_FILE) {
                $error = "Please choose a file to upload.";
            } elseif ($uploadError !== UPLOAD_ERR_OK) {
                $uploadErrorMessages = [
                    UPLOAD_ERR_INI_SIZE => "The uploaded file exceeds the server's size limit.",
                    UPLOAD_ERR_FORM_SIZE => "The uploaded file exceeds the form size limit.",
                    UPLOAD_ERR_PARTIAL => "The file was only partially uploaded. Please try again.",
                    UPLOAD_ERR_NO_TMP_DIR => "Temporary folder is missing on the server.",
                    UPLOAD_ERR_CANT_WRITE => "Failed to write the uploaded file to disk.",
                    UPLOAD_ERR_EXTENSION => "A PHP extension stopped the file upload."
                ];
                $error = $uploadErrorMessages[$uploadError] ?? "File upload failed. Please try again.";
            } else {
                $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt', 'zip'];
                $maxFileSize = 5 * 1024 * 1024; // 5MB

                $fileName = basename($fileData['name'] ?? '');
                $fileTmp = $fileData['tmp_name'] ?? '';
                $fileSize = $fileData['size'] ?? 0;
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if (!in_array($fileExt, $allowedExtensions, true)) {
                    $error = "Invalid file type. Allowed types: " . implode(', ', $allowedExtensions);
                } elseif ($fileSize > $maxFileSize) {
                    $error = "File too large. Max size: 5MB";
                } else {
                    $uploadDirAbsolute = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'quest_submissions' . DIRECTORY_SEPARATOR;
                    if (!is_dir($uploadDirAbsolute) && !mkdir($uploadDirAbsolute, 0777, true) && !is_dir($uploadDirAbsolute)) {
                        $error = "Unable to create upload directory.";
                    } else {
                        $newFileName = $employee_id . '_' . time() . '.' . $fileExt;
                        $absoluteFilePath = $uploadDirAbsolute . $newFileName;
                        $relativeFilePath = 'uploads/quest_submissions/' . $newFileName;

                        if (move_uploaded_file($fileTmp, $absoluteFilePath)) {
                            $quest_xp = 0;
                            $isResubmission = false;
                            $previousFileAbsolute = null;
                            $startedTransaction = false;

                            try {
                                if (!$pdo->inTransaction()) {
                                    $pdo->beginTransaction();
                                    $startedTransaction = true;
                                }

                                $stmt = $pdo->prepare("SELECT file_path FROM quest_submissions WHERE employee_id = ? AND quest_id = ? ORDER BY submitted_at DESC LIMIT 1");
                                $stmt->execute([$employee_id, $quest_id]);
                                $existingSubmission = $stmt->fetch(PDO::FETCH_ASSOC);

                                if ($existingSubmission && !empty($existingSubmission['file_path'])) {
                                    $isResubmission = true;
                                    $existingRelativePath = ltrim($existingSubmission['file_path'], '/\\');
                                    $previousFileAbsolute = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $existingRelativePath);
                                }

                                $stmt = $pdo->prepare("DELETE FROM quest_submissions WHERE employee_id = ? AND quest_id = ?");
                                $stmt->execute([$employee_id, $quest_id]);

                                static $questSubmissionColumnsCache = null;
                                $questSubmissionColumns = [];
                                try {
                                    if ($questSubmissionColumnsCache === null) {
                                        $schemaStmt = $pdo->query("SHOW COLUMNS FROM quest_submissions");
                                        $questSubmissionColumnsCache = $schemaStmt->fetchAll(PDO::FETCH_COLUMN);
                                    }
                                    $questSubmissionColumns = $questSubmissionColumnsCache;
                                } catch (PDOException $schemaException) {
                                    error_log("Unable to inspect quest_submissions schema: " . $schemaException->getMessage());
                                    $questSubmissionColumns = [];
                                }

                                $insertColumns = ['employee_id', 'quest_id'];
                                $placeholders = ['?', '?'];
                                $params = [$employee_id, $quest_id];

                                if (in_array('submission_type', $questSubmissionColumns, true)) {
                                    $insertColumns[] = 'submission_type';
                                    $placeholders[] = '?';
                                    $params[] = 'file';
                                }

                                if (in_array('file_path', $questSubmissionColumns, true)) {
                                    $insertColumns[] = 'file_path';
                                    $placeholders[] = '?';
                                    $params[] = $relativeFilePath;
                                } elseif (in_array('drive_link', $questSubmissionColumns, true)) {
                                    $insertColumns[] = 'drive_link';
                                    $placeholders[] = '?';
                                    $params[] = $relativeFilePath;
                                } elseif (in_array('text_content', $questSubmissionColumns, true)) {
                                    $insertColumns[] = 'text_content';
                                    $placeholders[] = '?';
                                    $params[] = $relativeFilePath;
                                } else {
                                    throw new RuntimeException('quest_submissions table lacks a column to store file submissions.');
                                }

                                if (in_array('status', $questSubmissionColumns, true)) {
                                    $insertColumns[] = 'status';
                                    $placeholders[] = '?';
                                    $params[] = 'pending';
                                }

                                if (in_array('submitted_at', $questSubmissionColumns, true)) {
                                    $insertColumns[] = 'submitted_at';
                                    $placeholders[] = 'NOW()';
                                }

                                $insertSql = sprintf(
                                    "INSERT INTO quest_submissions (%s) VALUES (%s)",
                                    implode(', ', $insertColumns),
                                    implode(', ', $placeholders)
                                );
                                $stmt = $pdo->prepare($insertSql);
                                $stmt->execute($params);

                                $stmt = $pdo->prepare("UPDATE user_quests SET status = 'submitted' WHERE employee_id = ? AND quest_id = ?");
                                $stmt->execute([$employee_id, $quest_id]);

                                if ($startedTransaction && $pdo->inTransaction()) {
                                    $pdo->commit();
                                }

                                try {
                                    $stmt = $pdo->prepare("SELECT xp FROM quests WHERE id = ?");
                                    $stmt->execute([$quest_id]);
                                    $quest_xp = $stmt->fetchColumn();
                                } catch (PDOException $xpFetchException) {
                                    error_log("XP lookup failed for quest {$quest_id}: " . $xpFetchException->getMessage());
                                    $quest_xp = 0;
                                }

                                if ($quest_xp === false) {
                                    $quest_xp = 0;
                                }

                                if ($quest_xp > 0 && !$isResubmission) {
                                    try {
                                        $stmt = $pdo->prepare("INSERT INTO xp_history 
                                                             (employee_id, xp_change, source_type, source_id, description)
                                                             VALUES (?, ?, 'quest_submit', ?, 'Quest submission reward')");
                                        $stmt->execute([$employee_id, $quest_xp, $quest_id]);
                                    } catch (PDOException $xpException) {
                                        error_log("XP history logging failed for employee {$employee_id} on quest {$quest_id}: " . $xpException->getMessage());
                                    }
                                }

                                $success = $isResubmission ? "Quest resubmitted successfully!" : "Quest submitted successfully!";
                                if ($quest_xp > 0 && !$isResubmission) {
                                    $success .= " +" . $quest_xp . " XP";
                                }

                                if ($previousFileAbsolute && is_file($previousFileAbsolute)) {
                                    @unlink($previousFileAbsolute);
                                }
                            } catch (Exception $e) {
                                if ($startedTransaction && $pdo->inTransaction()) {
                                    $pdo->rollBack();
                                }
                                error_log("Database error submitting quest for employee {$employee_id} on quest {$quest_id}: " . $e->getMessage());
                                $error = "Error submitting quest: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                                if (file_exists($absoluteFilePath)) {
                                    unlink($absoluteFilePath);
                                }
                            }
                        } else {
                            $error = "Error uploading file";
                        }
                    }
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
                    // Record XP gain for submitting quest (log but don't fail submission if XP logging has an issue)
                    try {
                        $stmt = $pdo->prepare("INSERT INTO xp_history 
                                             (employee_id, xp_change, source_type, source_id, description)
                                             VALUES (?, ?, 'quest_submit', ?, 'Quest submission reward')");
                        $stmt->execute([$employee_id, $quest_xp, $quest_id]);
                    } catch (PDOException $xpException) {
                        error_log("XP history logging failed for employee {$employee_id} on quest {$quest_id}: " . $xpException->getMessage());
                    }

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
        
    // Finally delete the quest itself (support both employee_id and legacy user_id stored values)
    $stmt = $pdo->prepare("DELETE FROM quests WHERE id = ? AND (created_by = ? OR created_by = ?)");
    $stmt->execute([$quest_id, $employee_id, (string)$user_id]);
        
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
$assigned_pending_quests = [];
$user_group = null;
$available_groups = [];
$pending_submissions = [];

// Initialize all quest counting variables to prevent undefined variable warnings (moved outside try block)
$total_available_quests = 0;
$total_pages_available_quests = 0;
$total_active_quests = 0;
$total_pages_active_quests = 0;
$total_quests = 0;
$total_pages_quests = 0;
$available_quests = [];
$active_quests = [];
$all_quests = [];
$submissions = [];
$total_submissions = 0;
$total_pages = 0;
$group_members = [];

// Pagination settings - Consistent with main settings
$items_per_page = 8; // Increased from 5 to 8 for better content density
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
    }
    
    // Always fetch pending submissions for quest creators
    if ($is_giver) {
        $offset = ($current_page - 1) * $items_per_page;
    $stmt = $pdo->prepare("SELECT qs.*, e.full_name as employee_name, q.title as quest_title, 
                  q.xp as base_xp, q.description as quest_description
                  FROM quest_submissions qs
                  JOIN users e ON qs.employee_id = e.employee_id
                  JOIN quests q ON qs.quest_id = q.id
                  WHERE qs.status = 'pending'
                  AND (q.created_by = ? OR q.created_by = ?)
                  ORDER BY qs.submitted_at DESC
                  LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $employee_id); // current user's employee_id
    $stmt->bindValue(2, (string)$user_id); // also support legacy created_by stored as user id
    $stmt->bindValue(3, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
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
    
    // Get quest counts for all users (moved outside conditional blocks)
    // Get total count of available quests
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests q 
                  LEFT JOIN user_quests uq ON q.id = uq.quest_id AND uq.employee_id = ?
                  WHERE q.status = 'active'
                  AND q.created_by NOT IN (?, ?)
                  AND uq.quest_id IS NULL");
    $stmt->execute([$employee_id, $employee_id, (string)$user_id]);
    $total_available_quests = $stmt->fetchColumn();
    $total_pages_available_quests = ceil($total_available_quests / $items_per_page);

    // Ensure available_page doesn't exceed total pages
    if ($available_page > $total_pages_available_quests && $total_pages_available_quests > 0) {
        $available_page = $total_pages_available_quests;
    }

    // Get paginated available quests
    $offset_available = ($available_page - 1) * $items_per_page;
    $stmt = $pdo->prepare("SELECT q.*, uq.status as user_status FROM quests q 
                  LEFT JOIN user_quests uq ON q.id = uq.quest_id AND uq.employee_id = ?
                  WHERE q.status = 'active'
                  AND q.created_by NOT IN (?, ?)
                  AND uq.quest_id IS NULL
                  ORDER BY q.created_at DESC
                  LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $employee_id);
    $stmt->bindValue(2, $employee_id);
    $stmt->bindValue(3, (string)$user_id);
    $stmt->bindValue(4, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(5, $offset_available, PDO::PARAM_INT);
    $stmt->execute();
    $available_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggregate quests already attached to this user and categorize them
    $stmt = $pdo->prepare("SELECT q.*, uq.status as user_status, 
                    uq.assigned_at AS user_assigned_at,
                    uq.started_at AS user_started_at,
                    uq.completed_at AS user_completed_at 
                  FROM user_quests uq
                  JOIN quests q ON q.id = uq.quest_id
                  WHERE uq.employee_id = ?
                  AND q.status = 'active'
                  ORDER BY 
                    CASE 
                        WHEN uq.status = 'assigned' THEN 0
                        WHEN uq.status = 'in_progress' THEN 1
                        WHEN uq.status = 'submitted' THEN 2
                        ELSE 3
                    END,
                    COALESCE(uq.assigned_at, q.created_at) DESC");
    $stmt->execute([$employee_id]);
    $user_quest_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assigned_pending_quests = [];
    $active_quest_rows = [];
    foreach ($user_quest_rows as $quest_row) {
        $status = strtolower(trim($quest_row['user_status'] ?? ''));
        if ($status === 'assigned') {
            $assigned_pending_quests[] = $quest_row;
        } elseif (in_array($status, ['in_progress', 'submitted'], true)) {
            $active_quest_rows[] = $quest_row;
        }
    }

    $total_active_quests = count($active_quest_rows);
    $total_pages_active_quests = $total_active_quests > 0 ? ceil($total_active_quests / $items_per_page) : 0;

    if ($active_page > $total_pages_active_quests && $total_pages_active_quests > 0) {
        $active_page = $total_pages_active_quests;
    }

    $offset_active = ($active_page - 1) * $items_per_page;
    $active_quests = array_slice($active_quest_rows, $offset_active, $items_per_page);
    
    // Additional quest taker logic
    if ($is_taker) {
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
        
        // Get user's submissions with pagination
        // First get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quest_submissions qs
                  JOIN quests q ON qs.quest_id = q.id
                  LEFT JOIN users u ON qs.reviewed_by = u.employee_id
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
                  LEFT JOIN users u ON qs.reviewed_by = u.employee_id
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
                 WHERE q.created_by = ? OR q.created_by = ?");
    $stmt->execute([$employee_id, (string)$user_id]);
        $total_quests = $stmt->fetchColumn();
        $total_pages_quests = ceil($total_quests / $items_per_page);
        
        // Ensure created_page doesn't exceed total pages
        if ($created_page > $total_pages_quests && $total_pages_quests > 0) {
            $created_page = $total_pages_quests;
        }
        
        // Then get paginated results
        $offset = ($created_page - 1) * $items_per_page;
    $stmt = $pdo->prepare("SELECT q.*, 
                             (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'approved') as approved_count,
                             (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'pending') as pending_count,
                             (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'rejected') as rejected_count,
                             (SELECT COUNT(*) FROM user_quests WHERE quest_id = q.id) as assigned_count 
                             FROM quests q
                 WHERE q.created_by = ? OR q.created_by = ?
                             ORDER BY q.status, q.created_at DESC
                             LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $employee_id);
    $stmt->bindValue(2, (string)$user_id);
    $stmt->bindValue(3, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $all_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get pending submissions for quests created by this user with pagination
        // First get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quest_submissions qs
                 JOIN users e ON qs.employee_id = e.employee_id
                 JOIN quests q ON qs.quest_id = q.id
                 WHERE qs.status = 'pending'
                 AND (q.created_by = ? OR q.created_by = ?)
    ");
    $stmt->execute([$employee_id, (string)$user_id]);
        $total_pending = $stmt->fetchColumn();
        $total_pages_pending = ceil($total_pending / $items_per_page);
        
        // Then get paginated results
        $offset = ($current_page - 1) * $items_per_page;
    $stmt = $pdo->prepare("SELECT qs.*, e.full_name as employee_name, q.title as quest_title, 
                  q.xp as base_xp, q.description as quest_description
                  FROM quest_submissions qs
                  JOIN users e ON qs.employee_id = e.employee_id
                  JOIN quests q ON qs.quest_id = q.id
                  WHERE qs.status = 'pending'
                  AND (q.created_by = ? OR q.created_by = ?)
                  ORDER BY qs.submitted_at DESC
                  LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $employee_id);
    $stmt->bindValue(2, (string)$user_id);
    $stmt->bindValue(3, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $pending_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all submissions for quests created by this giver with pagination
        // First get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quest_submissions qs
                 JOIN quests q ON qs.quest_id = q.id
                 JOIN users u ON qs.employee_id = u.employee_id
                 LEFT JOIN users rev ON qs.reviewed_by = rev.employee_id
                 WHERE (q.created_by = ? OR q.created_by = ?)
    ");
    $stmt->execute([$employee_id, (string)$user_id]);
        $total_all_submissions = $stmt->fetchColumn();
        $total_pages_all_submissions = ceil($total_all_submissions / $items_per_page);
        
        // Then get paginated results
        $offset = ($current_page - 1) * $items_per_page;
    $stmt = $pdo->prepare("SELECT qs.*, q.title as quest_title, u.full_name as employee_name, 
                  q.xp as base_xp, qs.additional_xp, rev.full_name as reviewer_name
                  FROM quest_submissions qs
                  JOIN quests q ON qs.quest_id = q.id
                  JOIN users u ON qs.employee_id = u.employee_id
                  LEFT JOIN users rev ON qs.reviewed_by = rev.employee_id
                  WHERE (q.created_by = ? OR q.created_by = ?)
                  ORDER BY qs.submitted_at DESC
                  LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $employee_id);
    $stmt->bindValue(2, (string)$user_id);
    $stmt->bindValue(3, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $all_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get quests assigned to this giver (if any)
    $stmt = $pdo->prepare("SELECT q.*, uq.status as user_status 
                  FROM quests q
                  JOIN user_quests uq ON q.id = uq.quest_id
                  JOIN users u ON uq.employee_id = u.employee_id
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests WHERE created_by = ? OR created_by = ?");
    $stmt->execute([$employee_id, (string)$user_id]);
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
    // Preserve any data gathered before the exception and only provide safe defaults where missing
    if (!isset($stats) || !is_array($stats)) {
        $stats = [
            'total_xp' => 0,
            'completed_quests' => 0,
            'created_quests' => 0,
            'reviewed_submissions' => 0
        ];
    } else {
        $stats = array_merge([
            'total_xp' => 0,
            'completed_quests' => 0,
            'created_quests' => 0,
            'reviewed_submissions' => 0
        ], $stats);
    }

    if (!isset($level)) {
        $level = 1;
    }
    if (!isset($rank)) {
        $rank = 'Newbie';
    }
}

// Fallback: if no assigned quests were collected (e.g., due to a later query failure), re-fetch directly
if ($is_taker && empty($assigned_pending_quests)) {
    try {
        $stmt = $pdo->prepare("SELECT q.*, uq.status AS user_status, uq.assigned_at AS user_assigned_at, uq.started_at AS user_started_at, uq.completed_at AS user_completed_at
                               FROM user_quests uq
                               JOIN quests q ON q.id = uq.quest_id
                               WHERE uq.employee_id = ?
                                 AND LOWER(TRIM(uq.status)) IN ('assigned', 'pending', 'pending_acceptance')
                             ORDER BY COALESCE(uq.assigned_at, q.created_at) DESC");
        $stmt->execute([$employee_id]);
        $fallback_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fallback_rows as $row) {
            $assigned_pending_quests[] = $row;
        }

        if (empty($active_quests) && !empty($active_quest_rows)) {
            $total_active_quests = count($active_quest_rows);
            $total_pages_active_quests = $total_active_quests > 0 ? ceil($total_active_quests / $items_per_page) : 0;
            $offset_active = ($active_page - 1) * $items_per_page;
            $active_quests = array_slice($active_quest_rows, $offset_active, $items_per_page);
        }
    } catch (PDOException $e) {
        error_log('Fallback assigned quest retrieval failed: ' . $e->getMessage());
    }
}

// Dedicated retrieval for "My Created Quests" to guarantee visibility even if prior queries failed
if ($is_giver) {
    try {
        $createdIdentifiers = [$employee_id];
        if (!empty($user_id) && (string)$user_id !== (string)$employee_id) {
            $createdIdentifiers[] = (string)$user_id;
        }

        // Build dynamic condition placeholders (q.created_by = ? [OR ...])
        $creatorConditions = implode(' OR ', array_fill(0, count($createdIdentifiers), 'q.created_by = ?'));

        // Recompute total quests for pagination using robust identifiers
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests q WHERE $creatorConditions");
        $stmt->execute($createdIdentifiers);
        $total_quests = (int)$stmt->fetchColumn();
        $total_pages_quests = ($total_quests > 0) ? ceil($total_quests / $items_per_page) : 0;

        if ($created_page < 1) {
            $created_page = 1;
        }
        if ($total_pages_quests > 0 && $created_page > $total_pages_quests) {
            $created_page = $total_pages_quests;
        }

        $offset_created = ($created_page - 1) * $items_per_page;

        // Fetch quests created by this user (handles both employee_id and legacy user_id storage)
        $listSql = "SELECT q.*, 
                         (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'approved') as approved_count,
                         (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'pending') as pending_count,
                         (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'rejected') as rejected_count,
                         (SELECT COUNT(*) FROM user_quests WHERE quest_id = q.id) as assigned_count
                     FROM quests q
                     WHERE $creatorConditions
                     ORDER BY q.status, q.created_at DESC
                     LIMIT ? OFFSET ?";

        $stmt = $pdo->prepare($listSql);
        $bindParams = array_merge($createdIdentifiers, [$items_per_page, $offset_created]);
        foreach ($bindParams as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $all_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure stats section reflects accurate created quest count
        if (isset($stats)) {
            $stats['created_quests'] = $total_quests;
        }
    } catch (PDOException $e) {
        error_log("Created quests retrieval error: " . $e->getMessage());
        if (!isset($all_quests) || !is_array($all_quests)) {
            $all_quests = [];
        }
    }
}

// Role-based styling (simple rename)
$role_badge_class = [
    'skill_associate' => 'bg-green-100 text-green-800',    // was quest_taker
    'quest_lead' => 'bg-purple-100 text-purple-800'   // was hybrid 
][$role] ?? 'bg-gray-100 text-gray-800';

$role_icon_color = [
    'skill_associate' => 'text-green-500',    // was quest_taker
    'quest_lead' => 'text-purple-500'    // was hybrid
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

// Simple, foolproof pagination function that ALWAYS shows navigation when needed
function generatePagination($total_pages, $current_page, $section = '', $total_items = 0, $displayed_items = 0) {
    // Build the page parameter name based on section
    $page_param = !empty($section) ? $section . '_page' : 'page';
    
    // Build query string preserving other pagination parameters
    $query_params = $_GET;
    
    // CRITICAL FIX: Always show pagination if there are multiple pages OR user is beyond page 1
    // This ensures users can navigate back even from empty pages
    if ($total_pages <= 1 && $current_page <= 1) {
        return ''; // Only hide if truly single page and user is on page 1
    }
    
    // Ensure at least 1 page for navigation when user is beyond page 1
    $max_pages = max(1, $total_pages);
    
    $pagination = '<div class="flex items-center justify-between mt-6">';
    
    // Calculate quest counts based on actual displayed items
    $items_per_page = 8;
    
    if ($displayed_items > 0 && $total_items > 0) {
        // Calculate starting quest number for this page
        $start_quest = ($current_page - 1) * $items_per_page + 1;
        $end_quest = $start_quest + $displayed_items - 1;
    } else {
        $start_quest = 0;
        $end_quest = 0;
    }
    
    $pagination .= '<div class="text-sm text-gray-600">';
    if ($displayed_items > 0 && $total_items > 0) {
        if ($displayed_items == 1) {
            // Showing single quest
            $pagination .= 'Showing quest <span class="font-medium">' . $start_quest . '</span> of <span class="font-medium">' . $total_items . '</span> total';
        } else {
            // Showing range of quests  
            $pagination .= 'Showing quests <span class="font-medium">' . $start_quest . '</span>-<span class="font-medium">' . $end_quest . '</span> of <span class="font-medium">' . $total_items . '</span> total';
        }
    } elseif ($total_items > 0) {
        // We have total items but none displayed on this page
        $pagination .= 'No quests on this page (total: <span class="font-medium">' . $total_items . '</span>)';
    } else {
        // No items at all
        $pagination .= 'No quests found';
    }
    $pagination .= '</div>';
    
    // Navigation on the right - ALWAYS show for navigation
    $pagination .= '<nav class="inline-flex rounded-lg shadow-sm" aria-label="Pagination">';
    
    // Previous button - show if current page > 1
    if ($current_page > 1) {
        $query_params[$page_param] = $current_page - 1;
        $pagination .= '<a href="?' . http_build_query($query_params) . '" class="relative inline-flex items-center px-3 py-2 rounded-l-lg border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors">Previous</a>';
    } else {
        $pagination .= '<span class="relative inline-flex items-center px-3 py-2 rounded-l-lg border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">Previous</span>';
    }
    
    // Always show page 1
    if ($current_page == 1) {
        $pagination .= '<span class="relative inline-flex items-center px-3 py-2 border border-blue-500 bg-blue-500 text-sm font-medium text-white">1</span>';
    } else {
        $query_params[$page_param] = 1;
        $pagination .= '<a href="?' . http_build_query($query_params) . '" class="relative inline-flex items-center px-3 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors">1</a>';
    }
    
    // Show additional pages if they exist
    for ($i = 2; $i <= min($max_pages, 5); $i++) {
        if ($i == $current_page) {
            $pagination .= '<span class="relative inline-flex items-center px-3 py-2 border border-blue-500 bg-blue-500 text-sm font-medium text-white">' . $i . '</span>';
        } else {
            $query_params[$page_param] = $i;
            $pagination .= '<a href="?' . http_build_query($query_params) . '" class="relative inline-flex items-center px-3 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors">' . $i . '</a>';
        }
    }
    
    // Next button - show if there are more pages
    if ($current_page < $max_pages) {
        $query_params[$page_param] = $current_page + 1;
        $pagination .= '<a href="?' . http_build_query($query_params) . '" class="relative inline-flex items-center px-3 py-2 rounded-r-lg border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors">Next</a>';
    } else {
        $pagination .= '<span class="relative inline-flex items-center px-3 py-2 rounded-r-lg border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">Next</span>';
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

        /* Enhanced responsive layout fixes */
        .section-animation {
            min-height: 400px;
        }
        
        /* Remove problematic min-height for lg screens */
        
        /* Fix for role icons */
        .role-icon {
            flex-shrink: 0;
        }
        
        /* Improve column alignment */
        .dashboard-columns {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        @media (min-width: 1024px) {
            .dashboard-columns {
                flex-direction: row;
                align-items: flex-start;
            }
            
            .dashboard-columns.justify-center {
                justify-content: center;
            }
        }
        
        /* Improve card spacing and consistency */
        .grid.gap-4 > div,
        .grid.gap-6 > div {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .grid.gap-4 > div:hover,
        .grid.gap-6 > div:hover {
            transform: translateY(-2px);
        }
        
        /* Better responsive behavior for quest cards */
        @media (max-width: 768px) {
            .grid.grid-cols-2 {
                grid-template-columns: 1fr;
            }
            
            /* Reduce min-height on mobile */
            .dashboard-column {
                min-height: auto !important;
                margin-bottom: 1rem;
            }
        }
        
        /* Fix spacing consistency */
        .section-header h2 {
            margin: 0;
        }
        
        /* Ensure consistent alignment for quest sections */
        .mb-8 {
            margin-bottom: 2rem;
        }
        
        .mb-6 {
            margin-bottom: 1.5rem;
        }
        
        /* Fix alignment issues for quest cards */
        .grid.gap-4,
        .grid.gap-6 {
            align-items: start;
        }
        
        /* Ensure columns maintain consistent spacing */
        .dashboard-column {
            flex: 1;
            max-width: 100%;
        }
        
        @media (min-width: 1024px) {
            .dashboard-column {
                max-width: calc(50% - 0.75rem);
            }
        }
        
        /* Better card consistency */
        .quest-card {
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }
        
        .quest-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateY(-1px);
        }
        
        /* Apply quest-card styling to existing cards */
        .bg-gray-50.border.border-gray-200.rounded-lg.p-4 {
            background: white !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }
        
        .bg-gray-50.border.border-gray-200.rounded-lg.p-4:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateY(-1px);
        }
        
        /* Better button consistency */
        .btn {
            transition: all 0.2s ease;
        }
        
        .btn:hover {
            transform: translateY(-1px);
        }
        
        /* Improve section spacing */
        .section-header {
            margin-bottom: 1.5rem;
        }
        
        .section-header h2 {
            margin: 0;
            font-weight: 600;
        }
        .mb-8 {
            margin-bottom: 2rem;
        }
        
        .mb-6 {
            margin-bottom: 1.5rem;
        }
        
        /* Fix alignment issues for quest cards */
        .grid.gap-4,
        .grid.gap-6 {
            align-items: start;
        }
        
        /* Ensure flex columns maintain consistent spacing */
        .section-animation .mb-8:last-child {
            margin-bottom: 0;
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
        
        /* Enhanced grid layouts */
        .grid.gap-4 {
            gap: 1rem;
        }
        
        .grid.gap-6 {
            gap: 1.5rem;
        }
        
        /* Better responsive text */
        @media (max-width: 640px) {
            .section-header h2 {
                font-size: 1rem;
            }
            
            .dashboard-column {
                padding: 1rem;
            }
        }
        
        /* Enhanced quest card layouts for pagination */
        .quest-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        @media (min-width: 640px) {
            .quest-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .quest-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.5rem;
            }
        }
        
        /* Pagination enhancements */
        .pagination-info {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .pagination-nav a:hover,
        .pagination-nav button:hover {
            transform: translateY(-1px);
        }
        
        /* Quest card consistency */
        .quest-card-uniform {
            min-height: 200px;
            display: flex;
            flex-direction: column;
        }
        
        .quest-card-content {
            flex: 1;
        }
        
        .quest-card-actions {
            margin-top: auto;
            padding-top: 1rem;
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
                <?php if ($role === 'quest_lead'): ?>
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
                <?php if ($role === 'quest_lead'): ?>
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

            <!-- User Info Compact Bar -->
            <div class="bg-gray-50 border border-gray-200 rounded-md px-4 py-3 mb-4 text-sm text-gray-700" style="background-color: var(--card-bg);">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-6">
                        <span><strong class="text-gray-900">Employee ID:</strong> <?php echo htmlspecialchars($employee_id); ?></span>
                        <span class="hidden sm:inline"><strong class="text-gray-900">Email:</strong> <?php echo htmlspecialchars($email); ?></span>
                    </div>
                    <div class="flex items-center gap-1">
                        <?php 
                        if ($is_giver) echo '<span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">Quest Creator</span>';
                        if ($is_taker) echo '<span class="px-2 py-1 bg-green-100 text-green-800 rounded text-sm font-medium">Quest Taker</span>';
                        if (!$is_giver && !$is_taker) echo '<span class="px-2 py-1 bg-gray-100 text-gray-800 rounded text-sm font-medium">Basic Access</span>';
                        ?>
                    </div>
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

            <!-- Main Dashboard Layout Header -->
            <div class="text-center mb-6">
                <?php if ($is_giver): ?>
                    <h2 class="text-2xl font-bold text-gray-800 mb-2">🎯 Quest Lead Dashboard</h2>
                    <p class="text-gray-600">As a Quest Lead, you can both complete learning quests and create/manage quests for others</p>
                <?php else: ?>
                    <h2 class="text-2xl font-bold text-gray-800 mb-2">📚 Skill Associate Dashboard</h2>
                    <p class="text-gray-600">Welcome! Complete learning quests to develop new skills and earn XP</p>
                <?php endif; ?>
            </div>

            <!-- Role-Based Content Grid -->
            <div class="dashboard-columns <?php echo $is_giver ? '' : 'justify-center'; ?>">

                <!-- LEFT COLUMN: Learning Journey (Quest Taking) -->
                <div class="dashboard-column <?php echo $is_giver ? 'w-full lg:w-1/2' : 'w-full max-w-4xl'; ?> bg-white rounded-lg shadow-sm p-6" style="background-color: var(--card-bg); min-height: 600px;">
                    <div class="section-header flex items-center mb-6">
                        <svg class="role-icon text-green-500 w-6 h-6 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                        <h2 class="text-lg font-bold text-gray-800">📚 Personal Learning</h2>
                    </div>
                    
                    <!-- Section Description -->
                    <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-400 rounded-r-lg">
                        <p class="text-sm text-green-800">
                            <strong>Your Learning Journey:</strong> Complete learning quests to develop new skills and earn XP. 
                            <?php if ($is_giver): ?>
                                As a Quest Lead, continuous learning helps you create better content for others.
                            <?php else: ?>
                                Focus on building your expertise through practical learning experiences.
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <?php if (!empty($assigned_pending_quests)): ?>
                    <!-- Assigned Quests Awaiting Acceptance -->
                    <div class="mb-8">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xl font-semibold text-gray-800">Assigned to You</h3>
                            <span class="bg-purple-100 text-purple-800 px-3 py-1 rounded-full text-sm font-medium">
                                <?php echo count($assigned_pending_quests); ?> awaiting action
                            </span>
                        </div>
                        <div class="grid <?php echo $is_giver ? 'grid-cols-1 xl:grid-cols-2' : 'grid-cols-1 lg:grid-cols-2 xl:grid-cols-3'; ?> gap-4">
                            <?php foreach ($assigned_pending_quests as $quest): ?>
                                <div class="bg-gradient-to-r from-purple-50 to-fuchsia-50 border border-purple-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 overflow-hidden">
                                    <div class="p-4">
                                            <div class="flex justify-between items-start mb-3">
                                            <div class="flex-1">
                                                <?php 
                                                    $assignmentType = strtolower(trim($quest['quest_assignment_type'] ?? 'optional'));
                                                    $isMandatory = in_array($assignmentType, ['mandatory', 'required'], true);
                                                ?>
                                                <h4 class="font-semibold text-gray-900 mb-1">
                                                    📌 <?php echo htmlspecialchars($quest['title']); ?>
                                                </h4>
                                                <p class="text-gray-600 text-sm leading-relaxed mb-2">
                                                    <?php echo htmlspecialchars($quest['description']); ?>
                                                </p>
                                                <div class="space-y-1 text-xs text-gray-500">
                                                    <div class="flex items-center gap-2">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border <?php 
                                                            echo $isMandatory ? 'bg-red-100 text-red-700 border-red-200' : 'bg-blue-100 text-blue-700 border-blue-200';
                                                        ?>">
                                                            <?php echo $isMandatory ? '⚠️ Mandatory Quest' : '✅ Optional Quest'; ?>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <strong>Assigned:</strong> <?php echo !empty($quest['user_assigned_at']) ? date('M d, Y g:i A', strtotime($quest['user_assigned_at'])) : 'Just now'; ?>
                                                    </div>
                                                    <?php if (!empty($quest['due_date'])): ?>
                                                        <div>
                                                            <strong>Due:</strong> <?php echo date('M d, Y g:i A', strtotime($quest['due_date'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="bg-white border border-purple-100 rounded-md p-3 mb-3">
                                            <p class="text-xs text-gray-600">
                                                This quest has been assigned to you and is waiting for your acceptance. Once you accept, it will move to <strong>My Active Quests</strong> so you can submit your work.
                                            </p>
                                        </div>
                                        <div class="flex justify-end flex-wrap gap-2">
                                            <form method="post" class="flex items-center">
                                                <input type="hidden" name="quest_id" value="<?php echo $quest['id']; ?>">
                                                <input type="hidden" name="quest_action" value="accept">
                                                <button type="submit" class="inline-flex items-center px-3 py-2 bg-purple-600 text-white text-xs font-medium rounded-md hover:bg-purple-700 focus:ring-2 focus:ring-purple-500 transition-colors">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                    </svg>
                                                    Accept Quest
                                                </button>
                                            </form>
                                            <?php if (!$isMandatory): ?>
                                                <form method="post" class="flex items-center">
                                                    <input type="hidden" name="quest_id" value="<?php echo $quest['id']; ?>">
                                                    <input type="hidden" name="quest_action" value="decline">
                                                    <button type="submit" class="inline-flex items-center px-3 py-2 bg-white text-purple-700 border border-purple-300 text-xs font-medium rounded-md hover:bg-purple-50 focus:ring-2 focus:ring-purple-400 transition-colors">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                        </svg>
                                                        Decline
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Available Quests to Accept -->
                    <div class="mb-8">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xl font-semibold text-gray-800">Available Quests</h3>
                            <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium">
                                <?php echo count($available_quests); ?> available
                            </span>
                        </div>
                        <?php if (!empty($available_quests)): ?>
                            <div class="grid <?php echo $is_giver ? 'grid-cols-1 xl:grid-cols-2' : 'grid-cols-1 lg:grid-cols-2 xl:grid-cols-3'; ?> gap-4">
                                <?php foreach ($available_quests as $quest): ?>
                                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 overflow-hidden">
                                        <div class="p-4">
                                            <div class="flex justify-between items-start mb-3">
                                                <div class="flex-1">
                                                    <h4 class="font-semibold text-gray-900 mb-1">
                                                        🎯 <?php echo htmlspecialchars($quest['title']); ?>
                                                    </h4>
                                                    <p class="text-gray-600 text-sm leading-relaxed">
                                                        <?php echo htmlspecialchars($quest['description']); ?>
                                                    </p>
                                                </div>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200 ml-2">
                                                    🏆 +<?php echo isset($quest['xp']) ? (int)$quest['xp'] : 0; ?> XP
                                                </span>
                                            </div>
                                            <div class="flex justify-end">
                                                <form method="post" name="accept_quest_form">
                                                    <input type="hidden" name="quest_id" value="<?php echo $quest['id']; ?>">
                                                    <input type="hidden" name="quest_action" value="accept">
                                                    <button type="submit" class="inline-flex items-center px-3 py-2 bg-blue-600 text-white text-xs font-medium rounded-md hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 transition-colors">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                        </svg>
                                                        Accept
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($total_available_quests == 0): ?>
                            <div class="text-center py-6">
                                <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <p class="text-gray-500">No available quests at the moment.</p>
                                    <p class="text-sm text-gray-400 mt-1">Check back later for new learning opportunities!</p>
                                </div>
                        <?php else: ?>
                            <div class="text-center py-6">
                                <p class="text-gray-500">No available quests found on this page.</p>
                                <p class="text-sm text-gray-400 mt-1">Try navigating to a different page.</p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Pagination for available quests - Show if there are any quests OR user is on page > 1 -->
                        <?php if ($total_available_quests > 0 || $available_page > 1): ?>
                            <div class="mt-4">
                                <?php echo generatePagination($total_pages_available_quests, $available_page, 'available', $total_available_quests, count($available_quests)); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Active Quests to Submit -->
                    <div class="mb-8">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xl font-semibold text-gray-800">My Active Quests</h3>
                            <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
                                <?php echo count($active_quests); ?> active / assigned
                            </span>
                        </div>
                        <?php if (!empty($active_quests)): ?>
                            <div class="grid grid-cols-1 gap-4">
                                <?php foreach ($active_quests as $quest): ?>
                                    <div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 overflow-hidden">
                                        <div class="p-4">
                                            <div class="flex justify-between items-start mb-3">
                                                <div class="flex-1">
                                                    <h4 class="font-semibold text-gray-900 mb-1">
                                                        ⚡ <?php echo htmlspecialchars($quest['title']); ?>
                                                    </h4>
                                                    <p class="text-gray-600 text-sm leading-relaxed">
                                                        <?php echo htmlspecialchars($quest['description']); ?>
                                                    </p>
                                                </div>
                                                <div class="ml-2 flex flex-col items-end space-y-1">
                                                    <?php $status = strtolower(trim($quest['user_status'] ?? '')); ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php 
                                                        echo $status === 'in_progress' ? 'bg-blue-100 text-blue-800 border border-blue-200' : 
                                                            ($status === 'submitted' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' : 
                                                             ($status === 'assigned' ? 'bg-purple-100 text-purple-800 border border-purple-200' : 'bg-gray-100 text-gray-800 border border-gray-200'));
                                                    ?>">
                                                        <?php 
                                                        echo $status === 'in_progress' ? '🔄 In Progress' : 
                                                            ($status === 'submitted' ? '📤 Submitted' : 
                                                             ($status === 'assigned' ? '📌 Assigned' : '📝 ' . ucfirst(str_replace('_', ' ', $status)))); 
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <?php if ($status === 'in_progress'): ?>
                                                <div class="bg-white rounded-md p-3 border border-gray-200 mt-3">
                                                    <h5 class="font-medium text-gray-800 mb-2 text-sm">📤 Submit Your Work</h5>
                                                    <form method="post" enctype="multipart/form-data" class="space-y-3">
                                                        <input type="hidden" name="quest_id" value="<?php echo $quest['id']; ?>">
                                                        
                                                        <!-- Submission Type Selection -->
                                                        <div class="flex gap-3 mb-3">
                                                            <div class="flex items-center">
                                                                <input type="radio" id="file_<?php echo $quest['id']; ?>" name="submission_type" value="file" checked class="mr-1">
                                                                <label for="file_<?php echo $quest['id']; ?>" class="text-xs font-medium text-gray-700">📁 Upload File</label>
                                                            </div>
                                                            <div class="flex items-center">
                                                                <input type="radio" id="link_<?php echo $quest['id']; ?>" name="submission_type" value="link" class="mr-1">
                                                                <label for="link_<?php echo $quest['id']; ?>" class="text-xs font-medium text-gray-700">🔗 Google Drive</label>
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- File Upload Section -->
                                                        <div id="file-section_<?php echo $quest['id']; ?>">
                                                            <input type="file" name="quest_file" 
                                                                   class="w-full px-2 py-1 border border-gray-300 rounded text-xs focus:ring-1 focus:ring-green-500 focus:border-green-500">
                                                            <p class="text-xs text-gray-500 mt-1">📋 PDF, DOC, JPG, PNG, TXT, ZIP (Max 5MB)</p>
                                                        </div>
                                                        
                                                        <!-- Drive Link Section -->
                                                        <div id="link-section_<?php echo $quest['id']; ?>" class="hidden">
                                                            <input type="text" name="drive_link" 
                                                                   placeholder="https://drive.google.com/..." 
                                                                   class="w-full px-2 py-1 border border-gray-300 rounded text-xs focus:ring-1 focus:ring-green-500 focus:border-green-500">
                                                        </div>
                                                        
                                                        <div class="flex justify-end">
                                                            <button type="submit" name="submit_quest" 
                                                                    class="inline-flex items-center px-3 py-1 bg-green-600 text-white text-xs font-medium rounded hover:bg-green-700 focus:ring-1 focus:ring-green-500 transition-colors">
                                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                                                                </svg>
                                                                Submit
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            <?php elseif ($status === 'assigned'): ?>
                                                <div class="bg-white rounded-md p-3 border border-purple-200 mt-3">
                                                    <h5 class="font-medium text-gray-800 mb-2 text-sm">✅ Accept or Decline</h5>
                                                    <p class="text-xs text-gray-600 mb-3">Accept to move this quest into progress. Declining will remove it from your list.</p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <form method="post">
                                                            <input type="hidden" name="quest_id" value="<?php echo $quest['id']; ?>">
                                                            <input type="hidden" name="quest_action" value="accept">
                                                            <button type="submit" class="inline-flex items-center px-3 py-2 bg-purple-600 text-white text-xs font-medium rounded-md hover:bg-purple-700 focus:ring-2 focus:ring-purple-500 transition-colors">
                                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                                </svg>
                                                                Accept Quest
                                                            </button>
                                                        </form>
                                                        <?php 
                                                            $assignmentType = strtolower(trim($quest['quest_assignment_type'] ?? 'optional'));
                                                            $isMandatory = in_array($assignmentType, ['mandatory', 'required'], true);
                                                        ?>
                                                        <?php if (!$isMandatory): ?>
                                                            <form method="post">
                                                                <input type="hidden" name="quest_id" value="<?php echo $quest['id']; ?>">
                                                                <input type="hidden" name="quest_action" value="decline">
                                                                <button type="submit" class="inline-flex items-center px-3 py-2 bg-white text-purple-700 border border-purple-300 text-xs font-medium rounded-md hover:bg-purple-50 focus:ring-2 focus:ring-purple-400 transition-colors">
                                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                                    </svg>
                                                                    Decline
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($total_active_quests == 0): ?>
                            <div class="text-center py-6">
                                <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                                <p class="text-gray-500">You don't have any active quests.</p>
                                <p class="text-sm text-gray-400 mt-1">Start by accepting some available quests above!</p>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-6">
                                <p class="text-gray-500">No active quests found on this page.</p>
                                <p class="text-sm text-gray-400 mt-1">Try navigating to a different page.</p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Pagination for active quests - Show if there are any quests OR user is on page > 1 -->
                        <?php if ($total_active_quests > 0 || $active_page > 1): ?>
                            <div class="mt-4">
                                <?php echo generatePagination($total_pages_active_quests, $active_page, 'active', $total_active_quests, count($active_quests)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Teaching Responsibilities (Quest Management) -->
                <?php if ($is_giver): ?>
                    <div class="dashboard-column w-full lg:w-1/2 bg-white rounded-lg shadow-sm p-6" style="background-color: var(--card-bg); min-height: 600px;">
                        <div class="section-header flex items-center mb-6">
                            <svg class="role-icon text-blue-500 w-6 h-6 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            <h2 class="text-lg font-bold text-gray-800">🎯 Quest Management</h2>
                        </div>
                        
                        <!-- Section Description -->
                        <div class="mb-6 p-4 bg-blue-50 border-l-4 border-blue-400 rounded-r-lg">
                            <p class="text-sm text-blue-800">
                                <strong>Teaching & Leadership:</strong> Create learning quests, review submissions, and guide others. 
                                Use your expertise to design meaningful learning experiences for the community.
                            </p>
                        </div>
                        
                        <!-- Pending Reviews (Submissions to Review) -->
                        <div class="mb-8">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-xl font-semibold text-gray-800">Pending Reviews</h3>
                                <span class="bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-sm font-medium">
                                    <?php echo count($pending_submissions); ?> pending
                                </span>
                            </div>
                            
                            <?php if (!empty($pending_submissions)): ?>
                                <div class="grid grid-cols-1 gap-4">
                                    <?php foreach ($pending_submissions as $submission): ?>
                                        <div class="bg-gradient-to-r from-orange-50 to-amber-50 border-l-4 border-orange-400 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200">
                                            <div class="p-4">
                                                <!-- Header -->
                                                <div class="flex justify-between items-start mb-3">
                                                    <div class="flex-1">
                                                        <h4 class="font-semibold text-gray-900 mb-1">
                                                            📋 <?php echo htmlspecialchars($submission['quest_title']); ?>
                                                        </h4>
                                                        <div class="flex items-center space-x-3 text-sm text-gray-600">
                                                            <span class="flex items-center">
                                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                                                </svg>
                                                                <?php echo htmlspecialchars($submission['employee_name']); ?>
                                                            </span>
                                                            <span class="text-xs text-gray-500">
                                                                <?php echo date('M d • H:i', strtotime($submission['submitted_at'])); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 border border-yellow-200">
                                                        ⏳ Awaiting Review
                                                    </span>
                                                </div>
                                                
                                                <!-- Quick Actions -->
                                                <div class="flex justify-end space-x-2 mt-3">
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="submission_id" value="<?php echo $submission['id']; ?>">
                                                        <input type="hidden" name="review_submission" value="1">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="inline-flex items-center px-2 py-1 bg-green-600 text-white text-xs font-medium rounded hover:bg-green-700 transition-colors">
                                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                            </svg>
                                                            Approve
                                                        </button>
                                                    </form>
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="submission_id" value="<?php echo $submission['id']; ?>">
                                                        <input type="hidden" name="review_submission" value="1">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="inline-flex items-center px-2 py-1 bg-red-600 text-white text-xs font-medium rounded hover:bg-red-700 transition-colors">
                                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                            </svg>
                                                            Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-6">
                                    <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <p class="text-gray-500">No pending submissions to review.</p>
                                    <p class="text-sm text-gray-400 mt-1">Submissions will appear here once learners start submitting their work.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- My Created Quests -->
                        <div class="mb-8">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-xl font-semibold text-gray-800">My Created Quests</h3>
                                <a href="create_quest.php" 
                                   class="inline-flex items-center px-3 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 transition-colors">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    Create New
                                </a>
                            </div>
                            
                            <?php if (!empty($all_quests)): ?>
                                <div class="grid grid-cols-1 gap-4">
                                    <?php foreach ($all_quests as $quest): ?>
                                        <div class="bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 overflow-hidden">
                                            <div class="p-4">
                                                <div class="flex justify-between items-start mb-3">
                                                    <div class="flex-1">
                                                        <h4 class="font-semibold text-gray-900 mb-1">
                                                            🎯 <?php echo htmlspecialchars($quest['title']); ?>
                                                        </h4>
                                                        <p class="text-gray-600 text-sm leading-relaxed">
                                                            <?php echo htmlspecialchars($quest['description']); ?>
                                                        </p>
                                                    </div>
                                                    <div class="ml-2 flex flex-col items-end space-y-1">
                                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php 
                                                            echo $quest['status'] === 'active' ? 'bg-green-100 text-green-800 border border-green-200' : 
                                                                ($quest['status'] === 'inactive' ? 'bg-gray-100 text-gray-800 border border-gray-200' : 
                                                                 'bg-blue-100 text-blue-800 border border-blue-200'); ?>">
                                                            <?php echo $quest['status'] === 'active' ? '🟢 Active' : 
                                                                     ($quest['status'] === 'inactive' ? '⚪ Inactive' : '🔵 ' . ucfirst($quest['status'])); ?>
                                                        </span>
                                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                                                            🏆 <?php echo isset($quest['xp']) ? (int)$quest['xp'] : 0; ?> XP
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                <!-- Quick Stats -->
                                                <div class="grid grid-cols-3 gap-2 mb-3">
                                                    <div class="text-center p-2 bg-green-50 rounded border border-green-200">
                                                        <div class="text-sm font-bold text-green-800"><?php echo $quest['approved_count'] ?? 0; ?></div>
                                                        <div class="text-xs text-green-700">✅ Approved</div>
                                                    </div>
                                                    <div class="text-center p-2 bg-yellow-50 rounded border border-yellow-200">
                                                        <div class="text-sm font-bold text-yellow-800"><?php echo $quest['pending_count'] ?? 0; ?></div>
                                                        <div class="text-xs text-yellow-700">⏳ Pending</div>
                                                    </div>
                                                    <div class="text-center p-2 bg-red-50 rounded border border-red-200">
                                                        <div class="text-sm font-bold text-red-800"><?php echo $quest['rejected_count'] ?? 0; ?></div>
                                                        <div class="text-xs text-red-700">❌ Rejected</div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Action Buttons -->
                                                <div class="flex flex-wrap gap-2">
                                                    <a href="edit_quest.php?id=<?php echo $quest['id']; ?>" 
                                                       class="inline-flex items-center px-2 py-1 bg-yellow-500 text-white text-xs font-medium rounded hover:bg-yellow-600 transition-colors">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                        Edit
                                                    </a>
                                                    
                                                    <a href="view_submissions.php?quest_id=<?php echo $quest['id']; ?>" 
                                                       class="inline-flex items-center px-2 py-1 bg-blue-500 text-white text-xs font-medium rounded hover:bg-blue-600 transition-colors">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                        </svg>
                                                        View (<?php echo $quest['assigned_count']; ?>)
                                                    </a>
                                                    
                                                    <button type="button" 
                                                            onclick="showDeleteModal(<?php echo $quest['id']; ?>)" 
                                                            class="inline-flex items-center px-2 py-1 bg-red-500 text-white text-xs font-medium rounded hover:bg-red-600 transition-colors">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                        Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($total_quests == 0): ?>
                                <!-- Only show "no quests" message if user has truly never created any -->
                                <div class="text-center py-6">
                                    <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    <p class="text-gray-500">You haven't created any quests yet.</p>
                                    <p class="text-sm text-gray-400 mt-1">Start by creating your first learning quest!</p>
                                </div>
                            <?php else: ?>
                                <!-- User has quests but none on this page -->
                                <div class="text-center py-6">
                                    <p class="text-gray-500">No quests found on this page.</p>
                                    <p class="text-sm text-gray-400 mt-1">Try navigating to a different page.</p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Pagination - ALWAYS show if there are any quests OR user is on page > 1 -->
                            <?php if ($total_quests > 0 || $created_page > 1): ?>
                                <div class="mt-4">
                                    <?php echo generatePagination($total_pages_quests, $created_page, 'created', $total_quests, count($all_quests)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
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