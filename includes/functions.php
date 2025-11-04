<?php
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function validate_password($password) {
    if (strlen($password) < 8) {
        throw new Exception("Password must be at least 8 characters");
    }
}

function is_manager($employee_id) {
    global $pdo;
    
    try {
        // Get the user's role from the users table
        $stmt = $pdo->prepare("SELECT role FROM users WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        $role = $stmt->fetchColumn();
        
        // Consider these roles as managers (adjust according to your needs)
        return in_array($role, ['admin', 'quest_giver', 'manager']);
    } catch (PDOException $e) {
        error_log("Database error in is_manager(): " . $e->getMessage());
        return false;
    }
}

function send_welcome_email($to_email, $to_name, $employee_id, $password) {
    // You'll need to configure these settings for your Gmail account
    $from_email = "christianbacani581@gmail.com"; // Replace with your Gmail address
    $from_name = "Yoonet Philippines";
    $smtp_host = "smtp.gmail.com";
    $smtp_port = 587;
    $smtp_username = "christianbacani581@gmail.com"; // Same as from_email
    $smtp_password = "12-04-2003"; // Gmail App Password
    
    $subject = "Welcome to YooNet Quest System - Your Account Details";
    
    $html_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #4338ca, #6366f1); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
            .credentials { background: #fff; padding: 20px; border-radius: 8px; border-left: 4px solid #4338ca; margin: 20px 0; }
            .footer { text-align: center; margin-top: 20px; color: #6b7280; font-size: 14px; }
            .button { display: inline-block; background: #4338ca; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 10px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to YooNet Quest System!</h1>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($to_name) . ",</h2>
                <p>Your account has been successfully created in the YooNet Quest System. Below are your login credentials:</p>
                
                <div class='credentials'>
                    <h3>Your Account Details:</h3>
                    <p><strong>Employee ID:</strong> " . htmlspecialchars($employee_id) . "</p>
                    <p><strong>Email:</strong> " . htmlspecialchars($to_email) . "</p>
                    <p><strong>Temporary Password:</strong> " . htmlspecialchars($password) . "</p>
                </div>
                
                <p><strong>Important:</strong> For security reasons, please change your password after your first login.</p>
                
                <p>You can now access the Quest System and start participating in quests, earning points, and tracking your progress.</p>
                
                <a href='http://localhost/yoonet-quest-system/login.php' class='button'>Login to Your Account</a>
                
                <p>If you have any questions or need assistance, please contact your system administrator.</p>
                
                <div class='footer'>
                    <p>This email was sent automatically from YooNet Quest System.<br>
                    Please do not reply to this email.</p>
                </div>
            </div>
        </div>
    </body>
    </html>";
    
    $text_body = "Welcome to YooNet Quest System!\n\n";
    $text_body .= "Hello " . $to_name . ",\n\n";
    $text_body .= "Your account has been successfully created. Here are your login credentials:\n\n";
    $text_body .= "Employee ID: " . $employee_id . "\n";
    $text_body .= "Email: " . $to_email . "\n";
    $text_body .= "Temporary Password: " . $password . "\n\n";
    $text_body .= "Please change your password after your first login.\n\n";
    $text_body .= "Login at: http://localhost/yoonet-quest-system/login.php\n\n";
    $text_body .= "Best regards,\nYooNet Quest System Team";
    
    // Email headers
    $headers = array();
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headers[] = "From: " . $from_name . " <" . $from_email . ">";
    $headers[] = "Reply-To: " . $from_email;
    $headers[] = "X-Mailer: PHP/" . phpversion();
    
    // Try to send email using mail() function first (simpler approach)
    if (mail($to_email, $subject, $html_body, implode("\r\n", $headers))) {
        return true;
    }
    
    return false;
}

// Enhanced validation functions for account creation
function check_employee_id_exists($employee_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Database error in check_employee_id_exists(): " . $e->getMessage());
        return false;
    }
}

function check_email_exists($email) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Database error in check_email_exists(): " . $e->getMessage());
        return false;
    }
}

function validate_email_domain($email) {
    // First check basic format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    // Extract domain
    $domain = substr(strrchr($email, "@"), 1);
    
    // Check if domain has MX record (indicates real email domain)
    if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
        return false;
    }
    
    // Common disposable email domains to block
    $disposable_domains = [
        '10minutemail.com', 'tempmail.org', 'guerrillamail.com', 'mailinator.com',
        'throwaway.email', 'temp-mail.org', 'yopmail.com', 'fakeinbox.com'
    ];
    
    if (in_array(strtolower($domain), $disposable_domains)) {
        return false;
    }
    
    return true;
}

