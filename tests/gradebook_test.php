<?php
// ================================================================
// LIVE GRADEBOOK WRITE-PATH ASSERTIONS (run: php tests/gradebook_test.php)
// Covers save_score_cells() validation + the class-average helpers that
// the Excel-style gradebook depends on for its live recompute.
// ================================================================
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../grade_engine.php';

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = ($got === $want);
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label = " . var_export($got, true) . " (expected " . var_export($want, true) . ")\n"; }
}

// ---- Locate the seeded demo class + one of its terms ----
$cid = (int)$db->query("SELECT id FROM classes WHERE join_code='G11MATH27'")->fetchColumn();
if (!$cid) { echo "Demo class not seeded - skipping.\n"; exit(0); }
$term = (int)$db->query("SELECT id FROM terms WHERE academic_year_id=(SELECT id FROM academic_years WHERE is_active=1) ORDER BY number LIMIT 1")->fetchColumn();
$sid = (int)$db->query("SELECT student_id FROM class_members WHERE class_id=" . $cid . " ORDER BY student_id LIMIT 1")->fetchColumn();
$item = (int)$db->query("SELECT id FROM grade_items WHERE class_id=" . $cid . " AND term_id=" . $term . " ORDER BY id LIMIT 1")->fetchColumn();
$max = (float)$db->query("SELECT max_score FROM grade_items WHERE id=" . $item)->fetchColumn();
echo "class=$cid term=$term student=$sid item=$item max=$max\n\n";

