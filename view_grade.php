<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/skill_progression.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
$error = '';

// Helpers for tiers
function vg_getTierPoints($tier) {
    switch ($tier) {
        case 'T1': return 25;
        case 'T2': return 40;
        case 'T3': return 60;
        case 'T4': return 85;
        case 'T5': return 115;
        default: return 25;
    }
}
function vg_getTierLabel($tier) {
    switch ($tier) {
        case 'T1': return 'Beginner';
        case 'T2': return 'Intermediate';
        case 'T3': return 'Advanced';
        case 'T4': return 'Expert';
        case 'T5': return 'Master';
        default: return '';
    }
}

if ($submission_id <= 0) {
    $error = 'Missing submission reference.';
}

$submission = null;
$quest = null;
$submitter = null;
$reviewer = null;
$details = [];

if (empty($error)) {
    try {
        // Load submission and quest
        $stmt = $pdo->prepare("SELECT qs.*, q.title AS quest_title, q.id AS quest_id FROM quest_submissions qs JOIN quests q ON qs.quest_id = q.id WHERE qs.id = ? LIMIT 1");
        $stmt->execute([$submission_id]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$submission) {
            $error = 'Submission not found.';
        }
    } catch (PDOException $e) {
        $error = 'Error loading submission.';
        error_log('view_grade load submission: ' . $e->getMessage());
    }
}

// Authorization: only the submitter can view (or admin)
if (empty($error)) {
    $currentEmployee = $_SESSION['employee_id'] ?? null;
    $role = $_SESSION['role'] ?? '';
    $isAdmin = ($role === 'admin');
    if (!$isAdmin && (!$currentEmployee || $submission['employee_id'] != $currentEmployee)) {
        $error = 'You are not allowed to view this grade.';
    }
}

