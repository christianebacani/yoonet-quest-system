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

    // Load quest skills (for display, especially for client_support quests)
    // Join skill_categories so we can show the skill category name and tier info in the view UI
    $stmt = $pdo->prepare("SELECT qs.*, cs.skill_name, cs.category_id, sc.category_name, cs.tier_1_points, cs.tier_2_points, cs.tier_3_points, cs.tier_4_points, cs.tier_5_points FROM quest_skills qs LEFT JOIN comprehensive_skills cs ON qs.skill_id = cs.id LEFT JOIN skill_categories sc ON cs.category_id = sc.id WHERE qs.quest_id = ?");
    $stmt->execute([$quest_id]);
    $quest_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load assigned users for this quest (so we can display assignments in the UI)
    $stmt = $pdo->prepare("SELECT uq.employee_id, u.full_name FROM user_quests uq LEFT JOIN users u ON uq.employee_id = u.employee_id WHERE uq.quest_id = ?");
    $stmt->execute([$quest_id]);
    $assigned_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
// Decide where the Back link should go. If we were opened from My Quests, return there.
$back_link = 'created_quests.php';
if (isset($_GET['from']) && $_GET['from'] === 'my_quests') {
    $back_link = 'my_quests.php';
} elseif (!empty($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'my_quests.php') !== false) {
    $back_link = 'my_quests.php';
}
?>
<?php
// Determine if this quest should be treated as a Custom-style quest for display rules.
$is_custom = false;
if (isset($quest['display_type']) && strtolower(trim((string)$quest['display_type'])) === 'custom') {
    $is_custom = true;
} elseif (isset($quest['quest_type']) && strtolower(trim((string)$quest['quest_type'])) === 'custom') {
    $is_custom = true;
}
// Also consider a pre-populated $display_type variable if present
if (isset($display_type) && strtolower(trim((string)$display_type)) === 'custom') {
    $is_custom = true;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>View Quest - <?php echo htmlspecialchars($quest['title']); ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/buttons.css">
<!-- Copy core UI styles used in create_quest for consistent display -->
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
/* Match the container width used by the create/edit UI so the box size is identical */
.container { max-width:1100px; margin:28px auto; padding:16px; }
.card { background:#fff; padding:24px; border-radius:8px; box-shadow:0 6px 20px rgba(2,6,23,0.04); }
.meta { color:#6b7280; font-size:0.95rem; }
.attachment { margin-top:8px; }
/* small helpers to mimic create_quest spacing */
.label { font-size:0.9rem; color:#374151; font-weight:600; margin-bottom:6px; display:block; }
.value-box { background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e6eef8; }
</style>
</head>
<body>
<div class="container">
    <?php if (!isset($quest['display_type']) || ($quest['display_type'] !== 'client_support' && $quest['display_type'] !== 'custom')): ?>
        <a href="<?php echo htmlspecialchars($back_link); ?>" class="btn btn-ghost">← Back</a>
        <h1><?php echo htmlspecialchars($quest['title']); ?></h1>
        <div class="meta">Status: <?php echo htmlspecialchars($quest['status']); ?> | Due: <?php echo !empty($quest['due_date']) ? htmlspecialchars($quest['due_date']) : '—'; ?></div>

        <div class="card" style="margin-top:12px;">
            <h3>Description</h3>
            <div><?php echo nl2br(htmlspecialchars($quest['description'])); ?></div>
        </div>
    <?php else: ?>
        <a href="<?php echo htmlspecialchars($back_link); ?>" class="btn btn-ghost">← Back</a>
    <?php endif; ?>

    <?php
    // If this is a client_support quest OR a custom quest viewed by its creator,
    // render the full create/edit style form UI in read-only mode so the creator
    // can view the quest exactly as it was created.
    if (isset($quest['display_type']) && ($quest['display_type'] === 'client_support' || ($quest['display_type'] === 'custom' && $is_creator))) {
        // Prepare variables expected by the form include so it renders identical UI.
        $mode = 'view';
        // Populate basic form variables from $quest so the form fields show saved values
        $title = $quest['title'] ?? '';
        $description = $quest['description'] ?? '';
        $display_type = $quest['display_type'] ?? 'custom';
        $quest_assignment_type = $quest['quest_assignment_type'] ?? 'optional';
        $client_name = $quest['client_name'] ?? '';
        $client_reference = $quest['client_reference'] ?? '';
        $sla_priority = $quest['sla_priority'] ?? 'medium';
        $expected_response = $quest['expected_response'] ?? '';
        $client_contact_email = $quest['client_contact_email'] ?? '';
        $client_contact_phone = $quest['client_contact_phone'] ?? '';
        $sla_due_hours = $quest['sla_due_hours'] ?? null;
        $estimated_hours = $quest['estimated_hours'] ?? null;
        $vendor_name = $quest['vendor_name'] ?? '';
        $external_ticket_link = $quest['external_ticket_link'] ?? '';
        $service_level_description = $quest['service_level_description'] ?? '';
        $due_date = $quest['due_date'] ?? '';
        $publish_at = $quest['publish_at'] ?? '';
        $assign_to = array_map(function($u){ return $u['employee_id'] ?? ''; }, $assigned_users ?: []);

        // Fetch employees and skills to match create_quest UI (used for lists and JS initialization)
        try {
            $stmt = $pdo->query("SELECT employee_id, full_name FROM users WHERE role IN ('skill_associate','quest_lead') ORDER BY full_name");
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $employees = []; }
        try {
            $stmt = $pdo->query("SELECT cs.id as skill_id, cs.skill_name, sc.category_name, sc.id as category_id, cs.tier_1_points, cs.tier_2_points, cs.tier_3_points, cs.tier_4_points, cs.tier_5_points FROM comprehensive_skills cs JOIN skill_categories sc ON cs.category_id = sc.id ORDER BY sc.category_name, cs.skill_name");
            $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $skills = []; }
        try {
            $qstmt = $pdo->query("SELECT type_key, name FROM quest_types WHERE type_key IN ('custom','client_support') ORDER BY id");
            $quest_types = $qstmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $quest_types = [ ['type_key'=>'custom','name'=>'Custom'], ['type_key'=>'client_support','name'=>'Client & Support Operations'] ];
        }

        // Count attached quest skills for display
        $quest_skills_count = is_array($quest_skills) ? count($quest_skills) : 0;

        // Include the full create-style form UI but rendered in view mode (read-only via JS)
        include __DIR__ . '/includes/quest_form_ui.php';
    }
    ?>

    <?php if (!$is_custom && (!isset($quest['display_type']) || $quest['display_type'] !== 'client_support')): ?>
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
    <?php endif; ?>

</div>
</body>
</html>
