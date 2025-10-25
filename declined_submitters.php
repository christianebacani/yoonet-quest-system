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

// Get all users who declined the quest
$stmt = $pdo->prepare("SELECT uq.employee_id, u.full_name FROM user_quests uq LEFT JOIN users u ON uq.employee_id = u.employee_id WHERE uq.quest_id = ? AND uq.status = 'declined'");
$stmt->execute([$quest_id]);
$declined = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Declined Submitters</title>
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
      <h2>Declined Submitters</h2>
      <div><a class="btn" href="pending_reviews.php">Back to Pending Reviews</a></div>
    </div>
    <div class="card">
      <?php if (!empty($declined)): ?>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>ID</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($declined as $d): ?>
            <tr>
              <td><?php echo htmlspecialchars($d['full_name'] ?? '—'); ?></td>
              <td><?php echo htmlspecialchars($d['employee_id'] ?? '—'); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="empty">No declined users for this quest.</div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
