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
    // Find sample output attachment if present (by file name or is_sample_output flag)
    $sample_output_url = '';
    foreach ($attachments as $att) {
        if ((isset($att['is_sample_output']) && $att['is_sample_output']) ||
            (isset($att['file_name']) && stripos($att['file_name'], 'sample') !== false) ||
            (isset($att['file_name']) && stripos($att['file_name'], 'expected') !== false)) {
            $sample_output_url = $att['file_path'];
            break;
        }
    }

    // No fallback to uploads directory: only show Expected Output when a DB attachment exists.

    // Load quest skills (for display, especially for client_call quests)
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

    <a href="<?php echo htmlspecialchars($back_link); ?>" class="btn btn-ghost">← Back</a>

    <?php
    // Always render the create quest UI in read-only mode for all quest types, with all values populated from the database.
    $mode = 'view';
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
    $aggregation_date = $quest['aggregation_date'] ?? '';
    $aggregation_shift = $quest['aggregation_shift'] ?? '';
    $call_log_path = $quest['call_log_path'] ?? '';
    $service_level_description = $quest['service_level_description'] ?? '';
    $due_date = $quest['due_date'] ?? '';
    $publish_at = $quest['publish_at'] ?? '';
    $assign_to = array_map(function($u){ return $u['employee_id'] ?? ''; }, $assigned_users ?: []);
    $quest_skills_count = is_array($quest_skills) ? count($quest_skills) : 0;

    // Determine if the current viewer is an assigned user (not the creator)
    $is_assigned = false;
    if (!empty($employee_id) && is_array($assigned_users)) {
        foreach ($assigned_users as $au) {
            if ((string)($au['employee_id'] ?? '') === (string)$employee_id) { $is_assigned = true; break; }
        }
    }

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
        $qstmt = $pdo->query("SELECT type_key, name FROM quest_types WHERE type_key IN ('custom','client_call') ORDER BY id");
        $quest_types = $qstmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $quest_types = [ ['type_key'=>'custom','name'=>'Custom'], ['type_key'=>'client_call','name'=>'Client Call'] ];
    }

    // Ensure client_call fallback exists in the fetched list (some installs may have client_support instead)
    $type_keys = array_column($quest_types, 'type_key');
    if (!in_array('client_call', $type_keys)) {
        $quest_types[] = ['type_key' => 'client_call', 'name' => 'Client Call Handling'];
    }

    // Determine human-friendly name for display_type (fallback if includes/quest_form_ui cannot resolve)
    $display_type_name = null;
    if (!empty($quest_types) && isset($display_type)) {
        foreach ($quest_types as $qt) {
            if (isset($qt['type_key']) && $qt['type_key'] === $display_type) { $display_type_name = $qt['name']; break; }
        }
    }
    if ($display_type_name === null && isset($display_type)) {
        try {
            $stmt = $pdo->prepare("SELECT name FROM quest_types WHERE type_key = ? LIMIT 1");
            $stmt->execute([$display_type]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['name'])) { $display_type_name = $row['name']; }
        } catch (Exception $e) {
            // ignore and fall back to raw key
        }
    }
    // friendly label for templates
    $display_type_display = $display_type_name ?? $display_type;

    // Ensure all client_call fields and correct skills are passed to the form UI
    // Pass $sample_output_url to the form UI for Expected Output visibility
    // If this is a client_call quest, override the displayed selected skills
    // to the standardized auto-skill set so viewers (creator or assignees)
    // always see the intended skill names and Beginner level.
    if (isset($display_type) && $display_type === 'client_call') {
        $quest_skills = [
            ['skill_name' => 'Communication', 'tier_level' => 1, 'category_name' => 'Auto'],
            ['skill_name' => 'Attention to Detail', 'tier_level' => 1, 'category_name' => 'Auto'],
            ['skill_name' => 'Tech Proficiency', 'tier_level' => 1, 'category_name' => 'Auto'],
            ['skill_name' => 'Empathy', 'tier_level' => 1, 'category_name' => 'Auto'],
            ['skill_name' => 'Teamwork', 'tier_level' => 1, 'category_name' => 'Auto']
        ];
        $quest_skills_count = count($quest_skills);
    }

    include __DIR__ . '/includes/quest_form_ui.php';

    // The Expected Output view block is rendered inside the included form UI (includes/quest_form_ui.php)

    // --- Show all quest attachments (not just sample output) ---
    // Hide generic attachment list for client_call quests (we already show Expected Output)
    // Also hide for assigned users viewing their assigned quest (they only need Expected Output shown above)
    if (!empty($attachments) && $display_type !== 'client_call' && (!($is_assigned && !$is_creator && ($_SESSION['role'] ?? '') !== 'learning_architect'))) {
        echo '<div class="card p-6 mt-6">';
        echo '<h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">';
        echo '<i class="fas fa-paperclip text-indigo-500 mr-2"></i> Quest Attachments';
        echo '</h2>';
        echo '<ul class="list-disc pl-6">';
        foreach ($attachments as $att) {
            $file_name = htmlspecialchars($att['file_name'] ?? basename($att['file_path']));
            $file_path = htmlspecialchars($att['file_path']);
            $is_sample = !empty($att['is_sample_output']);
            $label = $is_sample ? ' (Sample Output)' : '';
            echo '<li class="mb-2">';
            echo '<a href="' . $file_path . '" target="_blank" class="text-blue-700 underline">' . $file_name . '</a>' . $label;
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
    ?>

</div>
</body>
</html>
