<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Clear login messages
unset($_SESSION['login_error']);

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit();
}

// Get messages and form data
$error = $_SESSION['reg_error'] ?? '';
unset($_SESSION['reg_error']);

$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

// Define available roles
$roles = [
    'quest_taker' => 'Quest Taker',
    'quest_giver' => 'Quest Giver',
    'hybrid' => 'Hybrid (Both)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yoonet - Quest Registration</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Style to match existing input fields exactly */
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            margin: 8px 0 20px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            background-color: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
        }

        .form-group select:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
        }
    </style>
</head>

<script language="javascript" type="text/javascript">
function DisableBackButton() {
    window.history.forward();
}
DisableBackButton();
window.onload = DisableBackButton;
window.onpageshow = function(evt) { if (evt.persisted) DisableBackButton(); }
window.onunload = function() { void (0); }
</script>


<body>
    <div class="login-container">
        <div class="login-header">
            <img src="assets/images/yoonet-logo.jpg" alt="Yoonet Logo" class="logo">
            <h1>Quest Registration</h1>
            <p>Register with your Yoonet employee ID</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form action="includes/auth.php" method="POST" class="login-form">
            <div class="form-group">
                <label for="employee_id">Employee ID</label>
                <input type="text" id="employee_id" name="employee_id" 
                       value="<?php echo htmlspecialchars($form_data['employee_id'] ?? ''); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" 
                       value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password (must be 8 characters)</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            
            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="" disabled selected>Select your role</option>
                    <?php foreach ($roles as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value); ?>"
                            <?php if (isset($form_data['role']) && $form_data['role'] === $value) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" name="register" class="btn-login">Register</button>
        </form>
        
        <div class="login-footer">
            <p>Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>
</body>
</html>