function validate_password_strength($password) {
    $errors = [];
    
    // Length check
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    // Character requirements
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    // Common password checks
    $common_passwords = [
        'password', '123456', '12345678', 'qwerty', 'abc123', 
        'password123', 'admin', 'letmein', 'welcome', 'monkey'
    ];
    
    if (in_array(strtolower($password), $common_passwords)) {
        $errors[] = "Password is too common. Please choose a more secure password";
    }
    
    return $errors;
}

function sanitize_user_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_user_input', $data);
    }
    
    // Remove whitespace and normalize
    $data = trim($data);
    
    // Remove null bytes
    $data = str_replace(chr(0), '', $data);
    
    // Sanitize HTML
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    
    return $data;
}

function validate_employee_id_format($employee_id) {
    // Employee ID should be alphanumeric and between 3-20 characters
    if (!preg_match('/^[a-zA-Z0-9]{3,20}$/', $employee_id)) {
        return "Employee ID must be 3-20 characters long and contain only letters and numbers";
    }
    return true;
}

function validate_name_format($name) {
    // Name should only contain letters, spaces, and common name characters
    if (!preg_match('/^[a-zA-Z\s\.\-\']{2,50}$/', $name)) {
        return "Name must be 2-50 characters long and contain only letters, spaces, periods, hyphens, and apostrophes";
    }
    return true;
}

function check_rate_limit($ip_address, $time_window = 300, $max_attempts = 5) {
    // Simple file-based rate limiting (you could use Redis or database for production)
    $rate_limit_file = sys_get_temp_dir() . '/account_creation_' . md5($ip_address) . '.txt';
    
    $current_time = time();
    $attempts = [];
    
    // Read existing attempts
    if (file_exists($rate_limit_file)) {
        $data = file_get_contents($rate_limit_file);
        $attempts = $data ? json_decode($data, true) : [];
    }
    
    // Remove old attempts outside time window
    $attempts = array_filter($attempts, function($timestamp) use ($current_time, $time_window) {
        return ($current_time - $timestamp) < $time_window;
    });
    
    // Check if rate limit exceeded
    if (count($attempts) >= $max_attempts) {
        return false;
    }
    
    // Add current attempt
    $attempts[] = $current_time;
    
    // Save updated attempts
    file_put_contents($rate_limit_file, json_encode($attempts));
    
    return true;
}

/**
 * Format availability for display with appropriate styling
 * @param string $availability The availability value from database
 * @param bool $with_icon Whether to include clock icon
 * @return string Formatted HTML for availability display
 */
