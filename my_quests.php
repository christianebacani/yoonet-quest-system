
<?php

require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
        session_start();
}
if (!is_logged_in()) {
        header('Location: login.php');
        exit();
}
$employee_id = $_SESSION['employee_id'] ?? '';
$user_id = $_SESSION['user_id'] ?? '';
$role = $_SESSION['role'] ?? '';
if ($role === 'quest_taker' || $role === 'participant') {
        $role = 'skill_associate';
}

// Fetch all active quests for this user: (1) accepted non-mandatory (in_progress), (2) assigned mandatory (status 'assigned'), (3) in_progress mandatory
$my_quests = [];
try {
    // Exclude 'assigned' status here: assigned quests should be shown in Open Quests until the user accepts
    $sql = "SELECT uq.*, q.title, q.description, q.due_date, q.quest_assignment_type, q.id as quest_id FROM user_quests uq JOIN quests q ON uq.quest_id = q.id WHERE uq.employee_id = ? AND uq.status IN ('in_progress','accepted','started','submitted','completed') AND q.status = 'active' ORDER BY q.due_date ASC, q.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$employee_id]);
    $my_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // DEBUG: Show row count
    if (isset($_GET['debug'])) {
        echo '<pre style="background:#222;color:#fff;padding:16px;">';
        echo "<b>DEBUG: my_quests query rowCount()</b> ";
        echo $stmt->rowCount() . "\n";
        echo "<b>DEBUG: my_quests SQL</b>\n";
        echo $sql . "\n";
        echo '</pre>';
    }
    // For each quest, fetch skills and tiers
    foreach ($my_quests as &$q) {
        $q['skills'] = [];
    $stmt2 = $pdo->prepare("SELECT cs.skill_name FROM quest_skills qs JOIN comprehensive_skills cs ON qs.skill_id = cs.id WHERE qs.quest_id = ?");
    $stmt2->execute([$q['quest_id']]);
    $q['skills'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($q);
} catch (PDOException $e) {
    $my_quests = [];
    if (isset($_GET['debug'])) {
        echo '<pre style="background:#222;color:#fff;padding:16px;">';
        echo "<b>DEBUG: SQL Exception</b>\n";
        echo $e->getMessage();
        echo '</pre>';
    }
}
// DEBUG: Show raw $my_quests and all user_quests for troubleshooting
if (isset($_GET['debug'])) {
    echo '<pre style="background:#222;color:#fff;padding:16px;">';
    echo "<b>DEBUG: Raw my_quests array</b>\n";
    var_dump($my_quests);

    // Show all user_quests for this employee_id
    global $pdo;
    $stmt_dbg = $pdo->prepare("SELECT * FROM user_quests WHERE employee_id = ? ORDER BY quest_id DESC");
    $stmt_dbg->execute([$employee_id]);
    $all_user_quests = $stmt_dbg->fetchAll(PDO::FETCH_ASSOC);
    echo "\n<b>DEBUG: All user_quests for employee_id $employee_id</b>\n";
    var_dump($all_user_quests);

    // Show the quest row for quest_id 60
    $stmt_q = $pdo->prepare("SELECT * FROM quests WHERE id = 60");
    $stmt_q->execute();
    $quest_row = $stmt_q->fetch(PDO::FETCH_ASSOC);
    echo "\n<b>DEBUG: quests row for quest_id 60</b>\n";
    var_dump($quest_row);
    echo '</pre>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Quests</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .container { max-width: 1100px; margin: 28px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 0.98em; font-weight: 600; letter-spacing: 0.01em; }
        .badge-quest { background: #e0e7ff; color: #3730a3; border: 1px solid #6366f1; }
        .badge-due { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }
        .topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
        .btn { display:inline-block; padding: 8px 12px; border-radius: 6px; background:#4F46E5; color:#fff; text-decoration:none; border:none; font-weight:600; transition:background 0.2s; cursor:pointer; }
        .btn:hover { background:#3737b8; color:#fff; }
        .empty { text-align:center; color:#6B7280; padding:40px; font-size:1.1rem; }
        table { width:100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #eee; padding: 10px; text-align:left; font-size: 1rem; }
        th { background: #f9fafb; font-size: 14px; font-weight: 600; color: #374151; }
        tr:last-child td { border-bottom: none; }
        @media (max-width: 600px) {
            .container { padding: 0 2vw; }
            .card { padding: 10px; }
            th, td { padding: 8px 4px; font-size: 0.95rem; }
            .btn { padding: 8px 12px; font-size:0.95rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="topbar" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
            <h2 style="font-size:2rem;font-weight:700;color:#222;margin:0;">My Quests</h2>
            <a class="btn" href="dashboard.php" style="background:#6366f1;padding:12px 28px;font-size:1.1em;border-radius:10px;box-shadow:0 2px 8px #6366f133;">Back to Dashboard</a>
        </div>
        <?php if (count($my_quests) > 0): ?>
            <div class="card" style="padding:0 0 18px 0;box-shadow:0 2px 8px #e0e7ef33;border-radius:14px;">
                <table style="width:100%;border-collapse:separate;border-spacing:0 0.5em;">
                    <thead>
                        <tr style="background:#f9fafb;">
                            <th style="padding:12px 8px;font-size:1em;font-weight:700;color:#222;border:none;text-align:left;">Quest</th>
                            <th style="padding:12px 8px;font-size:1em;font-weight:700;color:#222;border:none;text-align:left;">Description</th>
                            
                            <th style="padding:12px 8px;font-size:1em;font-weight:700;color:#222;border:none;text-align:left;">Status</th>
                            <th style="padding:12px 8px;font-size:1em;font-weight:700;color:#222;border:none;text-align:left;">Due Date</th>
                            <th style="padding:12px 8px;font-size:1em;font-weight:700;color:#222;border:none;text-align:left;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($my_quests as $q): ?>
                        <?php
                        $stmtSub = $pdo->prepare("SELECT * FROM quest_submissions WHERE quest_id = ? AND employee_id = ? ORDER BY submitted_at DESC LIMIT 1");
                        $stmtSub->execute([$q['quest_id'], $employee_id]);
                        $submission = $stmtSub->fetch(PDO::FETCH_ASSOC);
                        $status = strtolower($q['status']);
                        $subStatus = strtolower($submission['status'] ?? '');
                        $uqStatus = strtolower($q['status']);
                        $isGraded = in_array($subStatus, ['approved','graded','rejected']);
                        $isSubmitted = (!empty($submission) && in_array($subStatus, ['submitted','pending','needs_revision','approved','graded','rejected'])) || $uqStatus === 'submitted';
                        ?>
                        <tr style="background:#fff;border-radius:10px;box-shadow:0 1px 4px #e0e7ef11;">
                            <td style="padding:10px 8px;font-weight:600;color:#3730a3;max-width:180px;vertical-align:middle;">
                                <?php echo htmlspecialchars($q['title'] ?? 'Untitled'); ?>
                            </td>
                            <td style="padding:10px 8px;color:#334155;max-width:260px;vertical-align:middle;">
                                <?php echo htmlspecialchars($q['description'] ?? '—'); ?>
                            </td>

                            <td style="padding:10px 8px;vertical-align:middle;">
                                <?php
                                if ($isGraded) {
                                    echo '<span style="background:#f0fdf4;color:#166534;border:1.5px solid #34d399;border-radius:12px;padding:5px 16px;font-size:0.98em;font-weight:600;">Graded</span>';
                                } elseif ($isSubmitted) {
                                    echo '<span style="background:#fef9c3;color:#92400e;border:1.5px solid #fde68a;border-radius:12px;padding:5px 16px;font-size:0.98em;font-weight:600;">Submitted</span>';
                                } else {
                                    echo '<span style="background:#e0e7ff;color:#3730a3;border:1.5px solid #6366f1;border-radius:12px;padding:5px 16px;font-size:0.98em;font-weight:600;">Pending</span>';
                                }
                                ?>
                            </td>
                            <td style="padding:10px 8px;vertical-align:middle;">
                                <span style="background:#fef3c7;color:#92400e;border:1.5px solid #f59e0b;border-radius:12px;padding:5px 16px;font-size:0.98em;font-weight:600;display:inline-block;min-width:90px;text-align:center;">
                                    <?php echo !empty($q['due_date']) ? htmlspecialchars(date('Y-m-d', strtotime($q['due_date']))) : '—'; ?>
                                </span>
                            </td>
                            <td style="padding:10px 8px;vertical-align:middle;">
                                <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start;">
                                <?php
                                if (!$isSubmitted) {
                                    echo '<a class="btn" href="submit_quest.php?quest_id=' . (int)$q['quest_id'] . '" style="background:linear-gradient(90deg,#6366f1 0%,#4f46e5 100%);color:#fff;font-weight:600;font-size:0.98em;padding:7px 18px;border-radius:8px;box-shadow:0 2px 8px #6366f133;">Submit</a>';
                                } elseif ($isGraded) {
                                    // Graded: show View Submission and View Grade
                                    echo '<a class="btn" href="view_submission.php?submission_id=' . (int)($submission['id'] ?? 0) . '" style="background:linear-gradient(90deg,#6366f1 0%,#4f46e5 100%);color:#fff;font-weight:600;font-size:0.98em;padding:7px 18px;border-radius:8px;box-shadow:0 2px 8px #6366f133;">View Submission</a>';
                                    echo '<a class="btn" href="view_grade.php?submission_id=' . (int)($submission['id'] ?? 0) . '" style="background:#f59e42;color:#fff;font-weight:600;font-size:0.98em;padding:7px 18px;border-radius:8px;box-shadow:0 2px 8px #f59e4233;">View Grade</a>';
                                } elseif (!empty($submission['id'])) {
                                    // Submitted but not graded: show View Submission and Edit Submission
                                    echo '<a class="btn" href="view_submission.php?submission_id=' . (int)($submission['id']) . '" style="background:linear-gradient(90deg,#6366f1 0%,#4f46e5 100%);color:#fff;font-weight:600;font-size:0.98em;padding:7px 18px;border-radius:8px;box-shadow:0 2px 8px #6366f133;">View Submission</a>';
                                    echo '<a class="btn" href="edit_submission.php?submission_id=' . (int)($submission['id']) . '" style="background:linear-gradient(90deg,#6366f1 0%,#4f46e5 100%);color:#fff;font-weight:600;font-size:0.98em;padding:7px 18px;border-radius:8px;box-shadow:0 2px 8px #6366f133;">Edit Submission</a>';
                                } else {
                                    echo '<span class="btn" style="background:#e5e7eb;color:#6366f1;cursor:not-allowed;font-weight:600;font-size:0.98em;padding:7px 18px;border-radius:8px;">Submitted</span>';
                                }
                                ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="card empty" style="text-align:center;padding:48px 0;">
                <p style="font-size:1.2em;color:#6B7280;">No quests found.</p>
                <p class="meta" style="color:#64748b;">Once a quest taker accepts a non-mandatory quest or Quest creator assigned a mandatory quest. They will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
