<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Check if setup has already been completed
$setup_complete_file = __DIR__ . '/.setup_complete';

if (file_exists($setup_complete_file)) {
    // Setup already completed, redirect to login
    header('Location: login.php');
    exit();
}

// Check if any Quest Lead accounts exist in the database
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'quest_lead'");
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        // Quest Lead already exists, create lock file and redirect
        file_put_contents($setup_complete_file, date('Y-m-d H:i:s'));
        header('Location: login.php');
        exit();
    }
} catch (PDOException $e) {
    // If there's an error checking, allow setup to proceed
}

$error = '';
$success = '';
$created_user_email = '';
$created_user_name = '';
$created_user_id = '';

// Handle setup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get user IP for rate limiting
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
    
    // Check rate limiting first
    if (!check_rate_limit($user_ip)) {
        $error = 'Too many account creation attempts. Please try again in 5 minutes.';
    } else {
        // Sanitize all inputs
        $new_employee_id = sanitize_user_input($_POST['employee_id'] ?? '');
        $new_last_name = preg_replace('/\s+/', ' ', trim($_POST['last_name'] ?? ''));
        $new_first_name = preg_replace('/\s+/', ' ', trim($_POST['first_name'] ?? ''));
        $new_middle_name = preg_replace('/\s+/', ' ', trim($_POST['middle_name'] ?? ''));
        $new_job_position = sanitize_user_input($_POST['job_position'] ?? 'customer_service_manager');
        $new_availability = sanitize_user_input($_POST['availability'] ?? '');
        
        // Build canonical full_name
        $middle_initial = '';
        if (!empty($new_middle_name)) {
            $mn_parts = preg_split('/\s+/', $new_middle_name);
            $middle_initial = strtoupper(mb_substr($mn_parts[0], 0, 1)) . '.';
        }
        $new_name = $new_last_name . ', ' . $new_first_name;
        if ($middle_initial !== '') {
            $new_name .= ', ' . $middle_initial;
        }
        
        $new_email = sanitize_user_input($_POST['email'] ?? '');
        $new_gender = sanitize_user_input($_POST['gender'] ?? '');
        $new_password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Role is always 'quest_lead' for setup
        $new_role = 'quest_lead';
        
        // Validation
        if (!$new_employee_id || !$new_last_name || !$new_first_name || !$new_middle_name || !$new_availability || !$new_email || !$new_password || !$confirm_password || !$new_gender) {
            $error = 'All fields are required.';
        }
        elseif (($employee_id_validation = validate_employee_id_format($new_employee_id)) !== true) {
            $error = $employee_id_validation;
        }
        elseif (check_employee_id_exists($new_employee_id)) {
            $error = 'Employee ID already exists.';
        }
        elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        }
        elseif (!in_array($new_availability, ['full_time','part_time','casual','project_based'])) {
            $error = 'Invalid availability selected.';
        }
        elseif (!in_array($new_gender, ['male', 'female', 'other'])) {
            $error = 'Gender is required.';
        }
        elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        }
        elseif (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        }
        elseif (!preg_match('/[A-Z]/', $new_password)) {
            $error = 'Password must contain at least one uppercase letter.';
        }
        elseif (!preg_match('/[a-z]/', $new_password)) {
            $error = 'Password must contain at least one lowercase letter.';
        }
        elseif (!preg_match('/[0-9]/', $new_password)) {
            $error = 'Password must contain at least one number.';
        }
        else {
            try {
                $pdo->beginTransaction();
                
                // Hash password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $created_at = date('Y-m-d H:i:s');
                
                // Detect availability column
                $availability_col_name = 'availability_hours';
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'availability'");
                    if ($stmt->rowCount() > 0) {
                        $availability_col_name = 'availability';
                    }
                } catch (PDOException $e) {
                    // Default to availability_hours
                }
                
                // Check if gender column exists
                $has_gender_column = false;
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'gender'");
                    if ($stmt->rowCount() > 0) {
                        $has_gender_column = true;
                    }
                } catch (PDOException $e) {
                    // Gender column does not exist
                }
                
                // Insert the first Quest Lead account
                if ($has_gender_column) {
                    $sql = 'INSERT INTO users (employee_id, full_name, email, password, role, gender, created_at, last_name, first_name, middle_name, job_position, ' . $availability_col_name . ', profile_completed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)';
                    $params = [$new_employee_id, $new_name, $new_email, $hashed_password, $new_role, $new_gender, $created_at, $new_last_name, $new_first_name, $new_middle_name, $new_job_position, $new_availability];
                } else {
                    $sql = 'INSERT INTO users (employee_id, full_name, email, password, role, created_at, last_name, first_name, middle_name, job_position, ' . $availability_col_name . ', profile_completed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)';
                    $params = [$new_employee_id, $new_name, $new_email, $hashed_password, $new_role, $created_at, $new_last_name, $new_first_name, $new_middle_name, $new_job_position, $new_availability];
                }
                
                $stmt = $pdo->prepare($sql);
                $success = $stmt->execute($params);
                
                if ($success) {
                    $pdo->commit();
                    $success = 'Setup completed successfully!';
                    $created_user_email = $new_email;
                    $created_user_name = $new_name;
                    $created_user_id = $new_employee_id;
                } else {
                    throw new Exception('Failed to create account.');
                }
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                // Log the actual error for debugging
                error_log("Database error in setup: " . $e->getMessage());
                
                // Provide user-friendly error messages based on error code
                if ($e->getCode() == 23000) { // Duplicate entry error
                    // Check which field caused the duplicate
                    if (strpos($e->getMessage(), 'email') !== false) {
                        $error = 'This email address is already in use. Please use a different email.';
                    } elseif (strpos($e->getMessage(), 'employee_id') !== false) {
                        $error = 'This Employee ID already exists. Please use a different Employee ID.';
                    } else {
                        $error = 'This information already exists in the system. Please use different values.';
                    }
                } else {
                    $error = 'Database error: Unable to create account. Please try again later.';
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
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
    <title>Yoonet - Initial Setup</title>
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
            padding: 16px 24px;
        }
        
        .nav-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-section img {
            height: 40px;
            width: auto;
        }
        
        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #4338ca, #6366f1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        main {
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
        
        /* Custom select wrapper to visually match job-position input */
        .custom-select {
            position: relative;
        }
        
        .custom-select .form-select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background: #fff;
            border-radius: 0.5rem;
            border: 1px solid #cbd5e1;
            padding: 0.75rem 2.75rem 0.75rem 0.75rem; /* leave room for chevron */
            box-shadow: 0 4px 12px rgba(60,72,88,0.06);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        
        .custom-select .form-select:focus {
            border-color: #6366f1;
            box-shadow: 0 6px 18px rgba(99,102,241,0.08);
            outline: none;
        }
        
        .select-arrow {
            color: #6b7280;
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
        
        /* Ensure no button styling conflicts */
        .password-toggle i {
            pointer-events: none;
            font-size: 14px;
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
        
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .form-actions-center {
            justify-content: center;
        }
        
        .btn {
            padding: 0.875rem 1.5rem;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            flex: 1;
        }
        
        .btn-full {
            width: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4338ca, #6366f1);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #3730a3, #4f46e5);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(67, 56, 202, 0.4);
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
            transform: translateY(-1px);
        }
        
        /* Email Confirmation Modal Styles */
        .email-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: modalFadeIn 0.3s ease-out;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .email-modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }
        
        .email-modal-content {
            position: relative;
            background: white;
            border-radius: 1rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            animation: modalSlideIn 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }
        
        @keyframes modalSlideIn {
            from { transform: translateY(-30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .email-modal-header {
            padding: 24px 20px;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #f0f9ff 0%, #eff6ff 100%);
        }
        
        .email-modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: #065f46;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .email-modal-header i {
            color: #10b981;
        }
        
        .email-modal-body {
            padding: 24px 20px;
            flex: 1;
        }
        
        .email-modal-body p {
            margin: 0 0 1.5rem 0;
            color: #374151;
            line-height: 1.6;
        }
        
        .email-details {
            background: #f9fafb;
            padding: 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e5e7eb;
        }
        
        .email-details p {
            margin: 0.5rem 0;
            color: #374151;
            font-size: 0.9rem;
        }
        
        .email-details strong {
            color: #1f2937;
        }
        
        .email-modal-warning {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            padding: 1rem;
            border-radius: 0.5rem;
            display: flex;
            gap: 10px;
            font-size: 0.9rem;
            color: #92400e;
        }
        
        .email-modal-warning i {
            flex-shrink: 0;
            color: #d97706;
        }
        
        .email-modal-footer {
            padding: 16px 20px;
            display: flex;
            gap: 10px;
            flex-direction: column;
        }
        
        .email-modal-footer .btn {
            width: 100%;
            min-width: auto;
        }
        
        .email-modal-footer .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .email-modal-footer .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .email-modal-footer .btn-primary {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .email-modal-footer .btn-primary:hover {
            background: linear-gradient(135deg, #059669, #047857);
        }
        
        @media (max-width: 768px) {
            main {
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
        <nav class="nav-header">
            <div class="nav-content">
                <div class="logo-section">
                    <img src="assets/images/yoonet-logo.jpg" alt="Yoonet Logo">
                    <span class="logo-text">Yoonet</span>
                </div>
            </div>
        </nav>

        <main>
            <div class="page-header">
                <h1 class="page-title">Initial Setup</h1>
            </div>

            <div class="card">
                <h2 class="form-title">Create First Quest Lead Account</h2>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="setup.php" id="setupForm">
                    <div class="form-group">
                        <label for="employee_id" class="form-label">Employee ID</label>
                        <input type="text" name="employee_id" id="employee_id" class="form-input" required value="<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error) || (isset($error) && strpos($error, 'Employee ID') === false)) echo htmlspecialchars($_POST['employee_id'] ?? ''); ?>" placeholder="e.g., QL001">
                    </div>

                    <div class="form-group">
                        <label for="last_name" class="form-label">Last Name</label>
                        <input type="text" name="last_name" id="last_name" class="form-input" required value="<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error) || (isset($error) && strpos($error, 'Last Name') === false)) echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="first_name" class="form-label">First Name</label>
                        <input type="text" name="first_name" id="first_name" class="form-input" required value="<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error) || (isset($error) && strpos($error, 'First Name') === false)) echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="middle_name" class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" id="middle_name" class="form-input" required value="<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error) || (isset($error) && strpos($error, 'Middle Name') === false)) echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group custom-select" style="position:relative;">
                        <label for="job_position" class="form-label">Job Position</label>
                        <select name="job_position" id="job_position" class="form-select" required>
                            <option value="">Select Job Position</option>
                            <option value="junior_customer_service_associate" <?php if (!isset($_POST['job_position']) || (isset($_POST['job_position']) && $_POST['job_position']==='junior_customer_service_associate')) echo 'selected'; ?>>Junior Customer Service Associate</option>
                            <option value="mid_level_customer_service_associate" <?php if (isset($_POST['job_position']) && $_POST['job_position']==='mid_level_customer_service_associate') echo 'selected'; ?>>Mid-level Customer Service Associate</option>
                            <option value="senior_customer_service_associate" <?php if (isset($_POST['job_position']) && $_POST['job_position']==='senior_customer_service_associate') echo 'selected'; ?>>Senior Customer Service Associate</option>
                            <option value="customer_service_team_lead" <?php if (isset($_POST['job_position']) && $_POST['job_position']==='customer_service_team_lead') echo 'selected'; ?>>Customer Service Team Lead</option>
                            <option value="customer_service_manager" <?php if (isset($_POST['job_position']) && $_POST['job_position']==='customer_service_manager') echo 'selected'; ?>>Customer Service Manager</option>
                        </select>
                    </div>

                    <div class="form-group custom-select" style="position:relative;">
                        <label for="availability" class="form-label">Employee Type</label>
                        <select name="availability" id="availability" class="form-select" required>
                            <option value="">Select Employee Type</option>
                            <option value="full_time" <?php if (isset($_POST['availability']) && $_POST['availability']==='full_time') echo 'selected'; ?>>Full Time (30+ hrs/week)</option>
                            <option value="part_time" <?php if (isset($_POST['availability']) && $_POST['availability']==='part_time') echo 'selected'; ?>>Part Time (8–29 hrs/week)</option>
                            <option value="casual" <?php if (isset($_POST['availability']) && $_POST['availability']==='casual') echo 'selected'; ?>>Casual (&lt;20 hrs/week)</option>
                            <option value="project_based" <?php if (isset($_POST['availability']) && $_POST['availability']==='project_based') echo 'selected'; ?>>Project Based (varies)</option>
                        </select>
                    </div>

                    <div class="form-group custom-select" style="position:relative;">
                        <label for="gender" class="form-label">Gender</label>
                        <select name="gender" id="gender" class="form-select" required>
                            <option value="">Select Gender</option>
                            <option value="male" <?php if (isset($_POST['gender']) && $_POST['gender']==='male') echo 'selected'; ?>>Male</option>
                            <option value="female" <?php if (isset($_POST['gender']) && $_POST['gender']==='female') echo 'selected'; ?>>Female</option>
                            <option value="other" <?php if (isset($_POST['gender']) && $_POST['gender']==='other') echo 'selected'; ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email" class="form-input" required value="<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error) || (isset($error) && strpos($error, 'email') === false)) echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <div class="password-field">
                            <input type="password" name="password" id="password" class="form-input" required placeholder="Min 8 chars, 3 of: A-Z, a-z, 0-9, symbols" title="Password must be at least 8 characters and contain at least 3 of: uppercase, lowercase, numbers, special characters">
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
                            <input type="password" name="confirm_password" id="confirm_password" class="form-input" required placeholder="Re-enter password">
                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')" aria-label="Toggle confirm password visibility">
                                <i class="fas fa-eye" id="confirm_password-icon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-actions form-actions-center">
                        <button type="submit" class="btn btn-primary btn-full" id="submitBtn">
                            <i class="fas fa-user-plus"></i> Complete Setup
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <!-- Setup Confirmation Modal -->
        <div id="setupConfirmationModal" class="email-modal" style="display: none;">
            <div class="email-modal-overlay"></div>
            <div class="email-modal-content">
                <div class="email-modal-header">
                    <h3><i class="fas fa-check-circle"></i> Setup Completed Successfully!</h3>
                </div>
                <div class="email-modal-body">
                    <p>The first Quest Lead account has been created successfully. Your system is now ready to use.</p>
                    <div class="email-details">
                        <p><strong>Name:</strong> <span id="modalUserName"></span></p>
                        <p><strong>Email:</strong> <span id="modalUserEmail"></span></p>
                        <p><strong>Employee ID:</strong> <span id="modalUserId"></span></p>
                    </div>
                    <div class="email-modal-warning">
                        <i class="fas fa-info-circle"></i>
                        <span>Please save these credentials. You will need them to log in to the system.</span>
                    </div>
                </div>
                <div class="email-modal-footer">
                    <button type="button" class="btn btn-primary" onclick="proceedToLogin()" id="proceedLoginBtn">
                        <i class="fas fa-sign-in-alt"></i> Proceed to Login
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Password toggle functionality
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '-icon');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthFill = document.getElementById('strength-fill');
        const strengthText = document.getElementById('strength-text');

        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                
                if (password.length >= 8) strength++;
                if (password.length >= 12) strength++;
                if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^a-zA-Z0-9]/.test(password)) strength++;
                
                let width = (strength / 5) * 100;
                let text = '';
                let className = '';
                
                if (strength <= 2) {
                    text = 'Weak';
                    className = 'strength-weak';
                } else if (strength <= 4) {
                    text = 'Medium';
                    className = 'strength-medium';
                } else {
                    text = 'Strong';
                    className = 'strength-strong';
                }
                
                strengthFill.style.width = width + '%';
                strengthFill.className = 'strength-fill ' + className;
                strengthText.textContent = 'Password strength: ' + text;
                strengthText.style.color = strength <= 2 ? '#ef4444' : strength <= 4 ? '#f59e0b' : '#10b981';
            });
        }

        // Show modal if setup was successful
        window.addEventListener('load', function() {
            const createdEmail = '<?php echo htmlspecialchars($created_user_email); ?>';
            if (createdEmail) {
                document.getElementById('modalUserName').textContent = '<?php echo htmlspecialchars($created_user_name); ?>';
                document.getElementById('modalUserEmail').textContent = '<?php echo htmlspecialchars($created_user_email); ?>';
                document.getElementById('modalUserId').textContent = '<?php echo htmlspecialchars($created_user_id); ?>';
                document.getElementById('setupConfirmationModal').style.display = 'flex';
            }
        });

        // Proceed to login
        function proceedToLogin() {
            // Create a marker file and redirect to login
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'setup.php';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'setup_complete';
            input.value = '1';
            form.appendChild(input);
            document.body.appendChild(form);
            
            // Redirect to login after a brief delay
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 500);
        }
    </script>
</body>
</html>
