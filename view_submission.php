<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
$error = '';

if ($submission_id <= 0) {
    $error = 'Missing submission reference.';
}

$submission = null;
$quest = null;

if (empty($error)) {
    try {
        $stmt = $pdo->prepare("SELECT qs.*, q.title AS quest_title, q.id AS quest_id FROM quest_submissions qs JOIN quests q ON qs.quest_id = q.id WHERE qs.id = ? LIMIT 1");
        $stmt->execute([$submission_id]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$submission) {
            $error = 'Submission not found.';
        }
    } catch (PDOException $e) {
        $error = 'Error loading submission.';
        error_log('view_submission load submission: ' . $e->getMessage());
    }
}

// Authorization: only the submitter can view (or admin)
if (empty($error)) {
    $currentEmployee = $_SESSION['employee_id'] ?? null;
    $role = $_SESSION['role'] ?? '';
    $isAdmin = ($role === 'admin');
    if (!$isAdmin && (!$currentEmployee || $submission['employee_id'] != $currentEmployee)) {
        $error = 'You are not allowed to view this submission.';
    }
}

if (empty($error)) {
    $quest = [ 'id' => (int)$submission['quest_id'], 'title' => (string)$submission['quest_title'] ];
}

// Helper to get absolute-ish URL for local files (relative path works under same site)
$filePath = '';
$driveLink = '';
$textContent = '';
$submissionText = '';
if ($submission) {
    $filePath = $submission['file_path'] ?? '';
    $driveLink = $submission['drive_link'] ?? '';
    $textContent = $submission['text_content'] ?? '';
    $submissionText = $submission['submission_text'] ?? '';
}

// Derive display info
$fileName = '';
$fileType = '';
$absUrl = '';
$extLower = '';
$gviewUrl = '';
$hasOpen = false;
$inlineId = '';
$isLink = false;
if (empty($error)) {
    if (!empty($filePath)) {
        // Prefer an explicitly stored original filename if present in the submission row
        $fileNameCandidates = [
            $submission['file_name'] ?? null,
            $submission['original_name'] ?? null,
            $submission['original_filename'] ?? null,
            $submission['file_original_name'] ?? null,
            $submission['original_file_name'] ?? null,
        ];
        $fileName = '';
        foreach ($fileNameCandidates as $c) { if (!empty($c)) { $fileName = $c; break; } }
        if (empty($fileName)) { $fileName = basename($filePath); }
        $absUrl = $filePath; // relative URL should work
        $extLower = strtolower(pathinfo($absUrl, PATHINFO_EXTENSION));
        $fileType = strtoupper($extLower);
        $inlineId = 'inline-' . md5($absUrl);
        $hasOpen = true;
    } elseif (!empty($driveLink) && filter_var($driveLink, FILTER_VALIDATE_URL)) {
        $isLink = true;
        $absUrl = $driveLink;
        $u = parse_url($driveLink);
        $path = $u['path'] ?? '';
        $bn = $path !== '' ? basename($path) : '';
        $fileName = $bn !== '' ? $bn : ($u['host'] ?? 'external link');
        // Try to infer extension from link path
        
        $extLower = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $fileType = $extLower ? strtoupper($extLower) : 'LINK';
    } elseif (!empty($textContent)) {
        $fileName = 'Text submission';
        $fileType = 'TEXT';
        $inlineId = 'inline-' . md5($submission_id . '-text');
        $hasOpen = true;
    } elseif (!empty($submissionText)) {
        if (filter_var($submissionText, FILTER_VALIDATE_URL)) {
            $isLink = true;
            $absUrl = $submissionText;
            $u = parse_url($submissionText);
            $path = $u['path'] ?? '';
            $fileName = $u['host'] ?? 'external link';
            $extLower = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $fileType = $extLower ? strtoupper($extLower) : 'LINK';
        } else {
            $fileName = 'Text submission';
            $fileType = 'TEXT';
            $inlineId = 'inline-' . md5($submission_id . '-text2');
            $hasOpen = true;
        }
    }
}

