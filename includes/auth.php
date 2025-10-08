<?php
require_once 'config.php';
require_once 'functions.php';

// Handle registration only
if (isset($_POST['register'])) {
    try {
        $employee_id = sanitize_input($_POST['employee_id']);
        $password = sanitize_input($_POST['password']);
        $confirm_password = sanitize_input($_POST['confirm_password']);
        $full_name = sanitize_input($_POST['full_name']);
        $email = sanitize_input($_POST['email']);
        $role = sanitize_input($_POST['role'] ?? 'participant'); // Default role updated
        
        // Validate inputs
        if (empty($employee_id) || empty($password) || empty($full_name) || empty($email)) {
            throw new Exception("All fields are required");
        }

        if ($password !== $confirm_password) {
            throw new Exception("Passwords do not match");
        }

        if (strlen($password) < 8) {
            throw new Exception("Password must be at least 8 characters");
        }

        // Check if employee ID exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        
        if ($stmt->rowCount() > 0) {
            throw new Exception("Employee ID already registered");
        }

        // Hash password and register user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (employee_id, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
        
        // Changed from hardcoded 'quest_taker' to $role variable (now participant)
        if ($stmt->execute([$employee_id, $hashed_password, $full_name, $email, $role])) {
            $_SESSION['reg_success'] = "Registration successful! Please login with your credentials.";
            header('Location: ../login.php');
            exit();
        } else {
            throw new Exception("Registration failed. Please try again.");
        }
    } catch (Exception $e) {
        $_SESSION['reg_error'] = $e->getMessage();
        $_SESSION['form_data'] = $_POST;
        header('Location: ../register.php');
        exit();
    }
}
?>