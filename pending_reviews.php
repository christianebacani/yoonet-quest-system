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

// Normalize legacy roles similarly to dashboard
if ($role === 'quest_taker') {
    $role = 'skill_associate';
} elseif (in_array($role, ['hybrid','quest_giver','contributor','learning_architect'], true)) {
    $role = 'quest_lead';
} elseif ($role === 'participant') {
    $role = 'skill_associate';
}

$is_admin = ($role === 'admin');
$is_giver = in_array($role, ['quest_lead', 'admin'], true);

if (!$is_giver) {
    // Basic guard: only quest creators or admins
    header('Location: dashboard.php');
    exit();
}

$items_per_page = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Get quests created by this user (handle different created_by formats)
$createdQuestIds = [];
try {
    if (!$is_admin) {
        $stmt = $pdo->prepare("SELECT id FROM quests WHERE (created_by = ? OR created_by = ? OR LOWER(TRIM(created_by)) = LOWER(?))");
        $stmt->execute([$employee_id, (string)$user_id, 'user_' . (string)$user_id]);
        $createdQuestIds = array_map(function($r){ return (int)$r['id']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (PDOException $e) {
    error_log('pending_reviews: error fetching created quest ids: ' . $e->getMessage());
    $createdQuestIds = [];
}

// Fetch rows: admin sees recent submissions; creators see their created quests with aggregated info
$rows = [];
if ($is_admin) {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM quest_submissions WHERE status IN ('pending','under_review','approved','rejected','needs_revision')")->fetchColumn();
    $total_pages = $total > 0 ? (int)ceil($total / $items_per_page) : 1;

    $stmt = $pdo->prepare("SELECT qs.id, qs.employee_id, qs.quest_id, qs.file_path, qs.text_content AS submission_text, qs.status, qs.submitted_at,
                  q.title AS quest_title, q.description AS quest_description,
                  e.full_name AS employee_name, e.id AS employee_user_id
               FROM quest_submissions qs
               JOIN quests q ON qs.quest_id = q.id
               LEFT JOIN users e ON qs.employee_id = e.employee_id
               WHERE qs.status IN ('pending','under_review','approved','rejected','needs_revision')
               ORDER BY qs.submitted_at DESC
               LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    if (!empty($createdQuestIds)) {
        $total = count($createdQuestIds);
        $total_pages = $total > 0 ? (int)ceil($total / $items_per_page) : 1;
        $ph = implode(',', array_fill(0, count($createdQuestIds), '?'));

        $dataSql = "SELECT q.id AS quest_id, q.title AS quest_title, q.description AS quest_description,
                    (SELECT COUNT(*) FROM quest_submissions qs2 WHERE qs2.quest_id = q.id AND qs2.status IN ('pending','under_review')) AS pending_count,
                    (SELECT qs2.id FROM quest_submissions qs2 WHERE qs2.quest_id = q.id ORDER BY qs2.submitted_at DESC LIMIT 1) AS latest_submission_id,
                    (SELECT qs2.employee_id FROM quest_submissions qs2 WHERE qs2.quest_id = q.id ORDER BY qs2.submitted_at DESC LIMIT 1) AS latest_employee_id,
                    (SELECT qs2.file_path FROM quest_submissions qs2 WHERE qs2.quest_id = q.id ORDER BY qs2.submitted_at DESC LIMIT 1) AS latest_file_path,
                    (SELECT qs2.text_content FROM quest_submissions qs2 WHERE qs2.quest_id = q.id ORDER BY qs2.submitted_at DESC LIMIT 1) AS latest_text_content,
                    (SELECT qs2.status FROM quest_submissions qs2 WHERE qs2.quest_id = q.id ORDER BY qs2.submitted_at DESC LIMIT 1) AS latest_status,
                    (SELECT qs2.submitted_at FROM quest_submissions qs2 WHERE qs2.quest_id = q.id ORDER BY qs2.submitted_at DESC LIMIT 1) AS latest_submitted_at
              FROM quests q
              WHERE q.id IN ($ph)
              ORDER BY q.created_at DESC
              LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($dataSql);
        $bind = 1;
        foreach ($createdQuestIds as $qid) { $stmt->bindValue($bind++, $qid, PDO::PARAM_INT); }
        $stmt->bindValue($bind++, $items_per_page, PDO::PARAM_INT);
        $stmt->bindValue($bind, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $total = 0; $total_pages = 1; $rows = [];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Submitted Quest</title>
  <link rel="stylesheet" href="assets/css/style.css" />
  <style>
    .container { max-width: 1100px; margin: 28px auto; padding: 0 16px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
  .badge { display: inline-block; padding: 2px 8px; font-size: 12px; border-radius: 9999px; }
  .badge-pending { background: #FEF3C7; color: #92400E; border: 1px solid #F59E0B; }
  .badge-submitted { background: #DCFCE7; color: #065F46; border: 1px solid #10B981; }
  .badge-missed { background: #FEE2E2; color: #7F1D1D; border: 1px solid #EF4444; }
  .badge-declined { background: #FFF7ED; color: #7C2D12; border: 1px solid #F97316; }
  .badge-under { background: #DBEAFE; color: #1E40AF; border: 1px solid #3B82F6; }
    .meta { color: #6B7280; font-size: 12px; }
    .topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
    .btn { display:inline-block; padding: 8px 12px; border-radius: 6px; background:#4F46E5; color:#fff; text-decoration:none; }
    table { width:100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #eee; padding: 10px; text-align:left; }
    th { background: #f9fafb; }
    .empty { text-align:center; color:#6B7280; padding:40px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="topbar">
      <h2>Submitted Quest</h2>
      <div><a class="btn" href="dashboard.php">Back to Dashboard</a></div>
    </div>

    <?php if (!empty($rows)): ?>
      <div class="card">
        <table>
          <thead>
            <tr>
              <th>Quest</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <strong><?php echo htmlspecialchars($r['quest_title'] ?? 'Untitled'); ?></strong>
                <div class="meta">Quest ID: <?php echo (int)($r['quest_id'] ?? 0); ?></div>
                <?php
                  // Get counts for statuses. We ensure users who declined are not counted as pending/missed.
                  // Pending: assigned (user_quests) with no submission, not declined, and not past due
                  $pendingStmt = $pdo->prepare(
                    "SELECT uq.employee_id FROM user_quests uq 
                     LEFT JOIN quest_submissions qs ON uq.employee_id = qs.employee_id AND uq.quest_id = qs.quest_id 
                     LEFT JOIN quests q ON uq.quest_id = q.id 
                     WHERE uq.quest_id = ? 
                       AND (qs.id IS NULL OR qs.file_path IS NULL OR qs.file_path = '') 
                       AND (uq.status IS NULL OR uq.status NOT IN ('declined')) 
                       AND (q.due_date IS NULL OR q.due_date >= NOW())"
                  );
                  $pendingStmt->execute([(int)$r['quest_id']]);
                  $pendingUsers = $pendingStmt->fetchAll(PDO::FETCH_COLUMN);
                  $pendingCount = count($pendingUsers);

                  // Submitted: distinct submitters who provided file/text
                  $submittedStmt = $pdo->prepare("SELECT COUNT(DISTINCT employee_id) FROM quest_submissions WHERE quest_id = ? AND ((file_path IS NOT NULL AND file_path <> '') OR (text_content IS NOT NULL AND text_content <> ''))");
                  $submittedStmt->execute([(int)$r['quest_id']]);
                  $submittedCount = (int)$submittedStmt->fetchColumn();

                  // Declined: explicit declined entries in user_quests
                  $declinedStmt = $pdo->prepare("SELECT COUNT(*) FROM user_quests WHERE quest_id = ? AND status = 'declined'");
                  $declinedStmt->execute([(int)$r['quest_id']]);
                  $declinedCount = (int)$declinedStmt->fetchColumn();

                  // Missed: assigned, not declined, no submission, and due_date passed
                  $missedStmt = $pdo->prepare(
                    "SELECT uq.employee_id FROM user_quests uq 
                     LEFT JOIN quest_submissions qs ON uq.employee_id = qs.employee_id AND uq.quest_id = qs.quest_id 
                     JOIN quests q ON uq.quest_id = q.id 
                     WHERE uq.quest_id = ? 
                       AND (qs.id IS NULL OR qs.file_path IS NULL OR qs.file_path = '') 
                       AND (uq.status IS NULL OR uq.status NOT IN ('declined')) 
                       AND q.due_date IS NOT NULL AND q.due_date < NOW()"
                  );
                  $missedStmt->execute([(int)$r['quest_id']]);
                  $missedUsers = $missedStmt->fetchAll(PDO::FETCH_COLUMN);
                  $missedCount = count($missedUsers);
                ?>
                <div class="meta" style="margin-top:6px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                  <span class="badge badge-pending">Pending: <?php echo $pendingCount; ?></span>
                  <span class="badge badge-submitted">Submitted: <?php echo $submittedCount; ?></span>
                  <span class="badge badge-missed">Missed: <?php echo $missedCount; ?></span>
                  <span class="badge badge-declined">Declined: <?php echo $declinedCount; ?></span>
                  <!-- IDs listing removed per request: only show badges now -->
                </div>
              </td>
              <td>
                <a class="btn" href="view_submitters.php?quest_id=<?php echo urlencode((string)$r['quest_id']); ?>">View Submitters</a>
                <a class="btn" style="background:#991b1b; margin-left:8px;" href="missed_submitters.php?quest_id=<?php echo urlencode((string)$r['quest_id']); ?>">Missed</a>
                <a class="btn" style="background:#f59e42; margin-left:8px;" href="declined_submitters.php?quest_id=<?php echo urlencode((string)$r['quest_id']); ?>">Declined</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_pages > 1): ?>
        <div class="card" style="display:flex; gap:8px; justify-content:center;">
          <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <?php if ($p == $page): ?>
              <span class="badge" style="background:#E5E7EB; color:#111827; border:1px solid #9CA3AF;">Page <?php echo $p; ?></span>
            <?php else: ?>
              <a class="btn" href="?page=<?php echo $p; ?>">Page <?php echo $p; ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="card empty">
        <p>No submissions found.</p>
        <p class="meta">Once learners submit, or as reviews are completed, they will appear here.</p>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>

