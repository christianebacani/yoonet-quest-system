
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

// Normalize role for consistency
if ($role === 'quest_taker' || $role === 'participant') {
    $role = 'skill_associate';
}

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Handle accept/decline actions
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
                $_SESSION['success'] = 'Quest accepted!';
            } elseif ($uq['status'] === 'assigned') {
                $stmt = $pdo->prepare("UPDATE user_quests SET status = 'in_progress', started_at = NOW() WHERE employee_id = ? AND quest_id = ?");
                $stmt->execute([$employee_id, $quest_id]);
                $_SESSION['success'] = 'Quest accepted!';
            } else {
                $_SESSION['error'] = 'You have already interacted with this quest.';
            }
        } elseif ($quest_action === 'decline') {
            if (!$uq) {
                $stmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at) VALUES (?, ?, 'cancelled', NOW())");
                $stmt->execute([$employee_id, $quest_id]);
                $_SESSION['success'] = 'Quest declined.';
            } elseif ($uq['status'] === 'assigned') {
                $stmt = $pdo->prepare("UPDATE user_quests SET status = 'cancelled' WHERE employee_id = ? AND quest_id = ?");
                $stmt->execute([$employee_id, $quest_id]);
                $_SESSION['success'] = 'Quest declined.';
            } else {
                $_SESSION['error'] = 'You have already interacted with this quest.';
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error processing quest action.';
        error_log('open_quests quest action: ' . $e->getMessage());
    }
    header('Location: open_quests.php');
    exit();
}



