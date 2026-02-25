<?php
// ─── BOOTSTRAP: single config + functions include ───────────────────────────
require_once 'includes/config.php';   // provides $pdo
require_once 'includes/functions.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

$role            = $_SESSION['role']        ?? '';
$current_user_id = $_SESSION['employee_id'] ?? 0;

// ─── GET quest_id from URL ────────────────────────────────────────────────────
$quest_id = intval($_GET['id'] ?? 0);
if (!$quest_id) {
    header('Location: dashboard.php');
    exit();
}

// ─── Ensure optional columns exist (non-fatal) ───────────────────────────────
$alter_cols = [
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS display_type          VARCHAR(50)  DEFAULT 'custom'",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS client_name           VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS client_reference      VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS sla_priority          ENUM('low','medium','high') DEFAULT 'medium'",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS expected_response     VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS client_contact_email  VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS client_contact_phone  VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS sla_due_hours         INT          DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS external_ticket_link  VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS service_level_description TEXT     DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS vendor_name           VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS estimated_hours       DECIMAL(6,2) DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS aggregation_period    VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE quests ADD COLUMN IF NOT EXISTS quest_type            ENUM('routine','minor','standard','major','project','recurring') DEFAULT 'routine'",
];
foreach ($alter_cols as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $e) { /* non-fatal */ }
}

// ─── Load the quest ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM quests WHERE id = ? LIMIT 1");
$stmt->execute([$quest_id]);
$quest = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quest) {
    // Quest not found – bounce back
    header('Location: dashboard.php?error=quest_not_found');
    exit();
}

// ─── Unpack all quest fields into named variables (used in form defaults) ────

// Default: prefill from DB
$title                     = $quest['title']                     ?? '';
$description               = $quest['description']               ?? '';
$display_type              = $quest['display_type']              ?? 'custom';
$quest_assignment_type     = $quest['quest_assignment_type']     ?? '';
$visibility_value          = $quest['visibility_value']          ?? '';
$due_date                  = $quest['due_date']                  ?? '';
$recurrence_pattern        = $quest['recurrence_pattern']        ?? '';
$recurrence_end_date       = $quest['recurrence_end_date']       ?? '';
$publish_at                = $quest['publish_at']                ?? '';
$client_name               = $quest['client_name']               ?? '';
$client_reference          = $quest['client_reference']          ?? '';
$sla_priority              = $quest['sla_priority']              ?? 'medium';
$expected_response         = $quest['expected_response']         ?? '';
$client_contact_email      = $quest['client_contact_email']      ?? '';
$client_contact_phone      = $quest['client_contact_phone']      ?? '';
$sla_due_hours             = $quest['sla_due_hours']             ?? '';
$external_ticket_link      = $quest['external_ticket_link']      ?? '';
$service_level_description = $quest['service_level_description'] ?? '';
$vendor_name               = $quest['vendor_name']               ?? '';
$estimated_hours           = $quest['estimated_hours']           ?? '';
$aggregation_period        = $quest['aggregation_period']        ?? '';
$aggregation_date          = $quest['aggregation_date']          ?? '';
$aggregation_shift         = $quest['aggregation_shift']         ?? '';
$expected_output_file      = $quest['expected_output_file']      ?? '';
$quest_type_value          = $quest['quest_type']                ?? 'routine';

// If POST (form error), override with POST values so user doesn't lose input
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title               = trim($_POST['title']                     ?? $title);
    $description         = trim($_POST['description']               ?? $description);
    $due_date            = $_POST['due_date']                       ?? $due_date;
    $quest_assignment_type = $_POST['quest_assignment_type']        ?? $quest_assignment_type;
    $recurrence_pattern  = $_POST['recurrence_pattern']             ?? $recurrence_pattern;
    $recurrence_end_date = $_POST['recurrence_end_date']            ?? $recurrence_end_date;
    $client_name         = $_POST['client_name']                    ?? $client_name;
    $client_reference    = $_POST['client_reference']               ?? $client_reference;
    $sla_priority        = $_POST['sla_priority']                   ?? $sla_priority;
    $expected_response   = $_POST['expected_response']              ?? $expected_response;
    $client_contact_email= $_POST['client_contact_email']           ?? $client_contact_email;
    $client_contact_phone= $_POST['client_contact_phone']           ?? $client_contact_phone;
    $sla_due_hours       = $_POST['sla_due_hours']                  ?? $sla_due_hours;
    $external_ticket_link= $_POST['external_ticket_link']           ?? $external_ticket_link;
    $service_level_description = $_POST['service_level_description']?? $service_level_description;
    $vendor_name         = $_POST['vendor_name']                    ?? $vendor_name;
    $estimated_hours     = $_POST['estimated_hours']                ?? $estimated_hours;
    $aggregation_period  = $_POST['aggregation_period']             ?? $aggregation_period;
    $aggregation_date    = $_POST['aggregation_date']               ?? $aggregation_date;
    $aggregation_shift   = $_POST['aggregation_shift']              ?? $aggregation_shift;
    // expected_output_file is only replaced if a new file is uploaded in POST logic
}

