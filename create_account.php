<?php

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$role = $_SESSION['role'] ?? '';

// Simple role renaming
if ($role === 'hybrid') {
    $role = 'contributor';
} elseif ($role === 'quest_taker') {
    $role = 'participant';
} elseif ($role === 'quest_giver') {
    $role = 'contributor';
}

if (!in_array($role, ['contributor'])) { // was 'hybrid'
    echo '<div style="background:#ffecec;color:#d8000c;padding:10px;border:1px solid #d8000c;">';
    echo 'Access denied. Your role is: ' . htmlspecialchars($role) . '. Only contributors can access this page.';
    echo '</div>';
    exit;
}

$error = '';
$success = '';

// Handle account creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get user IP for rate limiting
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
    
    // Check rate limiting first
    if (!check_rate_limit($user_ip)) {
        $error = 'Too many account creation attempts. Please try again in 5 minutes.';
    } else {
        // Sanitize all inputs
        $new_employee_id = sanitize_user_input($_POST['employee_id'] ?? '');
        $new_name = sanitize_user_input($_POST['name'] ?? '');
        $new_email = sanitize_user_input($_POST['email'] ?? '');
        $new_role = sanitize_user_input($_POST['role'] ?? 'participant');
        $new_gender = sanitize_user_input($_POST['gender'] ?? '');
        $new_password = $_POST['password'] ?? ''; // Don't sanitize password as it might remove special chars
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Basic required field validation
        if (!$new_employee_id || !$new_name || !$new_email || !$new_password || !$confirm_password || !$new_gender) {
            $error = 'All fields are required.';
        }
        // Employee ID format validation
        elseif (($employee_id_validation = validate_employee_id_format($new_employee_id)) !== true) {
            $error = $employee_id_validation;
        }
        // Check if employee ID already exists
        elseif (check_employee_id_exists($new_employee_id)) {
            $error = 'Employee ID already exists. Please choose a different Employee ID.';
        }
        // Name format validation
        elseif (($name_validation = validate_name_format($new_name)) !== true) {
            $error = $name_validation;
        }
        // Enhanced email validation
        elseif (!validate_email_domain($new_email)) {
            $error = 'Invalid email address or domain. Please use a valid email address.';
        }
        // Check if email already exists
        elseif (check_email_exists($new_email)) {
            $error = 'Email address already exists. Please use a different email address.';
        }
        // Password strength validation
        elseif (!empty($password_errors = validate_password_strength($new_password))) {
            $error = 'Password requirements not met: ' . implode(', ', $password_errors);
        }
        // Password confirmation
        elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        }
        // Role validation
        elseif (!in_array($new_role, ['participant', 'contributor'])) {
            $error = 'Invalid role selected.';
        }
        // Gender validation
        elseif (!in_array($new_gender, ['male', 'female', 'other'])) {
            $error = 'Invalid gender selected.';
        }
        else {
            // Check table structure first (for debugging)
            try {
                $stmt = $pdo->query("DESCRIBE users");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                error_log("Users table columns: " . implode(', ', $columns));
            } catch (PDOException $e) {
                error_log("Error checking table structure: " . $e->getMessage());
            }
            
            // All validations passed, create the account
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $created_at = date('Y-m-d H:i:s');
            
            try {
                // Use transaction for data integrity
                $pdo->beginTransaction();
                
                // Check if gender column exists, if not, don't include it
                $has_gender_column = false;
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'gender'");
                    $has_gender_column = $stmt->rowCount() > 0;
                } catch (PDOException $e) {
                    // If this fails, assume no gender column
                    $has_gender_column = false;
                }
                
                if ($has_gender_column) {
                    $stmt = $pdo->prepare('INSERT INTO users (employee_id, full_name, email, password, role, gender, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $success = $stmt->execute([$new_employee_id, $new_name, $new_email, $hashed_password, $new_role, $new_gender, $created_at]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO users (employee_id, full_name, email, password, role, created_at) VALUES (?, ?, ?, ?, ?, ?)');
                    $success = $stmt->execute([$new_employee_id, $new_name, $new_email, $hashed_password, $new_role, $created_at]);
                }
                
                if ($success) {
                    $pdo->commit();
                    $success = 'Account created successfully!';
                    $created_user_email = $new_email;
                    $created_user_name = $new_name;
                    $created_user_id = $new_employee_id;
                    
                    // Log successful account creation
                    error_log("Account created successfully for: " . $new_email . " (Employee ID: " . $new_employee_id . ")");
                } else {
                    $pdo->rollBack();
                    $error = 'Failed to create account. Please try again.';
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                // Temporary: Show actual error for debugging
                error_log("Database error in account creation: " . $e->getMessage());
                
                if ($e->getCode() == 23000) { // Duplicate entry error
                    $error = 'Employee ID or email already exists. Please use different values.';
                } else {
                    // Temporarily show the actual error for debugging
                    $error = 'Database error: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ')';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Account</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #eef2ff 0%, #c7d2fe 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        .page-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Navigation Header */
        .nav-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .main-content {
            flex: 1;
            padding: 40px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .page-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .page-title {
            margin: 0;
            font-size: 2rem;
            font-weight: 600;
            color: #374151;
            background: linear-gradient(135deg, #4338ca, #6366f1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .card {
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 8px 32px rgba(60, 72, 88, 0.2);
            padding: 2rem;
            max-width: 400px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
        }
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4338ca, #6366f1);
            border-radius: 1rem 1rem 0 0;
        }
        .form-title {
            color: #4338ca;
            font-weight: bold;
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-label {
            display: block;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .form-input, .form-select {
            width: 100%;
            padding: 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid #cbd5e1;
            font-size: 1rem;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-input:focus, .form-select:focus {
            border-color: #6366f1;
            outline: none;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        /* Password field styling */
        .password-field {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-field .form-input {
            padding-right: 50px;
            width: 100%;
            flex: 1;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
            font-size: 14px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        .password-toggle:hover {
            color: #4338ca;
            background-color: rgba(67, 56, 202, 0.1);
        }
        .password-toggle:focus {
            outline: 2px solid #4338ca;
            outline-offset: 2px;
            color: #4338ca;
        }
        .password-toggle:active {
            transform: translateY(-50%) scale(0.95);
        }
        
        /* Ensure no button styling conflicts */
        .password-toggle i {
            pointer-events: none;
            font-size: 14px;
        }
        
        /* Prevent any duplicate or overlapping elements */
        .password-field::after,
        .password-field::before {
            display: none;
        }
        
        /* Override any default input styling that might interfere */
        .password-field .form-input::-ms-reveal,
        .password-field .form-input::-ms-clear {
            display: none;
        }
        
        /* Webkit browsers (Chrome, Safari) - hide default password reveal */
        .password-field .form-input::-webkit-credentials-auto-fill-button,
        .password-field .form-input::-webkit-strong-password-auto-fill-button {
            display: none !important;
        }
        
        /* Password strength indicator */
        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }
        .strength-bar {
            height: 4px;
            background-color: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.25rem;
        }
        .strength-fill {
            height: 100%;
            transition: width 0.3s ease, background-color 0.3s ease;
            border-radius: 2px;
        }
        .strength-weak { background-color: #ef4444; }
        .strength-medium { background-color: #f59e0b; }
        .strength-strong { background-color: #10b981; }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }
        .alert-error {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        /* Toast Notification System */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
            width: 100%;
        }

        .toast {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            border: 1px solid rgba(0, 0, 0, 0.08);
            margin-bottom: 10px;
            padding: 16px 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transform: translateX(120%);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            backdrop-filter: blur(10px);
        }

        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }

        .toast.hide {
            transform: translateX(120%);
            opacity: 0;
        }

        .toast-success {
            border-left: 4px solid #10b981;
        }

        .toast-error {
            border-left: 4px solid #ef4444;
        }

        .toast-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 14px;
            color: white;
            margin-top: 2px;
        }

        .toast-success .toast-icon {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .toast-error .toast-icon {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .toast-content {
            flex: 1;
            min-width: 0;
        }

        .toast-title {
            font-weight: 600;
            font-size: 15px;
            color: #111827;
            margin: 0 0 4px 0;
            line-height: 1.2;
        }

        .toast-message {
            font-size: 14px;
            color: #6b7280;
            margin: 0;
            line-height: 1.4;
        }

        .toast-close {
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.2s ease;
            font-size: 16px;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .toast-close:hover {
            background: #f3f4f6;
            color: #374151;
        }

        .toast-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 0 0 12px 12px;
            transition: width linear;
        }

        .toast-success .toast-progress {
            background: linear-gradient(90deg, #10b981, #059669);
        }

        .toast-error .toast-progress {
            background: linear-gradient(90deg, #ef4444, #dc2626);
        }

        /* Loading state for submit button */
        .btn-loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        /* Form disabled state */
        .form-disabled {
            pointer-events: none;
            opacity: 0.7;
        }

        /* Mobile responsiveness for toast */
        @media (max-width: 480px) {
            .toast-container {
                top: 10px;
                right: 10px;
                left: 10px;
                max-width: none;
            }

            .toast {
                padding: 14px 16px;
                transform: translateY(-120%);
            }

            .toast.show {
                transform: translateY(0);
            }

            .toast.hide {
                transform: translateY(-120%);
            }
        }
        
        /* Email Confirmation Modal Styles */
        .email-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: modalFadeIn 0.3s ease-out;
        }
        
        .email-modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
        }
        
        .email-modal-content {
            position: relative;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            animation: modalSlideIn 0.3s ease-out;
        }
        
        .email-modal-header {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 24px;
            text-align: center;
        }
        
        .email-modal-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
        
        .email-modal-header i {
            font-size: 1.8rem;
        }
        
        .email-modal-body {
            padding: 32px 24px;
        }
        
        .email-modal-body p {
            color: #374151;
            font-size: 1rem;
            line-height: 1.6;
            margin: 0 0 20px 0;
        }
        
        .email-details {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #4338ca;
        }
        
        .email-details p {
            margin: 8px 0;
            font-size: 0.95rem;
        }
        
        .email-details strong {
            color: #1f2937;
            display: inline-block;
            min-width: 100px;
        }
        
        .email-modal-warning {
            background: #fef3cd;
            border: 1px solid #fbbf24;
            border-radius: 8px;
            padding: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            color: #92400e;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .email-modal-warning i {
            color: #f59e0b;
            margin-top: 2px;
            flex-shrink: 0;
        }
        
        .email-modal-footer {
            padding: 20px 24px;
            background: #f9fafb;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            border-top: 1px solid #e5e7eb;
        }
        
        .email-modal-footer .btn {
            min-width: 140px;
            padding: 12px 20px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .email-modal-footer .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
        }
        
        .email-modal-footer .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }
        
        .email-modal-footer .btn-primary {
            background: linear-gradient(135deg, #4338ca, #6366f1);
            color: white;
            border: none;
        }
        
        .email-modal-footer .btn-primary:hover {
            background: linear-gradient(135deg, #3730a3, #4f46e5);
            transform: translateY(-1px);
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        @keyframes modalSlideIn {
            from {
                transform: scale(0.95) translateY(-20px);
                opacity: 0;
            }
            to {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }
        
        /* Mobile responsiveness for modal */
        @media (max-width: 640px) {
            .email-modal-content {
                width: 95%;
                margin: 20px;
            }
            
            .email-modal-header {
                padding: 20px;
            }
            
            .email-modal-header h3 {
                font-size: 1.25rem;
            }
            
            .email-modal-body {
                padding: 24px 20px;
            }
            
            .email-modal-footer {
                padding: 16px 20px;
                flex-direction: column;
            }
            
            .email-modal-footer .btn {
                width: 100%;
                min-width: auto;
            }
        }
        
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.95rem;
            color: #6b7280;
        }
        .login-link a {
            color: #6366f1;
            text-decoration: underline;
            font-weight: 500;
        }
        @media (max-width: 768px) {
            .main-content {
                padding: 20px 15px;
            }
            .card { 
                padding: 1.5rem; 
                margin: 0 10px;
            }
            .page-title {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 480px) {
            .nav-header {
                padding: 12px 16px;
            }
            .card {
                padding: 1.25rem;
            }
            .form-title {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="page-container">
        <!-- Navigation Header -->
        <header class="nav-header">
            <div class="nav-left">
                <a href="dashboard.php" class="btn btn-navigation btn-back">
                    <i class="fas fa-arrow-left btn-icon"></i>
                    <span class="btn-text">Back to Dashboard</span>
                </a>
            </div>
            <div class="nav-right">
                <h1 class="nav-title">Create New Account</h1>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="page-header">
                <h2 class="page-title">Add New Team Member</h2>
            </div>
            
            <div class="card">
            <div class="card">
                <h3 class="form-title">Account Details</h3>
                
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="post" id="createAccountForm">
            <div class="form-group">
                <label for="employee_id" class="form-label">Employee ID</label>
                <input type="text" name="employee_id" id="employee_id" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="name" class="form-label">Full Name</label>
                <input type="text" name="name" id="name" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="email" class="form-label">Email</label>
                <input type="email" name="email" id="email" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="role" class="form-label">Role</label>
                <select name="role" id="role" class="form-select">
                    <option value="participant">Participant</option>
                    <option value="contributor">Contributor</option>
                </select>
            </div>
            <div class="form-group">
                <label for="gender" class="form-label">Gender</label>
                <select name="gender" id="gender" class="form-select" required>
                    <option value="">Select Gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="password-field">
                    <input type="password" name="password" id="password" class="form-input" required minlength="6">
                    <button type="button" class="password-toggle" onclick="togglePassword('password')" aria-label="Toggle password visibility">
                        <i class="fas fa-eye" id="password-icon"></i>
                    </button>
                </div>
                <div class="password-strength" id="password-strength">
                    <div class="strength-bar">
                        <div class="strength-fill" id="strength-fill"></div>
                    </div>
                    <div class="strength-text" id="strength-text"></div>
                </div>
            </div>
            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <div class="password-field">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-input" required minlength="6">
                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')" aria-label="Toggle confirm password visibility">
                        <i class="fas fa-eye" id="confirm_password-icon"></i>
                    </button>
                </div>
                <div id="password-match" class="mt-2 text-sm"></div>
            </div>
            
            <div class="form-actions form-actions-center">
                <button type="submit" class="btn btn-primary btn-full" id="submitBtn">
                    <i class="fas fa-user-plus"></i>
                    Create Account
                </button>
            </div>
        </form>
            </div>
        </main>

        <!-- Email Confirmation Modal -->
        <div id="emailConfirmationModal" class="email-modal" style="display: none;">
            <div class="email-modal-overlay"></div>
            <div class="email-modal-content">
                <div class="email-modal-header">
                    <h3><i class="fas fa-check-circle"></i> Account Created Successfully!</h3>
                </div>
                <div class="email-modal-body">
                    <p>The account has been created successfully. Would you like to open Gmail to compose an email with the account details?</p>
                    <div class="email-details">
                        <p><strong>Name:</strong> <span id="modalUserName"></span></p>
                        <p><strong>Email:</strong> <span id="modalUserEmail"></span></p>
                        <p><strong>Employee ID:</strong> <span id="modalUserId"></span></p>
                    </div>
                    <div class="email-modal-warning">
                        <i class="fas fa-info-circle"></i>
                        <span>This will open Gmail in a new tab with pre-filled login credentials including the temporary password. You can review and send the email manually.</span>
                    </div>
                </div>
                <div class="email-modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEmailModal()">
                        <i class="fas fa-times"></i> No, Skip Email
                    </button>
                    <button type="button" class="btn btn-primary" onclick="sendWelcomeEmail()" id="sendEmailBtn">
                        <i class="fas fa-external-link-alt"></i> Open Gmail to Send
                    </button>
                </div>
            </div>
        </div>

        <!-- Toast Container -->
        <div class="toast-container" id="toastContainer"></div>
    </div>

    <script>
        // Toast Notification System
        class ToastManager {
            constructor() {
                this.container = document.getElementById('toastContainer');
                this.toasts = [];
            }

            show(type, title, message, duration = 5000) {
                const toast = this.createToast(type, title, message, duration);
                this.container.appendChild(toast);
                this.toasts.push(toast);

                // Trigger animation
                setTimeout(() => toast.classList.add('show'), 10);

                // Auto dismiss
                if (duration > 0) {
                    setTimeout(() => this.dismiss(toast), duration);
                }

                return toast;
            }

            createToast(type, title, message, duration) {
                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;
                toast.setAttribute('role', 'alert');
                toast.setAttribute('aria-live', 'polite');

                const iconClass = type === 'success' ? 'fas fa-check' : 'fas fa-exclamation-triangle';

                toast.innerHTML = `
                    <div class="toast-icon">
                        <i class="${iconClass}"></i>
                    </div>
                    <div class="toast-content">
                        <div class="toast-title">${title}</div>
                        <div class="toast-message">${message}</div>
                    </div>
                    <button class="toast-close" onclick="toastManager.dismiss(this.parentElement)" aria-label="Close notification">
                        <i class="fas fa-times"></i>
                    </button>
                `;

                // Add progress bar for timed toasts
                if (duration > 0) {
                    const progress = document.createElement('div');
                    progress.className = 'toast-progress';
                    progress.style.width = '100%';
                    progress.style.animationDuration = `${duration}ms`;
                    toast.appendChild(progress);

                    setTimeout(() => {
                        progress.style.width = '0%';
                        progress.style.transition = `width ${duration}ms linear`;
                    }, 10);
                }

                return toast;
            }

            dismiss(toast) {
                toast.classList.add('hide');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                    this.toasts = this.toasts.filter(t => t !== toast);
                }, 400);
            }

            success(title, message, duration = 5000) {
                return this.show('success', title, message, duration);
            }

            error(title, message, duration = 8000) {
                return this.show('error', title, message, duration);
            }
        }

        // Initialize toast manager
        const toastManager = new ToastManager();

        // Password visibility toggle
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '-icon');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                field.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // Enhanced password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            let text = '';
            let requirements = [];
            
            // Check each requirement
            if (password.length >= 8) {
                strength++;
            } else {
                requirements.push('8+ characters');
            }
            
            if (password.match(/[a-z]/)) {
                strength++;
            } else {
                requirements.push('lowercase letter');
            }
            
            if (password.match(/[A-Z]/)) {
                strength++;
            } else {
                requirements.push('uppercase letter');
            }
            
            if (password.match(/[0-9]/)) {
                strength++;
            } else {
                requirements.push('number');
            }
            
            if (password.match(/[^a-zA-Z0-9]/)) {
                strength++;
            } else {
                requirements.push('special character');
            }
            
            // Check for common passwords
            const commonPasswords = ['password', '123456', '12345678', 'qwerty', 'abc123', 'password123', 'admin', 'letmein', 'welcome', 'monkey'];
            if (commonPasswords.includes(password.toLowerCase())) {
                strength = Math.max(0, strength - 2);
                requirements.push('avoid common passwords');
            }
            
            const fill = document.getElementById('strength-fill');
            const textEl = document.getElementById('strength-text');
            
            if (password.length === 0) {
                fill.style.width = '0%';
                textEl.textContent = '';
                fill.className = 'strength-fill';
                return;
            }
            
            // Update display based on strength
            if (strength <= 2) {
                fill.style.width = '25%';
                fill.className = 'strength-fill strength-weak';
                text = 'Weak - Missing: ' + requirements.join(', ');
            } else if (strength === 3) {
                fill.style.width = '50%';
                fill.className = 'strength-fill strength-medium';
                text = 'Fair - Missing: ' + requirements.join(', ');
            } else if (strength === 4) {
                fill.style.width = '75%';
                fill.className = 'strength-fill strength-medium';
                text = 'Good - Missing: ' + requirements.join(', ');
            } else if (strength === 5) {
                fill.style.width = '100%';
                fill.className = 'strength-fill strength-strong';
                text = 'Strong password';
            }
            
            textEl.textContent = text;
        }

        // Password match checker
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchDiv = document.getElementById('password-match');
            
            if (confirmPassword.length === 0) {
                matchDiv.textContent = '';
                matchDiv.className = 'mt-2 text-sm';
                return;
            }
            
            if (password === confirmPassword) {
                matchDiv.textContent = '✓ Passwords match';
                matchDiv.className = 'mt-2 text-sm text-green-600';
            } else {
                matchDiv.textContent = '✗ Passwords do not match';
                matchDiv.className = 'mt-2 text-sm text-red-600';
            }
        }

        // Form loading state management
        function setFormLoading(loading) {
            const form = document.getElementById('createAccountForm');
            const submitBtn = document.getElementById('submitBtn');
            
            if (loading) {
                form.classList.add('form-disabled');
                submitBtn.classList.add('btn-loading');
                submitBtn.disabled = true;
            } else {
                form.classList.remove('form-disabled');
                submitBtn.classList.remove('btn-loading');
                submitBtn.disabled = false;
            }
        }

        // Redirect with countdown
        function redirectToDashboard() {
            let countdown = 3;
            const toast = toastManager.success(
                'Success!', 
                `Account created successfully! Redirecting to dashboard in ${countdown} seconds...`,
                0 // Don't auto-dismiss
            );

            const interval = setInterval(() => {
                countdown--;
                if (countdown > 0) {
                    const messageEl = toast.querySelector('.toast-message');
                    messageEl.textContent = `Account created successfully! Redirecting to dashboard in ${countdown} seconds...`;
                } else {
                    clearInterval(interval);
                    window.location.href = 'dashboard.php';
                }
            }, 1000);

            // Allow manual close to cancel redirect
            const closeBtn = toast.querySelector('.toast-close');
            closeBtn.addEventListener('click', () => {
                clearInterval(interval);
            });
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            const passwordField = document.getElementById('password');
            const confirmPasswordField = document.getElementById('confirm_password');
            const form = document.getElementById('createAccountForm');
            
            passwordField.addEventListener('input', function() {
                checkPasswordStrength(this.value);
                checkPasswordMatch();
            });
            
            confirmPasswordField.addEventListener('input', checkPasswordMatch);
            
            // Enhanced form submission with comprehensive validation
            form.addEventListener('submit', function(e) {
                const employeeId = document.getElementById('employee_id').value.trim();
                const name = document.getElementById('name').value.trim();
                const email = document.getElementById('email').value.trim();
                const password = passwordField.value;
                const confirmPassword = confirmPasswordField.value;
                const role = document.getElementById('role').value;
                const gender = document.getElementById('gender').value;
                
                let validationErrors = [];
                
                // Check required fields
                if (!employeeId) validationErrors.push('Employee ID is required');
                if (!name) validationErrors.push('Full Name is required');
                if (!email) validationErrors.push('Email is required');
                if (!password) validationErrors.push('Password is required');
                if (!confirmPassword) validationErrors.push('Confirm Password is required');
                if (!gender) validationErrors.push('Gender is required');
                
                // Employee ID format validation
                if (employeeId && !/^[a-zA-Z0-9]{3,20}$/.test(employeeId)) {
                    validationErrors.push('Employee ID must be 3-20 characters and contain only letters and numbers');
                }
                
                // Name format validation
                if (name && !/^[a-zA-Z\s\.\-\']{2,50}$/.test(name)) {
                    validationErrors.push('Name must be 2-50 characters and contain only letters, spaces, periods, hyphens, and apostrophes');
                }
                
                // Email format validation
                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    validationErrors.push('Please enter a valid email address');
                }
                
                // Password strength validation
                if (password) {
                    if (password.length < 8) validationErrors.push('Password must be at least 8 characters long');
                    if (!/[a-z]/.test(password)) validationErrors.push('Password must contain at least one lowercase letter');
                    if (!/[A-Z]/.test(password)) validationErrors.push('Password must contain at least one uppercase letter');
                    if (!/[0-9]/.test(password)) validationErrors.push('Password must contain at least one number');
                    if (!/[^a-zA-Z0-9]/.test(password)) validationErrors.push('Password must contain at least one special character');
                    
                    // Check for common passwords
                    const commonPasswords = ['password', '123456', '12345678', 'qwerty', 'abc123', 'password123', 'admin', 'letmein', 'welcome', 'monkey'];
                    if (commonPasswords.includes(password.toLowerCase())) {
                        validationErrors.push('Password is too common, please choose a more secure password');
                    }
                }
                
                // Password confirmation
                if (password !== confirmPassword) {
                    validationErrors.push('Passwords do not match');
                }
                
                // Role validation
                if (role && !['participant', 'contributor'].includes(role)) {
                    validationErrors.push('Invalid role selected');
                }
                
                // Gender validation
                if (gender && !['male', 'female', 'other'].includes(gender)) {
                    validationErrors.push('Invalid gender selected');
                }
                
                // If there are validation errors, show them and prevent submission
                if (validationErrors.length > 0) {
                    e.preventDefault();
                    toastManager.error('Validation Errors', validationErrors.join('. '));
                    return;
                }

                // Show loading state
                setFormLoading(true);
            });

            // Check for success message from PHP
            <?php if ($success): ?>
                // Show modal instead of redirecting immediately
                setTimeout(() => {
                    showEmailConfirmationModal(
                        '<?php echo addslashes($created_user_name ?? ''); ?>',
                        '<?php echo addslashes($created_user_email ?? ''); ?>',
                        '<?php echo addslashes($created_user_id ?? ''); ?>',
                        '<?php echo addslashes($new_password ?? ''); ?>'
                    );
                }, 100);
            <?php endif; ?>
        });
        
        // Email Modal Functions
        function showEmailConfirmationModal(userName, userEmail, userId, userPassword) {
            const modal = document.getElementById('emailConfirmationModal');
            document.getElementById('modalUserName').textContent = userName;
            document.getElementById('modalUserEmail').textContent = userEmail;
            document.getElementById('modalUserId').textContent = userId;
            
            // Store password for potential email sending
            modal.dataset.userPassword = userPassword;
            modal.dataset.userName = userName;
            modal.dataset.userEmail = userEmail;
            modal.dataset.userId = userId;
            
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Show success toast
            toastManager.success('Account Created!', 'Account has been created successfully for ' + userName);
        }
        
        function closeEmailModal() {
            const modal = document.getElementById('emailConfirmationModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Redirect to dashboard after closing modal
            setTimeout(() => {
                redirectToDashboard();
            }, 500);
        }
        
        function sendWelcomeEmail() {
            const modal = document.getElementById('emailConfirmationModal');
            const userName = modal.dataset.userName;
            const userEmail = modal.dataset.userEmail;
            const userId = modal.dataset.userId;
            const userPassword = modal.dataset.userPassword;
            
            // Create email content
            const subject = `Welcome to YooNet Quest System - Your Account Details`;
            
            const emailBody = `Hello ${userName},

Your account has been successfully created in the YooNet Quest System. Below are your login credentials:

== YOUR ACCOUNT DETAILS ==
Employee ID: ${userId}
Email: ${userEmail}
Temporary Password: ${userPassword}

IMPORTANT: For security reasons, please change your password after your first login.

You can now access the Quest System and start participating in quests, earning points, and tracking your progress.

Login at: http://localhost/yoonet-quest-system/login.php

If you have any questions or need assistance, please contact your system administrator.

Best regards,
YooNet Quest System Team

---
This email contains sensitive login information. Please keep it secure and delete it after you've changed your password.`;

            // Encode the email parameters for Gmail URL
            const gmailUrl = `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(userEmail)}&su=${encodeURIComponent(subject)}&body=${encodeURIComponent(emailBody)}`;
            
            // Open Gmail compose in new tab
            window.open(gmailUrl, '_blank');
            
            // Show success message and close modal
            toastManager.success('Gmail Opened!', 'Gmail compose window opened. Please review and send the email manually.');
            
            // Close modal after a short delay
            setTimeout(() => {
                closeEmailModal();
            }, 1500);
        }
        
        // Close modal when clicking overlay
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('email-modal-overlay')) {
                closeEmailModal();
            }
        });
        
        // Handle escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('emailConfirmationModal');
                if (modal.style.display === 'flex') {
                    closeEmailModal();
                }
            }
        });
    </script>
</body>
</html>