function format_availability($availability, $with_icon = true) {
    $availability_options = [
        'full_time' => ['label' => 'Full-time', 'subtitle' => '40+ hrs/week'],
        'part_time' => ['label' => 'Part-time', 'subtitle' => '20-40 hrs/week'],
        'casual' => ['label' => 'Casual', 'subtitle' => '<20 hrs/week'],
        'project_based' => ['label' => 'Project-based', 'subtitle' => 'Flexible timing']
    ];
    
    // Normalize various possible stored values into canonical keys used by the UI
    $key = null;
    if ($availability === null || $availability === '') {
        $key = null;
    } elseif (is_numeric($availability)) {
        // Interpret numeric availability as hours/week and bucket conservatively
        $hours = (int)$availability;
        if ($hours >= 30) {
            $key = 'full_time';
        } elseif ($hours >= 8) {
            $key = 'part_time';
        } else {
            $key = 'casual';
        }
    } else {
        // Normalize strings like 'Full-time', 'Full time', 'full_time' to canonical keys
        $k = mb_strtolower(trim($availability));
        $k = preg_replace('/[\s\-]+/', '_', $k);
        // Common mapping corrections
        $map = [
            'full' => 'full_time',
            'full_time' => 'full_time',
            'full_time_hours' => 'full_time',
            'full-time' => 'full_time',
            'part_time' => 'part_time',
            'part-time' => 'part_time',
            'part' => 'part_time',
            'limited' => 'part_time',
            'project_based' => 'project_based',
            'project-based' => 'project_based',
            'project' => 'project_based',
            'casual' => 'casual',
            'weekends' => 'casual'
        ];
        $key = $map[$k] ?? ($k);
    }

    if (empty($key) || !isset($availability_options[$key])) {
        return '<span style="color: #9ca3af;">—</span>';
    }
    
    $option = $availability_options[$key];
    $icon = $with_icon ? '<i class="fas fa-clock"></i>' : '';
    
    return sprintf(
        '<span class="availability-badge availability-%s">%s%s<span class="availability-subtitle">(%s)</span></span>',
        htmlspecialchars($key),
        $icon,
        htmlspecialchars($option['label']),
        htmlspecialchars($option['subtitle'])
    );
}

/**
 * Format display name consistently as: "Surname, Firstname, MI."
 * - If last_name/first_name/middle_name columns exist in the array, use them.
 * - Otherwise attempt to parse full_name which may be in formats like
 *   "Surname, Firstname Middlename" or "Firstname Middlename Surname".
 * - Ensures internal spaces are preserved (multi-word surnames/firstnames).
 * @param array $userRow associative array with possible keys: last_name, first_name, middle_name, full_name
 * @return string formatted display name safe for HTML output
 */
