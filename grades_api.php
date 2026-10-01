<?php
// ================================================================
// GRADEBOOK LIVE ENDPOINT (Excel-style grid)
// Accepts the dirty cells of the gradebook, validates + writes them,
// and returns the recomputed PS/IG/TG/descriptor plus the class
// averages so the browser can patch the grid without a page reload.
//
// All grade math stays in grade_engine.php — this file only moves
// data. Auth: teacher session + CSRF header + class ownership.
// ================================================================
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function gb_out($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (current_role() !== 'teacher') {
    gb_out(['ok' => false, 'error' => 'Teacher access only.'], 403);
}
if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    gb_out(['ok' => false, 'error' => 'Session expired. Reload the page.'], 419);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gb_out(['ok' => false, 'error' => 'POST required.'], 405);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) gb_out(['ok' => false, 'error' => 'Invalid request body.'], 400);

$class_id = (int)($in['class_id'] ?? 0);
$term_id = (int)($in['term_id'] ?? 0);
$changes = $in['changes'] ?? null;

if (!$class_id || !$term_id) gb_out(['ok' => false, 'error' => 'Missing class or term.'], 400);
if (!is_array($changes) || !$changes) gb_out(['ok' => false, 'error' => 'No changes to save.'], 400);
if (!teacher_owns_class($db, current_user_id(), $class_id)) {
    gb_out(['ok' => false, 'error' => 'Unauthorized.'], 403);
}

// Guard against a runaway client posting the entire gradebook in one go.
if (count($changes) > 5000) gb_out(['ok' => false, 'error' => 'Too many cells in one request.'], 413);

$res = save_score_cells($db, $class_id, $term_id, $changes);

// Recompute the whole class once: the class-average row must stay
// correct, and with the policy memoized this is ~2 queries per student.
$all = compute_class_term_grades($db, $class_id, $term_id);

$rows = [];
foreach ($res['students'] as $sid) {
    if (isset($all[(int)$sid])) $rows[(string)$sid] = grade_row_payload($all[(int)$sid]);
}

// Audit on a coarse cadence — one row per class per minute instead of one
// per keystroke — so autosave does not flood the audit log.
$now = time();
if ($res['saved'] > 0 && ($now - (int)($_SESSION['gb_audit_at'] ?? 0)) >= 60) {
    audit($db, 'gradebook_autosave', 'classes', $class_id, null,
        $res['saved'] . ' cell(s), term ' . $term_id);
    $_SESSION['gb_audit_at'] = $now;
}

gb_out([
    'ok' => true,
    'saved' => $res['saved'],
    'rejected' => $res['rejected'],
    'rows' => $rows,
    'averages' => [
        'items' => class_item_averages($db, $class_id, $term_id),
        'ps' => class_category_averages($all),
        'grades' => class_grade_averages($all),
    ],
]);
