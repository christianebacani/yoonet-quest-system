<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!is_logged_in()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

// Check if user has quest giver permissions
$role = $_SESSION['role'] ?? '';

// Simple role renaming
if ($role === 'hybrid') {
    $role = 'quest_lead';
} elseif ($role === 'quest_giver') {
    $role = 'quest_lead';
} elseif ($role === 'contributor') {
    $role = 'quest_lead';
}

if (!in_array($role, ['quest_lead'])) { // was ['quest_giver', 'hybrid']
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quest_id = $_POST['quest_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    $action = $_POST['action'] ?? '';

    if ($action !== 'update_quest_status' || !in_array($status, ['active', 'archived'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE quests SET status = ? WHERE id = ?");
        $stmt->execute([$status, $quest_id]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Database error updating quest status: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}