// Precompute a Google Viewer URL for office files when needed
try {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocal = (stripos($host, 'localhost') !== false) || (stripos($host, '127.0.0.1') !== false);
    if (!$isLocal && in_array($extLower, ['doc','docx','ppt','pptx','xls','xlsx'])) {
        $gviewUrl = 'https://docs.google.com/gview?embedded=1&url=' . rawurlencode($absUrl);
    }
} catch (Throwable $e) {
    // ignore
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Submission - YooNet Quest System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css">
    <style>
        body { background: #f3f4f6; min-height: 100vh; font-family: 'Segoe UI', Arial, sans-serif; margin:0; }
        .page { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; }
        .header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 16px; }
        .title { font-size: 1.35rem; font-weight: 800; color:#111827; }
        .meta { color:#6b7280; font-size:0.95rem; }
        .row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; }
        .file-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:9999px; background:#EEF2FF; color:#3730A3; font-weight:600; font-size:12px; border:1px solid #C7D2FE; }
        .btn { display:inline-block; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
        .btn-gray { background:#F3F4F6; color:#111827; border:1px solid #D1D5DB; }
        .btn-green { background:#10B981; color:#fff; }
        .btn-dark { background:#111827; color:#fff; }
        /* Modal preview styling to match grader UI */
        .preview-container { background:#fff; border-radius:10px; overflow:hidden; }
        .preview-header { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
        .preview-title { display:flex; align-items:center; gap:8px; font-weight:700; color:#111827; }
        .badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:9999px; font-size:12px; font-weight:700; border:1px solid transparent; }
        .badge-docx { background:#DBEAFE; color:#1E40AF; border-color:#93C5FD; }
        .badge-pdf { background:#FEE2E2; color:#991B1B; border-color:#FCA5A5; }
        .badge-img { background:#DCFCE7; color:#065F46; border-color:#86EFAC; }
        .badge-text { background:#E5E7EB; color:#111827; border-color:#D1D5DB; }
        .badge-link { background:#EDE9FE; color:#5B21B6; border-color:#C4B5FD; }
        .badge-other { background:#E0E7FF; color:#3730A3; border-color:#C7D2FE; }
        .preview-body { padding:12px; }
        .preview-body iframe { width:100%; border:0; min-height:70vh; }
        .preview-body .docx-view { min-height:70vh; }
        /* Ensure inline lightbox content never renders on the page */
        .glightbox-inline { display:none; }
    </style>
    <script>
        function goBack() { window.history.back(); }
    </script>
</head>
<body>
    <div class="page">
        <div class="header">
            <div>
                <div class="title">My Submission</div>
                <?php if (empty($error) && $quest): ?>
                    <div class="meta">Quest: <?php echo htmlspecialchars($quest['title']); ?> • ID #<?php echo (int)$quest['id']; ?></div>
                <?php endif; ?>
            </div>
            <div>
                <a class="btn btn-dark" href="javascript:void(0)" onclick="goBack()">Back</a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="card" style="background:#FEF2F2; border-color:#FCA5A5; color:#991B1B;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <div id="submissionPreview">
            <div class="card">
                <div class="row" style="margin-bottom:8px;">
                    <div class="file-pill">📄 <?php echo htmlspecialchars($fileName ?: 'Submission'); ?><?php if ($fileType): ?> <span style="margin-left:6px; font-weight:700; color:#111827;">• <?php echo htmlspecialchars($fileType); ?></span><?php endif; ?></div>
                    <div style="display:flex; gap:8px;">
                        <?php if ($hasOpen && !empty($inlineId)): ?>
                            <?php if (!empty($filePath)):
                                $ext = strtolower(pathinfo($absUrl, PATHINFO_EXTENSION));
                                $officeOpen = ($ext === 'docx');
                            ?>
                                <a href="#<?php echo $inlineId; ?>" class="btn glightbox <?php echo $officeOpen ? 'office-open' : ''; ?>" data-type="inline" <?php if ($officeOpen): ?>data-file="<?php echo htmlspecialchars($absUrl); ?>" data-inline="#<?php echo $inlineId; ?>"<?php endif; ?>>Open</a>
                            <?php else: ?>
                                <a href="#<?php echo $inlineId; ?>" class="btn glightbox" data-type="inline">Open</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($isLink && !empty($absUrl)): ?>
                                <a href="<?php echo htmlspecialchars($absUrl); ?>" target="_blank" rel="noopener" class="btn">Open</a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($absUrl)): ?>
                            <a href="<?php echo htmlspecialchars($absUrl); ?>"
                               target="_blank"
                               rel="noopener"
                               class="btn btn-gray view-newtab"
                               data-ext="<?php echo htmlspecialchars($extLower); ?>"
                               data-abs="<?php echo htmlspecialchars($absUrl); ?>"
                               <?php if (!empty($gviewUrl)): ?>data-gview="<?php echo htmlspecialchars($gviewUrl); ?>"<?php endif; ?>>
                               View in new tab
                            </a>
                            <?php if (!$isLink && !empty($filePath)): ?>
                                <a href="<?php echo htmlspecialchars($absUrl); ?>" download class="btn btn-green">Download</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                    // Render file preview if present
                    if (!empty($filePath)):
                        $web = $absUrl;
                        $ext = strtolower(pathinfo($web, PATHINFO_EXTENSION));
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $isLocal = (stripos($host, 'localhost') !== false) || (stripos($host, '127.0.0.1') !== false);
                        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])):
                ?>
                            <div class="glightbox-inline" id="<?php echo $inlineId; ?>">
                                <div class="preview-container">
                                    <div class="preview-header">
                                        <div class="preview-title">
                                            <span class="badge badge-img">IMG</span>
                                            <span><?php echo htmlspecialchars($fileName); ?></span>
                                        </div>
                                        <div></div>
                                    </div>
                                    <div class="preview-body">
                                        <img style="max-width:100%;max-height:75vh;height:auto;display:block;margin:0 auto;background:#fff;" src="<?php echo htmlspecialchars($web); ?>" alt="<?php echo htmlspecialchars($fileName); ?>"/>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($ext === 'pdf'): ?>
                            <div class="glightbox-inline" id="<?php echo $inlineId; ?>">
                                <div class="preview-container">
                                    <div class="preview-header">
                                        <div class="preview-title">
                                            <span class="badge badge-pdf">PDF</span>
                                            <span><?php echo htmlspecialchars($fileName); ?></span>
                                        </div>
                                        <div></div>
                                    </div>
                                    <div class="preview-body">
                                        <iframe src="<?php echo htmlspecialchars($web); ?>"></iframe>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (in_array($ext, ['txt','md','csv'])): ?>
                            <div class="glightbox-inline" id="<?php echo $inlineId; ?>">
                                <div class="preview-container">
                                    <div class="preview-header">
                                        <div class="preview-title">
                                            <span class="badge badge-text">TEXT</span>
                                            <span><?php echo htmlspecialchars($fileName); ?></span>
                                        </div>
                                        <div></div>
                                    </div>
                                    <div class="preview-body">
                                        <pre style="white-space:pre-wrap; background:#111827; color:#e5e7eb; padding:12px; border-radius:8px; max-height:75vh; overflow:auto;"><?php echo htmlspecialchars(@file_get_contents($filePath)); ?></pre>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if ($ext === 'docx'): ?>
                                <div class="glightbox-inline" id="<?php echo $inlineId; ?>">
                                    <div class="preview-container">
                                        <div class="preview-header">
                                            <div class="preview-title">
                                                <span class="badge badge-docx">DOCX</span>
                                                <span><?php echo htmlspecialchars($fileName); ?></span>
                                            </div>
                                            <div></div>
                                        </div>
                                        <div class="preview-body">
                                            <div class="docx-view"><div class="docx-html">Loading preview…</div></div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="glightbox-inline" id="<?php echo $inlineId; ?>">
                                    <div class="preview-container">
                                        <div class="preview-header">
                                            <div class="preview-title">
                                                <span class="badge badge-other"><?php echo strtoupper(htmlspecialchars($ext)); ?></span>
                                                <span><?php echo htmlspecialchars($fileName); ?></span>
                                            </div>
                                            <div></div>
                                        </div>
                                        <div class="preview-body">
                                            <?php if (!$isLocal): ?>
                                                <iframe src="<?php echo 'https://docs.google.com/gview?embedded=1&url=' . rawurlencode($web); ?>" height="640"></iframe>
                                            <?php else: ?>
                                                <div style="padding:12px; background:#fff;">Preview for this file type is not available on localhost. Use “View in new tab”.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php // Removed explicit external link panel for drive/link submissions per UX request.
                    // Links remain accessible via the 'View in new tab' button when appropriate.
                    ?>

                    <?php // Render inline text if present
                    if (!empty($textContent)): ?>
                        <div class="glightbox-inline" id="<?php echo $inlineId; ?>">
                            <div class="preview-container">
                                <div class="preview-header">
                                    <div class="preview-title">
                                        <span class="badge badge-text">TEXT</span>
                                        <span><?php echo htmlspecialchars($fileName); ?></span>
                                    </div>
                                </div>
                                <div class="preview-body">
                                    <pre style="white-space:pre-wrap; background:#111827; color:#e5e7eb; padding:12px; border-radius:8px; max-height:75vh; overflow:auto;"><?php echo htmlspecialchars($textContent); ?></pre>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($submissionText) && !filter_var($submissionText, FILTER_VALIDATE_URL)): ?>
                        <div class="glightbox-inline" id="<?php echo $inlineId; ?>">
                            <div class="preview-container">
                                <div class="preview-header">
                                    <div class="preview-title">
                                        <span class="badge badge-text">TEXT</span>
                                        <span><?php echo htmlspecialchars($fileName); ?></span>
                                    </div>
                                </div>
                                <div class="preview-body">
                                    <pre style="white-space:pre-wrap; background:#111827; color:#e5e7eb; padding:12px; border-radius:8px; max-height:75vh; overflow:auto;"><?php echo htmlspecialchars($submissionText); ?></pre>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php // Show comments if provided on the submission ?>
                    <?php if (!empty($submission['comments'])): ?>
                        <div style="margin-top:12px;">
                            <div style="font-weight:600;margin-bottom:6px;">Comments</div>
                            <div style="background:#fff;border:1px solid #e5e7eb;padding:12px;border-radius:8px;color:#111827;"><?php echo nl2br(htmlspecialchars($submission['comments'])); ?></div>
                        </div>
                    <?php endif; ?>
            </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.5.1/mammoth.browser.min.js"></script>
    <script>
        (function(){
            const lightbox = GLightbox({ selector: '.glightbox' });
            // DOCX: open lightbox immediately, then render via Mammoth
            document.addEventListener('click', async function(e){
                const a = e.target.closest('a.office-open');
                if (!a) return;
                e.preventDefault();
                const fileUrl = a.getAttribute('data-file');
                const inlineSel = a.getAttribute('data-inline');
                const container = document.querySelector(inlineSel + ' .docx-html');
                if (container) container.innerHTML = 'Loading preview…';
                lightbox.open({ href: inlineSel, type: 'inline' });
                try {
                    const res = await fetch(fileUrl);
                    const buf = await res.arrayBuffer();
                    if (window.mammoth) {
                        const result = await window.mammoth.convertToHtml({ arrayBuffer: buf });
                        if (container) container.innerHTML = result.value || '<em>Empty document</em>';
                    } else {
                        window.open(fileUrl, '_blank', 'noopener');
                    }
                } catch (err) {
                    console.error('DOCX preview failed', err);
                    window.open(fileUrl, '_blank', 'noopener');
                }
            }, true);

            // Smarter 'View in new tab' handling to avoid forced downloads
            document.querySelectorAll('a.view-newtab').forEach((a) => {
                a.addEventListener('click', async (e) => {
                    const ext = (a.getAttribute('data-ext') || '').toLowerCase();
                    const abs = a.getAttribute('data-abs') || a.href;
                    const gview = a.getAttribute('data-gview');
                    const host = location.host;
                    const isLocal = host.includes('localhost') || host.includes('127.0.0.1');

                    // Let the browser handle image/PDF/text normally
                    if (['jpg','jpeg','png','gif','webp','svg','pdf','txt','md','csv'].includes(ext)) {
                        return;
                    }

                    // Office types: DOCX -> render to HTML; others -> Google Viewer (public) or raw (local)
                    if (['doc','docx','ppt','pptx','xls','xlsx'].includes(ext)) {
                        e.preventDefault();
                        if (ext === 'docx' && window.mammoth) {
                            try {
                                const res = await fetch(abs);
                                const buf = await res.arrayBuffer();
                                const result = await window.mammoth.convertToHtml({ arrayBuffer: buf });
                                const title = abs.split('/').pop();
                                const html = `<!doctype html><html><head><meta charset="utf-8"><title>${title}</title><style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:16px;color:#111827;background:#fff;} img{max-width:100%;height:auto}</style></head><body>${result.value}</body></html>`;
                                const blob = new Blob([html], { type: 'text/html' });
                                const url = URL.createObjectURL(blob);
                                window.open(url, '_blank');
                                setTimeout(() => URL.revokeObjectURL(url), 30000);
                            } catch (err) {
                                console.error('DOCX new-tab render failed:', err);
                                if (!isLocal) {
                                    const url = gview || ('https://docs.google.com/gview?embedded=1&url=' + encodeURIComponent(abs));
                                    window.open(url, '_blank');
                                } else {
                                    window.open(abs, '_blank');
                                }
                            }
                            return;
                        }
                        if (!isLocal) {
                            const url = gview || ('https://docs.google.com/gview?embedded=1&url=' + encodeURIComponent(abs));
                            window.open(url, '_blank');
                        } else {
                            window.open(abs, '_blank');
                        }
                        return;
                    }
                    // Otherwise, allow default behavior
                });
            });

            // Removed custom 'view-newtab' handler to avoid duplicate behaviors; rely on default anchor behavior
        })();
    </script>
    <script>
        (function(){
            const sid = <?php echo (int)$submission_id; ?>;
            function refreshPreview(){
                fetch('ajax_get_submission.php?submission_id=' + sid)
                    .then(function(r){ if (!r.ok) throw new Error('fetch failed'); return r.text(); })
                    .then(function(html){ var cont = document.getElementById('submissionPreview'); if (cont) cont.innerHTML = html; })
                    .catch(function(){ try { location.reload(); } catch(e) { console.error(e); } });
            }

            window.addEventListener('storage', function(e){
                if (!e) return;
                if (e.key === 'yqs_submission_updated') {
                    var payload = null;
                    try { payload = JSON.parse(e.newValue); } catch(err) { payload = e.newValue; }
                    var sid2 = payload && payload.submission_id ? payload.submission_id : null;
                    if (!sid2 || sid2 == sid) refreshPreview();
                }
            });

            if (window.BroadcastChannel) {
                try {
                    var bc = new BroadcastChannel('yqs_updates');
                    bc.addEventListener('message', function(ev){ var d = ev && ev.data ? ev.data : null; if (!d) return; if (d.type === 'submission_updated') { var sid2 = d.id || null; if (!sid2 || sid2 == sid) refreshPreview(); } });
                } catch(e) { console.error('BroadcastChannel init failed', e); }
            }
        })();
    </script>
</body>
</html>
