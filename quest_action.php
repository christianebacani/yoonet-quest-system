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
            if (!$uq) {
                $stmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at, started_at) VALUES (?, ?, 'in_progress', NOW(), NOW())");
                $stmt->execute([$employee_id, $quest_id]);
                echo json_encode(['success' => true, 'message' => 'Quest accepted!']);
                exit();
            } elseif ($uq['status'] === 'assigned') {
                $stmt = $pdo->prepare("UPDATE user_quests SET status = 'in_progress', started_at = NOW() WHERE employee_id = ? AND quest_id = ?");
                $stmt->execute([$employee_id, $quest_id]);
                echo json_encode(['success' => true, 'message' => 'Quest accepted!']);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'You have already interacted with this quest.']);
                exit();
            }
        } elseif ($quest_action === 'decline') {
            if (!$uq) {
                $stmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at) VALUES (?, ?, 'cancelled', NOW())");
                $stmt->execute([$employee_id, $quest_id]);
                echo json_encode(['success' => true, 'message' => 'Quest declined.']);
                exit();
            } elseif ($uq['status'] === 'assigned') {
                $stmt = $pdo->prepare("UPDATE user_quests SET status = 'cancelled' WHERE employee_id = ? AND quest_id = ?");
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
