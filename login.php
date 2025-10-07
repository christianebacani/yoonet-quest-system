<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Clear registration messages
unset($_SESSION['reg_error']);
unset($_SESSION['form_data']);

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit();
}

// Get messages
$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$success = $_SESSION['reg_success'] ?? '';
unset($_SESSION['reg_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yoonet - Quest Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
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
            <h1>Quest Portal</h1>
            <p>Login to access your career progression quests</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
                <p class="error-help">Don't have an account? <a href="register.php">Register here</a></p>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <form action="includes/login.php" method="POST" class="login-form">
            <div class="form-group">
                <label for="employee_id">Employee ID</label>
                <input type="text" id="employee_id" name="employee_id" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" name="login" class="btn-login">Login</button>
        </form>
        
        <div class="login-footer">
            <p>New to Yoonet Quest? <a href="register.php">Register here</a></p>
        </div>
    </div>
</body>
</html>