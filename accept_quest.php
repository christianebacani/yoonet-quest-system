<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!is_logged_in()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

// Check if user has quest taker permissions
$role = $_SESSION['role'] ?? '';

// Simple role renaming
if ($role === 'quest_taker') {
    $role = 'participant';
} elseif ($role === 'hybrid') {
    $role = 'contributor';
} elseif ($role === 'quest_giver') {
    $role = 'contributor';
}

if (!in_array($role, ['participant', 'contributor'])) { // was ['quest_taker', 'hybrid']
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quest_id = $_POST['quest_id'] ?? 0;
    $employee_id = $_SESSION['employee_id'] ?? 0;

    try {
        // Check if quest exists and is active
        $stmt = $pdo->prepare("SELECT id FROM quests WHERE id = ? AND status = 'active'");
        $stmt->execute([$quest_id]);
        $quest = $stmt->fetch();
        
        if (!$quest) {
            echo json_encode(['success' => false, 'error' => 'Quest not available']);
            exit();
        }

        // Check if user already has this quest (in any status)
        $stmt = $pdo->prepare("SELECT id FROM user_quests WHERE employee_id = ? AND quest_id = ?");
        $stmt->execute([$employee_id, $quest_id]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            echo json_encode(['success' => false, 'error' => 'You already have this quest']);
            exit();
        }

        // Accept the quest
        $stmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at) 
                             VALUES (?, ?, 'in_progress', NOW())");
        $stmt->execute([$employee_id, $quest_id]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Database error accepting quest: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}