// ─── Load existing quest skills ───────────────────────────────────────────────
$quest_skills = [];
try {
    // Detect whether skill_name column lives in quest_skills or needs a join
    $qs_stmt = $pdo->prepare("
        SELECT qs.skill_id,
               qs.tier_level  AS tier,
               COALESCE(s.skill_name, qs.skill_name, '') AS skill_name,
               COALESCE(sc.name, s.category_name, '')    AS category_name
        FROM quest_skills qs
        LEFT JOIN skills          s  ON s.id  = qs.skill_id
        LEFT JOIN skill_categories sc ON sc.id = s.category_id
        WHERE qs.quest_id = ?
    ");
    $qs_stmt->execute([$quest_id]);
    $quest_skills = $qs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Try simpler fallback without join
    try {
        $qs_stmt2 = $pdo->prepare("SELECT skill_id, tier_level AS tier FROM quest_skills WHERE quest_id = ?");
        $qs_stmt2->execute([$quest_id]);
        $quest_skills = $qs_stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) { /* leave empty */ }
}

// ─── Load all available skills for the skill picker ──────────────────────────
$all_skills = [];
try {
    $as_stmt = $pdo->query("
        SELECT s.id AS skill_id,
               s.skill_name,
               COALESCE(sc.name, '') AS category_name
        FROM skills s
        LEFT JOIN skill_categories sc ON sc.id = s.category_id
        ORDER BY sc.name, s.skill_name
    ");
    $all_skills = $as_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* empty */ }

// ─── Load employees for assignment ───────────────────────────────────────────
$employees = [];
try {
    $emp_stmt = $pdo->prepare("
        SELECT employee_id, full_name
        FROM users
        WHERE role IN ('skill_associate','quest_lead')
          AND employee_id != ?
        ORDER BY full_name
    ");
    $emp_stmt->execute([$current_user_id]);
    $employees = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $employees = []; }

// ─── Load currently assigned employees ───────────────────────────────────────
$assign_to = [];
try {
    $qa_stmt = $pdo->prepare("SELECT employee_id FROM quest_assignments WHERE quest_id = ?");
    $qa_stmt->execute([$quest_id]);
    $assign_to = $qa_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $assign_to = []; }

// ─── Quest types ──────────────────────────────────────────────────────────────
$quest_types = [
    ['type_key' => 'custom',      'name' => 'Custom'],
    ['type_key' => 'client_call', 'name' => 'Client Call Handling'],
];

// ─── Minimum lead time for due-date validation (5 min) ───────────────────────
$minLeadSeconds = 300;

// ─── Theme helpers ────────────────────────────────────────────────────────────
$current_theme = $_SESSION['theme']     ?? 'default';
$dark_mode     = $_SESSION['dark_mode'] ?? false;
$font_size     = $_SESSION['font_size'] ?? 'medium';

function getBodyClass() {
    global $current_theme, $dark_mode;
    $classes = [];
    if ($dark_mode) $classes[] = 'dark-mode';
    if ($current_theme !== 'default') $classes[] = $current_theme . '-theme';
    return implode(' ', $classes);
}
function getFontSize() {
    global $font_size;
    switch ($font_size) {
        case 'small': return '14px';
        case 'large': return '18px';
        default:      return '16px';
    }
}

// ─── PROCESS FORM SUBMISSION ──────────────────────────────────────────────────
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect POST values (override defaults)
    $title                     = trim($_POST['title']                     ?? '');
    $description               = trim($_POST['description']               ?? '');
    $due_date                  = $_POST['due_date']                       ?? null;
    $quest_assignment_type     = $_POST['quest_assignment_type']          ?? '';
    $recurrence_pattern        = $_POST['recurrence_pattern']             ?? null;
    $recurrence_end_date       = $_POST['recurrence_end_date']            ?? null;
    $client_name               = $_POST['client_name']                    ?? null;
    $client_reference          = $_POST['client_reference']               ?? null;
    $sla_priority              = $_POST['sla_priority']                   ?? 'medium';
    $expected_response         = $_POST['expected_response']              ?? null;
    $client_contact_email      = $_POST['client_contact_email']           ?? null;
    $client_contact_phone      = $_POST['client_contact_phone']           ?? null;
    $sla_due_hours             = $_POST['sla_due_hours']                  ?? null;
    $external_ticket_link      = $_POST['external_ticket_link']           ?? null;
    $service_level_description = $_POST['service_level_description']      ?? null;
    $vendor_name               = $_POST['vendor_name']                    ?? null;
    $estimated_hours           = $_POST['estimated_hours']                ?? null;
    $aggregation_period        = $_POST['aggregation_period']             ?? null;
    // Client Call Handling specific fields
    // Only update these fields for client_call quests
    if ($display_type === 'client_call') {
        $aggregation_date  = $_POST['aggregation_date']  ?? $quest['aggregation_date'] ?? null;
        $aggregation_shift = $_POST['aggregation_shift'] ?? $quest['aggregation_shift'] ?? null;
        $aggregation_period = $_POST['aggregation_period'] ?? $quest['aggregation_period'] ?? null;
        $expected_output_file = $quest['expected_output_file'] ?? null;
        if (isset($_FILES['expected_output_file']) && $_FILES['expected_output_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/quest_attachments/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
            $ext = pathinfo($_FILES['expected_output_file']['name'], PATHINFO_EXTENSION);
            $basename = uniqid('expected_output_') . '.' . $ext;
            $target_path = $upload_dir . $basename;
            if (move_uploaded_file($_FILES['expected_output_file']['tmp_name'], $target_path)) {
                $expected_output_file = 'uploads/quest_attachments/' . $basename;
            }
        }
    } else {
        $aggregation_date = null;
        $aggregation_shift = null;
        $aggregation_period = null;
        $expected_output_file = null;
    }

    // Normalise empty strings to NULL for optional fields
    $due_date              = ($due_date              === '') ? null : $due_date;
    $recurrence_pattern    = ($recurrence_pattern    === '') ? null : $recurrence_pattern;
    $recurrence_end_date   = ($recurrence_end_date   === '') ? null : $recurrence_end_date;
    $sla_due_hours         = ($sla_due_hours         === '') ? null : $sla_due_hours;
    $estimated_hours       = ($estimated_hours       === '') ? null : $estimated_hours;
    $aggregation_date      = ($aggregation_date      === '') ? null : $aggregation_date;
    $aggregation_shift     = ($aggregation_shift     === '') ? null : $aggregation_shift;

    // Parse quest_skills JSON
    $new_quest_skills = [];
    if (!empty($_POST['quest_skills'])) {
        $new_quest_skills = json_decode($_POST['quest_skills'], true) ?: [];
    }

    // Basic validation
    if (empty($title)) {
        $error = 'Quest title is required.';
    } elseif (empty($description)) {
        $error = 'Quest description is required.';
    } else {
        try {
            $pdo->beginTransaction();

            // Update quest record
            $upd = $pdo->prepare("
                UPDATE quests SET
                    title                     = ?,
                    description               = ?,
                    due_date                  = ?,
                    quest_assignment_type     = ?,
                    recurrence_pattern        = ?,
                    recurrence_end_date       = ?,
                    client_name               = ?,
                    client_reference          = ?,
                    sla_priority              = ?,
                    expected_response         = ?,
                    client_contact_email      = ?,
                    client_contact_phone      = ?,
                    sla_due_hours             = ?,
                    external_ticket_link      = ?,
                    service_level_description = ?,
                    vendor_name               = ?,
                    estimated_hours           = ?,
                    aggregation_period        = ?,
                    aggregation_date          = ?,
                    aggregation_shift         = ?,
                    expected_output_file      = ?,
                    updated_at                = NOW()
                WHERE id = ?
            ");
            $upd->execute([
                $title,
                $description,
                $due_date,
                $quest_assignment_type,
                $recurrence_pattern,
                $recurrence_end_date,
                $client_name,
                $client_reference,
                in_array($sla_priority, ['low','medium','high']) ? $sla_priority : 'medium',
                $expected_response,
                $client_contact_email,
                $client_contact_phone,
                $sla_due_hours,
                $external_ticket_link,
                $service_level_description,
                $vendor_name,
                $estimated_hours,
                $aggregation_period,
                $aggregation_date,
                $aggregation_shift,
                $expected_output_file,
                $quest_id,
            ]);

            // Replace quest skills
            $pdo->prepare("DELETE FROM quest_skills WHERE quest_id = ?")->execute([$quest_id]);

            if (!empty($new_quest_skills)) {
                // Detect base_points column
                $has_bp = false;
                try {
                    $col_check = $pdo->prepare("
                        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quest_skills' AND COLUMN_NAME = 'base_points'
                    ");
                    $col_check->execute();
                    $has_bp = (bool) $col_check->fetch();
                } catch (PDOException $e) { /* ignore */ }

                $tierToBase = [1 => 25, 2 => 40, 3 => 55, 4 => 70, 5 => 85];
                $ins_sql    = $has_bp
                    ? "INSERT INTO quest_skills (quest_id, skill_id, tier_level, base_points) VALUES (?, ?, ?, ?)"
                    : "INSERT INTO quest_skills (quest_id, skill_id, tier_level) VALUES (?, ?, ?)";
                $ins_stmt   = $pdo->prepare($ins_sql);

                foreach ($new_quest_skills as $sk) {
                    $sid  = $sk['skill_id'] ?? null;
                    $tier = intval($sk['tier'] ?? 2);
                    if (!$sid || !is_numeric($sid)) continue; // skip custom/invalid
                    if ($has_bp) {
                        $ins_stmt->execute([$quest_id, intval($sid), $tier, $tierToBase[$tier] ?? 40]);
                    } else {
                        $ins_stmt->execute([$quest_id, intval($sid), $tier]);
                    }
                }
            }

            $pdo->commit();
            $success = 'Quest updated successfully!';

            // Reload quest_skills so badges reflect the save
            try {
                $qs_stmt->execute([$quest_id]);
                $quest_skills = $qs_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { /* keep old */ }

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Failed to update quest: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Quest – <?php echo htmlspecialchars($title); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,.05), 0 2px 4px -1px rgba(0,0,0,.03);
            transition: box-shadow .3s;
            width: 100%;
        }
        .card:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,.07), 0 4px 6px -2px rgba(0,0,0,.05); }

        /* Flatpickr tweaks */
        #due_date_display_input, #customEndDate {
            font-size: 1rem !important; font-weight: 500 !important;
            color: #4338ca !important; background: #f3f4f6 !important;
            border-radius: 8px !important; border: 1.2px solid #c7d2fe !important;
            padding: 8px 14px !important;
        }
        .flatpickr-calendar { font-family: 'Inter', sans-serif; border-radius: 12px; border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,.15); z-index: 99999 !important; }
        .flatpickr-day.selected, .flatpickr-day.selected:hover
            { background-color: #6366f1 !important; border-color: #6366f1 !important; color: #fff !important; }
        .flatpickr-day.today { border-color: #6366f1 !important; }
        .flatpickr-day:hover { background: #e0e7ff !important; }

        /* Recurrence patterns */
        .recurrence-pattern {
            display: flex; flex-direction: row; align-items: center;
            gap: .75rem; padding: .75rem 1rem; border: 1px solid #e2e8f0;
            border-radius: 8px; cursor: pointer; transition: all .2s;
            min-width: 140px; flex: 1 1 140px;
        }
        .recurrence-pattern:hover   { border-color: #c7d2fe; background: #f8fafc; }
        .recurrence-pattern.selected { border-color: #6366f1; background: #eef2ff; color: #4338ca; }
        .recurrence-row { display: flex; flex-direction: row; flex-wrap: wrap; gap: 1.5rem; justify-content: center; }

        /* Skill tiers colour coding */
        .tier-1 { background:#f1f5f9; color:#475569; border-color:#cbd5e1; }
        .tier-2 { background:#dbeafe; color:#1d4ed8; border-color:#93c5fd; }
        .tier-3 { background:#dcfce7; color:#15803d; border-color:#86efac; }
        .tier-4 { background:#fef9c3; color:#854d0e; border-color:#fde047; }
        .tier-5 { background:#fee2e2; color:#b91c1c; border-color:#fca5a5; }

        @keyframes pulse { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-3px)} }
        .pulse-animate { animation: pulse 2s infinite; }
        @keyframes slideIn { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
        .notification-enter { animation: slideIn .35s ease-out forwards; }
    </style>
</head>
<body class="<?php echo getBodyClass(); ?>" style="font-size:<?php echo getFontSize(); ?>">
<div class="max-w-4xl mx-auto px-4 py-8">

    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Edit Quest</h1>
            <p class="text-gray-500 mt-1">Update an existing challenge for your team</p>
        </div>
        <a href="dashboard.php" class="btn btn-navigation btn-back">
            <i class="fas fa-arrow-left btn-icon"></i>
            <span class="btn-text">Back to Dashboard</span>
        </a>
    </div>

    <!-- Error / Success banners -->
    <?php if ($error): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-lg flex items-start">
        <i class="fas fa-exclamation-circle text-red-500 mt-1 mr-3"></i>
        <div>
            <h3 class="text-sm font-medium text-red-800">Error</h3>
            <p class="text-sm text-red-700 mt-1"><?php echo htmlspecialchars($error); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div id="successMessage" class="bg-green-50 border-l-4 border-green-500 p-4 mb-6 rounded-lg flex items-start">
        <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
        <div>
            <h3 class="text-sm font-medium text-green-800">Success</h3>
            <p class="text-sm text-green-700 mt-1"><?php echo htmlspecialchars($success); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">

        <!-- ═══════════════════════════════════════════════════════════════════
             SECTION 1 – BASIC INFORMATION
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="card p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                <i class="fas fa-info-circle text-indigo-500 mr-2"></i> Basic Information
            </h2>

            <!-- Quest Type (read-only) -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Quest Type</label>
                <div class="w-full px-4 py-2.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-700">
                    <?php
                    $display_label = 'Custom';
                    foreach ($quest_types as $qt) {
                        if ($qt['type_key'] === $display_type) { $display_label = $qt['name']; break; }
                    }
                    echo htmlspecialchars($display_label);
                    ?>
                </div>
                <p class="text-xs text-gray-500 mt-1">Quest type cannot be changed after creation.</p>
            </div>

            <!-- Title -->
            <div class="mb-6">
                <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Quest Title *</label>
                <input type="text" id="title" name="title"
                       value="<?php echo htmlspecialchars($title); ?>"
                       class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                       placeholder="Enter quest title" required maxlength="255">
            </div>

            <!-- Description -->
            <div class="mb-6">
                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description *</label>
                <textarea id="description" name="description" rows="5"
                          class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300"
                          placeholder="Describe the quest requirements and objectives"
                          required maxlength="2000"><?php echo htmlspecialchars($description); ?></textarea>
            </div>

            <!-- ── Client Call-specific fields ─────────────────────────────── -->
            <?php if ($display_type === 'client_call'): ?>
            <div id="clientDetails" class="space-y-4">
                <p class="text-xs text-gray-500 p-3 bg-blue-50 rounded-lg">
                    <strong>Aggregate Client Call Handling:</strong> This quest should cover all client calls handled for a specific shift, day, or reporting period. Please specify the period and upload a summary or log of all calls handled.<br>
                    <em>Example title:</em> "Client Call Handling for 2026-02-16 (Morning Shift)"<br>
                    <span class="italic">Attach a call log, ticket summary, or report as evidence.</span>
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="aggregation_date" class="block text-sm font-medium text-gray-700 mb-1">Date*</label>
                        <input type="date" id="aggregation_date" name="aggregation_date" value="<?php echo htmlspecialchars($aggregation_date); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" required>
                    </div>
                    <div>
                        <label for="aggregation_shift" class="block text-sm font-medium text-gray-700 mb-1">Shift*</label>
                        <select id="aggregation_shift" name="aggregation_shift" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" required>
                            <option value="" <?php echo ($aggregation_shift === '' ? 'selected' : ''); ?>>Select shift</option>
                            <option value="Morning" <?php echo ($aggregation_shift === 'Morning' ? 'selected' : ''); ?>>Morning</option>
                            <option value="Afternoon" <?php echo ($aggregation_shift === 'Afternoon' ? 'selected' : ''); ?>>Afternoon</option>
                            <option value="Evening" <?php echo ($aggregation_shift === 'Evening' ? 'selected' : ''); ?>>Evening</option>
                            <option value="Night" <?php echo ($aggregation_shift === 'Night' ? 'selected' : ''); ?>>Night</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="expected_output_file" class="block text-sm font-medium text-gray-700 mb-1">Expected Output Upload (sample)</label>
                    <?php if (!empty($expected_output_file)): ?>
                        <div class="mb-2 text-xs text-green-700 flex items-center gap-2">
                            <span>Current file:</span>
                            <a href="<?php echo htmlspecialchars($expected_output_file); ?>" target="_blank" class="underline text-blue-700">Download/View</a>
                            <a href="<?php echo htmlspecialchars($expected_output_file); ?>" download class="ml-2 px-2 py-1 bg-blue-100 text-blue-800 rounded hover:bg-blue-200">Download</a>
                        </div>
                    <?php endif; ?>
                    <input type="file" id="expected_output_file" name="expected_output_file" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg">
                    <span class="text-xs text-gray-500">Upload the expected output sample file that submitters should match. This will be shown on the quest view as "Expected Output" and saved to the quest_attachments table.</span>
                </div>
                <div>
                    <label for="aggregation_period" class="block text-sm font-medium text-gray-700 mb-1">Aggregation Period *</label>
                    <input type="text" id="aggregation_period" name="aggregation_period" value="<?php echo htmlspecialchars($aggregation_period); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="e.g., 2026-02-16 (Morning Shift)" required>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="client_contact_email" class="block text-sm font-medium text-gray-700 mb-1">Client Contact Email</label>
                        <input type="email" id="client_contact_email" name="client_contact_email" value="<?php echo htmlspecialchars($client_contact_email); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="name@client.com">
                    </div>
                    <div>
                        <label for="client_contact_phone" class="block text-sm font-medium text-gray-700 mb-1">Client Contact Phone</label>
                        <input type="text" id="client_contact_phone" name="client_contact_phone" value="<?php echo htmlspecialchars($client_contact_phone); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="+1 555 000 0000">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="sla_due_hours" class="block text-sm font-medium text-gray-700 mb-1">SLA Due (hours)</label>
                        <input type="number" id="sla_due_hours" name="sla_due_hours" min="0" step="1" value="<?php echo htmlspecialchars($sla_due_hours); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="24">
                    </div>
                    <div>
                        <label for="estimated_hours" class="block text-sm font-medium text-gray-700 mb-1">Estimated Effort (hrs)</label>
                        <input type="number" id="estimated_hours" name="estimated_hours" min="0" step="0.25" value="<?php echo htmlspecialchars($estimated_hours); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="3.5">
                    </div>
                    <div>
                        <label for="vendor_name" class="block text-sm font-medium text-gray-700 mb-1">Vendor / Provider</label>
                        <input type="text" id="vendor_name" name="vendor_name" value="<?php echo htmlspecialchars($vendor_name); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="Vendor name">
                    </div>
                </div>
                <div>
                    <label for="external_ticket_link" class="block text-sm font-medium text-gray-700 mb-1">External Ticket Link</label>
                    <input type="url" id="external_ticket_link" name="external_ticket_link" value="<?php echo htmlspecialchars($external_ticket_link); ?>" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg" placeholder="https://support.example.com/ticket/12345">
                </div>
                <div>
                    <label for="service_level_description" class="block text-sm font-medium text-gray-700 mb-1">Service Level / Notes</label>
                    <textarea id="service_level_description" name="service_level_description" rows="3" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg"><?php echo htmlspecialchars($service_level_description); ?></textarea>
                </div>
            </div>
            <?php endif; ?>

            <!-- Due Date & Time -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Due Date &amp; Time (Optional)</label>
                <div class="relative">
                    <button type="button" id="dueDateBtn"
                            onclick="toggleCalendarBox()"
                            class="w-full px-4 py-2.5 border border-indigo-200 rounded-lg bg-white hover:bg-gray-50 text-left flex items-center justify-between shadow-sm transition">
                        <span id="dueDateDisplay" class="text-gray-500">
                            <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i>
                            <?php echo $due_date ? 'Current: ' . htmlspecialchars($due_date) : 'Click to select due date and time'; ?>
                        </span>
                        <i class="fas fa-chevron-down text-gray-400" id="chevronIcon"></i>
                    </button>
                    <input type="hidden" id="due_date" name="due_date"
                           value="<?php echo htmlspecialchars($due_date); ?>">

                    <!-- Calendar box -->
                    <div id="calendarBox" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-xl z-50 mt-1 hidden">
                        <div class="p-4">
                            <div id="calendarContainer" class="mb-4"></div>
                            <div class="border-t pt-4">
                                <label class="text-sm font-medium text-gray-700 block mb-3">Select Time</label>
                                <div class="grid grid-cols-3 gap-3 mb-4">
                                    <div>
                                        <label class="text-xs text-gray-500 block mb-1">Hour</label>
                                        <input type="number" id="hourSelect" min="1" max="12" value="12"
                                               class="w-full text-center border border-gray-300 rounded-lg px-2 py-2 focus:ring-2 focus:ring-indigo-500"
                                               oninput="clearFieldError(this)">
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500 block mb-1">Minute</label>
                                        <input type="number" id="minuteSelect" min="0" max="59" value="0"
                                               class="w-full text-center border border-gray-300 rounded-lg px-2 py-2 focus:ring-2 focus:ring-indigo-500"
                                               oninput="clearFieldError(this)">
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500 block mb-1">AM/PM</label>
                                        <select id="ampmSelect"
                                                class="w-full border border-gray-300 rounded-lg px-2 py-2 focus:ring-2 focus:ring-indigo-500">
                                            <option value="AM">AM</option>
                                            <option value="PM">PM</option>
                                        </select>
                                    </div>
                                </div>
                                <!-- Quick time presets -->
                                <div class="flex flex-wrap gap-2 mb-4">
                                    <?php foreach (['9:00 AM','12:00 PM','3:00 PM','5:00 PM','6:00 PM'] as $t): ?>
                                    <button type="button" onclick="setQuickTime('<?php echo $t; ?>')"
                                            class="px-3 py-1 text-xs bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-full border border-indigo-200 transition">
                                        <?php echo $t; ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" onclick="applyDateTime()"
                                            class="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition">
                                        <i class="fas fa-check mr-2"></i>Apply
                                    </button>
                                    <button type="button" onclick="clearDueDate()"
                                            class="px-4 py-2.5 border border-gray-300 hover:bg-gray-50 text-gray-600 rounded-lg transition">
                                        Clear
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div><!-- /calendarBox -->
                </div>
            </div>

            <!-- Publish At removed for edit page -->

            <!-- Recurrence (only for recurring quest type) -->
            <?php if ($quest_type_value === 'recurring'): ?>
            <div id="recurringOptions" class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Recurrence Pattern</label>
                <div class="recurrence-row">
                    <?php
                    $patterns = [
                        'daily'   => ['fa-sun',      'Daily'],
                        'weekly'  => ['fa-calendar-week', 'Weekly'],
                        'monthly' => ['fa-calendar-alt',  'Monthly'],
                        'custom'  => ['fa-cog',       'Custom'],
                    ];
                    foreach ($patterns as $val => [$icon, $label]):
                        $selected = ($recurrence_pattern === $val) ? 'selected' : '';
                    ?>
                    <label class="recurrence-pattern <?php echo $selected; ?>">
                        <input type="radio" name="recurrence_pattern" value="<?php echo $val; ?>"
                               class="sr-only" <?php echo $selected ? 'checked' : ''; ?>>
                        <i class="fas <?php echo $icon; ?> text-indigo-500"></i>
                        <span><?php echo $label; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div id="recurrenceEndDateBox" class="mt-4 <?php echo in_array($recurrence_pattern, ['daily','weekly','monthly']) ? '' : 'hidden'; ?>">
                    <label for="recurrence_end_date" class="block text-sm font-medium text-gray-700 mb-1">Recurrence End Date</label>
                    <input type="text" id="recurrence_end_date" name="recurrence_end_date"
                           value="<?php echo htmlspecialchars($recurrence_end_date); ?>"
                           class="w-full px-4 py-2.5 border border-gray-200 rounded-lg"
                           placeholder="Select end date">
                </div>
            </div>
            <?php endif; ?>

            <!-- Visibility removed for edit page -->
        </div><!-- /SECTION 1 -->


        <!-- ═══════════════════════════════════════════════════════════════════
             SECTION 2 – SKILLS & ASSESSMENT
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="card p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                <i class="fas fa-brain text-indigo-500 mr-2"></i> Skills &amp; Assessment
            </h2>

            <!-- Selected skills summary badge area -->
            <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-medium text-blue-800">Selected Skills:</span>
                    <span class="text-sm text-gray-600"><span id="skillCount">0</span>/5 selected</span>
                </div>
                <div id="selectedSkillsBadges" class="flex flex-wrap gap-2">
                    <span class="text-xs text-blue-600 italic" id="noSkillsMessage">No skills selected yet</span>
                </div>
            </div>

            <!-- Category buttons -->
            <div class="flex flex-wrap gap-2 mb-4">
                <?php
                $cats = [
                    ['technical',    'fa-code',     'bg-blue-100 hover:bg-blue-200 text-blue-800 border-blue-300',     'Technical Skills'],
                    ['communication','fa-comments',  'bg-amber-100 hover:bg-amber-200 text-amber-800 border-amber-300',  'Communication Skills'],
                    ['soft',         'fa-heart',     'bg-rose-100 hover:bg-rose-200 text-rose-800 border-rose-300',      'Soft Skills'],
                    ['business',     'fa-briefcase', 'bg-emerald-100 hover:bg-emerald-200 text-emerald-800 border-emerald-300', 'Business Skills'],
                ];
                foreach ($cats as [$cid, $icon, $cls, $lbl]): ?>
                <button type="button"
                        onclick="showCategorySkills('<?php echo $cid; ?>', '<?php echo $lbl; ?>')"
                        class="skill-category-btn px-4 py-2 rounded-lg border transition-colors flex items-center <?php echo $cls; ?>">
                    <i class="fas <?php echo $icon; ?> mr-2"></i><?php echo $lbl; ?>
                </button>
                <?php endforeach; ?>
            </div>

            <p class="text-xs text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>
                Select 1–5 skills. Fewer skills = higher XP efficiency per skill.
            </p>

            <input type="hidden" name="quest_skills" id="questSkillsInput" value="">
        </div><!-- /SECTION 2 -->


        <!-- ═══════════════════════════════════════════════════════════════════
             SECTION 3 – ASSIGNMENT
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="card p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">
                <i class="fas fa-users text-indigo-500 mr-2"></i> Assignment
            </h2>

            <!-- Search -->
            <div class="relative mb-3">
                <input type="text" id="employeeSearch"
                       placeholder="Search employees by name or ID..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 text-sm"
                       oninput="filterEmployees()">
                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                <button type="button" id="clearSearchBtn" onclick="clearEmployeeSearch()"
                        class="absolute right-3 top-3 text-gray-400 hover:text-gray-600 hidden">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>

            <!-- Selected employees badges -->
            <div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg min-h-[40px]">
                <div class="text-xs font-medium text-blue-800 mb-1">Selected Employees:</div>
                <div id="selectedEmployeesBadges" class="flex flex-wrap gap-2">
                    <div class="text-xs text-blue-600 italic">No employees selected</div>
                </div>
            </div>

            <!-- Employee list -->
            <div class="border border-gray-200 rounded-lg max-h-48 overflow-y-auto bg-white">
                <div id="employeeList">
                    <?php foreach ($employees as $employee):
                        $emp_id        = $employee['employee_id'];
                        $user_stmt     = $pdo->prepare('SELECT id, last_name, first_name, middle_name, full_name FROM users WHERE employee_id = ? LIMIT 1');
                        $user_stmt->execute([$emp_id]);
                        $urow          = $user_stmt->fetch(PDO::FETCH_ASSOC);
                        $profile_uid   = $urow ? $urow['id'] : '';
                        $display_name  = $urow ? format_display_name($urow) : format_display_name(['full_name' => $employee['full_name']]);
                        $is_checked    = in_array($emp_id, $assign_to) ? 'checked' : '';
                    ?>
                    <label class="employee-item flex items-center p-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0"
                           data-name="<?php echo strtolower(htmlspecialchars($display_name)); ?>"
                           data-id="<?php echo strtolower(htmlspecialchars($emp_id)); ?>">
                        <input type="checkbox" name="assign_to[]"
                               value="<?php echo htmlspecialchars($emp_id); ?>"
                               class="employee-checkbox h-4 w-4 text-indigo-600 border-gray-300 rounded"
                               data-name="<?php echo htmlspecialchars($display_name); ?>"
                               data-employee-id="<?php echo htmlspecialchars($emp_id); ?>"
                               data-user-id="<?php echo htmlspecialchars($profile_uid); ?>"
                               onchange="handleEmployeeSelection(this)"
                               <?php echo $is_checked; ?>>
                        <div class="ml-2 flex-1">
                            <a class="text-sm font-medium text-gray-900 hover:underline"
                               href="profile_view.php?user_id=<?php echo urlencode($profile_uid); ?>">
                               <?php echo htmlspecialchars($display_name); ?>
                            </a>
                            <div class="text-xs text-gray-500">ID: <?php echo htmlspecialchars($emp_id); ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="noEmployeesFound" class="hidden p-4 text-center text-gray-500 text-sm">
                    <i class="fas fa-search text-2xl mb-2 block"></i>No employees found.
                </div>
            </div>
            <p class="mt-1 text-xs text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>
                Previously assigned employees are pre-ticked above.
            </p>
        </div><!-- /SECTION 3 -->


        <!-- Submit -->
        <div class="flex justify-center pt-2 pb-8">
            <button type="submit"
                    class="px-10 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold shadow-lg hover:shadow-xl transition flex items-center">
                <i class="fas fa-save mr-2"></i> Update Quest
            </button>
        </div>

    </form>
</div><!-- /container -->


<!-- ═══════════════════════════════════════════════════════════════════════════
     SKILLS MODAL
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="skillsModalEdit" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[80vh] flex flex-col">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                <span id="modalCategoryIconEdit" class="mr-2"></span>
                <span id="modalCategoryTitleEdit">Select Skills</span>
            </h3>
            <button type="button" onclick="closeSkillsModalEdit()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-4 border-b border-gray-200">
            <div class="relative">
                <input type="text" id="skillSearchInputEdit" placeholder="Search skills..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500"
                       oninput="filterSkillsEdit()">
                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
            </div>
        </div>
        <div class="flex-1 overflow-y-auto p-4">
            <div id="skillsListEdit" class="space-y-2"></div>
            <div id="noSkillsFoundEdit" class="hidden text-center py-8 text-gray-500">
                <i class="fas fa-search text-3xl mb-2 block"></i>No skills found.
            </div>
        </div>
        <div class="p-4 border-t border-gray-200 flex items-center justify-between">
            <button type="button" onclick="showCustomSkillModal()"
                    class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg border border-gray-300 flex items-center">
                <i class="fas fa-plus mr-2"></i>Add Custom Skill
            </button>
            <div class="flex gap-3">
                <button type="button" onclick="closeSkillsModalEdit()"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
                <button id="applySkillsBtnEdit" type="button" onclick="applySelectedSkillsFromModal()"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Apply Selected Skills</button>
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════════
     CUSTOM SKILL MODAL
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="customSkillModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-800">
                <i class="fas fa-plus-circle text-indigo-500 mr-2"></i>Add Custom Skill
            </h3>
            <button type="button" onclick="closeCustomSkillModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Category: <span id="modalCategoryName" class="text-indigo-600 font-semibold"></span>
            </label>
            <input type="hidden" id="modalCategoryId" value="">
        </div>
        <div class="mb-4">
            <label for="customSkillName" class="block text-sm font-medium text-gray-700 mb-1">Skill Name *</label>
            <input type="text" id="customSkillName"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500"
                   placeholder="e.g., Python Programming" maxlength="100">
        </div>
        <div class="mb-6">
            <label for="customSkillTier" class="block text-sm font-medium text-gray-700 mb-1">Skill Tier *</label>
            <select id="customSkillTier"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500"
                    onchange="updateCustomSkillTierInfo()"></select>
            <div id="customSkillTierInfo" class="mt-2 text-xs text-gray-600 font-semibold flex items-center"></div>
        </div>
        <div class="flex gap-3">
            <button type="button" onclick="closeCustomSkillModal()"
                    class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Cancel</button>
            <button type="button" onclick="addCustomSkill()"
                    class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                <i class="fas fa-plus mr-1"></i>Add Skill
            </button>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════════════════════════ -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js"></script>
<script>
// ─── SERVER-SIDE DATA ─────────────────────────────────────────────────────────
const serverQuestType  = <?php echo json_encode($quest_type_value); ?>;
const serverDisplayType = <?php echo json_encode($display_type); ?>;
let   allSkills         = <?php echo json_encode($all_skills); ?>;

// Deduplicate allSkills
(function(){
    const seen = new Set(), uniq = [];
    allSkills.forEach(s => {
        const k = ((s.category_name||'') + '::' + (s.skill_name||'')).toLowerCase().trim();
        if (!seen.has(k)) { seen.add(k); uniq.push(s); }
    });
    allSkills = uniq;
})();

// ─── TIER HELPERS ─────────────────────────────────────────────────────────────
const questTypeTierPoints = {
    routine:  [2, 4, 6, 8, 10],
    minor:    [5, 10, 15, 20, 25],
    standard: [10, 20, 30, 40, 50],
    major:    [20, 40, 60, 80, 100],
    project:  [40, 80, 120, 160, 200],
};
const TIER_NAMES = ['Beginner','Intermediate','Advanced','Expert','Master'];

function getTierPoints(tier, qt) {
    return (questTypeTierPoints[qt] || questTypeTierPoints.routine)[tier - 1] || 0;
}
function generateTierOptions(selectedTier, qt) {
    return TIER_NAMES.map((n, i) => {
        const t = i + 1, pts = getTierPoints(t, qt);
        return `<option value="${t}" ${selectedTier==t?'selected':''}>${'Tier ' + t} – ${n} (${pts} pts)</option>`;
    }).join('');
}
function updateCustomSkillTierInfo() {
    const tier = parseInt(document.getElementById('customSkillTier').value) || 2;
    const pts  = getTierPoints(tier, serverQuestType);
    document.getElementById('customSkillTierInfo').innerHTML =
        `<span class="inline-block px-2 py-1 rounded bg-indigo-50 text-indigo-700 border border-indigo-200 mr-2">
            Tier ${tier} – ${TIER_NAMES[tier-1]}
         </span>
         <span class="inline-block px-2 py-1 rounded bg-green-50 text-green-700 border border-green-200">
            ${pts} base points
         </span>`;
}

// ─── SKILL STATE ──────────────────────────────────────────────────────────────
const MAX_SKILLS       = 5;
let selectedSkills     = new Set();   // string skill IDs
let skillTiers         = {};          // {skillId: tier number}
let skillNamesCache    = {};          // {skillId: name}
let skillCatsCache     = {};          // {skillId: category_name}
let customSkillCounter = 1000;
let tempSelectedSkills = new Set();
let currentCategory    = '';

// ─── INIT: load existing quest skills ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    const existingSkills = <?php echo json_encode($quest_skills); ?>;
    existingSkills.forEach(sk => {
        const sid  = String(sk.skill_id);
        const tier = parseInt(sk.tier) || 2;
        selectedSkills.add(sid);
        skillTiers[sid]     = tier;
        skillNamesCache[sid] = sk.skill_name     || '';
        skillCatsCache[sid]  = sk.category_name  || '';
    });
    updateSkillDisplay();

    // Init tier dropdowns in custom modal
    updateAllTierDropdowns();

    // Init employee pre-selections
    document.querySelectorAll('.employee-checkbox:checked').forEach(cb => handleEmployeeSelection(cb));

    // Init due-date display
    const existingDueDate = document.getElementById('due_date').value;
    if (existingDueDate) { updateDateDisplay(existingDueDate); selectedDate = existingDueDate.split(' ')[0]; }

    // Flatpickr for publish_at and recurrence end date
    if (document.getElementById('publish_at'))
        flatpickr('#publish_at', { enableTime:true, dateFormat:'Y-m-d h:i K', minDate:'today', time_24hr:false });
    if (document.getElementById('recurrence_end_date'))
        flatpickr('#recurrence_end_date', { enableTime:false, dateFormat:'Y-m-d', minDate:'today' });

    // Recurrence end date box show/hide
    document.querySelectorAll('input[name="recurrence_pattern"]').forEach(r => {
        r.addEventListener('change', function () {
            const box = document.getElementById('recurrenceEndDateBox');
            if (box) box.classList.toggle('hidden', !['daily','weekly','monthly'].includes(this.value));
        });
    });

    // Insert focus efficiency hint
    const badges = document.getElementById('selectedSkillsBadges');
    if (badges && !document.getElementById('focus-hint')) {
        const hint = document.createElement('div');
        hint.id = 'focus-hint';
        hint.className = 'mt-2 text-xs text-indigo-600';
        hint.textContent = 'Tip: 1–2 skills = 100% XP each | 3 = 90% | 4 = 75% | 5 = 60%';
        badges.parentElement.insertBefore(hint, badges.nextSibling);
    }
});

function updateAllTierDropdowns() {
    document.querySelectorAll('.tier-select').forEach(sel => {
        const cur = parseInt(sel.value) || 2;
        sel.innerHTML = generateTierOptions(cur, serverQuestType);
    });
    const cs = document.getElementById('customSkillTier');
    if (cs) {
        cs.innerHTML = generateTierOptions(parseInt(cs.value)||2, serverQuestType);
        updateCustomSkillTierInfo();
    }
}

// ─── SKILL DISPLAY ────────────────────────────────────────────────────────────
function updateSkillDisplay() {
    document.getElementById('skillCount').textContent = selectedSkills.size;
    const badges = document.getElementById('selectedSkillsBadges');
    const input  = document.getElementById('questSkillsInput');

    if (selectedSkills.size === 0) {
        badges.innerHTML = '<span class="text-xs text-blue-600 italic">No skills selected yet</span>';
        input.value = '[]';
        return;
    }

    const tierClasses = ['','tier-1','tier-2','tier-3','tier-4','tier-5'];
    badges.innerHTML = Array.from(selectedSkills).map(sid => {
        const tier     = skillTiers[sid] || 2;
        let   name     = skillNamesCache[sid] || '';
        let   catName  = skillCatsCache[sid]  || '';

        if (!name) {
            const found = allSkills.find(s => String(s.skill_id) === sid);
            if (found) { name = found.skill_name; catName = found.category_name; }
        }
        name = name || 'Unknown Skill';

        const isCustom = sid.startsWith('custom_');
        const customTag = isCustom ? '<span class="ml-1 text-yellow-600">(Custom)</span>' : '';
        const catTag    = (!isCustom && catName) ? `<span class="ml-1 text-gray-500 text-xs">(${catName})</span>` : '';

        return `<span class="inline-flex items-center px-3 py-1 rounded-full border text-xs ${tierClasses[tier] || tierClasses[2]}">
                    <span class="font-medium">${name}</span>${customTag}${catTag}
                    <span class="ml-2 bg-white px-2 py-0.5 rounded-full font-bold">${TIER_NAMES[tier-1]}</span>
                    <button type="button" onclick="removeSkill('${sid}')" class="ml-2 text-gray-400 hover:text-red-500">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </span>`;
    }).join('');

    input.value = JSON.stringify(
        Array.from(selectedSkills).map(sid => ({ skill_id: sid, tier: skillTiers[sid] || 2 }))
    );
}

function removeSkill(sid) {
    selectedSkills.delete(String(sid));
    delete skillTiers[sid];
    updateSkillDisplay();
}

// ─── SKILLS MODAL ─────────────────────────────────────────────────────────────
const categoryConfigEdit = {
    technical:     { name: 'Technical Skills',     icon: '<i class="fas fa-code"></i>' },
    communication: { name: 'Communication Skills', icon: '<i class="fas fa-comments"></i>' },
    soft:          { name: 'Soft Skills',           icon: '<i class="fas fa-heart"></i>' },
    business:      { name: 'Business Skills',       icon: '<i class="fas fa-briefcase"></i>' },
};
const catMap = {
    'Technical Skills':'technical', 'Communication Skills':'communication',
    'Soft Skills':'soft',           'Business Skills':'business',
};

function showCategorySkills(categoryId, categoryName) {
    currentCategory = categoryId;
    const cfg = categoryConfigEdit[categoryId] || {};
    document.getElementById('modalCategoryIconEdit').innerHTML  = cfg.icon || '';
    document.getElementById('modalCategoryTitleEdit').textContent = cfg.name || categoryName;

    const filtered = allSkills.filter(s => catMap[s.category_name] === categoryId);

    // Snapshot current selections into temp set
    tempSelectedSkills.clear();
    selectedSkills.forEach(sid => {
        const sk = allSkills.find(s => String(s.skill_id) === sid);
        if (sk) tempSelectedSkills.add({ id: sid, name: sk.skill_name, tier: skillTiers[sid] || 2 });
    });

    populateSkillsListEdit(filtered);
    document.getElementById('skillsModalEdit').classList.remove('hidden');
    document.getElementById('skillSearchInputEdit').value = '';
    document.getElementById('skillSearchInputEdit').focus();
}

function populateSkillsListEdit(skills) {
    const list = document.getElementById('skillsListEdit');
    const none = document.getElementById('noSkillsFoundEdit');
    list.innerHTML = '';

    if (!skills.length) { none.classList.remove('hidden'); return; }
    none.classList.add('hidden');

    skills.forEach(skill => {
        const sid        = String(skill.skill_id);
        const isSelected = selectedSkills.has(sid);
        const curTier    = skillTiers[sid] || 2;

        const wrapper = document.createElement('div');
        wrapper.className      = 'skill-item border border-gray-200 rounded-lg p-3 hover:bg-gray-50 transition-colors';
        wrapper.dataset.skillId   = sid;
        wrapper.dataset.skillName = skill.skill_name;
        wrapper.innerHTML = `
            <label class="flex items-start cursor-pointer">
                <input type="checkbox" class="skill-modal-checkbox mt-1 h-4 w-4 text-indigo-600 border-gray-300 rounded"
                       data-skill-id="${sid}" data-skill-name="${skill.skill_name}" data-category-name="${skill.category_name}"
                       ${isSelected ? 'checked' : ''}>
                <div class="ml-3 flex-1">
                    <div class="text-sm font-medium text-gray-900">${skill.skill_name}</div>
                    <div class="mt-2 ${isSelected ? '' : 'hidden'}" id="tier-edit-${sid}">
                        <select class="tier-selector-edit text-xs border border-gray-300 rounded px-2 py-1 bg-white"
                                data-skill-id="${sid}">
                            ${generateTierOptions(curTier, serverQuestType)}
                        </select>
                        <div class="text-xs text-gray-500 mt-1" id="tier-pts-${sid}">
                            Base Points: ${getTierPoints(curTier, serverQuestType)} (${TIER_NAMES[curTier-1]})
                        </div>
                    </div>
                </div>
            </label>`;

        const cb  = wrapper.querySelector('.skill-modal-checkbox');
        const sel = wrapper.querySelector('.tier-selector-edit');
        const pts = wrapper.querySelector(`#tier-pts-${sid}`);

        if (sel) {
            sel.value = String(curTier);
            sel.addEventListener('change', function () {
                const t = parseInt(this.value);
                if (pts) pts.textContent = `Base Points: ${getTierPoints(t, serverQuestType)} (${TIER_NAMES[t-1]})`;
            });
        }

        cb.addEventListener('change', function () {
            if (this.checked) {
                if (selectedSkills.size >= MAX_SKILLS && !selectedSkills.has(sid)) {
                    alert(`You can only select up to ${MAX_SKILLS} skills.`);
                    this.checked = false; return;
                }
                wrapper.querySelector(`#tier-edit-${sid}`).classList.remove('hidden');
            } else {
                wrapper.querySelector(`#tier-edit-${sid}`).classList.add('hidden');
            }
        });

        list.appendChild(wrapper);
    });
}

function filterSkillsEdit() {
    const term = document.getElementById('skillSearchInputEdit').value.toLowerCase();
    let visible = 0;
    document.querySelectorAll('#skillsListEdit .skill-item').forEach(it => {
        const match = (it.dataset.skillName || '').toLowerCase().includes(term);
        it.style.display = match ? 'block' : 'none';
        if (match) visible++;
    });
    document.getElementById('noSkillsFoundEdit').classList.toggle('hidden', visible > 0 || term.length === 0);
}

function applySelectedSkillsFromModal() {
    document.querySelectorAll('#skillsListEdit .skill-modal-checkbox:checked').forEach(cb => {
        const sid = String(cb.dataset.skillId);
        if (!selectedSkills.has(sid) && selectedSkills.size >= MAX_SKILLS) return; // skip extras
        selectedSkills.add(sid);
        const sel = document.querySelector(`.tier-selector-edit[data-skill-id="${sid}"]`);
        skillTiers[sid]      = sel ? parseInt(sel.value) : 2;
        skillNamesCache[sid] = cb.dataset.skillName   || skillNamesCache[sid] || '';
        skillCatsCache[sid]  = cb.dataset.categoryName || skillCatsCache[sid] || '';
    });
    // Remove unchecked skills from this category
    document.querySelectorAll('#skillsListEdit .skill-modal-checkbox:not(:checked)').forEach(cb => {
        const sid = String(cb.dataset.skillId);
        selectedSkills.delete(sid);
        delete skillTiers[sid];
    });

    updateSkillDisplay();
    closeSkillsModalEdit();
}

function closeSkillsModalEdit() {
    const modal = document.getElementById('skillsModalEdit');
    $(modal).fadeOut(180, function () {
        modal.classList.add('hidden');
        $(modal).css('display', '');
        document.getElementById('skillSearchInputEdit').value = '';
        tempSelectedSkills.clear();
    });
}

// ─── CUSTOM SKILL MODAL ───────────────────────────────────────────────────────
function showCustomSkillModal(categoryId, categoryName) {
    document.getElementById('customSkillModal').classList.remove('hidden');
    document.getElementById('modalCategoryId').value   = categoryId   || currentCategory;
    document.getElementById('modalCategoryName').textContent = categoryName || currentCategory;
    document.getElementById('customSkillName').value   = '';
    updateAllTierDropdowns();
    document.getElementById('customSkillTier').value   = '2';
    updateCustomSkillTierInfo();
    document.getElementById('customSkillName').focus();
}
function closeCustomSkillModal() {
    document.getElementById('customSkillModal').classList.add('hidden');
}
function addCustomSkill() {
    const name = document.getElementById('customSkillName').value.trim();
    const tier = parseInt(document.getElementById('customSkillTier').value) || 2;
    if (!name) { alert('Please enter a skill name.'); return; }
    if (selectedSkills.size >= MAX_SKILLS) { alert(`Maximum ${MAX_SKILLS} skills allowed.`); return; }

    const cid = `custom_${customSkillCounter++}`;
    selectedSkills.add(cid);
    skillTiers[cid]     = tier;
    skillNamesCache[cid] = name;
    skillCatsCache[cid]  = 'Custom';

    updateSkillDisplay();
    closeCustomSkillModal();
    closeSkillsModalEdit();
}
document.getElementById('customSkillName').addEventListener('keypress', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); addCustomSkill(); }
});

// ─── CALENDAR / DUE DATE ──────────────────────────────────────────────────────
let calendarBoxOpen = false;
let calendarInstance = null;
let selectedDate = null;
const MIN_LEAD_MS = <?php echo $minLeadSeconds * 1000; ?>;

function toggleCalendarBox() { calendarBoxOpen ? closeCalendarBox() : openCalendarBox(); }

function openCalendarBox() {
    document.getElementById('calendarBox').classList.remove('hidden');
    document.getElementById('chevronIcon').classList.replace('fa-chevron-down','fa-chevron-up');
    calendarBoxOpen = true;
    if (!calendarInstance) {
        calendarInstance = flatpickr('#calendarContainer', {
            inline: true, dateFormat: 'Y-m-d', minDate: 'today', defaultDate: 'today',
            onChange: (d, s) => { selectedDate = s; }
        });
        selectedDate = new Date().toISOString().split('T')[0];
    }
    setDefaultTime();
    setTimeout(() => document.addEventListener('click', handleOutsideClick), 100);
}

function closeCalendarBox() {
    document.getElementById('calendarBox').classList.add('hidden');
    document.getElementById('chevronIcon').classList.replace('fa-chevron-up','fa-chevron-down');
    calendarBoxOpen = false;
    document.removeEventListener('click', handleOutsideClick);
}

function handleOutsideClick(e) {
    if (!document.getElementById('calendarBox').contains(e.target) &&
        !document.getElementById('dueDateBtn').contains(e.target)) closeCalendarBox();
}

function setDefaultTime() {
    const now = new Date();
    let h = now.getHours(), m = Math.ceil(now.getMinutes() / 15) * 15;
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    document.getElementById('hourSelect').value   = h;
    document.getElementById('minuteSelect').value = m;
    document.getElementById('ampmSelect').value   = ampm;
}

function setQuickTime(t) {
    const [time, ampm] = t.split(' ');
    const [h, m] = time.split(':');
    document.getElementById('hourSelect').value   = parseInt(h);
    document.getElementById('minuteSelect').value = parseInt(m);
    document.getElementById('ampmSelect').value   = ampm;
}

function applyDateTime() {
    if (!selectedDate) selectedDate = new Date().toISOString().split('T')[0];
    const h  = parseInt(document.getElementById('hourSelect').value);
    const m  = parseInt(document.getElementById('minuteSelect').value);
    const ap = document.getElementById('ampmSelect').value;

    if (isNaN(h) || h < 1 || h > 12) { showCalendarError('Hour must be 1–12.'); return; }
    if (isNaN(m) || m < 0 || m > 59) { showCalendarError('Minute must be 0–59.'); return; }

    let h24 = h;
    if (ap === 'PM' && h24 !== 12) h24 += 12;
    if (ap === 'AM' && h24 === 12) h24 = 0;

    const pad = n => String(n).padStart(2,'0');
    const dt  = new Date(`${selectedDate}T${pad(h24)}:${pad(m)}:00`);
    if (dt.getTime() <= Date.now() + MIN_LEAD_MS) {
        showCalendarError(`Please pick a time at least ${MIN_LEAD_MS/60000} minutes in the future.`); return;
    }
    const str = `${selectedDate} ${pad(h24)}:${pad(m)}:00`;
    document.getElementById('due_date').value = str;
    updateDateDisplay(str);
    closeCalendarBox();
}

function showCalendarError(msg) {
    const ex = document.getElementById('cal-error');
    if (ex) ex.remove();
    const d = document.createElement('div');
    d.id = 'cal-error';
    d.className = 'mt-2 px-3 py-2 bg-red-100 border border-red-300 text-red-700 rounded-lg text-sm flex items-center';
    d.innerHTML = `<i class="fas fa-exclamation-triangle mr-2"></i>${msg}`;
    const grid = document.querySelector('#calendarBox .grid');
    if (grid) grid.parentNode.insertBefore(d, grid.nextSibling);
    setTimeout(() => { if (d.parentNode) d.remove(); }, 5000);
}
function clearFieldError(input) { input.classList.remove('border-red-500','bg-red-50'); const e=document.getElementById('cal-error'); if(e)e.remove(); }

function clearDueDate() {
    document.getElementById('due_date').value = '';
    selectedDate = null;
    if (calendarInstance) calendarInstance.clear();
    updateDateDisplay('');
    closeCalendarBox();
}

function updateDateDisplay(str) {
    const span = document.getElementById('dueDateDisplay');
    if (str) {
        const d = new Date(str);
        span.innerHTML = `<i class="fas fa-calendar-check mr-2 text-green-500"></i>${d.toLocaleString('en-US',{weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit',hour12:true})}`;
        span.classList.replace('text-gray-500','text-gray-900');
        span.classList.add('font-medium');
    } else {
        span.innerHTML = `<i class="fas fa-calendar-alt mr-2 text-indigo-500"></i>Click to select due date and time`;
        span.classList.replace('text-gray-900','text-gray-500');
        span.classList.remove('font-medium');
    }
}

// ─── EMPLOYEE SELECTION ───────────────────────────────────────────────────────
let selectedEmployees = new Set();

function filterEmployees() {
    const term = document.getElementById('employeeSearch').value.toLowerCase();
    document.getElementById('clearSearchBtn').style.display = term ? 'block' : 'none';
    let visible = 0;
    document.querySelectorAll('.employee-item').forEach(it => {
        const match = it.dataset.name.includes(term) || it.dataset.id.includes(term);
        it.style.display = match ? 'flex' : 'none';
        if (match) visible++;
    });
    document.getElementById('noEmployeesFound').classList.toggle('hidden', !(visible===0 && term));
}

function clearEmployeeSearch() {
    document.getElementById('employeeSearch').value = '';
    document.getElementById('clearSearchBtn').style.display = 'none';
    filterEmployees();
}

function handleEmployeeSelection(cb) {
    const id = cb.dataset.employeeId, name = cb.dataset.name, uid = cb.dataset.userId || '';
    if (cb.checked) { selectedEmployees.add({ id, name, uid }); }
    else { selectedEmployees.forEach(e => { if (e.id === id) selectedEmployees.delete(e); }); }
    updateSelectedEmployeesDisplay();
}

function updateSelectedEmployeesDisplay() {
    const c = document.getElementById('selectedEmployeesBadges');
    if (!selectedEmployees.size) { c.innerHTML = '<div class="text-xs text-blue-600 italic">No employees selected</div>'; return; }
    c.innerHTML = Array.from(selectedEmployees).map(e =>
        `<div class="inline-flex items-center px-3 py-1 bg-indigo-100 text-indigo-800 text-xs rounded-full border border-indigo-200">
            <span class="font-medium">${e.name}</span>
            <span class="ml-1 text-indigo-600">(${e.id})</span>
            <button type="button" onclick="removeEmployee('${e.id}')" class="ml-2 text-indigo-600 hover:text-indigo-800">
                <i class="fas fa-times text-xs"></i>
            </button>
         </div>`
    ).join('');
}

function removeEmployee(id) {
    const cb = document.querySelector(`.employee-checkbox[value="${id}"]`);
    if (cb) { cb.checked = false; handleEmployeeSelection(cb); }
}

// ─── FORM VALIDATION ──────────────────────────────────────────────────────────
document.querySelector('form').addEventListener('submit', function (e) {
    if (selectedSkills.size === 0) {
        e.preventDefault();
        alert('Please select at least one skill for this quest.');
    }
});

// ─── SUCCESS FEEDBACK (chime + confetti) ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('successMessage');
    if (!el) return;
    el.classList.add('pulse-animate');
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine'; o.frequency.setValueAtTime(880, ctx.currentTime);
        g.gain.setValueAtTime(0, ctx.currentTime);
        g.gain.linearRampToValueAtTime(0.18, ctx.currentTime + .01);
        g.gain.exponentialRampToValueAtTime(.001, ctx.currentTime + .6);
        o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + .7);
    } catch(e) {}
    const cols = ['#FFD166','#06D6A0','#118AB2','#EF476F','#FFD700'];
    for (let i = 0; i < 12; i++) {
        const d = document.createElement('div');
        Object.assign(d.style, {
            position:'fixed', left:(50+Math.random()*40-20)+'%', top:(30+Math.random()*10)+'%',
            width:'10px', height:'10px', borderRadius:'3px', opacity:'.95', zIndex:99999,
            background: cols[Math.floor(Math.random()*cols.length)],
            transform:'translateY(0) scale(1)',
            transition:'transform 900ms cubic-bezier(.2,.8,.2,1),opacity 900ms ease-out'
        });
        document.body.appendChild(d);
        setTimeout(() => {
            d.style.transform = `translateY(${-120-Math.random()*140}px) rotate(${Math.random()*360}deg) scale(1.2)`;
            d.style.opacity = '0';
        }, 20 + i*30);
        setTimeout(() => document.body.removeChild(d), 1200 + i*30);
    }
});
</script>
</body>
</html>