// Fetch all available quests: (1) public/optional not yet accepted/declined, (2) assigned to user and not yet accepted/declined
$available_quests = [];
try {
    // 1. Public, active, optional quests not yet accepted/declined
    $sql = "SELECT q.* FROM quests q
            WHERE q.status = 'active' AND q.visibility = 'public' AND (q.quest_assignment_type IS NULL OR q.quest_assignment_type = 'optional')
            ORDER BY q.created_at DESC";
    $stmt = $pdo->query($sql);
    $all_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Assigned quests (user_quests with status 'assigned')
    $stmt = $pdo->prepare("SELECT uq.quest_id, q.* FROM user_quests uq JOIN quests q ON uq.quest_id = q.id WHERE uq.employee_id = ? AND uq.status = 'assigned' AND q.status = 'active' ORDER BY q.created_at DESC");
    $stmt->execute([$employee_id]);
    $assigned_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all user_quests for this user (for exclusion)
    $stmt = $pdo->prepare("SELECT * FROM user_quests WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $user_quests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $user_quests_map = [];
    foreach ($user_quests as $uq) {
        $user_quests_map[$uq['quest_id']] = $uq;
    }

    // Only show public/optional quests not in user_quests (never accepted/declined/assigned)
    foreach ($all_quests as $q) {
        $qid = $q['id'];
        // Only show if no user_quests record exists for this quest
        if (!isset($user_quests_map[$qid])) {
            $available_quests[$qid] = $q;
        }
    }
    // Add assigned quests (status 'assigned' only)
    foreach ($assigned_quests as $q) {
        $qid = $q['id'];
        if (isset($user_quests_map[$qid]) && $user_quests_map[$qid]['status'] === 'assigned') {
            $available_quests[$qid] = $q;
        }
    }
    // Remove any quests that have already been accepted, declined, completed, or failed
    $available_quests = array_filter($available_quests, function($q) use ($user_quests_map) {
        $qid = $q['id'];
        if (!isset($user_quests_map[$qid])) return true;
        $status = $user_quests_map[$qid]['status'];
        return $status === 'assigned';
    });
    $available_quests = array_values($available_quests);
} catch (PDOException $e) {
    $error = 'Error loading quests.';
    error_log('open_quests fetch: ' . $e->getMessage());
}

// Fetch past quests (accepted/declined/completed/failed/cancelled)
$past_quests = [];
?>
<!DOCTYPE html>
<html lang="en">


<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Open Quests</title>
    <link rel="stylesheet" href="assets/css/style.css" />
    <style>
        .container { max-width: 1100px; margin: 28px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
        .btn { display:inline-block; padding: 8px 12px; border-radius: 6px; background:#4F46E5; color:#fff; text-decoration:none; border:none; font-weight:600; transition:background 0.2s; }
        .btn:hover { background:#3737b8; color:#fff; }
        .empty { text-align:center; color:#6B7280; padding:40px; }
        .oq-table { width:100%; border-collapse: collapse; background:#fff; border-radius:8px; overflow:hidden; }
        .oq-table th, .oq-table td { border-bottom: 1px solid #eee; padding: 10px; text-align:left; }
        .oq-table th { background: #f9fafb; font-size: 14px; font-weight: 600; color: #374151; }
        .oq-table tr:last-child td { border-bottom: none; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!empty($success)): ?>
            <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-4 rounded-lg flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <span class="text-green-800 font-semibold"><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php elseif (!empty($error)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-4 rounded-lg flex items-center">
                <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                <span class="text-red-800 font-semibold"><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        <div class="topbar">
            <h2>Open Quests</h2>
            <div>
                <a class="btn" href="dashboard.php">Back to Dashboard</a>
            </div>
        </div>
        <?php if (count($available_quests) > 0): ?>
            <div class="card">
                <table class="oq-table">
                    <thead>
                        <tr>
                            <th>Quest</th>
                            <th>Description</th>
                            <th>Due</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($available_quests as $q): ?>
                        <tr>
                            <td>
                                <span class="badge badge-quest"><?php echo htmlspecialchars($q['title'] ?? 'Untitled'); ?></span>
                            </td>
                            <td style="color:#334155;"><?php echo htmlspecialchars($q['description'] ?? '—'); ?></td>
                            <td>
                                <span class="badge badge-due">
                                    <?php echo !empty($q['due_date']) ? htmlspecialchars(date('Y-m-d', strtotime($q['due_date']))) : '—'; ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    $qid = $q['id'] ?? $q['quest_id'];
                                    $uq = $user_quests_map[$qid] ?? null;
                                ?>
                                <?php if (empty($q['quest_assignment_type']) || strtolower($q['quest_assignment_type']) === 'optional'): ?>
                                    <?php if ($uq && in_array($uq['status'], ['in_progress', 'cancelled', 'completed', 'failed', 'declined'])): ?>
                                        <?php
                                            $status = $uq['status'];
                                            $statusMap = [
                                                'in_progress' => ['Accepted', '#d1fae5', '#065f46'],
                                                'completed' => ['Completed', '#f3f4f6', '#2563eb'],
                                                'failed' => ['Failed', '#fee2e2', '#b91c1c'],
                                                'cancelled' => ['Declined', '#fef3c7', '#92400e'],
                                                'declined' => ['Declined', '#fef3c7', '#92400e'],
                                            ];
                                            $label = $statusMap[$status][0] ?? ucfirst($status);
                                            $bg = $statusMap[$status][1] ?? '#f3f4f6';
                                            $color = $statusMap[$status][2] ?? '#374151';
                                        ?>
                                        <span class="badge" style="background:<?php echo $bg; ?>;color:<?php echo $color; ?>;border:1px solid #e5e7eb;min-width:70px;display:inline-block;text-align:center;">
                                            <?php echo $label; ?>
                                        </span>
                                    <?php else: ?>
                                        <div class="quest-action-btns" data-quest-id="<?php echo (int)$qid; ?>">
                                            <button type="button" class="btn btn-accept" style="margin-right:6px;min-width:70px;">Accept</button>
                                            <button type="button" class="btn btn-decline" style="background:#f3f4f6;color:#1e3a8a;border:1px solid #cbd5e1;min-width:70px;">Decline</button>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="meta" style="color:#6366f1;font-weight:600;">Mandatory</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="card empty">
                <p>No open quests found.</p>
                <p class="meta">Once the creator assigns a quest, they will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
<script src="assets/js/script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    function showQuestMessage(msg, success) {
        let msgDiv = document.querySelector('.quest-action-message');
        if (!msgDiv) {
            msgDiv = document.createElement('div');
            msgDiv.className = 'quest-action-message';
            document.querySelector('.container').insertBefore(msgDiv, document.querySelector('.topbar'));
        }
        msgDiv.innerHTML = `<div class="${success ? 'bg-green-50 border-green-500 text-green-800' : 'bg-red-50 border-red-500 text-red-800'} border-l-4 p-4 mb-4 rounded-lg flex items-center"><i class="fas ${success ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500'} mr-3"></i><span class="font-semibold">${msg}</span></div>`;
    }
    document.querySelectorAll('.quest-action-btns').forEach(function(btns) {
        const qid = btns.getAttribute('data-quest-id');
        btns.querySelector('.btn-accept').addEventListener('click', function() {
            handleQuestAction(qid, 'accept', btns);
        });
        btns.querySelector('.btn-decline').addEventListener('click', function() {
            handleQuestAction(qid, 'decline', btns);
        });
    });
    function handleQuestAction(qid, action, btns) {
        btns.querySelectorAll('button').forEach(b => b.disabled = true);
        fetch('quest_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `quest_id=${encodeURIComponent(qid)}&quest_action=${encodeURIComponent(action)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Remove the quest row
                const row = btns.closest('tr');
                if (row) row.remove();
                showQuestMessage(data.message, true);
            } else {
                showQuestMessage(data.message, false);
                btns.querySelectorAll('button').forEach(b => b.disabled = false);
            }
        })
        .catch(() => {
            showQuestMessage('An error occurred. Please try again.', false);
            btns.querySelectorAll('button').forEach(b => b.disabled = false);
        });
    }
});
</script>
</body>
    <style>
        body { background: #f6f7fa; font-family: 'Inter', Arial, sans-serif; }
        .container { max-width: 1100px; margin: 28px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
        .btn { display:inline-block; padding: 8px 12px; border-radius: 6px; background:#4F46E5; color:#fff; text-decoration:none; border:none; font-weight:600; transition:background 0.2s; cursor:pointer; }
        .btn:hover { background:#3737b8; color:#fff; }
    .empty { text-align:center; color:#6B7280; padding:40px; font-size:1.1rem; }
    .oq-table { width:100%; border-collapse: collapse; background:#fff; border-radius:8px; overflow:hidden; }
    .oq-table th, .oq-table td { border-bottom: 1px solid #eee; padding: 10px; text-align:left; font-size: 1rem; }
    .oq-table th { background: #f9fafb; font-size: 14px; font-weight: 600; color: #374151; }
    .oq-table tr:last-child td { border-bottom: none; }
    .badge { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 0.98em; font-weight: 600; letter-spacing: 0.01em; }
    .badge-quest { background: #e0e7ff; color: #3730a3; border: 1px solid #6366f1; }
    .badge-due { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }
        @media (max-width: 600px) {
            .container { padding: 0 2vw; }
            .card { padding: 10px; }
            .oq-table th, .oq-table td { padding: 8px 4px; font-size: 0.95rem; }
            .btn { padding: 8px 12px; font-size:0.95rem; }
        }
    </style>



