<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$employee_id = $_SESSION['employee_id'] ?? null;
$full_name = $_SESSION['full_name'] ?? 'User';

// Try to map to users.id where possible
$user_id = null;
try {
    if (!empty($employee_id) && isset($pdo)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$employee_id]);
        $user_id = $stmt->fetchColumn() ?: null;
    }
} catch (Exception $e) {
    // ignore mapping errors
}

// Handle simple assignment action (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_quest_id']) && !empty($_POST['assign_to'])) {
    $assign_quest_id = (int)$_POST['assign_quest_id'];
    // Accept alphanumeric employee IDs (e.g. QL003, SA001) — do not cast to int
    $assign_to = array_values(array_filter(array_map('trim', (array)$_POST['assign_to'])));
    if ($assign_quest_id > 0 && count($assign_to) > 0 && isset($pdo)) {
        try {
            // verify creator and status
            $stmt = $pdo->prepare("SELECT created_by, status FROM quests WHERE id = ?");
            $stmt->execute([$assign_quest_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $createdBy = $row['created_by'] ?? null;
            $status = strtolower($row['status'] ?? '');

            // Normalize created_by and identifiers to allow various storage formats
            $norm = function($v) { return strtolower(trim((string)($v ?? ''))); };
            $createdByNorm = $norm($createdBy);
            $empNorm = $norm($employee_id);
            $userNorm = $norm($user_id);
            $userPrefixed = $norm('user_' . (string)$user_id);

            $allowed = $row && (
                ($empNorm !== '' && $createdByNorm === $empNorm) ||
                ($userNorm !== '' && $createdByNorm === $userNorm) ||
                ($userPrefixed !== '' && $createdByNorm === $userPrefixed)
            );

            if (!$allowed) {
                $_SESSION['error'] = 'You do not have permission to assign this quest or it is already assigned.';
            } elseif (!in_array($status, ['created','active','assigned'])) {
                $_SESSION['error'] = 'This quest cannot be assigned in its current state.';
            } else {
                $pdo->beginTransaction();
                // prepared insert (use INSERT IGNORE if supported by your DB)
                $insertStmt = $pdo->prepare("INSERT INTO user_quests (employee_id, quest_id, status, assigned_at) VALUES (?, ?, 'assigned', NOW())");
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM user_quests WHERE quest_id = ? AND employee_id = ?");
                foreach ($assign_to as $emp_id) {
                    $emp_id = trim((string)$emp_id);
                    if ($emp_id === '') continue;
                    try {
                        $checkStmt->execute([$assign_quest_id, $emp_id]);
                        if ($checkStmt->fetchColumn() == 0) {
                            $insertStmt->execute([$emp_id, $assign_quest_id]);
                        }
                    } catch (PDOException $e) {
                        // non-fatal per-assignee error; continue
                        error_log('Assignment insert error for employee ' . $emp_id . ': ' . $e->getMessage());
                    }
                }
                $pdo->commit();

                // Verify assignments exist and update quest status to 'assigned'
                try {
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM user_quests WHERE quest_id = ?");
                    $cntStmt->execute([$assign_quest_id]);
                    $countAssigned = (int)$cntStmt->fetchColumn();
                    if ($countAssigned > 0) {
                        $updateStatus = $pdo->prepare("UPDATE quests SET status = 'assigned' WHERE id = ?");
                        $updateStatus->execute([$assign_quest_id]);
                    }
                } catch (PDOException $e) {
                    error_log('Failed to verify/update quest status after assignment: ' . $e->getMessage());
                }

                $_SESSION['success'] = 'Quest assigned successfully.';
            }
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Error assigning quest: ' . $e->getMessage());
            $_SESSION['error'] = 'Error assigning quest.';
        }
    }
    header('Location: created_quests.php');
    exit();
}

$page_title = 'My Created Quests';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$employee_id = $_SESSION['employee_id'] ?? null;
$full_name = $_SESSION['full_name'] ?? 'User';

// Fetch user id and role if available
try {
    $stmt = $pdo->prepare("SELECT id, role, email FROM users WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_id = $userRow['id'] ?? null;
    $email = $userRow['email'] ?? $_SESSION['email'] ?? '';
    $role = $userRow['role'] ?? $_SESSION['role'] ?? 'skill_associate';
} catch (PDOException $e) {
    error_log('Error fetching user info in created_quests: ' . $e->getMessage());
    $user_id = null;
    $email = $_SESSION['email'] ?? '';
    $role = $_SESSION['role'] ?? 'skill_associate';
}

// Normalize role names used across the app
if ($role === 'quest_taker') { $role = 'skill_associate'; }
elseif ($role === 'quest_giver') { $role = 'quest_lead'; }
elseif ($role === 'participant') { $role = 'skill_associate'; }
elseif ($role === 'contributor') { $role = 'quest_lead'; }
elseif ($role === 'learning_architect') { $role = 'quest_lead'; }

// Small mapping for badge class
$role_badge_class = [
    'skill_associate' => 'px-2 py-1 bg-green-100 text-green-800 rounded text-sm font-medium',
    'quest_lead' => 'px-2 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium',
][$role] ?? 'px-2 py-1 bg-gray-100 text-gray-800 rounded text-sm font-medium';
// user_id already retrieved earlier in $userRow

// Pagination (read page early so redirects can preserve it)
$items_per_page = 12;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Handle "Save as draft" action (creator only) BEFORE output so we can redirect (PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_draft_id'])) {
    $sid = (int)$_POST['save_draft_id'];
    if ($sid > 0) {
        try {
            // Verify creator
            $stmt = $pdo->prepare("SELECT created_by FROM quests WHERE id = ?");
            $stmt->execute([$sid]);
            $createdBy = $stmt->fetchColumn();
            $allowed = ($createdBy === (string)$employee_id) || ($createdBy === (string)$user_id) || (strtolower(trim((string)$createdBy)) === strtolower('user_' . (string)$user_id));
            if (!$allowed) {
                $_SESSION['error'] = 'You do not have permission to change this quest.';
            } else {
                // Prevent saving as draft if there are any submissions
                $subStmt = $pdo->prepare("SELECT COUNT(*) FROM quest_submissions WHERE quest_id = ?");
                $subStmt->execute([$sid]);
                $submissionCount = (int)$subStmt->fetchColumn();
                if ($submissionCount > 0) {
                    $_SESSION['error'] = 'This quest cannot be saved as a draft because users have already submitted responses to it.';
                } else {
                    // Attempt to set status to 'draft' if supported; if DB rejects, fallback to 'deleted' as a last resort
                    try {
                        $u = $pdo->prepare("UPDATE quests SET status = 'draft' WHERE id = ?");
                        $u->execute([$sid]);
                        $_SESSION['success'] = 'Quest saved as draft.';
                    } catch (PDOException $e) {
                        error_log('Draft status unsupported, falling back to deleted. Error: ' . $e->getMessage());
                        try {
                            $u2 = $pdo->prepare("UPDATE quests SET status = 'deleted' WHERE id = ?");
                            $u2->execute([$sid]);
                            $_SESSION['success'] = 'Quest saved as draft (fallback).';
                        } catch (PDOException $e2) {
                            error_log('Failed to save draft/fallback delete: ' . $e2->getMessage());
                            $_SESSION['error'] = 'Error saving quest as draft.';
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('Error saving quest as draft: ' . $e->getMessage());
            $_SESSION['error'] = 'Error saving quest as draft.';
        }
    }
    // Redirect to avoid resubmission and to show flash message
    header('Location: created_quests.php?page=' . $page);
    exit();
}

// Handle "Publish" action (creator only) - make a draft active again
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_id'])) {
    $pid = (int)$_POST['publish_id'];
    if ($pid > 0) {
        try {
            // Verify creator
            $stmt = $pdo->prepare("SELECT created_by FROM quests WHERE id = ?");
            $stmt->execute([$pid]);
            $createdBy = $stmt->fetchColumn();
            $allowed = ($createdBy === (string)$employee_id) || ($createdBy === (string)$user_id) || (strtolower(trim((string)$createdBy)) === strtolower('user_' . (string)$user_id));
            if (!$allowed) {
                $_SESSION['error'] = 'You do not have permission to publish this quest.';
            } else {
                try {
                    $u = $pdo->prepare("UPDATE quests SET status = 'active' WHERE id = ?");
                    $u->execute([$pid]);
                    $_SESSION['success'] = 'Quest published and is now visible to users.';
                } catch (PDOException $e) {
                    error_log('Failed to publish quest (status update): ' . $e->getMessage());
                    $_SESSION['error'] = 'Error publishing quest.';
                }
            }
        } catch (PDOException $e) {
            error_log('Error publishing quest: ' . $e->getMessage());
            $_SESSION['error'] = 'Error publishing quest.';
        }
    }
    header('Location: created_quests.php?page=' . $page);
    exit();
}

$offset = ($page - 1) * $items_per_page;

$quests = [];
$total = 0;
try {
    // Build query allowing for various created_by formats
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quests WHERE (created_by = ? OR created_by = ? OR LOWER(TRIM(created_by)) = LOWER(?)) AND status <> 'deleted'");
    $stmt->execute([$employee_id, (string)$user_id, 'user_' . (string)$user_id]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT q.*, 
        (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'approved') as approved_count,
        (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'pending') as pending_count,
        (SELECT COUNT(*) FROM quest_submissions WHERE quest_id = q.id AND status = 'rejected') as rejected_count,
        (SELECT COUNT(*) FROM user_quests WHERE quest_id = q.id) as assigned_count,
        (SELECT COUNT(*) FROM user_quests WHERE quest_id = q.id AND status = 'missed') as missed_count,
        (SELECT COUNT(*) FROM user_quests WHERE quest_id = q.id AND status = 'declined') as declined_count
        FROM quests q
        WHERE (created_by = ? OR created_by = ? OR LOWER(TRIM(created_by)) = LOWER(?)) AND status <> 'deleted'
        ORDER BY q.created_at DESC
        LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $employee_id);
    $stmt->bindValue(2, (string)$user_id);
    $stmt->bindValue(3, 'user_' . (string)$user_id);
    $stmt->bindValue(4, $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(5, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $quests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Created quests retrieval error: ' . $e->getMessage());
    $quests = [];
}

// Helper to generate pagination links
function localPagination($total_items, $current_page, $per_page) {
    $total_pages = $total_items > 0 ? (int)ceil($total_items / $per_page) : 1;
    $out = '<div class="mt-6 flex items-center justify-between">';
    $out .= '<div class="text-sm text-gray-600">Page ' . $current_page . ' of ' . $total_pages . '</div>';
    $out .= '<div class="pagination-nav">';
    if ($current_page > 1) {
        $out .= '<a href="?page=' . ($current_page - 1) . '" class="px-3 py-1 border rounded mr-2">Previous</a>';
    }
    if ($current_page < $total_pages) {
        $out .= '<a href="?page=' . ($current_page + 1) . '" class="px-3 py-1 border rounded">Next</a>';
    }
    $out .= '</div></div>';
    return $out;
}

// Read and clear flash messages from session
$success = '';
$error = '';
if (isset($_SESSION['success'])) { $success = $_SESSION['success']; unset($_SESSION['success']); }
if (isset($_SESSION['error'])) { $error = $_SESSION['error']; unset($_SESSION['error']); }

function statusBadge($s) {
    $status = strtolower(trim((string)$s));
    // Render status as a standardized meta-badge with a status modifier class
    $label = htmlspecialchars($s);
    return '<span class="meta-badge status-' . htmlspecialchars($status) . '" title="' . $label . '">' . $label . '</span>';
}

// Minimal header (avoid depending on missing includes/header.php)
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <style>
        /* Modern centered layout for created_quests page */
        :root{ --bg:#f8fafc; --card:#ffffff; --muted:#6b7280; --accent:#6366f1; --accent-2:#7c3aed; }
        body { background: var(--bg); color: #0f172a; font-family: Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial; }
        .page-wrap { display:flex; justify-content:center; }
    .page { width:100%; max-width:1100px; padding:28px 1cm; }
    /* Provide comfortable spacing from viewport edges for header actions, pagination and footer */
    .container-inner { padding-left: 1cm; padding-right: 1cm; }
        header h1 { margin:0; }
        .header-bar { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .header-left { display:flex; align-items:center; gap:14px; }
        .role-badge { padding:6px 10px; border-radius:999px; font-size:0.85rem; }

        /* Card grid and cards */
        .grid-cards { display:grid; grid-template-columns:1fr; gap:16px; }
        @media(min-width:850px){ .grid-cards { grid-template-columns: repeat(2, 1fr); } }
            .quest-card { background:var(--card); border-radius:12px; padding:18px; box-shadow: 0 4px 10px rgba(2,6,23,0.04); border: 1px solid rgba(15,23,42,0.04); transition: transform .22s ease, box-shadow .22s ease; }
            .quest-card.draft { background: #fffbeb; border-style: dashed; border-color: rgba(245,158,66,0.45); }
            .quest-card.active { background: #f0fdf4; border-left: 4px solid #10b981; }
        .quest-card:hover { transform: translateY(-6px); box-shadow: 0 18px 40px rgba(2,6,23,0.08); }
        .quest-title { font-size:1.05rem; font-weight:600; color:#0b1220; }
        .quest-desc { color:var(--muted); margin-top:8px; font-size:0.95rem; }
    .quest-meta { margin-top:6px; color:var(--muted); font-size:0.86rem; display:flex; flex-direction:column; gap:6px; align-items:flex-start; }

        /* Buttons */
        .btn { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; font-weight:600; font-size:0.9rem; cursor:pointer; transition: transform .14s ease, box-shadow .14s ease; border: none; }
    .btn:focus { outline: 3px solid rgba(99,102,241,0.18); }
    /* Disabled button state */
    .btn-disabled, .btn[disabled] { opacity:0.56; cursor:not-allowed; transform:none; box-shadow:none; }
    .btn-primary { background: linear-gradient(90deg,var(--accent),var(--accent-2)); color:#fff; box-shadow: 0 6px 20px rgba(99,102,241,0.18); }
    .btn-primary:hover { transform: translateY(-3px); }
    .btn-success { background: linear-gradient(90deg,#10b981,#059669); color:#fff; box-shadow: 0 6px 18px rgba(16,185,129,0.12); }
    .btn-success:hover { transform: translateY(-2px); }
    .btn-warning { background: #fffbeb; color:#92400e; border:1px solid rgba(245,158,66,0.16); }
    .btn-warning:hover { transform: translateY(-2px); }
    .btn-draft { background: transparent; color:#b45309; border:1px solid rgba(245,158,66,0.12); }
    .btn-draft:hover { transform: translateY(-2px); }
    .btn-ghost { background: #fff; color:#0f172a; border:1px solid rgba(15,23,42,0.06); }
        .btn-ghost:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(2,6,23,0.04); }
        .btn-outline { background:transparent; color:var(--accent); border:1px solid rgba(99,102,241,0.12); }
    .btn-edit { color:#2563eb; border-color: rgba(37,99,235,0.12); }
        .btn-danger { background:#fff7f7; color:#991b1b; border:1px solid rgba(220,38,38,0.12); }
        .btn-small { padding:6px 10px; font-size:0.85rem; border-radius:6px; }

    /* Small status badges used inside the card meta area */
    .meta-badge { display:inline-block; padding:4px 10px; border-radius:999px; font-size:0.78rem; font-weight:700; margin:0; vertical-align:middle; line-height:1; background:#ffffff; }
    /* Use white background + colored border and text for clear contrast on any card background */
    .meta-badge.pending { background:#ffffff; color:#92400e; border:1px solid #f59e0b; }
    .meta-badge.approved { background:#ffffff; color:#065f46; border:1px solid #10b981; }
    .meta-badge.rejected { background:#ffffff; color:#9f1239; border:1px solid #ef4444; }
    .meta-badge.missed { background:#ffffff; color:#991b1b; border:1px solid #ef4444; }
    .meta-badge.declined { background:#ffffff; color:#374151; border:1px solid #e5e7eb; }
    .meta-badge.assigned { background:#ffffff; color:#3730a3; border:1px solid #6366f1; }
    .meta-badge.due { background:#ffffff; color:#92400e; border:1px solid #f59e0b; }
    /* Status-specific badge styles matching action semantics */
    .meta-badge.status-active { background:#ffffff; color:#065f46; border:1px solid #10b981; }
    .meta-badge.status-draft { background:#ffffff; color:#92400e; border:1px solid #f59e0b; }
    .meta-badge.status-inactive { background:#ffffff; color:#475569; border:1px solid #cbd5e1; }
    .meta-badge.status-deleted { background:#ffffff; color:#6b7280; border:1px solid #e5e7eb; }
    .meta-badge.status-pending { background:#ffffff; color:#92400e; border:1px solid #f59e0b; }
    .meta-badge.status-approved { background:#ffffff; color:#065f46; border:1px solid #10b981; }
    .date-status { display:inline-flex; gap:8px; align-items:center; white-space:nowrap; }

        /* small responsive tweaks */
        .actions { display:flex; gap:8px; flex-wrap:wrap; }
        .center-note { text-align:center; color:var(--muted); margin-top:20px; }

        /* subtle animation for entry */
        .card-anim { opacity:0; transform: translateY(8px); transition: opacity .42s ease, transform .42s ease; }
        .card-anim.show { opacity:1; transform: translateY(0); }

        /* Pagination bottom-right placement */
    .pagination-bottom-right { display:flex; justify-content:flex-end; margin-top:18px; padding-right:1cm; }
        .pagination-bottom-right .text-sm { font-size:0.82rem; color:var(--muted); }
        @media(max-width:600px) { .pagination-bottom-right { position: static; padding: 0 6px; } }
        /* Footer lower-right placement */
    .footer-right { display:flex; justify-content:flex-end; gap:12px; color:var(--muted); font-size:0.9rem; margin-top:22px; margin-right:1cm; }
        @media(max-width:600px) { .footer-right { justify-content:center; } }
    </style>
    </style>
    <style>
    /* Improved Assign modal styles */
    .assign-modal{display:none;position:fixed;inset:0;background:rgba(2,6,23,0.36);z-index:9999;align-items:center;justify-content:center;padding:28px}
    .assign-modal[aria-hidden="false"]{display:flex}
    .assign-modal-content{width:100%;max-width:620px;background:#fff;border-radius:12px;padding:22px 22px 18px;box-shadow:0 20px 60px rgba(2,6,23,0.12);border:1px solid rgba(15,23,42,0.04);}
    .assign-modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
    .assign-modal-title{font-size:1.25rem;font-weight:700;color:#0f172a}
    .assign-modal-close{background:transparent;border:none;color:#6b7280;font-size:20px;cursor:pointer}
    .assign-employee-list{max-height:300px;overflow:auto;border-radius:10px;border:1px solid #eef2f7;padding:8px;background:#fbfdff}
    .assign-employee-list label{display:flex;align-items:center;gap:12px;padding:10px;border-radius:8px;cursor:pointer}
    .assign-employee-list label:hover{background:#f8fafc}
    .assign-employee-list input[type=checkbox]{width:18px;height:18px}
    .assign-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:16px}
    .btn-cancel{background:#fff;border:1px solid #e6e9f2;color:#5b6b8a;padding:10px 14px;border-radius:10px}
    .btn-assign{background:linear-gradient(90deg,#7c3aed,#6366f1);color:#fff;padding:10px 14px;border-radius:10px;border:none}
    </style>
</head>
<body>
    <div class="max-w-6xl mx-auto py-8 px-4 container-inner">
        <header class="header-bar" style="margin-bottom:18px;">
            <div class="header-left">
                <img src="assets/images/yoonet-logo.jpg" alt="YooNet" style="height:46px;border-radius:6px;">
                <div>
                    <h1 class="text-2xl font-bold"><?php echo htmlspecialchars($page_title); ?></h1>
                    <div class="text-sm text-gray-600">Welcome, <?php echo htmlspecialchars($full_name); ?> <span class="role-badge <?php echo $role_badge_class; ?>"><?php echo htmlspecialchars(str_replace('_',' ', $role)); ?></span></div>
                </div>
            </div>
            <div class="actions">
                <a href="dashboard.php" class="btn btn-ghost btn-small">← Dashboard</a>
            </div>
        </header>

<?php
// Start main content
?>
<main class="max-w-6xl mx-auto py-2 px-0">
    <div class="flex items-center justify-between mb-6">
        <!-- header area intentionally left minimal for this page -->
    </div>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (empty($quests)): ?>
        <div class="card">
            <p class="center-note">You haven't created any quests yet. Use the Create Quest button above to add one.</p>
        </div>
    <?php else: ?>
    <div class="page-wrap"><div class="page">
        <div class="grid-cards">
            <?php foreach ($quests as $index => $q): ?>
                <?php $isDraft = (strtolower(trim((string)$q['status'] ?? '')) === 'draft'); ?>
                <?php $isActive = (strtolower(trim((string)$q['status'] ?? '')) === 'active'); ?>
                <article class="quest-card card-anim<?php echo $isDraft ? ' draft' : ($isActive ? ' active' : ''); ?>" data-index="<?php echo $index; ?>">
                    <div>
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                            <div style="flex:1;">
                                <div class="quest-title"><?php echo htmlspecialchars($q['title']); ?></div>
                                <div class="quest-desc"><?php echo htmlspecialchars(substr($q['description'] ?? '', 0, 250)); ?><?php echo (strlen($q['description'] ?? '') > 250) ? '...' : ''; ?></div>
                                <div class="quest-meta">
                                    <?php
                                        $displayStatus = strtolower(trim((string)$q['status'] ?? ''));
                                        $INACTIVE_DAYS = 30;
                                        if ($displayStatus === 'active') {
                                            $createdTs = strtotime($q['created_at'] ?? '');
                                            $hasSubmissions = ((int)($q['approved_count'] ?? 0) + (int)($q['pending_count'] ?? 0) + (int)($q['rejected_count'] ?? 0)) > 0;
                                            if (!$hasSubmissions && (is_numeric($createdTs) && (time() - $createdTs) > ($INACTIVE_DAYS * 86400)) && ((int)($q['assigned_count'] ?? 0) === 0)) {
                                                $displayStatus = 'inactive';
                                            }
                                        }
                                    ?>
                                    <span style="background:#eee;color:#b91c1c;padding:2px 8px;border-radius:6px;font-size:0.8em;margin-bottom:2px;">DEBUG status: '<?php echo htmlspecialchars($q['status']); ?>'</span>
                                    <?php if (!empty($q['due_date'])): ?>
                                        <span class="date-status">
                                            <span class="meta-badge due" title="Due date"><?php echo htmlspecialchars(date('M j, Y', strtotime($q['due_date']))); ?></span>
                                            <?php echo statusBadge($displayStatus); ?>
                                        </span>
                                    <?php endif; ?>
                                    <div class="card-bottom-badges" style="margin-top:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                        <?php if ((int)$q['assigned_count'] > 0): ?>
                                            <span class="meta-badge assigned">Assigned: <?php echo (int)$q['assigned_count']; ?></span>
                                        <?php endif; ?>
                                        <?php if ((int)$q['approved_count'] > 0): ?>
                                            <span class="meta-badge approved">Approved: <?php echo (int)$q['approved_count']; ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($q['missed_count']) && (int)$q['missed_count'] > 0): ?>
                                            <span class="meta-badge missed">Missed: <?php echo (int)$q['missed_count']; ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($q['declined_count']) && (int)$q['declined_count'] > 0): ?>
                                            <span class="meta-badge declined">Declined: <?php echo (int)$q['declined_count']; ?></span>
                                        <?php endif; ?>
                                        <?php if ((int)$q['rejected_count'] > 0): ?>
                                            <span class="meta-badge rejected">Rejected: <?php echo (int)$q['rejected_count']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:8px; margin-left:12px;">
                                <?php
                                    $total_submissions = (int)($q['approved_count'] ?? 0) + (int)($q['pending_count'] ?? 0) + (int)($q['rejected_count'] ?? 0);
                                ?>
                                <?php $qstatus = strtolower(trim((string)$q['status'])); ?>
                                <?php if (in_array($qstatus, ['created','assigned'])): ?>
                                    <?php
                                        // fetch currently assigned employee_ids for this quest so we can exclude them in the modal
                                        $assignedIds = [];
                                        try {
                                            $aStmt = $pdo->prepare("SELECT employee_id FROM user_quests WHERE quest_id = ?");
                                            $aStmt->execute([(int)$q['id']]);
                                            $assignedIds = $aStmt->fetchAll(PDO::FETCH_COLUMN);
                                        } catch (PDOException $e) { $assignedIds = []; }
                                    ?>
                                    <button class="btn btn-primary btn-small assign-btn" style="margin-bottom:8px;" data-quest-id="<?php echo (int)$q['id']; ?>" data-creator="<?php echo htmlspecialchars($q['created_by'], ENT_QUOTES); ?>" data-assigned='<?php echo json_encode($assignedIds); ?>'>Assign</button>
                                <?php endif; ?>
                                <?php if ($isDraft): ?>
                                    <a href="view_quest.php?id=<?php echo (int)$q['id']; ?>&show_accepted=1" class="btn btn-ghost btn-small">View</a>
                                    <form method="post" class="publish-form" data-title="<?php echo htmlspecialchars($q['title'], ENT_QUOTES); ?>" style="margin:0;">
                                        <input type="hidden" name="publish_id" value="<?php echo (int)$q['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-small">Publish</button>
                                    </form>
                                <?php else: ?>
                                    <a href="view_quest.php?id=<?php echo (int)$q['id']; ?>&show_accepted=1" class="btn btn-ghost btn-small">View</a>
                                    <?php if ($total_submissions > 0): ?>
                                        <button class="btn btn-outline btn-small btn-disabled" disabled title="Editing locked: this quest has submissions">Update</button>
                                    <?php else: ?>
                                        <a href="edit_quest.php?id=<?php echo (int)$q['id']; ?>" class="btn btn-outline btn-small btn-edit" data-quest-id="<?php echo (int)$q['id']; ?>" data-quest-title="<?php echo htmlspecialchars($q['title'], ENT_QUOTES); ?>">Update</a>
                                    <?php endif; ?>
                                    <form method="post" class="save-draft-form" data-title="<?php echo htmlspecialchars($q['title'], ENT_QUOTES); ?>">
                                        <input type="hidden" name="save_draft_id" value="<?php echo (int)$q['id']; ?>">
                                        <?php if ($total_submissions > 0): ?>
                                            <button type="button" class="btn btn-danger btn-small btn-disabled" disabled title="Cannot save as draft: this quest has submissions">Save as draft</button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-draft btn-small">Save as draft</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </article>
            <!-- Assignment modal moved outside the loop -->
            <?php endforeach; ?>
            <!-- Assignment Modal (single instance) -->
            <div id="assignmentModal" class="assign-modal" aria-hidden="true" role="dialog" aria-modal="true">
                <div class="assign-modal-content">
                    <div class="assign-modal-header">
                        <div class="assign-modal-title">Assign Quest</div>
                        <button id="closeAssignmentModal" class="assign-modal-close" aria-label="Close">&times;</button>
                    </div>
                    <form id="assignmentForm" method="post">
                        <input type="hidden" name="assign_quest_id" id="assign_quest_id" value="">
                        <div>
                            <label style="font-weight:600;display:block;margin-bottom:8px;color:#374151">Select employees to assign:</label>
                            <div id="employeeListModal" class="assign-employee-list">
                                <!-- Employee checkboxes will be injected here -->
                            </div>
                        </div>
                        <div class="assign-actions">
                            <button type="button" id="cancelAssignBtn" class="btn-cancel">Cancel</button>
                            <button type="submit" class="btn-assign">Assign</button>
                        </div>
                    </form>
                </div>
            </div>
            <script>
            // Employees data for assignment modal (copied from PHP)
            var employees = <?php
                // Fetch all assignable employees (quest_lead, skill_associate) except current user
                $empList = [];
                try {
                    $stmt = $pdo->prepare("SELECT employee_id, full_name FROM users WHERE role IN ('skill_associate', 'quest_lead') AND employee_id != ? ORDER BY full_name");
                    $stmt->execute([$employee_id]);
                    $empList = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) { $empList = []; }
                echo json_encode($empList);
            ?>;

            function renderEmployeeListModal(selected, creatorId, assignedList) {
                var html = '';
                assignedList = assignedList || [];
                var assignedLower = assignedList.map(function(a){ return String(a).toLowerCase(); });
                employees.forEach(function(emp) {
                    // Exclude the quest creator from the assignable list
                    if (creatorId && String(emp.employee_id).toLowerCase() === String(creatorId).toLowerCase()) return;
                    // Exclude already-assigned employees
                    if (assignedLower.indexOf(String(emp.employee_id).toLowerCase()) !== -1) return;
                    var checked = selected && selected.indexOf(emp.employee_id) !== -1 ? 'checked' : '';
                    html += `<label style="display:flex;align-items:center;gap:8px;padding:6px 16px;cursor:pointer;">
                        <input type="checkbox" name="assign_to[]" value="${emp.employee_id}" style="accent-color:#6366f1;" ${checked}>
                        <span style="font-size:0.97rem;">${emp.full_name} <span style="color:#64748b;font-size:0.85em;">(${emp.employee_id})</span></span>
                    </label>`;
                });
                var container = document.getElementById('employeeListModal');
                if (container) container.innerHTML = html;
            }

            document.addEventListener('DOMContentLoaded', function() {
                // Assign button click handler
                document.querySelectorAll('.assign-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var questId = btn.getAttribute('data-quest-id');
                            var creatorId = btn.getAttribute('data-creator');
                            var assignedJson = btn.getAttribute('data-assigned') || '[]';
                            var assignedList = [];
                            try { assignedList = JSON.parse(assignedJson); } catch(e) { assignedList = []; }
                            var assignId = document.getElementById('assign_quest_id');
                            if (assignId) assignId.value = questId;
                            renderEmployeeListModal([], creatorId, assignedList);
                            var modal = document.getElementById('assignmentModal');
                            if (modal) { modal.style.display = 'flex'; modal.setAttribute('aria-hidden','false'); }
                            // focus first checkbox for accessibility
                            setTimeout(function(){ var cb = document.querySelector('#employeeListModal input[type=checkbox]'); if(cb) cb.focus(); }, 50);
                        });
                });
                // Close modal
                var closeBtn = document.getElementById('closeAssignmentModal');
                if (closeBtn) closeBtn.onclick = function() { var m = document.getElementById('assignmentModal'); if(m){ m.style.display='none'; m.setAttribute('aria-hidden','true'); } };
                var cancelBtn = document.getElementById('cancelAssignBtn');
                if (cancelBtn) cancelBtn.onclick = function(){ var m = document.getElementById('assignmentModal'); if(m){ m.style.display='none'; m.setAttribute('aria-hidden','true'); } };
                // Prevent accidental modal close on click outside
                var modalEl = document.getElementById('assignmentModal');
                if (modalEl) modalEl.addEventListener('click', function(e) { if (e.target === this) { this.style.display='none'; this.setAttribute('aria-hidden','true'); } });
            });
            </script>
        </div>
        </div></div>

        <div class="pagination-bottom-right">
            <?php echo localPagination($total, $page, $items_per_page); ?>
        </div>
    <?php endif; ?>
    </main>
    <footer class="mt-10">
        <div class="footer-right">
            <div>YooNet Quest System</div>
            <div>&copy; <?php echo date('Y'); ?></div>
        </div>
    </footer>
</div>
<script>
// Simple entrance animation for cards
document.addEventListener('DOMContentLoaded', function(){
    var cards = document.querySelectorAll('.card-anim');
    cards.forEach(function(c, idx){
        setTimeout(function(){ c.classList.add('show'); }, idx * 80);
    });

    // Intercept save-as-draft forms to show a confirm dialog
    document.querySelectorAll('.save-draft-form').forEach(function(f){
        f.addEventListener('submit', function(e){
            e.preventDefault();
            var title = f.getAttribute('data-title') || 'this quest';
            if (confirm('Save "' + title + '" as a draft? This will remove it from active listings.')) {
                f.submit();
            }
        });
    });

    // Intercept publish forms to confirm
    document.querySelectorAll('.publish-form').forEach(function(f){
        f.addEventListener('submit', function(e){
            e.preventDefault();
            var title = f.getAttribute('data-title') || 'this quest';
            if (confirm('Publish "' + title + '"? This will make it visible to users again.')) {
                f.submit();
            }
        });
    });

    // Intercept edit links to require a quick confirmation (prevents accidental edits)
    document.querySelectorAll('.btn-edit').forEach(function(link){
        link.addEventListener('click', function(e){
            e.preventDefault();
            var title = link.getAttribute('data-quest-title') || 'this quest';
            if (confirm('You are about to edit "' + title + '". Proceed to the edit page?')) {
                window.location = link.getAttribute('href');
            }
        });
    });
});
</script>
</body>
</html>

