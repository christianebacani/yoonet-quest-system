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
    $submission = null;
    $quest = null;

    if ($submission_id <= 0) {
        $error = 'Missing submission reference.';
    }

    if (empty($error)) {
        try {
            $stmt = $pdo->prepare("SELECT qs.*, q.title AS quest_title, q.id AS quest_id FROM quest_submissions qs JOIN quests q ON qs.quest_id = q.id WHERE qs.id = ? LIMIT 1");
            $stmt->execute([$submission_id]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$submission) $error = 'Submission not found.';
        } catch (PDOException $e) {
            $error = 'Error loading submission.';
            error_log('view_submission load submission: ' . $e->getMessage());
        }
    }

    // Authorization: only the submitter or admin
    if (empty($error)) {
        $currentEmployee = $_SESSION['employee_id'] ?? null;
        $role = $_SESSION['role'] ?? '';
        $isAdmin = ($role === 'admin');
        if (!$isAdmin && (!$currentEmployee || $submission['employee_id'] != $currentEmployee)) {
            $error = 'You are not allowed to view this submission.';
        }
    }

    if (empty($error)) {
        $quest = ['id' => (int)($submission['quest_id'] ?? 0), 'title' => (string)($submission['quest_title'] ?? '')];
    }

    // get display type if available
    if (empty($error) && !empty($submission['quest_id'])) {
        try {
            $qstmt = $pdo->prepare("SELECT display_type FROM quests WHERE id = ? LIMIT 1");
            $qstmt->execute([(int)$submission['quest_id']]);
            $qrow = $qstmt->fetch(PDO::FETCH_ASSOC);
            if ($qrow) $quest['display_type'] = $qrow['display_type'] ?? null;
        } catch (PDOException $e) {
            // ignore
        }
    }

    $isClientSupport = (isset($quest['display_type']) && $quest['display_type'] === 'client_support');

    // prepare file-related variables
    $filePath = '';
    $driveLink = '';
    $textContent = '';
    $submissionText = '';
    $fsPath = null;
    $absUrl = '';

    if (!empty($submission)) {
        // try many columns for stored file path / name
        $filePath = $submission['file_path'] ?? $submission['support_file'] ?? $submission['support_file_path'] ?? $submission['support_filepath'] ?? $submission['supportpath'] ?? $submission['supporting_file'] ?? $submission['supporting_file_path'] ?? $submission['supporting_filepath'] ?? $submission['file'] ?? $submission['uploaded_file'] ?? $submission['uploaded_filepath'] ?? $submission['attachment'] ?? $submission['attachment_path'] ?? $submission['attachments'] ?? $submission['support_files'] ?? $submission['submission_file'] ?? $submission['filepath'] ?? '';
        $driveLink = $submission['drive_link'] ?? '';
        $textContent = $submission['text_content'] ?? '';
        $submissionText = $submission['submission_text'] ?? '';

        // normalize slashes
        $filePath = is_string($filePath) ? str_replace('\\','/', $filePath) : $filePath;

        if (!empty($filePath)) {
            if (filter_var($filePath, FILTER_VALIDATE_URL)) {
                $absUrl = $filePath;
            } else {
                $cand1 = __DIR__ . '/' . ltrim($filePath, '/');
                $docRoot = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
                $cand2 = ($docRoot ? $docRoot : __DIR__) . '/' . ltrim($filePath, '/');
                $cand3 = $filePath;

                if (@file_exists($cand3)) {
                    $fsPath = $cand3;
                } elseif (@file_exists($cand1)) {
                    $fsPath = $cand1;
                } elseif (@file_exists($cand2)) {
                    $fsPath = $cand2;
                }

                if ($fsPath) {
                    $fsNorm = str_replace('\\','/', $fsPath);
                    if (!empty($docRoot) && stripos($fsNorm, $docRoot) === 0) {
                        $absUrl = substr($fsNorm, strlen($docRoot));
                        if ($absUrl === '' || $absUrl[0] !== '/') $absUrl = '/' . ltrim($absUrl, '/');
                    } else {
                        $absUrl = '/' . ltrim($filePath, '/');
                    }
                } else {
                    $absUrl = '/' . ltrim($filePath, '/');
                }
            }
        }

        // if not found in DB, try to locate likely upload
        if (empty($filePath) && !empty($submission['employee_id'])) {
            $empId = (string)$submission['employee_id'];
            $uploadsDir = __DIR__ . '/uploads/quest_submissions/';
            if (is_dir($uploadsDir)) {
                $candidates = [];
                // Only consider files that clearly belong to this employee to avoid
                // showing another user's support file when a submission row lacks
                // an explicit file_path. This is conservative but prevents cross-user leaks.
                foreach (scandir($uploadsDir) as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $low = strtolower($f);

                    // Require that the filename contains the employee id as a distinct token
                    // (prefix like "EMPID_" or contains "_EMPID_" or ends with "_EMPID.ext").
                    $hasEmpToken = false;
                    if ($empId !== '') {
                        if (strpos($low, $empId . '_') === 0) $hasEmpToken = true;
                        elseif (strpos($low, '_' . $empId . '_') !== false) $hasEmpToken = true;
                        elseif (preg_match('/(^|_)' . preg_quote(strtolower($empId), '/') . '(\.|_|$)/', $low)) $hasEmpToken = true;
                    }

                    if (!$hasEmpToken) {
                        // skip files that do not contain the employee id
                        continue;
                    }

                    $score = 0;
                    if (strpos($low, $empId . '_') === 0) $score += 10;
                    if (strpos($low, '_support_') !== false || strpos($low, 'support_') === 0) $score += 8;
                    if (isset($submission['quest_id']) && $submission['quest_id'] !== null && strpos($low, 'ql' . ($submission['quest_id'])) !== false) $score += 4;

                    if ($score > 0) {
                        $full = $uploadsDir . $f;
                        if (file_exists($full)) $candidates[] = ['file' => $f, 'full' => $full, 'score' => $score, 'mtime' => filemtime($full)];
                    }
                }
                if (!empty($candidates)) {
                    usort($candidates, function($a,$b){ if ($a['score'] !== $b['score']) return $b['score'] - $a['score']; return $b['mtime'] - $a['mtime']; });
                    $pick = $candidates[0];
                    $filePath = 'uploads/quest_submissions/' . $pick['file'];
                    $fsPath = $pick['full'];
                    $docRoot = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
                    $fsNorm = str_replace('\\','/', $fsPath);
                    if (!empty($docRoot) && stripos($fsNorm, $docRoot) === 0) {
                        $absUrl = substr($fsNorm, strlen($docRoot));
                        if ($absUrl === '' || $absUrl[0] !== '/') $absUrl = '/' . ltrim($absUrl, '/');
                    } else {
                        $absUrl = '/' . ltrim($filePath, '/');
                    }
            }
        }
    }
}
$scriptDir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (!empty($absUrl) && strpos($absUrl, 'http') !== 0 && strpos($absUrl, '/') === 0) {
    if ($scriptDir && $scriptDir !== '/' && strpos($absUrl, $scriptDir) !== 0) {
        $absUrl = $scriptDir . $absUrl;
    }
}
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
            // other common columns
            $submission['support_file'] ?? null,
            $submission['supporting_file'] ?? null,
            $submission['uploaded_file'] ?? null,
            $submission['attachment'] ?? null,
            $submission['attachment_path'] ?? null,
            $submission['file'] ?? null,
        ];
        $fileName = '';
        foreach ($fileNameCandidates as $c) { if (!empty($c)) { $fileName = $c; break; } }
        if (empty($fileName)) {
            // Prefer deriving filename from the filesystem path ($fsPath) when available
            if (!empty($fsPath)) {
                $fileName = basename($fsPath);
            } else {
                // otherwise derive from the computed absUrl (if available), otherwise fall back to filePath
                $nameSource = !empty($absUrl) ? $absUrl : $filePath;
                $fileName = basename($nameSource);
            }
        }

        // Normalize $fileName: if it's a URL or contains directory separators, reduce to basename so display matches uploaded file name
        $fileName = (string)$fileName;
        if (filter_var($fileName, FILTER_VALIDATE_URL)) {
            $u = parse_url($fileName);
            $p = $u['path'] ?? '';
            $fileName = basename($p ?: $fileName);
        } else {
            // Remove any leading path components (handles windows backslashes)
            $fileName = basename(str_replace('\\', '/', $fileName));
        }
        // If still empty, try fsPath or absUrl
        if ($fileName === '') {
            if (!empty($fsPath)) $fileName = basename($fsPath);
            elseif (!empty($absUrl)) $fileName = basename(parse_url($absUrl, PHP_URL_PATH) ?: $absUrl);
        }
        // Apply display overrides for known stored filenames (helps when original filename wasn't preserved in DB)
        $storedBase = '';
        if (!empty($fsPath)) $storedBase = basename($fsPath);
        elseif (!empty($absUrl)) $storedBase = basename(parse_url($absUrl, PHP_URL_PATH) ?: $absUrl);
        elseif (!empty($filePath)) $storedBase = basename($filePath);
        if ($storedBase !== '' && isset($displayNameOverrides[$storedBase])) {
            $fileName = $displayNameOverrides[$storedBase];
            $downloadName = $displayNameOverrides[$storedBase];
        } else {
            $downloadName = $fileName;
        }
        // Prefer the previously computed web URL (from filesystem discovery) when present
        if (empty($absUrl)) {
            $absUrl = $filePath; // relative URL fallback
        }
        $extPath = parse_url($absUrl, PHP_URL_PATH) ?: $absUrl;
        $extLower = strtolower(pathinfo($extPath, PATHINFO_EXTENSION));
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
    } elseif (!empty($textContent) && !$isClientSupport) {
        $fileName = 'Text submission';
        $fileType = 'TEXT';
        $inlineId = 'inline-' . md5($submission_id . '-text');
        $hasOpen = true;
    } elseif (!empty($submissionText) && !$isClientSupport) {
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
                    <?php if (!$isClientSupport): ?>
                        <div class="file-pill">📄 <?php echo htmlspecialchars($fileName ?: 'Submission'); ?><?php if ($fileType): ?> <span style="margin-left:6px; font-weight:700; color:#111827;">• <?php echo htmlspecialchars($fileType); ?></span><?php endif; ?></div>
                    <?php endif; ?>
                    <?php if (!$isClientSupport): ?>
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
                                <a href="<?php echo htmlspecialchars($absUrl); ?>" download="<?php echo htmlspecialchars($downloadName ?? basename(parse_url($absUrl, PHP_URL_PATH) ?: $absUrl)); ?>" class="btn btn-green">Download</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
                                        <?php
                                            $txtPreview = '';
                                            // Prefer filesystem read when available
                                            if (!empty($fsPath) && @file_exists($fsPath)) {
                                                $txtPreview = @file_get_contents($fsPath);
                                            } else {
                                                // Try HTTP fetch from absUrl as a fallback
                                                if (!empty($absUrl)) {
                                                    if (strpos($absUrl, 'http') !== 0) {
                                                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                                                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                                        $url = $scheme . '://' . $host . preg_replace('#^/#','/',$absUrl);
                                                    } else {
                                                        $url = $absUrl;
                                                    }
                                                    try { $txtPreview = @file_get_contents($url); } catch (Throwable $e) { $txtPreview = ''; }
                                                }
                                            }
                                        ?>
                                        <pre style="white-space:pre-wrap; background:#111827; color:#e5e7eb; padding:12px; border-radius:8px; max-height:75vh; overflow:auto;">
                                            <?php echo htmlspecialchars((string)$txtPreview); ?></pre>
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

                    <?php
                        // If this quest is client_support, render all client/support specific submitted fields
                        $isClientSupport = (isset($quest['display_type']) && $quest['display_type'] === 'client_support');
                        // Safely detect if any client-related fields exist without triggering undefined-key notices
                        $hasClientFields = false;
                        $clientKeys = ['ticket_reference','ticket_id','ticket','action_taken','time_spent','time_spent_hours','time_spent_hrs','evidence_json','evidence','resolution_status','follow_up_required','follow_up','comments'];
                        foreach ($clientKeys as $k) {
                            if (!empty($submission[$k] ?? null)) { $hasClientFields = true; break; }
                        }

                        if ($isClientSupport || $hasClientFields):
                            // Normalize and prefer explicit submission fields, but also handle cases where
                            // these values were embedded inside the `comments` field (JSON or key:value pairs).
                            $ticket = $submission['ticket_reference'] ?? $submission['ticket_id'] ?? $submission['ticket'] ?? '';
                            $action_taken = $submission['action_taken'] ?? $submission['text_content'] ?? $submission['text'] ?? '';
                            $time_spent = $submission['time_spent'] ?? $submission['time_spent_hours'] ?? $submission['time_spent_hrs'] ?? '';
                            $resolution_status = $submission['resolution_status'] ?? '';
                            $follow_up_raw = $submission['follow_up_required'] ?? $submission['follow_up'] ?? '';

                            // Use only the raw user-entered comments for the Comments field.
                            // Do NOT extract structured values from comments; those should come
                            // from dedicated submission columns (e.g. time_spent, ticket_reference).
                            $clean_comments = '';
                            if (!empty($submission['comments'] ?? null)) {
                                $clean_comments = (string)$submission['comments'];
                                // Normalize newlines
                                $clean_comments = preg_replace("/\r\n|\r/", "\n", $clean_comments);
                                // Trim trailing spaces from each line to remove accidental indentation at line ends
                                // but preserve leading spaces the user may have intentionally added.
                                $lines = preg_split('/\n/', $clean_comments);
                                $lines = array_map('rtrim', $lines);
                                // Remove leading/trailing empty lines
                                while (count($lines) && $lines[0] === '') { array_shift($lines); }
                                while (count($lines) && end($lines) === '') { array_pop($lines); }
                                // Collapse consecutive empty lines to a single blank line
                                $out = [];
                                $prevEmpty = false;
                                foreach ($lines as $ln) {
                                    if ($ln === '') {
                                        if (!$prevEmpty) { $out[] = ''; $prevEmpty = true; }
                                    } else {
                                        // preserve internal spacing; only collapse trailing tabs/spaces already removed
                                        $out[] = $ln;
                                        $prevEmpty = false;
                                    }
                                }
                                $clean_comments = implode("\n", $out);
                                $clean_comments = trim($clean_comments);
                            }
                            // Evidence: support multiple storage formats and be resilient to missing keys
                            $evidence_list = [];
                            if (!empty($submission['evidence_json'] ?? null)) {
                                $tmp = json_decode($submission['evidence_json'], true);
                                if (is_array($tmp)) $evidence_list = $tmp;
                            } elseif (!empty($submission['evidence'] ?? null)) {
                                $evraw = $submission['evidence'];
                                $tmp = json_decode($evraw, true);
                                if (is_array($tmp)) $evidence_list = $tmp;
                                else $evidence_list = array_filter(array_map('trim', explode(',', $evraw)));
                            }
                    ?>
                    <div style="margin-top:12px;">
                        <div class="cs-section-title">Client & Support Details</div>
                        <div class="card" style="padding:14px;">
                            <dl class="cs-dl">
                                <dt style="color:#6b7280;font-weight:700;">Ticket / Reference ID</dt>
                                <dd style="margin:0;color:#111827;"><?php echo $ticket !== '' ? htmlspecialchars($ticket) : '<span style="color:#9CA3AF;font-style:italic;">— not provided —</span>'; ?></dd>

                                <dt style="color:#6b7280;font-weight:700;">Action Taken / Resolution (required)</dt>
                                <dd style="margin:0;color:#111827;"><?php if (!empty($action_taken)): ?><div style="background:#fff;border:1px solid #e5e7eb;padding:10px;border-radius:8px;color:#111827;white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($action_taken)); ?></div><?php else: ?><span style="color:#9CA3AF;font-style:italic;">— not provided —</span><?php endif; ?></dd>

                                <dt style="color:#6b7280;font-weight:700;">Time Spent (hours)</dt>
                                <dd style="margin:0;color:#111827;"><?php echo ($time_spent !== '') ? htmlspecialchars($time_spent) : '<span style="color:#9CA3AF;font-style:italic;">— not provided —</span>'; ?></dd>

                                <dt style="color:#6b7280;font-weight:700;">Evidence / Attachments</dt>
                                <dd style="margin:0;color:#111827;">
                                    <?php if (!empty($evidence_list)): ?>
                                        <div style="display:flex;flex-direction:column;gap:8px;">
                                            <?php foreach ($evidence_list as $ev): ?>
                                                <?php $ev_trim = trim((string)$ev); if ($ev_trim === '') continue; $isUrl = filter_var($ev_trim, FILTER_VALIDATE_URL); ?>
                                                <label class="evidence-item">
                                                    <input type="checkbox" checked disabled />
                                                    <?php if ($isUrl): ?>
                                                        <a href="<?php echo htmlspecialchars($ev_trim); ?>" target="_blank" rel="noopener" style="color:#111827;text-decoration:underline;"><?php echo htmlspecialchars($ev_trim); ?></a>
                                                    <?php else: ?>
                                                        <span style="color:#111827;"><?php echo htmlspecialchars($ev_trim); ?></span>
                                                    <?php endif; ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#9CA3AF;font-style:italic;">— none provided —</span>
                                    <?php endif; ?>
                                </dd>

                                <dt style="color:#6b7280;font-weight:700;">Upload supporting file</dt>
                                <dd style="margin:0;color:#111827;">
                                    <?php if (!empty($filePath)): ?>
                                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                                            <div class="file-pill" style="padding:6px 10px;"><?php echo htmlspecialchars($fileName); ?><?php if ($fileType): ?> <span style="margin-left:8px;font-weight:700;color:#111827;">• <?php echo htmlspecialchars($fileType); ?></span><?php endif; ?></div>
                                            <div style="display:flex;gap:8px;align-items:center;">
                                                <?php if ($hasOpen && !empty($inlineId)): ?>
                                                    <?php $extForOpen = strtolower(pathinfo($absUrl, PATHINFO_EXTENSION)); $officeOpen = ($extForOpen === 'docx'); ?>
                                                    <a href="#<?php echo $inlineId; ?>" class="btn glightbox<?php echo $officeOpen ? ' office-open' : ''; ?>" data-type="inline"<?php if ($officeOpen): ?> data-file="<?php echo htmlspecialchars($absUrl); ?>" data-inline="#<?php echo $inlineId; ?>"<?php endif; ?>>Open</a>
                                                    <a href="<?php echo htmlspecialchars($absUrl); ?>" target="_blank" rel="noopener" class="btn btn-gray view-newtab" data-ext="<?php echo htmlspecialchars(pathinfo($absUrl, PATHINFO_EXTENSION)); ?>" data-abs="<?php echo htmlspecialchars($absUrl); ?>">View in new tab</a>
                                                    <a href="<?php echo htmlspecialchars($absUrl); ?>" download class="btn btn-green">Download</a>
                                                <?php elseif ($isLink && !empty($absUrl)): ?>
                                                    <a href="<?php echo htmlspecialchars($absUrl); ?>" target="_blank" rel="noopener" class="btn">Open</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#9CA3AF;font-style:italic;">— no supporting file uploaded —</span>
                                    <?php endif; ?>
                                    <?php
                                        // Temporary debug: enable by appending ?debug_submission=1 to the URL.
                                        $showDebug = (isset($_GET['debug_submission']) && $_GET['debug_submission'] == '1');
                                        if ($showDebug) {
                                            $dbgFs = $fsPath ?? '';
                                            $dbgExists = ($dbgFs !== '' && @file_exists($dbgFs)) ? 'yes' : 'no';
                                            echo '<div style="margin-top:8px;font-size:12px;color:#6b7280;">Debug: filePath=' . htmlspecialchars((string)$filePath) . ' • absUrl=' . htmlspecialchars((string)$absUrl) . ' • fsPath=' . htmlspecialchars((string)$dbgFs) . ' • exists=' . $dbgExists . '</div>';
                                            echo '<details style="margin-top:6px;color:#374151;background:#fff;padding:8px;border-radius:8px;border:1px solid #E5E7EB;"><summary style="font-weight:700;">Submission columns (click to expand)</summary><div style="margin-top:8px;line-height:1.45;">';
                                            foreach (($submission ?? []) as $k => $v) {
                                                $val = is_scalar($v) ? (string)$v : json_encode($v);
                                                echo '<div style="font-size:12px;color:#111827;margin-bottom:6px;"><strong>' . htmlspecialchars($k) . '</strong>: <span style="color:#6b7280;">' . htmlspecialchars($val) . '</span></div>';
                                            }
                                            echo '</div></details>';
                                        }
                                    ?>
                                </dd>

                                <dt style="color:#6b7280;font-weight:700;">Resolution Outcome</dt>
                                <dd style="margin:0;color:#111827;"><?php echo $resolution_status !== '' ? htmlspecialchars($resolution_status) : '<span style="color:#9CA3AF;font-style:italic;">— not specified —</span>'; ?></dd>

                                <dt style="color:#6b7280;font-weight:700;">Follow-up required</dt>
                                <dd style="margin:0;color:#111827;">
                                    <?php
                                        // Normalize common truthy values: numeric 1, '1', 'yes', 'true', 'on'
                                        $fval = $follow_up_raw ?? '';
                                        $fstr = is_bool($fval) ? ($fval ? '1' : '0') : trim((string)$fval);
                                        $fstr_l = strtolower($fstr);
                                        $follow_yes = in_array($fstr_l, ['1','yes','true','on','y'], true);
                                        echo $follow_yes ? 'Yes' : 'No';
                                    ?>
                                </dd>

                                <dt style="color:#6b7280;font-weight:700;">Comments (optional)</dt>
                                <dd style="margin:0;color:#111827;">
                                    <?php
                                        // Render comments with paragraph structure for readability.
                                        function render_comment_paragraphs($raw) {
                                            $raw = (string)$raw;
                                            $raw = preg_replace("/\r\n|\r/", "\n", $raw);
                                            $raw = trim($raw);
                                            if ($raw === '') return '';
                                            $paras = preg_split("/\n{2,}/", $raw);
                                            $out = '';
                                            foreach ($paras as $p) {
                                                $p = trim($p, "\n\r\t ");
                                                if ($p === '') continue;
                                                $out .= '<p style="margin:0 0 8px;">' . nl2br(htmlspecialchars($p)) . '</p>';
                                            }
                                            return $out;
                                        }

                                        if (!empty($clean_comments)) {
                                            $html = render_comment_paragraphs($clean_comments);
                                            echo '<div style="background:#fff;border:1px solid #e5e7eb;padding:10px;border-radius:8px;color:#111827;">' . $html . '</div>';
                                        } elseif (!empty($submission['comments'] ?? null) && trim((string)($submission['comments'])) !== '') {
                                            $html = render_comment_paragraphs($submission['comments']);
                                            echo '<div style="background:#fff;border:1px solid #e5e7eb;padding:10px;border-radius:8px;color:#111827;">' . $html . '</div>';
                                        } else {
                                            echo '<span style="color:#9CA3AF;font-style:italic;">— none —</span>';
                                        }
                                    ?>
                                </dd>
                            </dl>
                        </div>
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