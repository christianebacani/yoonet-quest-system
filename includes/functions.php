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
?>