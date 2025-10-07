<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Start session
session_start();

// Check if user is logged in and has quest giver permissions
if (!is_logged_in() || !in_array($_SESSION['role'], ['quest_giver', 'hybrid'])) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quest_id'])) {
    $quest_id = $_POST['quest_id'];
    $employee_id = $_SESSION['employee_id'];
    
    try {
        // Verify the quest belongs to the current user before deleting
        $stmt = $pdo->prepare("SELECT id FROM quests WHERE id = ? AND created_by = (SELECT id FROM users WHERE employee_id = ?)");
        $stmt->execute([$quest_id, $employee_id]);
        
        if ($stmt->fetch()) {
            // Delete the quest
            $stmt = $pdo->prepare("DELETE FROM quests WHERE id = ?");
            $stmt->execute([$quest_id]);
            
            $_SESSION['success'] = "Quest deleted successfully!";
        } else {
            $_SESSION['error'] = "You can only delete quests you created.";
        }
    } catch (PDOException $e) {
        error_log("Error deleting quest: " . $e->getMessage());
        $_SESSION['error'] = "Error deleting quest";
    }
    
    header('Location: dashboard.php');
    exit();
}