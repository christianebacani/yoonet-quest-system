<?php
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit();
}
header('Content-Type: application/json');
$employee_id = $_SESSION['employee_id'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quest_id'], $_POST['quest_action'])) {
    $quest_id = (int)$_POST['quest_id'];
    $quest_action = $_POST['quest_action'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_quests WHERE employee_id = ? AND quest_id = ?");
        $stmt->execute([$employee_id, $quest_id]);
        $uq = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($quest_action === 'accept') {
            // Determine effective due datetime for this quest. If the due date has passed,
            // accepting will insert a 'missed' status instead of 'in_progress'.
            $dueStmt = $pdo->prepare("SELECT due_date FROM quests WHERE id = ? LIMIT 1");
            $dueStmt->execute([$quest_id]);
            $qrow = $dueStmt->fetch(PDO::FETCH_ASSOC);
            $due_date = $qrow['due_date'] ?? null;
            $statusToInsert = 'in_progress';
            $acceptedAsMissed = false;
            if (!empty($due_date)) {
                $ts = strtotime($due_date);
                if ($ts !== false) {
                    // if stored time is midnight, treat as end-of-day
                    $timePart = date('H:i:s', $ts);
                    if ($timePart === '00:00:00') {
                        $ts += 86399;
                    }
                    if (time() > $ts) {
                        $statusToInsert = 'missed';
                        $acceptedAsMissed = true;
                    }
                }
            }

            if (!$uq) {
                $stmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at, started_at) VALUES (?, ?, ?, NOW(), NOW())");
                $stmt->execute([$employee_id, $quest_id, $statusToInsert]);
                $msg = $acceptedAsMissed ? 'Quest accepted but it is past due and has been marked as Missed.' : 'Quest accepted!';
                echo json_encode(['success' => true, 'message' => $msg, 'accepted_as_missed' => $acceptedAsMissed]);
                exit();
            } elseif ($uq['status'] === 'assigned') {
                // Update to the computed status
                $stmt = $pdo->prepare("UPDATE user_quests SET status = ?, started_at = NOW() WHERE employee_id = ? AND quest_id = ?");
                $stmt->execute([$statusToInsert, $employee_id, $quest_id]);
                $msg = $acceptedAsMissed ? 'Quest accepted but it is past due and has been marked as Missed.' : 'Quest accepted!';
                echo json_encode(['success' => true, 'message' => $msg, 'accepted_as_missed' => $acceptedAsMissed]);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'You have already interacted with this quest.']);
                exit();
            }
        } elseif ($quest_action === 'decline') {
            if (!$uq) {
                $stmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at) VALUES (?, ?, 'declined', NOW())");
                $stmt->execute([$employee_id, $quest_id]);
                echo json_encode(['success' => true, 'message' => 'Quest declined.']);
                exit();
            } elseif ($uq['status'] === 'assigned') {
                $stmt = $pdo->prepare("UPDATE user_quests SET status = 'declined' WHERE employee_id = ? AND quest_id = ?");
                $stmt->execute([$employee_id, $quest_id]);
                echo json_encode(['success' => true, 'message' => 'Quest declined.']);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'You have already interacted with this quest.']);
                exit();
            }
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error processing quest action.']);
        error_log('quest_action.php: ' . $e->getMessage());
        exit();
    }
}
echo json_encode(['success' => false, 'message' => 'Invalid request.']);