function score_of($db, $item, $sid) {
    $s = $db->prepare("SELECT score FROM grade_scores WHERE grade_item_id=? AND student_id=?");
    $s->execute([$item, $sid]);
    $v = $s->fetchColumn();
    return $v === false ? null : (float)$v;
}
// Idempotent: delete first, so restoring the same cell twice cannot create
// a duplicate row (the schema has a unique index, but the test should not
// rely on an INSERT failing).
function restore($db, $item, $sid, $old, $term) {
    $db->prepare("DELETE FROM grade_scores WHERE grade_item_id=? AND student_id=?")->execute([$item, $sid]);
    if ($old !== null) {
        $db->prepare("INSERT INTO grade_scores (grade_item_id, student_id, score, term_id) VALUES (?,?,?,?)")->execute([$item, $sid, $old, $term]);
    }
}
// Take a full snapshot of every score in this class+term so the test can put
// the database back exactly as it found it, whatever order the blocks run in.
$snapshot = [];
$snapq = $db->prepare(
    "SELECT gs.grade_item_id, gs.student_id, gs.score, gs.term_id
     FROM grade_scores gs JOIN grade_items gi ON gi.id = gs.grade_item_id
     WHERE gi.class_id=? AND gi.term_id=?");
$snapq->execute([$cid, $term]);
foreach ($snapq->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $snapshot[$r['grade_item_id'] . ':' . $r['student_id']] = [$r['grade_item_id'], $r['student_id'], $r['score'], $r['term_id']];
}
function restore_snapshot($db, $cid, $term, $snapshot) {
    $db->prepare("DELETE FROM grade_scores WHERE grade_item_id IN (SELECT id FROM grade_items WHERE class_id=? AND term_id=?)")->execute([$cid, $term]);
    if (!$snapshot) return;
    $ins = $db->prepare("INSERT INTO grade_scores (grade_item_id, student_id, score, term_id) VALUES (?,?,?,?)");
    foreach ($snapshot as $r) $ins->execute([$r[0], $r[1], $r[2], $r[3]]);
}

$original = score_of($db, $item, $sid);

echo "== save_score_cells: happy path ==\n";
$out = save_score_cells($db, $cid, $term, [$item => [$sid => 12]]);
check('one cell saved', $out['saved'], 1);
check('no rejections', count($out['rejected']), 0);
check('student reported as touched', $out['students'], [$sid]);
check('value written', score_of($db, $item, $sid), 12.0);

// Update in place (must not create a duplicate row)
$out = save_score_cells($db, $cid, $term, [$item => [$sid => 7]]);
check('update saved', $out['saved'], 1);
check('value updated, no duplicate', score_of($db, $item, $sid), 7.0);
check('still one score row', (int)$db->query("SELECT COUNT(*) FROM grade_scores WHERE grade_item_id=" . $item . " AND student_id=" . $sid)->fetchColumn(), 1);

echo "\n== save_score_cells: over max is rejected, not written ==\n";
$out = save_score_cells($db, $cid, $term, [$item => [$sid => $max + 1]]);
check('nothing saved', $out['saved'], 0);
check('one rejection', count($out['rejected']), 1);
check('rejection reason', $out['rejected'][0]['reason'], 'over max');
check('rejected row not touched', $out['students'], []);
check('previous value intact', score_of($db, $item, $sid), 7.0);

echo "\n== save_score_cells: exactly max is allowed ==\n";
$out = save_score_cells($db, $cid, $term, [$item => [$sid => $max]]);
check('max accepted', $out['saved'], 1);
check('value at max', score_of($db, $item, $sid), $max);

echo "\n== save_score_cells: negative / non-numeric ==\n";
$out = save_score_cells($db, $cid, $term, [$item => [$sid => -1]]);
check('negative rejected', $out['rejected'][0]['reason'], 'negative score');
check('negative not written', score_of($db, $item, $sid), $max);
$out = save_score_cells($db, $cid, $term, [$item => [$sid => 'abc']]);
check('non-numeric rejected', $out['rejected'][0]['reason'], 'not a number');
check('non-numeric not written', score_of($db, $item, $sid), $max);

echo "\n== save_score_cells: empty value deletes (stays excluded, not zero) ==\n";
$out = save_score_cells($db, $cid, $term, [$item => [$sid => '']]);
check('delete counted as saved', $out['saved'], 1);
check('row removed', score_of($db, $item, $sid), null);
check('student still reported as touched', $out['students'], [$sid]);
// Deleting again is a no-op but must not error
$out = save_score_cells($db, $cid, $term, [$item => [$sid => '']]);
check('repeat delete saves nothing', $out['saved'], 0);

echo "\n== save_score_cells: authorisation guards ==\n";
$outsider = (int)$db->query("SELECT id FROM users WHERE id NOT IN (SELECT student_id FROM class_members WHERE class_id=" . $cid . ") LIMIT 1")->fetchColumn();
if ($outsider) {
    $out = save_score_cells($db, $cid, $term, [$item => [$outsider => 5]]);
    check('non-member rejected', $out['rejected'][0]['reason'], 'student not enrolled');
    check('non-member not saved', $out['saved'], 0);
} else {
    echo "  (every user is enrolled - skipping non-member check)\n";
}
$out = save_score_cells($db, $cid, $term, [999999 => [$sid => 5]]);
check('unknown activity rejected', $out['rejected'][0]['reason'], 'unknown activity');
// Same activity id, but a term the class does not own -> not writable
$out = save_score_cells($db, $cid, 999999, [$item => [$sid => 5]]);
check('wrong term rejected', $out['rejected'][0]['reason'], 'unknown activity');
check('wrong term not saved', $out['saved'], 0);

echo "\n== batch: one good + one bad in the same request ==\n";
$item2 = (int)$db->query("SELECT id FROM grade_items WHERE class_id=" . $cid . " AND term_id=" . $term . " AND id<>" . $item . " ORDER BY id LIMIT 1")->fetchColumn();
if ($item2) {
    $max2 = (float)$db->query("SELECT max_score FROM grade_items WHERE id=" . $item2)->fetchColumn();
    $orig2 = score_of($db, $item2, $sid);
    $out = save_score_cells($db, $cid, $term, [$item => [$sid => 10], $item2 => [$sid => $max2 + 5]]);
    check('good cell saved', $out['saved'], 1);
    check('bad cell rejected', count($out['rejected']), 1);
    check('good cell written', score_of($db, $item, $sid), 10.0);
    restore($db, $item2, $sid, $orig2, $term);
} else {
    echo "  (only one activity in this term - skipping batch check)\n";
}

echo "\n== multi-cell paste shape (one activity, whole class) ==\n";
$sids = $db->query("SELECT student_id FROM class_members WHERE class_id=" . $cid . " ORDER BY student_id")->fetchAll(PDO::FETCH_COLUMN);
$pasted = [];
$origs = [];
foreach ($sids as $s) { $origs[$s] = score_of($db, $item, $s); $pasted[$s] = 15; }
$out = save_score_cells($db, $cid, $term, [$item => $pasted]);
check('whole class written', $out['saved'], count($sids));
check('all students reported', count($out['students']), count($sids));
check('sample value written', score_of($db, $item, $sids[0]), 15.0);

echo "\n== class average helpers ==\n";
$all = compute_class_term_grades($db, $cid, $term);
$item_avg = class_item_averages($db, $cid, $term);
$cat_avg = class_category_averages($all);
$grade_avg = class_grade_averages($all);
check('item average is a number', is_float($item_avg[$item]) || is_int($item_avg[$item]), true);
check('item average matches uniform column', $item_avg[$item], 15.0);
check('category averages present', count($cat_avg) > 0, true);
check('grade average is a number', is_float($grade_avg['ig']) || is_int($grade_avg['ig']), true);

// Clearing every activity of one category must drop that category entirely
// from the weighting — it must NOT contribute a zero and drag IG down.
$some = $sids[0];
$victim = (int)$db->query("SELECT id FROM grade_categories WHERE class_id=" . $cid . " AND term_id=" . $term . " ORDER BY id LIMIT 1")->fetchColumn();
$victim_items = $db->query("SELECT id FROM grade_items WHERE category_id=" . $victim)->fetchAll(PDO::FETCH_COLUMN);
$victim_saved = [];
foreach ($victim_items as $vi) $victim_saved[$vi] = score_of($db, $vi, $some);
foreach ($victim_items as $vi) $db->prepare("DELETE FROM grade_scores WHERE grade_item_id=? AND student_id=?")->execute([$vi, $some]);

$cleared = compute_class_term_grades($db, $cid, $term, [$some])[$some];
$cleared_comp = null;
foreach ($cleared['components'] as $c) if ($c['category_id'] === $victim) $cleared_comp = $c;
check('cleared category reports no PS', $cleared_comp['ps'], null);
check('cleared category contributes no weight', $cleared_comp['ws'], 0);

// Expected IG = weighted average over the categories that still have evidence
$ws = 0.0; $aw = 0.0;
foreach ($cleared['components'] as $c) {
    if ($c['ps'] === null) continue;
    $ws += $c['ps'] * $c['weight'] / 100;
    $aw += $c['weight'];
}
check('IG renormalized over remaining categories', $cleared['ig'], round($ws * 100 / $aw, 2));
check('active weight excludes the cleared category', $cleared['active_weight'], $aw);
check('total weight unchanged', $cleared['total_weight'], $aw + $cleared_comp['weight']);
check('IG is not dragged down by a zero', $cleared['ig'] > 0, true);

foreach ($victim_items as $vi) restore($db, $vi, $some, $victim_saved[$vi], $term);

echo "\n== grade_row_payload shape ==\n";
$payload = grade_row_payload($all[$sids[0]]);
check('has ps map', isset($payload['ps']) && is_array($payload['ps']), true);
check('has ig', isset($payload['ig']), true);
check('has tg key', array_key_exists('tg', $payload), true);
check('has desc key', array_key_exists('desc', $payload), true);

// ---- restore the whole snapshot, whatever the blocks above changed ----
restore_snapshot($db, $cid, $term, $snapshot);
check('database restored to its starting state', score_of($db, $item, $sid), $original);
check('score row count restored', (int)$db->query("SELECT COUNT(*) FROM grade_scores WHERE grade_item_id IN (SELECT id FROM grade_items WHERE class_id=" . $cid . " AND term_id=" . $term . ")")->fetchColumn(), count($snapshot));

echo "\n-------------------------------------\nRESULT: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
