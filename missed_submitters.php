<?php
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

if (!in_array($role, ['quest_lead', 'admin'], true)) {
    header('Location: dashboard.php');
    exit();
}

$quest_id = isset($_GET['quest_id']) ? (int)$_GET['quest_id'] : 0;
if ($quest_id <= 0) {
    echo '<div class="card empty">Invalid quest ID.</div>';
    exit();
}

// Get quest due date
$stmt = $pdo->prepare("SELECT due_date FROM quests WHERE id = ?");
$stmt->execute([$quest_id]);
$quest = $stmt->fetch(PDO::FETCH_ASSOC);
$due_date = $quest['due_date'] ?? null;

// Before rendering, ensure any assigned/accepted user_quests for this quest are
// marked 'missed' if the due date passed and they have no submission. This is
// a scoped, idempotent update so creators see missed submitters immediately
// without a global scheduler.
try {
  $updateSql = "UPDATE user_quests uq
    JOIN quests q ON uq.quest_id = q.id
    LEFT JOIN quest_submissions qs ON uq.quest_id = qs.quest_id AND uq.employee_id = qs.employee_id
    SET uq.status = 'missed'
    WHERE uq.quest_id = ?
      AND uq.status IN ('assigned','in_progress','accepted','started')
      AND qs.id IS NULL
      AND q.due_date IS NOT NULL
      AND (
         CASE WHEN TIME(q.due_date) = '00:00:00' THEN DATE_ADD(q.due_date, INTERVAL 86399 SECOND) ELSE q.due_date END
      ) < NOW()";
  $uStmt = $pdo->prepare($updateSql);
  $uStmt->execute([$quest_id]);
} catch (PDOException $e) {
  error_log('missed_submitters: failed to mark missed for quest ' . $quest_id . ': ' . $e->getMessage());
}

// Get all assigned users for this quest (include those who accepted or were assigned)
$stmt = $pdo->prepare("SELECT uq.employee_id, u.full_name, uq.status FROM user_quests uq LEFT JOIN users u ON uq.employee_id = u.employee_id WHERE uq.quest_id = ?");
$stmt->execute([$quest_id]);
$assigned = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all users who have submitted for this quest
$stmt = $pdo->prepare("SELECT DISTINCT employee_id FROM quest_submissions WHERE quest_id = ?");
$stmt->execute([$quest_id]);
$submitted_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'employee_id');
// Fetch missed submitters using the same DB logic used by pending_reviews so
// the list of names matches the Missed badge. This avoids inconsistencies
// when different date formats or submission representations exist.
// Use shared helper to get missed submitters so the list matches pending_reviews
$missed = [];
try {
  if (function_exists('get_missed_submitters')) {
    $missed = get_missed_submitters($pdo, (int)$quest_id);
  } else {
    // fallback to previous PHP-side filter
    $now = time();
    $due_ts = strtotime($due_date);
    if ($due_ts !== false && date('H:i:s', $due_ts) === '00:00:00') { $due_ts += 86399; }
    if ($due_ts !== false && $now > $due_ts) {
      foreach ($assigned as $a) {
        if (!in_array($a['employee_id'], $submitted_ids)) { $missed[] = $a; }
      }
    }
  }
} catch (Exception $e) {
  error_log('missed_submitters: helper get_missed_submitters failed: ' . $e->getMessage());
  $missed = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Missed Submitters</title>
  <link rel="stylesheet" href="assets/css/style.css" />
  <style>
    .container { max-width: 900px; margin: 28px auto; padding: 0 16px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
    .empty { text-align:center; color:#6B7280; padding:40px; }
    table { width:100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #eee; padding: 10px; text-align:left; }
    th { background: #f9fafb; }
    .btn {
      display: inline-block;
      padding: 8px 18px;
      background: #2563eb;
      color: #fff;
      border: none;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 500;
      box-shadow: 0 2px 8px rgba(37,99,235,0.08);
      transition: background 0.25s, transform 0.18s, box-shadow 0.18s;
      cursor: pointer;
    }
    .btn:hover, .btn:focus {
      background: #1d4ed8;
      transform: translateY(-2px) scale(1.04);
      box-shadow: 0 4px 16px rgba(37,99,235,0.16);
      outline: none;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="topbar" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <h2>Missed Submitters</h2>
      <div><a class="btn" href="pending_reviews.php">Back to Pending Reviews</a></div>
    </div>
    <div class="card">
      <?php if (!empty($missed)): ?>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>ID</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($missed as $m): ?>
            <tr>
              <td><?php echo htmlspecialchars($m['full_name'] ?? '—'); ?></td>
              <td><?php echo htmlspecialchars($m['employee_id'] ?? '—'); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="empty">No missed submitters for this quest (or deadline not reached).</div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
