<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pending_reviews.php');
    exit();
}

$submission_id = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;
$status = isset($_POST['status']) ? trim($_POST['status']) : '';
$feedback = isset($_POST['feedback']) ? trim($_POST['feedback']) : '';

if ($submission_id <= 0 || !in_array($status, ['approved','rejected','needs_revision','under_review','pending'])) {
    $_SESSION['error'] = 'Invalid request';
    header('Location: pending_reviews.php');
    exit();
}

try {
    // Update submission status and feedback
    $reviewedBy = $_SESSION['employee_id'] ?? (string)($_SESSION['user_id'] ?? '');
    $stmt = $pdo->prepare("UPDATE quest_submissions SET status = ?, feedback = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $feedback, $reviewedBy, $submission_id]);
    $_SESSION['success'] = 'Submission reviewed.';
} catch (PDOException $e) {
    error_log('pending_review_action error: ' . $e->getMessage());
    $_SESSION['error'] = 'Failed to update review.';
}

header('Location: pending_reviews.php');
exit();