if (empty($error)) {
    // Quest meta
    $quest = [ 'id' => (int)$submission['quest_id'], 'title' => (string)$submission['quest_title'] ];

    // Submitter and reviewer info
    try {
        // Submitter
        $stmt = $pdo->prepare('SELECT id, full_name, email, employee_id FROM users WHERE employee_id = ? LIMIT 1');
        $stmt->execute([$submission['employee_id']]);
        $submitter = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('view_grade submitter fetch failed: ' . $e->getMessage());
    }
    try {
        // Reviewer from submission table (more global)
        $revEmp = $submission['reviewed_by'] ?? '';
        if ($revEmp !== '') {
            $stmt = $pdo->prepare('SELECT id, full_name, email, employee_id FROM users WHERE employee_id = ? LIMIT 1');
            $stmt->execute([$revEmp]);
            $reviewer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (PDOException $e) {
        error_log('view_grade reviewer fetch failed: ' . $e->getMessage());
    }

    // Per-skill detailed grades
    try {
        $stmt = $pdo->prepare('SELECT skill_name, base_points, performance_multiplier, performance_label, adjusted_points, notes FROM quest_assessment_details WHERE submission_id = ? ORDER BY id');
        $stmt->execute([$submission_id]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('view_grade details fetch failed: ' . $e->getMessage());
    }

    // Build skill -> tier map from quest_skills
    $skillTierMap = [];
    try {
        $skillManager = new SkillProgression($pdo);
        $quest_skills = $skillManager->getQuestSkills($quest['id']);
        // Build skill_id -> name if needed
        $ids = [];
        foreach ((array)$quest_skills as $row) {
            if (!isset($row['skill_name']) && isset($row['skill_id'])) { $ids[] = (int)$row['skill_id']; }
        }
        $nameMap = [];
        if (!empty($ids)) {
            $ids = array_values(array_unique(array_filter($ids)));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, skill_name FROM comprehensive_skills WHERE id IN ($ph)");
                $stmt->execute($ids);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $nameMap[(int)$r['id']] = (string)$r['skill_name']; }
            }
        }
        foreach ((array)$quest_skills as $row) {
            $name = isset($row['skill_name']) && $row['skill_name'] !== '' ? (string)$row['skill_name'] : (isset($row['skill_id']) && isset($nameMap[(int)$row['skill_id']]) ? $nameMap[(int)$row['skill_id']] : '');
            if ($name === '') continue;
            // Normalize tier
            $tier = 'T2';
            if (isset($row['tier_level'])) {
                $tv = $row['tier_level'];
                if (is_numeric($tv)) { $t=(int)$tv; if($t<1)$t=1; if($t>5)$t=5; $tier='T'.$t; }
                elseif (is_string($tv) && preg_match('~^T[1-5]$~', $tv)) { $tier=$tv; }
            } elseif (isset($row['tier'])) {
                $t=(int)$row['tier']; if($t<1)$t=1; if($t>5)$t=5; $tier='T'.$t;
            } elseif (isset($row['required_level'])) {
                $map=['beginner'=>'T1','intermediate'=>'T2','advanced'=>'T3','expert'=>'T4'];
                $rl=strtolower((string)$row['required_level']); if(isset($map[$rl])) $tier=$map[$rl];
            }
            $skillTierMap[$name] = $tier;
        }
    } catch (Throwable $e) {
        // Non-fatal
        error_log('view_grade tier map failed: ' . $e->getMessage());
    }
}

// Compute totals
$totalAdjusted = 0;
foreach ($details as $d) { $totalAdjusted += (int)($d['adjusted_points'] ?? 0); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Grade - YooNet Quest System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <style>
        body { background: #f3f4f6; min-height: 100vh; font-family: 'Segoe UI', Arial, sans-serif; margin:0; }
        .page { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; }
        .header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
        .title { font-size: 1.35rem; font-weight: 800; color:#111827; }
        .meta { color:#6b7280; font-size:0.95rem; }
        .section-title { font-size: 1.1rem; font-weight: 700; margin: 12px 0; }
        .skill { border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin-bottom:10px; background:#f8fafc; }
        .row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; }
        .pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:9999px; background:#EEF2FF; color:#3730A3; font-weight:600; font-size:12px; border:1px solid #C7D2FE; }
        .pill-gray { background:#F3F4F6; color:#111827; border:1px solid #D1D5DB; }
        .pill-green { background:#D1FAE5; color:#065F46; border:1px solid #10B981; }
        .pill-yellow { background:#FEF3C7; color:#92400E; border:1px solid #F59E0B; }
        .pill-red { background:#FEE2E2; color:#991B1B; border:1px solid #EF4444; }
        .notes { margin-top:8px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:10px; }
        .footer { margin-top: 16px; display:flex; align-items:center; justify-content:space-between; }
        .btn { display:inline-block; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
        .btn-dark { background:#111827; color:#fff; }
    </style>
    <script>
        function goBack() { window.history.back(); }
    </script>
</head>
<body>
    <div class="page">
        <div class="header">
            <div>
                <div class="title">My Grade</div>
                <?php if (empty($error) && $quest): ?>
                    <div class="meta">Quest: <?php echo htmlspecialchars($quest['title']); ?> • ID #<?php echo (int)$quest['id']; ?></div>
                <?php endif; ?>
            </div>
            <div>
                <a class="btn btn-dark" href="javascript:void(0)" onclick="goBack()">Back</a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="card" style="border-color:#FCA5A5; background:#FEF2F2; color:#991B1B;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <div class="card" style="margin-bottom:12px;">
                <?php
                    $st = strtolower(trim($submission['status'] ?? ''));
                    $badge = 'pill-gray'; $label = 'Submitted';
                    if ($st === 'approved') { $badge='pill-green'; $label='Graded'; }
                    elseif ($st === 'under_review') { $badge='pill'; $label='Under Review'; }
                    elseif ($st === 'rejected') { $badge='pill-red'; $label='Declined'; }
                ?>
                <div class="row">
                    <div>
                        <span class="pill <?php echo $badge; ?>"><?php echo $label; ?></span>
                        <span class="pill pill-gray">Submitted: <?php echo !empty($submission['submitted_at']) ? date('M d, Y g:i A', strtotime($submission['submitted_at'])) : '—'; ?></span>
                        <?php if (!empty($submission['reviewed_at'])): ?>
                            <span class="pill pill-gray">Reviewed: <?php echo date('M d, Y g:i A', strtotime($submission['reviewed_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($reviewer): ?>
                            <span class="pill">Reviewed by: <?php echo htmlspecialchars($reviewer['full_name'] . ' • ' . $reviewer['employee_id']); ?></span>
                        <?php else: ?>
                            <span class="pill pill-gray">Reviewer: —</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="section-title">Per-skill Performance</div>
                <?php if (!empty($details)): ?>
                    <?php foreach ($details as $d): ?>
                        <?php
                            $name = (string)($d['skill_name'] ?? 'Skill');
                            $tier = $skillTierMap[$name] ?? '';
                            if ($tier === '') {
                                // guess tier by base points
                                $bp = (int)($d['base_points'] ?? 0);
                                $map = [25=>'T1', 40=>'T2', 60=>'T3', 85=>'T4', 115=>'T5'];
                                $tier = $map[$bp] ?? '';
                            }
                            $tierLabel = $tier !== '' ? ($tier . ' • ' . vg_getTierLabel($tier)) : '';
                            $basePoints = (int)($d['base_points'] ?? 0);
                            $perfLabel = (string)($d['performance_label'] ?? 'Meets Expectations');
                            $mult = (float)($d['performance_multiplier'] ?? 1.0);
                            $adj = (int)($d['adjusted_points'] ?? 0);
                            $note = (string)($d['notes'] ?? '');
                        ?>
                        <div class="skill">
                            <div class="row" style="margin-bottom:6px;">
                                <div style="font-weight:700; color:#111827;">🔹 <?php echo htmlspecialchars($name); ?></div>
                                <?php if ($tierLabel !== ''): ?>
                                    <div class="pill">Tier: <?php echo htmlspecialchars($tierLabel); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="row">
                                <div class="pill pill-gray">Base: <?php echo $basePoints; ?> pts</div>
                                <div class="pill pill-gray">Performance: <?php echo htmlspecialchars($perfLabel); ?> (×<?php echo rtrim(rtrim(number_format($mult, 2, '.', ''), '0'), '.'); ?>)</div>
                                <div class="pill pill-green">Awarded: <?php echo $adj; ?> pts</div>
                            </div>
                            <?php if ($note !== ''): ?>
                                <div class="notes"><strong>Note:</strong> <?php echo nl2br(htmlspecialchars($note)); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="footer">
                        <div style="font-weight:800; color:#065F46;">Total Awarded: <?php echo (int)$totalAdjusted; ?> pts</div>
                        <div></div>
                    </div>
                <?php else: ?>
                    <div>No detailed grade data found yet.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
