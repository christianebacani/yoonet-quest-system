<?php
require_once 'config.php';
require_once 'functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    try {
        // Validate inputs
        $employee_id = sanitize_input($_POST['employee_id'] ?? '');
        $password = sanitize_input($_POST['password'] ?? '');
        
        if (empty($employee_id) || empty($password)) {
            throw new Exception("Both Employee ID and password are required");
        }

        // Check credentials
        $stmt = $pdo->prepare("SELECT * FROM users WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password'])) {
            throw new Exception("Invalid credentials. Please try again.");
        }

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['employee_id'] = $user['employee_id'];
    // Store a consistently formatted display name in session (Surname, Firstname, MI.)
    $_SESSION['full_name'] = format_display_name($user);
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        
        // Check if profile is completed
        $profile_completed = $user['profile_completed'] ?? false;
        
        if (!$profile_completed) {
            // New user - send to profile setup to complete their profile
            header('Location: ../profile_setup.php');
        } else {
            // Existing user - redirect to landing page
            header('Location: ../landing.php');
        }
        exit();
        
    } catch (Exception $e) {
        // Store error and redirect back to login page
        $_SESSION['login_error'] = $e->getMessage();
        header('Location: ../login.php');
        exit();
    }
}

// If someone tries to access this file directly
header('Location: ../login.php');
exit();
?>