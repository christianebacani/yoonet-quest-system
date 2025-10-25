<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

$quest_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quest_id <= 0) {
    header('Location: created_quests.php');
    exit();
}

$employee_id = $_SESSION['employee_id'] ?? null;
$full_name = $_SESSION['full_name'] ?? 'User';

// Load quest
try {
    $stmt = $pdo->prepare("SELECT q.* FROM quests q WHERE q.id = ? AND q.status <> 'deleted'");
    $stmt->execute([$quest_id]);
    $quest = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quest) {
        $_SESSION['error'] = 'Quest not found';
        header('Location: created_quests.php');
        exit();
    }

    // Always allow the creator to view their own quest, regardless of visibility
    $createdBy = $quest['created_by'] ?? null;
    $role = $_SESSION['role'] ?? '';
    $visibility = $quest['visibility'] ?? 'public';
    $is_creator = false;
    if ($createdBy !== null && $employee_id) {
        if ((string)$createdBy === (string)$employee_id) { $is_creator = true; }
        // check user_{id} format
        $stmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$employee_id]);
        $urow = $stmt->fetch(PDO::FETCH_ASSOC);
        $cur_id = $urow['id'] ?? null;
        if ($cur_id && strtolower(trim((string)$createdBy)) === strtolower('user_' . (string)$cur_id)) { $is_creator = true; }
    }
    $allowed = false;
    if ($is_creator) {
        $allowed = true;
    } elseif ($visibility === 'public') {
        $allowed = true;
    } elseif ($visibility === 'private') {
        // assigned user check
        if ($employee_id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_quests WHERE quest_id = ? AND employee_id = ?");
            $stmt->execute([$quest_id, $employee_id]);
            if ((int)$stmt->fetchColumn() > 0) { $allowed = true; }
        }
        // role override
        if (!$allowed && $role === 'learning_architect') { $allowed = true; }
    }
    if (!$allowed) {
        $_SESSION['error'] = 'You do not have permission to view this quest.';
        header('Location: dashboard.php');
        exit();
    }

    // Load quest attachments (created by creator)
    $stmt = $pdo->prepare("SELECT * FROM quest_attachments WHERE quest_id = ?");
    $stmt->execute([$quest_id]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load submissions for this quest. If the viewer is the creator or a learning_architect, show all submissions.
    // Otherwise show only submissions made by this logged-in user (employee_id or user_{id} match)
    $params = [$quest_id];
    $sql = "SELECT qs.*, u.full_name FROM quest_submissions qs LEFT JOIN users u ON qs.employee_id = u.employee_id WHERE qs.quest_id = ?";
    if (!$is_creator && ($_SESSION['role'] ?? '') !== 'learning_architect') {
        // restrict to current user's submissions
        $sql .= " AND (qs.employee_id = ? OR qs.employee_id = ?)";
        // second param is user_{id} format
        $uid = $cur_id ?? null;
        $params[] = $employee_id;
        $params[] = $uid ? 'user_' . $uid : '';
    }
    $sql .= " ORDER BY qs.submitted_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Error loading quest view: ' . $e->getMessage());
    $_SESSION['error'] = 'Error loading quest information';
    header('Location: created_quests.php');
    exit();
}

// Render page (minimal, reusing site CSS)
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>View Quest - <?php echo htmlspecialchars($quest['title']); ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/buttons.css">
<style>
.container { max-width:900px; margin:28px auto; padding:16px; }
.card { background:#fff; padding:16px; border-radius:8px; box-shadow:0 6px 20px rgba(2,6,23,0.04); }
.meta { color:#6b7280; font-size:0.95rem; }
.attachment { margin-top:8px; }
</style>
</head>
<body>
<div class="container">
    <a href="created_quests.php" class="btn btn-ghost">← Back</a>
    <h1><?php echo htmlspecialchars($quest['title']); ?></h1>
    <div class="meta">Status: <?php echo htmlspecialchars($quest['status']); ?> | Due: <?php echo !empty($quest['due_date']) ? htmlspecialchars($quest['due_date']) : '—'; ?></div>

    <div class="card" style="margin-top:12px;">
        <h3>Description</h3>
        <div><?php echo nl2br(htmlspecialchars($quest['description'])); ?></div>
    </div>

    <div class="card" style="margin-top:12px;">
        <h3>Attachments (creator uploaded)</h3>
        <?php if (empty($attachments)): ?>
            <div class="meta">No attachments uploaded by creator.</div>
        <?php else: ?>
            <?php foreach ($attachments as $att): ?>
                <div class="attachment">
                    <a href="<?php echo htmlspecialchars($att['file_path']); ?>" target="_blank"><?php echo htmlspecialchars($att['file_name']); ?></a>
                    (<?php echo round(((int)$att['file_size'])/1024,1); ?> KB)
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-top:12px;">
        <h3>Your Submissions</h3>
        <?php if (empty($submissions)): ?>
            <div class="meta">No submissions found.</div>
        <?php else: ?>
            <ul>
            <?php foreach ($submissions as $s): ?>
                <li style="margin-bottom:10px;">
                    <div><strong><?php echo htmlspecialchars($s['title'] ?? 'Submission'); ?></strong> — <?php echo htmlspecialchars($s['status']); ?>
                    <div class="meta">Submitted at: <?php echo htmlspecialchars($s['submitted_at']); ?> by <?php echo htmlspecialchars($s['full_name'] ?? $s['employee_id']); ?></div>
                    <?php if (!empty($s['file_path'])): ?>
                        <div class="attachment"><a href="<?php echo htmlspecialchars($s['file_path']); ?>" target="_blank">Download attachment</a></div>
                    <?php endif; ?>
                    <?php if (!empty($s['link'])): ?>
                        <div class="attachment"><a href="<?php echo htmlspecialchars($s['link']); ?>" target="_blank">View link</a></div>
                    <?php endif; ?>
                    <?php if (!empty($s['text'])): ?>
                        <div class="attachment"><?php echo nl2br(htmlspecialchars($s['text'])); ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
