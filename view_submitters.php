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

// Only quest lead or admin can view
if (!in_array($role, ['quest_lead', 'admin'], true)) {
    header('Location: dashboard.php');
    exit();
}

$quest_id = isset($_GET['quest_id']) ? (int)$_GET['quest_id'] : 0;
if ($quest_id <= 0) {
    echo '<div class="card empty">Invalid quest ID.</div>';
    exit();
}

// Fetch submitters for this quest (file, drive link, or text)
$stmt = $pdo->prepare("SELECT qs.id AS submission_id, qs.employee_id, qs.status, qs.submitted_at, u.full_name
    FROM quest_submissions qs
    LEFT JOIN users u ON qs.employee_id = u.employee_id
  WHERE qs.quest_id = ? AND (
    (qs.file_path IS NOT NULL AND qs.file_path != '')
    OR (qs.drive_link IS NOT NULL AND qs.drive_link != '')
    OR (qs.text_content IS NOT NULL AND qs.text_content != '')
  )
    ORDER BY qs.submitted_at DESC");
$stmt->execute([$quest_id]);
$submitters = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Quest Submitters</title>
  <link rel="stylesheet" href="assets/css/style.css" />
  <style>
    .container { max-width: 900px; margin: 28px auto; padding: 0 16px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
    .badge { display: inline-block; padding: 2px 8px; font-size: 12px; border-radius: 9999px; }
    .badge-pending { background: #FEF3C7; color: #92400E; border: 1px solid #F59E0B; }
    .badge-approved { background: #D1FAE5; color: #065F46; border: 1px solid #10B981; }
    .meta { color: #6B7280; font-size: 12px; }
    .btn { display:inline-block; padding: 8px 12px; border-radius: 6px; background:#4F46E5; color:#fff; text-decoration:none; }
    table { width:100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #eee; padding: 10px; text-align:left; }
    th { background: #f9fafb; }
    .empty { text-align:center; color:#6B7280; padding:40px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="topbar" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <h2>Quest Submitters</h2>
      <div><a class="btn" href="pending_reviews.php">Back to Pending Reviews</a></div>
    </div>
    <div class="card">
      <?php if (!empty($submitters)): ?>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>ID</th>
              <th>Status</th>
              <th>Submitted At</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($submitters as $s): ?>
            <tr>
              <td><?php echo htmlspecialchars($s['full_name'] ?? '—'); ?></td>
              <td><?php echo htmlspecialchars($s['employee_id'] ?? '—'); ?></td>
              <td>
                <?php $st = strtolower($s['status'] ?? 'pending'); ?>
                <?php if ($st === 'approved'): ?>
                  <span class="badge badge-approved">Graded</span>
                <?php else: ?>
                  <span class="badge badge-pending">Pending</span>
                <?php endif; ?>
              </td>
              <td><?php echo htmlspecialchars(!empty($s['submitted_at']) ? date('Y-m-d H:i', strtotime($s['submitted_at'])) : '—'); ?></td>
              <td>
                <a class="btn" href="quest_assessment.php?quest_id=<?php echo urlencode((string)$quest_id); ?>&submission_id=<?php echo urlencode((string)$s['submission_id']); ?>&employee_id=<?php echo urlencode((string)$s['employee_id']); ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="empty">No submitters found for this quest.</div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