function format_display_name($userRow) {
    // Helper to normalize spaces
    $norm = function($s) {
        if ($s === null) return '';
        $s = trim($s);
        // Collapse multiple whitespace into a single space but keep existing internal spaces
        return preg_replace('/\s+/', ' ', $s);
    };

    $last = isset($userRow['last_name']) ? $norm($userRow['last_name']) : '';
    $first = isset($userRow['first_name']) ? $norm($userRow['first_name']) : '';
    $middle = isset($userRow['middle_name']) ? $norm($userRow['middle_name']) : '';

    // Normalize capitalization to Title Case for display (keeps internal multi-word parts)
    if ($last !== '') {
        $last = mb_convert_case($last, MB_CASE_TITLE, "UTF-8");
    }
    if ($first !== '') {
        $first = mb_convert_case($first, MB_CASE_TITLE, "UTF-8");
    }
    if ($middle !== '') {
        $middle = mb_convert_case($middle, MB_CASE_TITLE, "UTF-8");
    }

    // If we have explicit last & first, prefer them
    if ($last !== '' || $first !== '') {
        $display = '';
        // Ensure at least one of last/first is present
        if ($last !== '') {
            $display .= $last;
        }
        if ($first !== '') {
            if ($display !== '') $display .= ', ';
            $display .= $first;
        }

        if ($middle !== '') {
            // Use first token of middle as initial
            $mn_parts = preg_split('/\s+/', $middle);
            $mi = strtoupper(mb_substr($mn_parts[0], 0, 1));
            $display .= ', ' . $mi . '.';
        }

        return $display ?: ($userRow['full_name'] ?? '');
    }

    // Fallback: try to parse full_name
    $full = isset($userRow['full_name']) ? $norm($userRow['full_name']) : '';
    if ($full === '') return '';

    // Common stored format: "Surname, Firstname Middlename"
    if (strpos($full, ',') !== false) {
        [$maybe_last, $rest] = array_map($norm, array_map('trim', explode(',', $full, 2)));
        // Title-case parsed name parts for consistent display
        $maybe_last = mb_convert_case($maybe_last, MB_CASE_TITLE, "UTF-8");
        $rest_parts = preg_split('/\s+/', $rest);
        $maybe_first = $rest_parts[0] ?? '';
        $maybe_middle = count($rest_parts) > 1 ? implode(' ', array_slice($rest_parts, 1)) : '';
        $maybe_first = mb_convert_case($maybe_first, MB_CASE_TITLE, "UTF-8");
        $maybe_middle = mb_convert_case($maybe_middle, MB_CASE_TITLE, "UTF-8");

        $display = $maybe_last !== '' ? $maybe_last : '';
        if ($maybe_first !== '') {
            if ($display !== '') $display .= ', ';
            $display .= $maybe_first;
        }
        if ($maybe_middle !== '') {
            $mn_parts = preg_split('/\s+/', $maybe_middle);
            $mi = strtoupper(mb_substr($mn_parts[0], 0, 1));
            $display .= ', ' . $mi . '.';
        }
        return $display;
    }

    // If full_name doesn't contain comma, try to split into tokens: assume last token is surname
    $parts = preg_split('/\s+/', $full);
    if (count($parts) === 1) return $full;
    $lastTok = array_pop($parts);
    $firstTok = array_shift($parts);
    $middleTok = count($parts) ? implode(' ', $parts) : '';

    // Title-case tokens for consistent display
    $lastTok = mb_convert_case($lastTok, MB_CASE_TITLE, "UTF-8");
    $firstTok = mb_convert_case($firstTok, MB_CASE_TITLE, "UTF-8");
    $middleTok = mb_convert_case($middleTok, MB_CASE_TITLE, "UTF-8");

    $display = $lastTok . ', ' . $firstTok;
    if ($middleTok !== '') {
        $mn_parts = preg_split('/\s+/', $middleTok);
        $mi = strtoupper(mb_substr($mn_parts[0], 0, 1));
        $display .= ', ' . $mi . '.';
    }
    return $display;
}

/**
 * Check whether a quest submission exists for a given user and quest.
 * Considers both file_path and text_content as valid submissions.
 * @param PDO|null $pdo
 * @param int $quest_id
 * @param string|int $employee_id
 * @return bool
 */
