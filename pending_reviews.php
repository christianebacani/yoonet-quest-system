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

// Gather full list of quests created by this user to avoid created_by formatting mismatches
$createdQuestIds = [];
if ($is_admin) {
  // Admin can see everything; no need to build list
} else {
  try {
    $stmt = $pdo->prepare("SELECT id FROM quests WHERE (created_by = ? OR created_by = ? OR LOWER(TRIM(created_by)) = LOWER(?))");
    $stmt->execute([$employee_id, (string)$user_id, 'user_' . (string)$user_id]);
    $createdQuestIds = array_map(function($r){ return (int)$r['id']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
  } catch (PDOException $e) {
    error_log('pending_reviews: error fetching created quest ids: ' . $e->getMessage());
    $createdQuestIds = [];
  }
}

// Build filter: prefer quest_id IN (...) for non-admins
if ($is_admin) {
  // Show all statuses so reviewers can see both reviewed and unreviewed
  $total = (int)$pdo->query("SELECT COUNT(*) FROM quest_submissions WHERE status IN ('pending','under_review','approved','rejected')")->fetchColumn();
  $total_pages = $total > 0 ? (int)ceil($total / $items_per_page) : 1;
  $stmt = $pdo->prepare("SELECT qs.id, qs.employee_id, qs.quest_id, qs.file_path, qs.submission_text, qs.status, qs.submitted_at,
                  q.title AS quest_title, q.description AS quest_description,
                  e.full_name AS employee_name, e.id AS employee_user_id
               FROM quest_submissions qs
               JOIN quests q ON qs.quest_id = q.id
               LEFT JOIN users e ON qs.employee_id = e.employee_id
               WHERE qs.status IN ('pending','under_review','approved','rejected')
               ORDER BY qs.submitted_at DESC
               LIMIT ? OFFSET ?");
  $stmt->bindValue(1, $items_per_page, PDO::PARAM_INT);
  $stmt->bindValue(2, $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  if (!empty($createdQuestIds)) {
    $ph = implode(',', array_fill(0, count($createdQuestIds), '?'));
  $countSql = "SELECT COUNT(*) FROM quest_submissions WHERE status IN ('pending','under_review','approved','rejected') AND quest_id IN ($ph)";
    $stmt = $pdo->prepare($countSql);
    foreach ($createdQuestIds as $i => $qid) { $stmt->bindValue($i+1, $qid, PDO::PARAM_INT); }
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();
    $total_pages = $total > 0 ? (int)ceil($total / $items_per_page) : 1;

    $dataSql = "SELECT qs.id, qs.employee_id, qs.quest_id, qs.file_path, qs.submission_text, qs.status, qs.submitted_at,
               q.title AS quest_title, q.description AS quest_description,
               e.full_name AS employee_name, e.id AS employee_user_id
          FROM quest_submissions qs
          JOIN quests q ON qs.quest_id = q.id
          LEFT JOIN users e ON qs.employee_id = e.employee_id
          WHERE qs.status IN ('pending','under_review','approved','rejected') AND qs.quest_id IN ($ph)
          ORDER BY qs.submitted_at DESC
          LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($dataSql);
    $bind = 1;
    foreach ($createdQuestIds as $qid) { $stmt->bindValue($bind++, $qid, PDO::PARAM_INT); }
    $stmt->bindValue($bind++, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue($bind, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
    // If creator has no quests, no rows to show
    $total = 0;
    $total_pages = 1;
    $rows = [];
  }
}

// Fallback: surface user_quests submitted if nothing found
if (empty($rows)) {
    $fbParams = [];
  // Prefer quest_id IN (...) fallback if we know the list of created quests
  if ($is_admin) {
    $fbWhere = "WHERE uq.status = 'submitted'";
  } else if (!empty($createdQuestIds)) {
    $fbWhere = "WHERE uq.status = 'submitted' AND uq.quest_id IN (" . implode(',', array_fill(0, count($createdQuestIds), '?')) . ")";
    foreach ($createdQuestIds as $qid) { $fbParams[] = $qid; }
  } else {
    $fbWhere = "WHERE uq.status = 'submitted' AND (UPPER(TRIM(q.created_by)) = UPPER(?) OR UPPER(TRIM(q.created_by)) = UPPER(?) OR UPPER(TRIM(q.created_by)) = UPPER(?))";
    $fbParams[] = (string)$employee_id;
    $fbParams[] = (string)$user_id;
    $fbParams[] = 'user_' . (string)$user_id;
  }

    $fbSql = "SELECT 
                  NULL AS id,
                  uq.employee_id,
                  uq.quest_id,
                  '' AS file_path,
                  '' AS submission_text,
                  'pending' AS status,
                  NOW() AS submitted_at,
                  q.title AS quest_title,
                  q.description AS quest_description,
                  e.full_name AS employee_name,
                  e.id AS employee_user_id
              FROM user_quests uq
              JOIN quests q ON uq.quest_id = q.id
              LEFT JOIN users e ON uq.employee_id = e.employee_id
              $fbWhere
              ORDER BY q.id DESC
              LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($fbSql);
    $idx = 1;
  foreach ($fbParams as $p) {
    $stmt->bindValue($idx++, $is_admin ? $p : (is_int($p) ? $p : $p), is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
    $stmt->bindValue($idx++, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue($idx, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
      <div>
        <a class="btn" href="dashboard.php">Back to Dashboard</a>
      </div>
    </div>

    <?php if (!empty($rows)): ?>
      <div class="card">
        <table>
          <thead>
            <tr>
              <th>Quest</th>
              <th>Submitted By</th>
              <th>Status</th>
              <th>Submitted At</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <strong><?php echo htmlspecialchars($r['quest_title'] ?? 'Untitled'); ?></strong>
                <div class="meta">Quest ID: <?php echo (int)($r['quest_id'] ?? 0); ?></div>
              </td>
              <td>
                <?php echo htmlspecialchars($r['employee_name'] ?? ($r['employee_id'] ?? 'Unknown')); ?>
                <div class="meta">Employee ID: <?php echo htmlspecialchars($r['employee_id'] ?? ''); ?></div>
              </td>
              <td>
                <?php $st = strtolower($r['status'] ?? 'pending'); ?>
                <?php if ($st === 'under_review'): ?>
                  <span class="badge badge-under">Under Review</span>
                <?php elseif ($st === 'approved'): ?>
                  <span class="badge" style="background:#D1FAE5;color:#065F46;border:1px solid #10B981;">Reviewed</span>
                <?php elseif ($st === 'rejected'): ?>
                  <span class="badge" style="background:#FEE2E2;color:#991B1B;border:1px solid #EF4444;">Declined</span>
                <?php else: ?>
                  <span class="badge badge-pending">Pending</span>
                <?php endif; ?>
              </td>
              <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['submitted_at'] ?? 'now'))); ?></td>
              <td>
                <?php if (!empty($r['employee_user_id'])): ?>
                  <?php $label = (in_array($st, ['approved','rejected'], true)) ? 'View' : 'Review'; ?>
                  <a class="btn" href="quest_assessment.php?quest_id=<?php echo urlencode((string)$r['quest_id']); ?>&user_id=<?php echo urlencode((string)$r['employee_user_id']); ?>&employee_id=<?php echo urlencode((string)($r['employee_id'] ?? '')); ?>"><?php echo $label; ?></a>
                <?php else: ?>
                  <span class="meta">No user link</span>
                <?php endif; ?>
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
