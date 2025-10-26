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

        // Determine whether the quest is already past due. If so, mark as 'missed'
        $dueStmt = $pdo->prepare("SELECT due_date FROM quests WHERE id = ? LIMIT 1");
        $dueStmt->execute([$quest_id]);
        $qrow = $dueStmt->fetch(PDO::FETCH_ASSOC);
        $due_date = $qrow['due_date'] ?? null;
        $statusToInsert = 'in_progress';
        if (!empty($due_date)) {
            // compute effective due datetime: treat midnight-only times as end-of-day
            $ts = strtotime($due_date);
            if ($ts !== false) {
                // if original stored time is 00:00:00, assume end of day
                $timePart = date('H:i:s', $ts);
                if ($timePart === '00:00:00') {
                    $ts += 86399; // add 23:59:59
                }
                if (time() > $ts) {
                    $statusToInsert = 'missed';
                }
            }
        }

        // Accept the quest (or mark missed if accepting after deadline)
        $stmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at) 
                             VALUES (?, ?, ?, NOW())");
        $stmt->execute([$employee_id, $quest_id, $statusToInsert]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log("Database error accepting quest: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}