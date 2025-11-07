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
    $stmt = $pdo->prepare("SELECT qs.*, q.title AS quest_title, q.id AS quest_id FROM quest_submissions qs LEFT JOIN quests q ON qs.quest_id = q.id WHERE qs.id = ? LIMIT 1");
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
$filePath = $submission['file_path'] ?? '';
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

$web = to_web_path($filePath);
$abs = $web ?: ($driveLink ?: $submissionText);
$ext = strtolower(pathinfo($web ?: $abs, PATHINFO_EXTENSION));
$fname = $web ? basename($web) : ($driveLink ? basename(parse_url($driveLink, PHP_URL_PATH) ?? '') : 'Submission');

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