function has_submission($pdo = null, $quest_id, $employee_id) {
    if ($pdo === null) {
        global $pdo;
    }
    try {
    $stmt = $pdo->prepare("SELECT id FROM quest_submissions WHERE quest_id = ? AND employee_id = ? AND ((file_path IS NOT NULL AND file_path <> '') OR (drive_link IS NOT NULL AND drive_link <> '') OR (text_content IS NOT NULL AND text_content <> '')) LIMIT 1");
        $stmt->execute([(int)$quest_id, $employee_id]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('has_submission error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Determine whether a specific user_quest should be considered 'missed'.
 * Uses the same criteria as pending_reviews/missed_submitters: assigned (not declined/submitted), due_date passed, and no submission.
 * @param PDO|null $pdo
 * @param int $quest_id
 * @param string|int $employee_id
 * @return bool
 */
function is_user_missed($pdo = null, $quest_id, $employee_id) {
    if ($pdo === null) {
        global $pdo;
    }
    try {
                $stmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM user_quests uq
                         LEFT JOIN quests q ON q.id = uq.quest_id
                         LEFT JOIN (
                             SELECT qs1.* FROM quest_submissions qs1
                             JOIN (
                                 SELECT quest_id, employee_id, MAX(submitted_at) AS maxt
                                 FROM quest_submissions
                                 GROUP BY quest_id, employee_id
                             ) qmax ON qs1.quest_id = qmax.quest_id AND qs1.employee_id = qmax.employee_id AND qs1.submitted_at = qmax.maxt
                         ) qs ON qs.quest_id = uq.quest_id AND qs.employee_id = uq.employee_id
                         WHERE uq.quest_id = ? AND uq.employee_id = ?
                             AND (uq.status IS NULL OR uq.status NOT IN ('declined','submitted'))
                             AND q.due_date IS NOT NULL
                             AND q.due_date <> '0000-00-00 00:00:00'
                             AND (CASE WHEN TIME(q.due_date) = '00:00:00' THEN DATE_ADD(q.due_date, INTERVAL 86399 SECOND) ELSE q.due_date END) < NOW()
                             AND (
                                 qs.id IS NULL OR (
                                     (qs.file_path IS NULL OR qs.file_path = '')
                                     AND (qs.drive_link IS NULL OR qs.drive_link = '')
                                     AND (qs.text_content IS NULL OR qs.text_content = '')
                                 )
                             )"
                );
        $stmt->execute([(int)$quest_id, $employee_id]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (PDOException $e) {
        error_log('is_user_missed error: ' . $e->getMessage());
        // Fallback to PHP-side check: due_date vs now and has_submission
        try {
            $qstmt = $pdo->prepare("SELECT due_date FROM quests WHERE id = ?");
            $qstmt->execute([(int)$quest_id]);
            $row = $qstmt->fetch(PDO::FETCH_ASSOC);
            $due_date = $row['due_date'] ?? null;
            if (empty($due_date)) return false;
            $due_ts = strtotime($due_date);
            if ($due_ts !== false && date('H:i:s', $due_ts) === '00:00:00') { $due_ts += 86399; }
            if ($due_ts !== false && time() > $due_ts) {
                return !has_submission($pdo, $quest_id, $employee_id);
            }
        } catch (Exception $ex) {
            error_log('is_user_missed fallback error: ' . $ex->getMessage());
        }
        return false;
    }
}

/**
 * Return an array of missed submitters for a given quest_id. Each entry contains employee_id, full_name, status.
 * @param PDO|null $pdo
 * @param int $quest_id
 * @return array
 */
function get_missed_submitters($pdo = null, $quest_id) {
    if ($pdo === null) {
        global $pdo;
    }
    try {
                $missedStmt = $pdo->prepare(
                        "SELECT uq.employee_id, u.full_name, uq.status FROM user_quests uq
                         LEFT JOIN quests q ON q.id = uq.quest_id
                         LEFT JOIN (
                             SELECT qs1.* FROM quest_submissions qs1
                             JOIN (
                                 SELECT quest_id, employee_id, MAX(submitted_at) AS maxt
                                 FROM quest_submissions
                                 GROUP BY quest_id, employee_id
                             ) qmax ON qs1.quest_id = qmax.quest_id AND qs1.employee_id = qmax.employee_id AND qs1.submitted_at = qmax.maxt
                         ) qs ON qs.quest_id = uq.quest_id AND qs.employee_id = uq.employee_id
                         LEFT JOIN users u ON u.employee_id = uq.employee_id
                         WHERE uq.quest_id = ?
                             AND (uq.status IS NULL OR uq.status NOT IN ('declined','submitted'))
                             AND q.due_date IS NOT NULL
                             AND q.due_date <> '0000-00-00 00:00:00'
                             AND (CASE WHEN TIME(q.due_date) = '00:00:00' THEN DATE_ADD(q.due_date, INTERVAL 86399 SECOND) ELSE q.due_date END) < NOW()
                             AND (
                                 qs.id IS NULL OR (
                                     (qs.file_path IS NULL OR qs.file_path = '')
                                     AND (qs.drive_link IS NULL OR qs.drive_link = '')
                                     AND (qs.text_content IS NULL OR qs.text_content = '')
                                 )
                             )"
                );
        $missedStmt->execute([(int)$quest_id]);
        return $missedStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('get_missed_submitters error: ' . $e->getMessage());
        return [];
    }
}
?>