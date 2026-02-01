<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Simple flash + redirect helper for PRG pattern
if (!function_exists('redirect_with_message')) {
    function redirect_with_message(string $url, ?string $success = null, ?string $error = null): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if ($success !== null && $success !== '') {
            $_SESSION['success'] = $success;
        }
        if ($error !== null && $error !== '') {
            $_SESSION['error'] = $error;
        }
        header('Location: ' . $url);
        exit();
    }
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
    // PRG: Redirect after POST to avoid form resubmission prompts on back navigation
    redirect_with_message('dashboard.php', $success ?? null, $error ?? null);
}

$full_name = $_SESSION['full_name'] ?? 'User';
$first_name_only = 'User'; // Default fallback

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
    $stmt = $pdo->prepare("SELECT id, role, first_name, last_name FROM users WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $user = $stmt->fetch();
    if ($user && isset($user['id'])) {
        $user_id = $user['id'];
        
        // Extract first name properly
        if (!empty($user['first_name'])) {
            $first_name_only = trim($user['first_name']);
        } elseif (!empty($full_name)) {
            // Fallback: parse from full_name if first_name column is empty
            // Check if format is "Last, First" or "First Last"
            if (strpos($full_name, ',') !== false) {
                // Format is "Last, First"
                $parts = explode(',', $full_name);
                $first_name_only = isset($parts[1]) ? trim(explode(' ', trim($parts[1]))[0]) : trim($parts[0]);
            } else {
                // Format is "First Last" - take first word
                $first_name_only = trim(explode(' ', $full_name)[0]);
            }
        }
        
        // Refresh role in session if it has changed in database
        if (isset($user['role']) && $user['role'] !== $_SESSION['role']) {
            $_SESSION['role'] = $user['role'];
        }
        // Ensure we don't overwrite a valid session full_name with an empty value
        // The $user row fetched here contains only id and role; format_display_name
        // may return empty if name fields are not present. Only update session when
        // the formatted result is non-empty.
        try {
            $maybe_display = format_display_name($user);
            if (is_string($maybe_display) && trim($maybe_display) !== '') {
                $_SESSION['full_name'] = $maybe_display;
            }
        } catch (Exception $e) {
            error_log('format_display_name error in dashboard: ' . $e->getMessage());
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
$is_admin = ($role === 'admin');
$is_taker = in_array($role, ['skill_associate', 'quest_lead']); // skill_associate + quest_lead
$is_giver = in_array($role, ['quest_lead', 'admin']); // quest_lead + admin have creator/reviewer tools

// Pagination settings - Separate for each section to avoid conflicts
$items_per_page = 8; // Increased from 5 to 8 for better content density

// Separate pagination parameters for each quest section
$available_page = isset($_GET['available_page']) ? (int)$_GET['available_page'] : 1;
$active_page = isset($_GET['active_page']) ? (int)$_GET['active_page'] : 1;
$created_page = isset($_GET['created_page']) ? (int)$_GET['created_page'] : 1;
$submission_page = isset($_GET['submission_page']) ? (int)$_GET['submission_page'] : 1;
$pending_page = isset($_GET['pending_page']) ? (int)$_GET['pending_page'] : 1;
$review_page = isset($_GET['review_page']) ? (int)$_GET['review_page'] : 1;
$error = '';
$success = '';
// Read and clear flash messages (if any)
if (isset($_SESSION['success'])) { $success = $_SESSION['success']; unset($_SESSION['success']); }
if (isset($_SESSION['error'])) { $error = $_SESSION['error']; unset($_SESSION['error']); }

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
                        $success = "Quest declined. You can pick it up again later from Open Quests.";
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
        // PRG redirect
        redirect_with_message('dashboard.php', $success ?? null, $error ?? null);
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
                                } elseif (in_array('submission_text', $questSubmissionColumns, true)) {
                                    $insertColumns[] = 'submission_text';
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
            // Google Drive link handling (schema-adaptive)
            $drive_link = $_POST['drive_link'] ?? '';
            
            if (filter_var($drive_link, FILTER_VALIDATE_URL) === false) {
                $error = "Invalid URL format";
            } else {
                try {
                    // Inspect available columns to choose a storage column for the link
                    static $qsColsCache2 = null;
                    if ($qsColsCache2 === null) {
                        $schemaStmt = $pdo->query("SHOW COLUMNS FROM quest_submissions");
                        $qsColsCache2 = $schemaStmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                    $qsCols = $qsColsCache2 ?: [];

                    $insertColumns = ['employee_id', 'quest_id'];
                    $placeholders = ['?', '?'];
                    $params = [$employee_id, $quest_id];

                    if (in_array('submission_type', $qsCols, true)) {
                        $insertColumns[] = 'submission_type';
                        $placeholders[] = '?';
                        $params[] = 'link';
                    }

                    if (in_array('drive_link', $qsCols, true)) {
                        $insertColumns[] = 'drive_link';
                        $placeholders[] = '?';
                        $params[] = $drive_link;
                    } elseif (in_array('submission_text', $qsCols, true)) {
                        $insertColumns[] = 'submission_text';
                        $placeholders[] = '?';
                        $params[] = $drive_link;
                    } elseif (in_array('file_path', $qsCols, true)) {
                        $insertColumns[] = 'file_path';
                        $placeholders[] = '?';
                        $params[] = $drive_link;
                    } else {
                        throw new RuntimeException('No suitable column to store link submission.');
                    }

                    if (in_array('status', $qsCols, true)) {
                        $insertColumns[] = 'status';
                        $placeholders[] = '?';
                        $params[] = 'pending';
                    }
                    if (in_array('submitted_at', $qsCols, true)) {
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

                    // Update quest status to submitted for this user
                    $stmt = $pdo->prepare("UPDATE user_quests SET status = 'submitted' WHERE employee_id = ? AND quest_id = ?");
                    $stmt->execute([$employee_id, $quest_id]);

                    $success = "Quest submitted successfully!";
                } catch (Throwable $e) {
                    error_log("Database error submitting link quest: " . $e->getMessage());
                    $error = "Error submitting quest";
                }
            }
        }
        // PRG redirect after any submission attempt (success or error)
        redirect_with_message('dashboard.php', $success ?? null, $error ?? null);
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
    // PRG redirect
    redirect_with_message('dashboard.php', $success ?? null, $error ?? null);
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
                
                if ($action === 'approve') {
                    $success = "Submission reviewed successfully! +15 XP for reviewing";
                } else {
                    $success = "Submission declined successfully.";
                }
            } catch (PDOException $e) {
                error_log("Database error reviewing submission: " . $e->getMessage());
                $error = "Error processing submission";
            }
        }
        // PRG redirect
        redirect_with_message('dashboard.php', $success ?? null, $error ?? null);
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
    
    // Prepare normalized creator identifiers for giver-specific queries
    $normalizedCreatorIds = [];
    if ($is_giver && !$is_admin) {
        $rawCreatorCandidates = [];

        if (!empty($employee_id)) {
            $rawCreatorCandidates[] = $employee_id;
            $rawCreatorCandidates[] = trim((string) $employee_id);
            $rawCreatorCandidates[] = strtolower(trim((string) $employee_id));
            $rawCreatorCandidates[] = strtoupper(trim((string) $employee_id));
        }

        if (!empty($user_id)) {
            $rawUser = (string) $user_id;
            $rawCreatorCandidates[] = $rawUser;
            $rawCreatorCandidates[] = 'user_' . $rawUser;
            $rawCreatorCandidates[] = 'USER_' . $rawUser;
        }

        foreach ($rawCreatorCandidates as $candidate) {
            $normalized = strtolower(trim((string) $candidate));
            if ($normalized !== '') {
                $normalizedCreatorIds[$normalized] = true;
            }
        }

        $normalizedCreatorIds = array_keys($normalizedCreatorIds);
    }

    // Always fetch pending submissions for quest creators
    // (pending_submissions array already initialized above)
    
    // Get quest counts for all users (moved outside conditional blocks)
    // Get total count of available quests
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests q 
                  LEFT JOIN user_quests uq ON q.id = uq.quest_id AND uq.employee_id = ?
                  WHERE q.status = 'active'
                  AND q.visibility = 'public'
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
                  AND q.visibility = 'public'
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
        } elseif (in_array($status, ['in_progress', 'submitted', 'completed'], true)) {
            // Keep completed (graded) quests visible in Active Quests so users can view grades
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

        if ($submission_page > $total_pages && $total_pages > 0) {
            $submission_page = $total_pages;
        }

        // Then get paginated results
    $offset = ($submission_page - 1) * $items_per_page;
    $stmt = $pdo->prepare("SELECT qs.*, q.title as quest_title, qs.status as submission_status, 
                  qs.additional_xp, u.full_name as reviewer_name
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

        // Build full list of quest IDs created by this user (not limited by pagination)
        $createdQuestIds = [];
        try {
            $stmt = $pdo->prepare("SELECT id FROM quests WHERE (created_by = ? OR created_by = ? OR LOWER(TRIM(created_by)) = LOWER(?))");
            $stmt->execute([$employee_id, (string)$user_id, 'user_' . (string)$user_id]);
            $createdQuestIds = array_map(function($r){ return (int)$r['id']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            error_log('Error fetching created quest IDs: ' . $e->getMessage());
            $createdQuestIds = [];
        }
        
        // Get pending submissions for quests created by this user with pagination (schema-safe)
        // Include both 'pending' and 'under_review' statuses
    // Use the actual IDs of quests created by this user to avoid any string mismatch in created_by

        $total_pending = 0;
        $pending_submissions = [];

        if ($is_admin) {
            // Admin: count and fetch all pending/under_review submissions
            $stmt = $pdo->query("SELECT COUNT(*) FROM quest_submissions WHERE status IN ('pending','under_review')");
            $total_pending = (int)$stmt->fetchColumn();

            $total_pages_pending = $total_pending > 0 ? (int)ceil($total_pending / $items_per_page) : 0;
            if ($total_pages_pending === 0) { $pending_page = 1; }
            elseif ($pending_page > $total_pages_pending) { $pending_page = $total_pages_pending; }
            $offset = ($pending_page - 1) * $items_per_page;

            $stmt = $pdo->prepare("SELECT qs.id, qs.employee_id, qs.quest_id, qs.file_path, qs.submission_text, qs.status, qs.submitted_at,
                                           q.title AS quest_title, q.description AS quest_description,
                                           e.full_name AS employee_name, e.id AS employee_user_id
                                    FROM quest_submissions qs
                                    JOIN quests q ON qs.quest_id = q.id
                                    LEFT JOIN users e ON qs.employee_id = e.employee_id
                                    WHERE qs.status IN ('pending','under_review')
                                    ORDER BY qs.submitted_at DESC
                                    LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $items_per_page, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $pending_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            if (!empty($createdQuestIds)) {
                // Build dynamic IN clause
                $placeholders = implode(',', array_fill(0, count($createdQuestIds), '?'));

                // Count
                $countSql = "SELECT COUNT(*) FROM quest_submissions WHERE status IN ('pending','under_review') AND quest_id IN ($placeholders)";
                $stmt = $pdo->prepare($countSql);
                foreach ($createdQuestIds as $i => $qid) {
                    $stmt->bindValue($i + 1, $qid, PDO::PARAM_INT);
                }
                $stmt->execute();
                $total_pending = (int)$stmt->fetchColumn();

                $total_pages_pending = $total_pending > 0 ? (int)ceil($total_pending / $items_per_page) : 0;
                if ($total_pages_pending === 0) { $pending_page = 1; }
                elseif ($pending_page > $total_pages_pending) { $pending_page = $total_pages_pending; }
                $offset = ($pending_page - 1) * $items_per_page;

                // Data
                $dataSql = "SELECT qs.id, qs.employee_id, qs.quest_id, qs.file_path, qs.submission_text, qs.status, qs.submitted_at,
                                   q.title AS quest_title, q.description AS quest_description,
                                   e.full_name AS employee_name, e.id AS employee_user_id
                            FROM quest_submissions qs
                            JOIN quests q ON qs.quest_id = q.id
                            LEFT JOIN users e ON qs.employee_id = e.employee_id
                            WHERE qs.status IN ('pending','under_review') AND qs.quest_id IN ($placeholders)
                            ORDER BY qs.submitted_at DESC
                            LIMIT ? OFFSET ?";
                $stmt = $pdo->prepare($dataSql);
                $bindIdx = 1;
                foreach ($createdQuestIds as $qid) {
                    $stmt->bindValue($bindIdx++, $qid, PDO::PARAM_INT);
                }
                $stmt->bindValue($bindIdx++, $items_per_page, PDO::PARAM_INT);
                $stmt->bindValue($bindIdx, $offset, PDO::PARAM_INT);
                $stmt->execute();
                $pending_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Creator has no quests; nothing to review
                $total_pages_pending = 0;
                $pending_page = 1;
                $pending_submissions = [];
            }
        }

        // Ensure we always have a sensible name for display; try resolve by employee_id when missing
        foreach ($pending_submissions as &$pendingRow) {
            if (empty($pendingRow['employee_name'])) {
                $resolved = '';
                if (!empty($pendingRow['employee_id'])) {
                    try {
                        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE employee_id = ? LIMIT 1");
                        $stmt->execute([$pendingRow['employee_id']]);
                        $resolved = (string)($stmt->fetchColumn() ?: '');
                    } catch (PDOException $e) {
                        // ignore
                    }
                }
                $pendingRow['employee_name'] = $resolved !== '' ? $resolved : 'Unknown User';
            }
            if (empty($pendingRow['file_path'])) {
                $pendingRow['file_path'] = '';
            }
            if (!isset($pendingRow['submission_text'])) {
                $pendingRow['submission_text'] = '';
            }
        }
        unset($pendingRow);

        // Fallback: If no rows found, surface "submitted" items from user_quests so reviewers still see them
        if (empty($pending_submissions)) {
            $fallbackParams = [];
            $fallbackWhere = "WHERE uq.status = 'submitted'";
            if (!$is_admin) {
                $fallbackWhere .= " AND (UPPER(TRIM(q.created_by)) = UPPER(?) OR UPPER(TRIM(q.created_by)) = UPPER(?) OR UPPER(TRIM(q.created_by)) = UPPER(?))";
                $fallbackParams[] = (string)$employee_id;
                $fallbackParams[] = (string)$user_id;
                $fallbackParams[] = 'user_' . (string)$user_id;
            }

            // Count fallback
            $fbCountSql = "SELECT COUNT(*)
                           FROM user_quests uq
                           JOIN quests q ON uq.quest_id = q.id
                           $fallbackWhere";
            $stmt = $pdo->prepare($fbCountSql);
            $stmt->execute($fallbackParams);
            $fb_total = (int)$stmt->fetchColumn();

            if ($fb_total > 0) {
                $total_pending = $fb_total;
                $offset = ($pending_page - 1) * $items_per_page;
                $fbSql = "SELECT 
                              uq.employee_id,
                              uq.quest_id,
                              q.title AS quest_title,
                              q.description AS quest_description,
                              e.full_name AS employee_name,
                              e.id AS employee_user_id,
                              NOW() AS submitted_at,
                              'pending' AS status,
                              '' AS file_path,
                              '' AS submission_text
                          FROM user_quests uq
                          JOIN quests q ON uq.quest_id = q.id
                          LEFT JOIN users e ON uq.employee_id = e.employee_id
                          $fallbackWhere
                          ORDER BY q.id DESC
                          LIMIT ? OFFSET ?";
                $stmt = $pdo->prepare($fbSql);
                $fbParams = array_merge($fallbackParams, [$items_per_page, $offset]);
                $fbFilterParams = count($fallbackParams);
                foreach ($fbParams as $i => $v) {
                    $paramType = ($i >= $fbFilterParams) ? PDO::PARAM_INT : PDO::PARAM_STR;
                    $stmt->bindValue($i + 1, $v, $paramType);
                }
                $stmt->execute();
                $pending_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Normalize display fields and resolve missing names by employee_id
                foreach ($pending_submissions as &$row) {
                    if (empty($row['employee_name'])) {
                        $resolved = '';
                        if (!empty($row['employee_id'])) {
                            try {
                                $stmt = $pdo->prepare("SELECT full_name FROM users WHERE employee_id = ? LIMIT 1");
                                $stmt->execute([$row['employee_id']]);
                                $resolved = (string)($stmt->fetchColumn() ?: '');
                            } catch (PDOException $e) {
                                // ignore
                            }
                        }
                        $row['employee_name'] = $resolved !== '' ? $resolved : 'Unknown User';
                    }
                    if (empty($row['file_path'])) {
                        $row['file_path'] = '';
                    }
                    if (!isset($row['submission_text'])) {
                        $row['submission_text'] = '';
                    }
                }
                unset($row);
                // Format employee_name consistently (Surname, Firstname, MI.) for display
                foreach ($pending_submissions as &$__pname_row) {
                    if (!empty($__pname_row['employee_name'])) {
                        $__pname_row['employee_name'] = format_display_name(['full_name' => $__pname_row['employee_name']]);
                    }
                }
                unset($__pname_row);
            }
        }
        
        // Get all submissions for quests created by this giver with pagination
        // First get total count
    $createdFilterAll = $is_admin ? '' : ' WHERE (q.created_by = ? OR q.created_by = ?)';
    $sql = "SELECT COUNT(*) FROM quest_submissions qs
                 JOIN quests q ON qs.quest_id = q.id
                 JOIN users u ON qs.employee_id = u.employee_id
                 LEFT JOIN users rev ON qs.reviewed_by = rev.employee_id" . $createdFilterAll;
    $stmt = $pdo->prepare($sql);
    if ($is_admin) {
        $stmt->execute();
    } else {
        $stmt->execute([$employee_id, (string)$user_id]);
    }
        $total_all_submissions = $stmt->fetchColumn();
        $total_pages_all_submissions = ceil($total_all_submissions / $items_per_page);

        if ($review_page > $total_pages_all_submissions && $total_pages_all_submissions > 0) {
            $review_page = $total_pages_all_submissions;
        }

        // Then get paginated results
        $offset = ($review_page - 1) * $items_per_page;
        $createdFilterAllData = $is_admin ? '' : ' WHERE (q.created_by = ? OR q.created_by = ?)';
        $sql = "SELECT qs.*, q.title as quest_title, u.full_name as employee_name, 
                  qs.additional_xp, rev.full_name as reviewer_name
                  FROM quest_submissions qs
                  JOIN quests q ON qs.quest_id = q.id
                  JOIN users u ON qs.employee_id = u.employee_id
                  LEFT JOIN users rev ON qs.reviewed_by = rev.employee_id" . $createdFilterAllData . "
                  ORDER BY qs.submitted_at DESC
                  LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        $bindIndex = 1;
        if (!$is_admin) {
            $stmt->bindValue($bindIndex++, $employee_id);
            $stmt->bindValue($bindIndex++, (string)$user_id);
        }
        $stmt->bindValue($bindIndex++, $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $all_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format names in all_submissions for consistent display
        foreach ($all_submissions as &$__as_row) {
            if (!empty($__as_row['employee_name'])) {
                $__as_row['employee_name'] = format_display_name(['full_name' => $__as_row['employee_name']]);
            }
            if (!empty($__as_row['reviewer_name'])) {
                $__as_row['reviewer_name'] = format_display_name(['full_name' => $__as_row['reviewer_name']]);
            }
        }
        unset($__as_row);
        
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
        $questFilterClause = '1=1';
        $questFilterParams = [];

        if (!$is_admin) {
            if (!empty($creatorFilterClause) && !empty($creatorFilterParams)) {
                $questFilterClause = $creatorFilterClause;
                $questFilterParams = $creatorFilterParams;
            } else {
                $normalizedEmployee = strtolower(trim((string)$employee_id));
                $questFilterClause = 'LOWER(TRIM(q.created_by)) = ?';
                $questFilterParams = [$normalizedEmployee];
            }
        }

        // Recompute total quests for pagination using robust identifiers
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests q WHERE $questFilterClause");
        $stmt->execute($questFilterParams);
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
                     WHERE $questFilterClause
                     ORDER BY q.status, q.created_at DESC
                     LIMIT ? OFFSET ?";

        $stmt = $pdo->prepare($listSql);
        $bindParams = array_merge($questFilterParams, [$items_per_page, $offset_created]);
        $filterCount = count($questFilterParams);
        foreach ($bindParams as $idx => $value) {
            $paramType = ($idx < $filterCount) ? PDO::PARAM_STR : PDO::PARAM_INT;
            $stmt->bindValue($idx + 1, $value, $paramType);
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

            </div>

            <div class="flex items-center gap-4 mt-3 sm:mt-0">
                <span class="hidden sm:block">Welcome, <?php echo htmlspecialchars($first_name_only); ?></span>
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
                <a href="profile_view.php" class="flex items-center">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    View profile
                </a>
                <a href="leaderboard.php" class="flex items-center">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A.75.75 0 003 5.48v10.018a.75.75 0 00.784.713 45.455 45.455 0 012.07-.352M19.5 4.236c.982.143 1.954.317 2.916.52A.75.75 0 0021 5.48v10.018a.75.75 0 00-.784.713 45.456 45.456 0 01-2.07-.352"></path></svg>
                    Leaderboard
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
            <div class="w-full max-w-4xl mx-auto bg-gray-50 border border-gray-200 rounded-lg px-6 py-4 mb-6 text-sm text-gray-700 shadow-sm" style="background-color: var(--card-bg);">
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

            <!-- Modern Navigation Button Grid -->
            <div class="w-full max-w-3xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-8 mt-10">
                <?php if ($role === 'skill_associate'): ?>
                    <a href="open_quests.php" class="flex flex-col items-center justify-center p-8 bg-blue-50 border-2 border-blue-200 rounded-xl shadow hover:bg-blue-100 transition-all text-center interactive-card">
                        <svg class="w-10 h-10 mb-3 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-lg font-semibold text-blue-800">Open Quests</span>
                        <span class="text-sm text-blue-600 mt-1">Accept or decline new quests</span>
                    </a>
                    <a href="my_quests.php" class="flex flex-col items-center justify-center p-8 bg-green-50 border-2 border-green-200 rounded-xl shadow hover:bg-green-100 transition-all text-center interactive-card">
                        <svg class="w-10 h-10 mb-3 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span class="text-lg font-semibold text-green-800">My Quests</span>
                        <span class="text-sm text-green-600 mt-1">Submit work for your active quests</span>
                    </a>
                <?php else: ?>
                    <a href="open_quests.php" class="flex flex-col items-center justify-center p-8 bg-blue-50 border-2 border-blue-200 rounded-xl shadow hover:bg-blue-100 transition-all text-center interactive-card">
                        <svg class="w-10 h-10 mb-3 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-lg font-semibold text-blue-800">Open Quests</span>
                        <span class="text-sm text-blue-600 mt-1">Accept or decline new quests</span>
                    </a>
                    <a href="my_quests.php" class="flex flex-col items-center justify-center p-8 bg-green-50 border-2 border-green-200 rounded-xl shadow hover:bg-green-100 transition-all text-center interactive-card">
                        <svg class="w-10 h-10 mb-3 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span class="text-lg font-semibold text-green-800">My Quests</span>
                        <span class="text-sm text-green-600 mt-1">Submit work for your active quests</span>
                    </a>
                    <a href="pending_reviews.php" class="flex flex-col items-center justify-center p-8 bg-yellow-50 border-2 border-yellow-200 rounded-xl shadow hover:bg-yellow-100 transition-all text-center interactive-card">
                        <svg class="w-10 h-10 mb-3 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <span class="text-lg font-semibold text-yellow-800">Submitted Quests</span>
                        <span class="text-sm text-yellow-600 mt-1">Review or grade submitted quests</span>
                    </a>
                    <a href="created_quests.php" class="flex flex-col items-center justify-center p-8 bg-purple-50 border-2 border-purple-200 rounded-xl shadow hover:bg-purple-100 transition-all text-center interactive-card">
                        <svg class="w-10 h-10 mb-3 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span class="text-lg font-semibold text-purple-800">Created Quests</span>
                        <span class="text-sm text-purple-600 mt-1">View and manage your created quests</span>
                    </a>
                <?php endif; ?>
            </div>
</div>
<script src="assets/js/script_fixed.js"></script>
<script>
// Hamburger/mobile menu toggle
document.addEventListener('DOMContentLoaded', function() {
    var hamburger = document.getElementById('hamburger');
    var mobileMenu = document.getElementById('mobileMenu');
    var closeMenu = document.getElementById('closeMenu');
    var backdrop = document.getElementById('backdrop');

    function openMenu() {
        mobileMenu.classList.add('open');
        if (backdrop) backdrop.style.display = 'block';
    }
    function closeMenuFunc() {
        mobileMenu.classList.remove('open');
        if (backdrop) backdrop.style.display = 'none';
    }
    if (hamburger) {
        hamburger.addEventListener('click', openMenu);
    }
    if (closeMenu) {
        closeMenu.addEventListener('click', closeMenuFunc);
    }
    if (backdrop) {
        backdrop.addEventListener('click', closeMenuFunc);
    }
});
</script>
</body>
</html>