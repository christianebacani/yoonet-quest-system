<?php
// created_quests.php - lists quests created by the current user
$require_auth = true;
require_once 'includes/auth.php';
require_once 'includes/config.php';
require_once 'includes/functions.php';

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

// Handle soft-delete of a quest (creator only) BEFORE output so we can redirect (PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_quest_id'])) {
    $delId = (int)$_POST['delete_quest_id'];
    if ($delId > 0) {
        try {
            // Verify creator
            $stmt = $pdo->prepare("SELECT created_by FROM quests WHERE id = ?");
            $stmt->execute([$delId]);
            $createdBy = $stmt->fetchColumn();
            $allowed = ($createdBy === (string)$employee_id) || ($createdBy === (string)$user_id) || (strtolower(trim((string)$createdBy)) === strtolower('user_' . (string)$user_id));
            if ($allowed) {
                // Soft-delete: set status to 'deleted'
                $stmt = $pdo->prepare("UPDATE quests SET status = 'deleted' WHERE id = ?");
                $stmt->execute([$delId]);
                $_SESSION['success'] = 'Quest deleted successfully.';
            } else {
                $_SESSION['error'] = 'You do not have permission to delete this quest.';
            }
        } catch (PDOException $e) {
            error_log('Error deleting quest: ' . $e->getMessage());
            $_SESSION['error'] = 'Error deleting quest.';
        }
    }
    // Redirect to avoid resubmission and to show flash message
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
        (SELECT COUNT(*) FROM user_quests WHERE quest_id = q.id) as assigned_count
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
    $class = 'bg-gray-100 text-gray-800';
    if ($status === 'active') { $class = 'bg-green-100 text-green-800'; }
    elseif (in_array($status, ['pending','under_review'])) { $class = 'bg-yellow-100 text-yellow-800'; }
    elseif (in_array($status, ['approved','completed'])) { $class = 'bg-blue-100 text-blue-800'; }
    elseif ($status === 'rejected') { $class = 'bg-red-100 text-red-800'; }
    elseif ($status === 'deleted') { $class = 'bg-gray-200 text-gray-600'; }
    return '<span class="px-2 py-1 rounded text-xs font-medium ' . $class . '">' . htmlspecialchars($s) . '</span>';
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
        .quest-card:hover { transform: translateY(-6px); box-shadow: 0 18px 40px rgba(2,6,23,0.08); }
        .quest-title { font-size:1.05rem; font-weight:600; color:#0b1220; }
        .quest-desc { color:var(--muted); margin-top:8px; font-size:0.95rem; }
        .quest-meta { margin-top:12px; color:var(--muted); font-size:0.86rem; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }

        /* Buttons */
        .btn { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; font-weight:600; font-size:0.9rem; cursor:pointer; transition: transform .14s ease, box-shadow .14s ease; border: none; }
    .btn:focus { outline: 3px solid rgba(99,102,241,0.18); }
    /* Disabled button state */
    .btn-disabled, .btn[disabled] { opacity:0.56; cursor:not-allowed; transform:none; box-shadow:none; }
        .btn-primary { background: linear-gradient(90deg,var(--accent),var(--accent-2)); color:#fff; box-shadow: 0 6px 20px rgba(99,102,241,0.18); }
        .btn-primary:hover { transform: translateY(-3px); }
        .btn-ghost { background: #fff; color:#0f172a; border:1px solid rgba(15,23,42,0.06); }
        .btn-ghost:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(2,6,23,0.04); }
        .btn-outline { background:transparent; color:var(--accent); border:1px solid rgba(99,102,241,0.12); }
        .btn-danger { background:#fff7f7; color:#991b1b; border:1px solid rgba(220,38,38,0.12); }
        .btn-small { padding:6px 10px; font-size:0.85rem; border-radius:6px; }

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
                <article class="quest-card card-anim" data-index="<?php echo $index; ?>">
                    <div>
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                            <div style="flex:1;">
                                <div class="quest-title"><?php echo htmlspecialchars($q['title']); ?></div>
                                <div class="quest-desc"><?php echo htmlspecialchars(substr($q['description'] ?? '', 0, 250)); ?><?php echo (strlen($q['description'] ?? '') > 250) ? '...' : ''; ?></div>
                                <div class="quest-meta">
                                    <?php echo statusBadge($q['status']); ?>
                                    <?php if (!empty($q['due_date'])): ?>
                                        <span>Due: <strong><?php echo htmlspecialchars(date('M j, Y', strtotime($q['due_date']))); ?></strong></span>
                                    <?php endif; ?>
                                    <span>Assigned: <strong><?php echo (int)$q['assigned_count']; ?></strong></span>
                                    <span>Approved: <strong><?php echo (int)$q['approved_count']; ?></strong></span>
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:8px; margin-left:12px;">
                                <a href="view_quest.php?id=<?php echo (int)$q['id']; ?>&show_accepted=1" class="btn btn-ghost btn-small">View</a>
                                <?php if (!empty($q['approved_count']) && (int)$q['approved_count'] > 0): ?>
                                    <!-- Editing locked when there are approved submissions -->
                                    <button class="btn btn-outline btn-small btn-disabled" disabled title="Editing locked: this quest has approved submissions">Edit</button>
                                <?php else: ?>
                                    <a href="edit_quest.php?id=<?php echo (int)$q['id']; ?>" class="btn btn-outline btn-small btn-edit" data-quest-id="<?php echo (int)$q['id']; ?>" data-quest-title="<?php echo htmlspecialchars($q['title'], ENT_QUOTES); ?>">Edit</a>
                                <?php endif; ?>
                                <form method="post" class="delete-form" data-title="<?php echo htmlspecialchars($q['title'], ENT_QUOTES); ?>">
                                    <input type="hidden" name="delete_quest_id" value="<?php echo (int)$q['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-small">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
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

    // Intercept delete forms to show a nicer confirm dialog
    document.querySelectorAll('.delete-form').forEach(function(f){
        f.addEventListener('submit', function(e){
            e.preventDefault();
            var title = f.getAttribute('data-title') || 'this quest';
            if (confirm('Are you sure you want to delete "' + title + '"? This will mark it as deleted.')) {
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

