<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
if ($submission_id <= 0) {
    http_response_code(400);
    echo 'Invalid submission id';
    exit;
}

try {
    // Include display_type so we can render client_support specific fields when present
    $stmt = $pdo->prepare("SELECT qs.*, q.title AS quest_title, q.id AS quest_id, q.display_type AS display_type FROM quest_submissions qs LEFT JOIN quests q ON qs.quest_id = q.id WHERE qs.id = ? LIMIT 1");
    $stmt->execute([$submission_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$submission) {
        http_response_code(404);
        echo 'Submission not found';
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo 'DB error';
    exit;
}

// Build preview HTML (same structure used by view_submission/quest_assessment)
ob_start();
$quest_title = htmlspecialchars($submission['quest_title'] ?? 'Submission');
$status = strtolower(trim($submission['status'] ?? 'pending'));
$submittedAt = $submission['submitted_at'] ?? null;
$when = $submittedAt ? date('M d, Y g:i A', strtotime($submittedAt)) : 'Unknown time';
$filePath = '';
// Try a broad set of candidate columns (match view_submission.php logic) so updates stored
// into alternative columns (support_file, supporting_file, etc.) are picked up by the AJAX refresh.
$filePath = $submission['file_path'] ?? $submission['support_file'] ?? $submission['support_file_path'] ?? $submission['support_filepath'] ?? $submission['supportpath'] ?? $submission['supporting_file'] ?? $submission['supporting_file_path'] ?? $submission['supporting_filepath'] ?? $submission['file'] ?? $submission['uploaded_file'] ?? $submission['uploaded_filepath'] ?? $submission['attachment'] ?? $submission['attachment_path'] ?? $submission['attachments'] ?? $submission['support_files'] ?? $submission['submission_file'] ?? $submission['filepath'] ?? '';
$driveLink = $submission['drive_link'] ?? '';
$textContent = $submission['text_content'] ?? '';
$submissionText = $submission['submission_text'] ?? '';

// Helpers
function to_web_path($p) {
    if (!is_string($p) || $p === '') return '';
    $n = str_replace('\\','/',$p);
    $root = str_replace('\\','/', realpath(__DIR__) . '/');
    if (stripos($n, $root) === 0) {
        return ltrim(substr($n, strlen($root)), '/');
    }
    $pos = stripos($n, '/uploads/');
    if ($pos !== false) return ltrim(substr($n, $pos+1), '/');
    return $n;
}

// Normalize file path and compute a web-accessible URL similar to view_submission.php
$filePath = is_string($filePath) ? str_replace('\\','/', $filePath) : $filePath;
$absUrl = '';
if (!empty($filePath)) {
    if (filter_var($filePath, FILTER_VALIDATE_URL)) {
        $absUrl = $filePath;
    } else {
        $cand1 = __DIR__ . '/' . ltrim($filePath, '/');
        $docRoot = rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $cand2 = ($docRoot ? $docRoot : __DIR__) . '/' . ltrim($filePath, '/');
        $cand3 = $filePath;

        $fsPath = null;
        if (@file_exists($cand3)) { $fsPath = $cand3; }
        elseif (@file_exists($cand1)) { $fsPath = $cand1; }
        elseif (@file_exists($cand2)) { $fsPath = $cand2; }

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

$web = $absUrl ?: to_web_path($filePath);
$abs = $web ?: ($driveLink ?: $submissionText);
$ext = strtolower(pathinfo($web ?: $abs, PATHINFO_EXTENSION));
$fname = '';
if (!empty($filePath)) {
    // prefer explicit stored original filename fields if present
    $fileNameCandidates = [
        $submission['file_name'] ?? null,
        $submission['support_original_name'] ?? null,
        $submission['original_name'] ?? null,
        $submission['original_filename'] ?? null,
        $submission['file_original_name'] ?? null,
        $submission['original_file_name'] ?? null,
    ];
    foreach ($fileNameCandidates as $c) { if (!empty($c)) { $fname = $c; break; } }
    if ($fname === '') {
        if (!empty($abs)) $fname = basename(parse_url($abs, PHP_URL_PATH) ?: $abs);
        else $fname = basename($filePath);
    }
} else {
    $fname = $driveLink ? basename(parse_url($driveLink, PHP_URL_PATH) ?? '') : 'Submission';
}

$badgeClass = 'badge badge-pending'; $badgeLabel = 'Pending';
if ($status === 'under_review') { $badgeClass='badge badge-under'; $badgeLabel='Under Review'; }
elseif ($status === 'approved') { $badgeClass='badge badge-approved'; $badgeLabel='Graded'; }
elseif ($status === 'rejected') { $badgeClass='badge badge-rejected'; $badgeLabel='Declined'; }

?>
<div class="card">
    <div class="submission-header">
        <div>
            <div class="submission-title">Submission</div>
            <div class="submission-meta">Submitted: <?php echo htmlspecialchars($when); ?></div>
        </div>
        <span class="<?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
    </div>

    <?php
    $renderedAny = false;

    // Render file preview if present
    if ($web !== '') {
        $absUrl = $abs;
        $ext = strtolower(pathinfo($web ?: $abs, PATHINFO_EXTENSION));
        $fnameEsc = htmlspecialchars($fname);
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            echo '<div class="preview-block"><div class="btn-group" style="display:flex; gap:8px; flex-wrap:wrap;">'
                . '<a class="btn btn-primary btn-sm glightbox" href="#inline-'.md5($absUrl).'" data-type="inline">Open</a>'
                . '<a class="btn btn-secondary btn-sm view-newtab" href="'.htmlspecialchars($absUrl).'" target="_blank" rel="noopener">View in new tab</a>'
                . '<a class="btn btn-outline-primary btn-sm" href="'.htmlspecialchars($absUrl).'" download>Download</a>'
                . '</div>'
                . '<div class="glightbox-inline" id="inline-'.md5($absUrl).'">'
                . '<div class="preview-container"><div class="preview-header"><div class="preview-title"><span class="badge-file badge-img">IMG</span><span>'.$fnameEsc.'</span></div></div><div class="preview-body"><img style="max-width:100%;max-height:75vh;height:auto;display:block;margin:0 auto;background:#fff;" src="'.htmlspecialchars($absUrl).'" /></div></div></div></div>';
            $renderedAny = true;
        } elseif ($ext === 'pdf') {
            echo '<div class="preview-block"><div class="btn-group" style="display:flex; gap:8px; flex-wrap:wrap;">'
                . '<a class="btn btn-primary btn-sm glightbox" href="#inline-'.md5($absUrl).'" data-type="inline">Open</a>'
                . '<a class="btn btn-secondary btn-sm view-newtab" href="'.htmlspecialchars($absUrl).'" target="_blank" rel="noopener">View in new tab</a>'
                . '<a class="btn btn-outline-primary btn-sm" href="'.htmlspecialchars($absUrl).'" download>Download</a>'
                . '</div>'
                . '<div class="glightbox-inline" id="inline-'.md5($absUrl).'">'
                . '<div class="preview-container"><div class="preview-header"><div class="preview-title"><span class="badge-file badge-pdf">PDF</span><span>'.$fnameEsc.'</span></div></div><div class="preview-body"><iframe src="'.htmlspecialchars($absUrl).'" ></iframe></div></div></div></div>';
            $renderedAny = true;
        } elseif (in_array($ext, ['txt','md','csv'])) {
            echo '<div class="preview-block">'
                . '<div class="glightbox-inline" id="inline-'.md5($absUrl).'">'
                . '<div class="preview-container">'
                . '<div class="preview-header"><div class="preview-title"><span class="badge-file badge-text">TEXT</span><span>'.$fnameEsc.'</span></div></div>'
                . '<div class="preview-body"><div class="docx-view"><div class="docx-html">'.htmlspecialchars(@file_get_contents($web)).'</div></div></div>'
                . '</div></div>'
                . '<div class="btn-group" style="display:flex; gap:8px; flex-wrap:wrap;">'
                . '<a class="btn btn-primary btn-sm glightbox" href="#inline-'.md5($absUrl).'" data-gallery="submission" data-type="inline">Open</a>'
                . '<a class="btn btn-secondary btn-sm view-newtab" href="'.htmlspecialchars($absUrl).'" target="_blank" rel="noopener">View in new tab</a>'
                . '<a class="btn btn-outline-primary btn-sm" href="'.htmlspecialchars($absUrl).'" download>Download</a>'
                . '</div>'
                . '</div>';
            $renderedAny = true;
        } else {
            $inlineId = 'inline-'.md5($web);
            $title = $fnameEsc;
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $isLocal = (stripos($host, 'localhost') !== false) || (stripos($host, '127.0.0.1') !== false);
            $gview = 'https://docs.google.com/gview?embedded=1&url=' . rawurlencode($absUrl);

            echo '<div class="preview-block">'
                . '<div class="btn-group" style="display:flex; gap:8px; flex-wrap:wrap;">'
                . (
                    (strtolower(pathinfo($absUrl, PATHINFO_EXTENSION)) === 'docx')
                    ? '<a class="btn btn-primary btn-sm glightbox office-open" href="#' . $inlineId . '" data-type="inline" role="button" tabindex="0" data-file="' . htmlspecialchars($absUrl) . '" data-inline="#' . $inlineId . '" data-title="' . $title . '">Open</a>'
                    : '<a class="btn btn-primary btn-sm glightbox" href="#' . $inlineId . '" data-type="inline">Open</a>'
                )
                . '<a class="btn btn-secondary btn-sm view-newtab" href="'.htmlspecialchars($absUrl).'" data-abs="'.htmlspecialchars($absUrl).'" data-gview="'.htmlspecialchars($gview).'" data-ext="'.htmlspecialchars(pathinfo($absUrl, PATHINFO_EXTENSION)).'" target="_blank" rel="noopener">View in new tab</a>'
                . '<a class="btn btn-outline-primary btn-sm" href="'.htmlspecialchars($absUrl).'" download>Download</a>'
                . '</div>'
                . '<div class="glightbox-inline" id="' . $inlineId . '">'
                    . '<div class="preview-container">'
                        . '<div class="preview-header"><div class="preview-title"><span class="badge-file ' . (in_array(strtolower(pathinfo($absUrl, PATHINFO_EXTENSION)), ['xls','xlsx']) ? 'badge-other' : (in_array(strtolower(pathinfo($absUrl, PATHINFO_EXTENSION)), ['ppt','pptx']) ? 'badge-other' : 'badge-docx')) . '">' . strtoupper(htmlspecialchars(pathinfo($absUrl, PATHINFO_EXTENSION))) . '</span><span>' . $title . '</span></div></div>'
                        . '<div class="preview-body" style="min-height:70vh; background:#fff;">'
                            . (
                                strtolower(pathinfo($absUrl, PATHINFO_EXTENSION)) === 'docx'
                                ? '<div class="docx-view"><div class="docx-html">Loading preview…</div></div>'
                                : (
                                    !$isLocal
                                    ? '<iframe src="' . htmlspecialchars($gview) . '" height="640"></iframe>'
                                    : '<div style="padding:16px;line-height:1.6;">Preview for this file type is not available on localhost.<br><a class="btn btn-secondary btn-sm" href="' . htmlspecialchars($absUrl) . '" target="_blank" rel="noopener">Open in new tab</a></div>'
                                )
                            )
                        . '</div>'
                    . '</div>'
                . '</div>'
            . '</div>';
            $renderedAny = true;
        }
    }

    // Render drive link if present
    if (!empty($driveLink) && filter_var($driveLink, FILTER_VALIDATE_URL)) {
        echo '<div class="preview-block"><div>External Link:</div><div class="btn-group" style="margin-top:8px;"><a class="btn btn-primary btn-sm" href="'.htmlspecialchars($driveLink).'" target="_blank" rel="noopener">Open Link</a></div><div style="margin-top:6px;color:#374151;word-break:break-all;">'.htmlspecialchars($driveLink).'</div></div>';
        $renderedAny = true;
    }

    // Render text content if present
    if (!empty($textContent)) {
        echo '<div class="preview-block"><div class="preview-text">'.htmlspecialchars($textContent).'</div></div>';
        $renderedAny = true;
    }

    // Render submission_text if present and not a URL (or if URL, also show it as link)
    if (!empty($submissionText)) {
        if (filter_var($submissionText, FILTER_VALIDATE_URL)) {
            echo '<div class="preview-block"><div>External Link:</div><div class="btn-group" style="margin-top:8px;"><a class="btn btn-primary btn-sm" href="'.htmlspecialchars($submissionText).'" target="_blank" rel="noopener">Open Link</a></div><div style="margin-top:6px;color:#374151;word-break:break-all;">'.htmlspecialchars($submissionText).'</div></div>';
        } else {
            echo '<div class="preview-block"><div class="preview-text">'.htmlspecialchars($submissionText).'</div></div>';
        }
        $renderedAny = true;
    }

    // If this quest is client_support, render client/support specific fields so
    // the AJAX-refreshed card shows updated ticket, action_taken, time_spent, evidence, resolution and follow-up.
    $isClientSupport = (isset($submission['display_type']) && $submission['display_type'] === 'client_support');
    if ($isClientSupport) {
        // Normalize values from common column names
        $ticket = $submission['ticket_reference'] ?? $submission['ticket_id'] ?? $submission['ticket'] ?? '';
        $action_taken = $submission['action_taken'] ?? $submission['text_content'] ?? $submission['text'] ?? '';
        $time_spent = $submission['time_spent'] ?? $submission['time_spent_hours'] ?? $submission['time_spent_hrs'] ?? '';
        $resolution_status = $submission['resolution_status'] ?? '';
        $follow_up_raw = $submission['follow_up_required'] ?? $submission['follow_up'] ?? '';

        // Evidence handling (JSON or comma list)
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

        echo '<div class="preview-block" style="margin-top:12px;">';
        echo '<div style="font-weight:700;color:#6b7280;margin-bottom:6px;">Client & Support Details</div>';
        echo '<div class="card" style="padding:12px;">';
        echo '<div style="margin-bottom:8px;"><strong>Ticket / Reference ID</strong><div>' . (!empty($ticket) ? htmlspecialchars($ticket) : '<span style="color:#9CA3AF;font-style:italic;">— not provided —</span>') . '</div></div>';
        echo '<div style="margin-bottom:8px;"><strong>Action Taken / Resolution</strong><div>' . (!empty($action_taken) ? '<div style="background:#fff;border:1px solid #e5e7eb;padding:10px;border-radius:8px;color:#111827;white-space:pre-wrap;">' . nl2br(htmlspecialchars($action_taken)) . '</div>' : '<span style="color:#9CA3AF;font-style:italic;">— not provided —</span>') . '</div></div>';
        echo '<div style="margin-bottom:8px;"><strong>Time Spent (hours)</strong><div>' . ($time_spent !== '' ? htmlspecialchars($time_spent) : '<span style="color:#9CA3AF;font-style:italic;">— not provided —</span>') . '</div></div>';
        echo '<div style="margin-bottom:8px;"><strong>Evidence / Attachments</strong><div>';
        if (!empty($evidence_list)) {
            echo '<div style="display:flex;flex-direction:column;gap:8px;">';
            foreach ($evidence_list as $ev) {
                $ev_trim = trim((string)$ev);
                if ($ev_trim === '') continue;
                $isUrl = filter_var($ev_trim, FILTER_VALIDATE_URL);
                if ($isUrl) echo '<a href="' . htmlspecialchars($ev_trim) . '" target="_blank" rel="noopener">' . htmlspecialchars($ev_trim) . '</a>';
                else echo '<div>' . htmlspecialchars($ev_trim) . '</div>';
            }
            echo '</div>';
        } else {
            echo '<span style="color:#9CA3AF;font-style:italic;">— none provided —</span>';
        }
        echo '</div></div>';

        // Supporting file display (reuse computed $web/$abs when possible)
        echo '<div style="margin-bottom:8px;"><strong>Upload supporting file</strong><div>';
        if (!empty($filePath)) {
            $fname = basename($filePath);
            echo '<div class="file-pill" style="display:inline-flex;padding:6px 10px;margin-bottom:8px;">' . htmlspecialchars($fname) . '</div>';
        } else {
            echo '<span style="color:#9CA3AF;font-style:italic;">— no supporting file uploaded —</span>';
        }
        echo '</div></div>';

        echo '<div style="margin-bottom:8px;"><strong>Resolution Outcome</strong><div>' . (!empty($resolution_status) ? htmlspecialchars($resolution_status) : '<span style="color:#9CA3AF;font-style:italic;">— not specified —</span>') . '</div></div>';
        $fval = $follow_up_raw ?? '';
        $fstr = is_bool($fval) ? ($fval ? '1' : '0') : trim((string)$fval);
        $fstr_l = strtolower($fstr);
        $follow_yes = in_array($fstr_l, ['1','yes','true','on','y'], true);
        echo '<div style="margin-bottom:8px;"><strong>Follow-up required</strong><div>' . ($follow_yes ? 'Yes' : 'No') . '</div></div>';

        echo '<div style="margin-bottom:8px;"><strong>Comments</strong><div>' . (!empty($submission['comments'] ?? null) ? '<div style="background:#fff;border:1px solid #e5e7eb;padding:10px;border-radius:8px;color:#111827;white-space:pre-wrap;">' . nl2br(htmlspecialchars($submission['comments'])) . '</div>' : '<span style="color:#9CA3AF;font-style:italic;">— none —</span>') . '</div></div>';

        echo '</div>'; // .card
        echo '</div>'; // preview-block
        $renderedAny = true;
    }

    if (!$renderedAny) {
        echo '<div class="preview-block">No preview available. Check attachments or links in the submission record.</div>';
    }
    ?>
</div>

<?php
$out = ob_get_clean();
header('Content-Type: text/html; charset=utf-8');
echo $out;
exit;
