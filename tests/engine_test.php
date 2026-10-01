<?php
// ================================================================
// GRADE ENGINE ASSERTIONS (run: php tests/engine_test.php)
// ================================================================
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../grade_engine.php';

$pass = 0; $fail = 0;
function check($label, $actual, $expected) {
    global $pass, $fail;
    $ok = abs((float)$actual - (float)$expected) < 0.011;
    if ($ok) { $pass++; echo "  PASS  $label = $actual\n"; }
    else { $fail++; echo "  FAIL  $label = $actual (expected $expected)\n"; }
}
// Strict string/boolean comparison (check() above is numeric only)
function check_str($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label = " . var_export($got, true) . " (expected " . var_export($want, true) . ")\n"; }
}

echo "== Transmutation: adjusted_table (DO 015 s.2026) ==\n";
$rows = $db->query("SELECT min_ig, max_ig, tg FROM transmutation_rows WHERE policy_id=(SELECT id FROM grading_policies WHERE transmutation_mode='adjusted_table' LIMIT 1) ORDER BY min_ig")->fetchAll(PDO::FETCH_ASSOC);
check('IG 100 -> TG', transmute_ig(100, 'adjusted_table', $rows), 100);
check('IG 99.50 -> TG', transmute_ig(99.50, 'adjusted_table', $rows), 100);
check('IG 99.49 -> TG', transmute_ig(99.49, 'adjusted_table', $rows), 99);
check('IG 90.06 -> TG', transmute_ig(90.06, 'adjusted_table', $rows), 92);
check('IG 88.87 -> TG', transmute_ig(88.87, 'adjusted_table', $rows), 90);
check('IG 71.18 -> TG', transmute_ig(71.18, 'adjusted_table', $rows), 76);
check('IG 71.17 -> TG', transmute_ig(71.17, 'adjusted_table', $rows), 75);
check('IG 70.00 -> TG', transmute_ig(70.00, 'adjusted_table', $rows), 75);
check('IG 69.99 -> TG', transmute_ig(69.99, 'adjusted_table', $rows), 74);
check('IG 60.67 -> TG', transmute_ig(60.67, 'adjusted_table', $rows), 73);
check('IG 4.68 -> TG', transmute_ig(4.68, 'adjusted_table', $rows), 61);
check('IG 0 -> TG', transmute_ig(0, 'adjusted_table', $rows), 60);

echo "\n== Transmutation: zero_based ==\n";
check('IG 85.225 -> TG', transmute_ig(85.225, 'zero_based', []), 85);
check('IG 74.4 -> TG', transmute_ig(74.4, 'zero_based', []), 74);

echo "\n== Transmutation: legacy_linear (DO 8 s.2015) ==\n";
check('IG 100 -> TG', transmute_ig(100, 'legacy_linear', []), 100);
check('IG 73 -> TG', transmute_ig(73, 'legacy_linear', []), 75);
check('IG 80 -> TG', transmute_ig(80, 'legacy_linear', []), 81);
check('IG 74.9 -> TG', transmute_ig(74.9, 'legacy_linear', []), 77);
check('IG 70 -> TG', transmute_ig(70, 'legacy_linear', []), 75);
check('IG 69.99 -> TG', transmute_ig(69.99, 'legacy_linear', []), 74);

echo "\n== Demo class (SY 2026-2027, DO 015 policy) ==\n";
$cid = (int)$db->query("SELECT id FROM classes WHERE join_code='G11MATH27'")->fetchColumn();
if ($cid) {
    $t1 = (int)$db->query("SELECT id FROM terms WHERE academic_year_id=(SELECT id FROM academic_years WHERE is_active=1) AND number=1")->fetchColumn();
    $sids = $db->query("SELECT student_id FROM class_members WHERE class_id=" . $cid . " ORDER BY student_id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sids as $sid) {
        $r = compute_term_grade($db, $cid, $sid, $t1);
        echo "  student $sid: ";
        foreach ($r['components'] as $c) echo $c['code'] . '=' . ($c['ps'] ?? '--') . ' ';
        echo "IG=" . $r['ig'] . " TG=" . ($r['tg'] ?? '--') . " " . ($r['descriptor']['label_en'] ?? '') . "\n";
    }
    // Manually computed expectation for student with scores 18,17,45,40,35,32,50:
    // WW 35/40=87.5 -> WS 17.5 | PT 85/100=85 -> WS 42.5 | EX 87.5*0.3+80*0.3+83.333*0.4=83.583 -> WS 25.075
    // IG = 85.075 -> TG 87 (range 84.16-85.33)
    $demo = compute_term_grade($db, $cid, $sids[0], $t1);
    check('John IG', $demo['ig'], 85.08);
    check('John TG', $demo['tg'], 87);

    echo "\n== Missing-score exclusion (no zero penalty) ==\n";
    // Clear one PT item score for student 0, PT PS must recompute over recorded items only
    $pt_cat = (int)$db->query("SELECT gc.id FROM grade_categories gc WHERE gc.class_id=" . $cid . " AND gc.name LIKE '%Performance%' AND gc.term_id=" . $t1)->fetchColumn();
    $pt_items = $db->query("SELECT id, max_score FROM grade_items WHERE category_id=" . $pt_cat)->fetchAll(PDO::FETCH_ASSOC);
    $db->prepare("DELETE FROM grade_scores WHERE grade_item_id=? AND student_id=?")->execute([$pt_items[0]['id'], $sids[0]]);
    $r2 = compute_term_grade($db, $cid, $sids[0], $t1);
    // PT now 40/50 = 80% (previously 85%); WW 17.5 + PT 40 + EX 25.075 = IG 82.58
    $pt_comp = null;
    foreach ($r2['components'] as $c) if ($c['code'] == 'PT') $pt_comp = $c;
    check('PT PS after delete (80)', $pt_comp['ps'], 80);
    check('IG after delete (82.58)', $r2['ig'], 82.58);
    check('TG after delete (85)', $r2['tg'], 85);
    $db->prepare("INSERT INTO grade_scores (grade_item_id, student_id, score, term_id) VALUES (?,?,?,?)")->execute([$pt_items[0]['id'], $sids[0], 45, $t1]);

    echo "\n== Publishing gating ==\n";
    $db->prepare("DELETE FROM class_term_status WHERE class_id=? AND term_id=?")->execute([$cid, $t1]);
    check('Final grade unpublished -> null', final_grade_from_terms($db, $cid, $sids[0]) ?? -1, -1);
    $db->prepare("INSERT OR IGNORE INTO class_term_status (class_id, term_id) VALUES (?,?)")->execute([$cid, $t1]);
    $fg = final_grade_from_terms($db, $cid, $sids[0]);
    check('Final grade published (1 term)', $fg, 87);

    echo "\n== Target calculator ==\n";
    $t2 = (int)$db->query("SELECT id FROM terms WHERE academic_year_id=(SELECT id FROM academic_years WHERE is_active=1) AND number=2")->fetchColumn();
    $tr = target_requirements($db, $cid, $sids[0], $t2, 90);
    check('Target possible (empty term)', $tr['possible'] ? 1 : 0, 1);
    check('Target required PS (TG 90 -> IG 87.70)', $tr['required_ps'], 87.7);
    $tr2 = target_requirements($db, $cid, $sids[0], $t1, 90);
    check('Target on recorded term -> impossible', $tr2['possible'] ? 0 : 1, 1);

    // cleanup
    $db->prepare("DELETE FROM class_term_status WHERE class_id=? AND term_id=?")->execute([$cid, $t1]);
} else {
    echo "  (demo class not seeded - skipping)\n";
}

echo "\n== Descriptor lookup (DO 015 s.2026 bands) ==\n";
$desc = $db->query("SELECT * FROM descriptor_rows WHERE policy_id=(SELECT id FROM grading_policies WHERE transmutation_mode='adjusted_table' ORDER BY id LIMIT 1) ORDER BY min_grade DESC")->fetchAll(PDO::FETCH_ASSOC);
check_str('TG 92 -> Advancing', descriptor_for(92, $desc)['label_en'], 'Advancing');
check_str('TG 88 -> Benchmarking', descriptor_for(88, $desc)['label_en'], 'Benchmarking');
check_str('TG 80 -> Benchmarking (band edge)', descriptor_for(80, $desc)['label_en'], 'Benchmarking');
check_str('TG 79 -> Connecting', descriptor_for(79, $desc)['label_en'], 'Connecting');
check_str('TG 75 -> Connecting', descriptor_for(75, $desc)['label_en'], 'Connecting');
check_str('TG 74 -> Developing', descriptor_for(74, $desc)['label_en'], 'Developing');
check_str('TG 65 -> Developing (band edge)', descriptor_for(65, $desc)['label_en'], 'Developing');
check_str('TG 64 -> Emerging', descriptor_for(64, $desc)['label_en'], 'Emerging');
check_str('TG 0 -> Emerging', descriptor_for(0, $desc)['label_en'], 'Emerging');
check_str('Filipino label present', descriptor_for(92, $desc)['label_fil'], 'Namumukod-tangi');

// The repealed DO 8 s.2015 bands are retained only for historical classes.
$legacy_desc = $db->query("SELECT * FROM descriptor_rows WHERE policy_id=(SELECT id FROM grading_policies WHERE transmutation_mode='legacy_linear' LIMIT 1) ORDER BY min_grade DESC")->fetchAll(PDO::FETCH_ASSOC);
check_str('legacy TG 92 -> Outstanding', descriptor_for(92, $legacy_desc)['label_en'], 'Outstanding');
check_str('legacy TG 88 -> Very Satisfactory', descriptor_for(88, $legacy_desc)['label_en'], 'Very Satisfactory');
check_str('legacy TG 74 -> Did Not Meet Expectations', descriptor_for(74, $legacy_desc)['label_en'], 'Did Not Meet Expectations');

echo "\n== Number formatting (trim_num) ==\n";
// Regression: rtrim($n, '0') strips every trailing zero, which turns 20 into
// "2" and 50 into "5" - it silently corrupted activity maxima and the
// component weights shown in the policy banner.
check('20 stays 20', trim_num(20), '20');
check('50 stays 50', trim_num(50), '50');
check('40 stays 40', trim_num(40), '40');
check('60 stays 60', trim_num(60), '60');
check('100 stays 100', trim_num(100), '100');
check('20.00 becomes 20', trim_num('20.00'), '20');
check('20.50 stays 20.50', trim_num('20.50'), '20.50');
check('85.0750 becomes 85.075', trim_num('85.0750'), '85.075');
check('0 stays 0', trim_num(0), '0');
check('empty becomes 0', trim_num(''), '0');

if ($cid) {
    echo "\n== Policy banner keeps real weights ==\n";
    $summary = policy_components_summary(class_policy($db, $cid));
    $joined = implode(' | ', $summary);
    $weights = array_map(function ($s) { return (int)substr($s, strrpos($s, ' ') + 1); }, $summary);
    sort($weights);
    // Before the fix these rendered as "2%", "5%", "3%".
    check_str('policy weights render as 20/30/50', implode(',', $weights), '20,30,50');
    check_str('no mangled single-digit weights', (bool)preg_match('/(?<![0-9])[0-9]%/', $joined), false);
    echo "        summary: $joined\n";

    echo "\n== Activity maxima are intact ==\n";
    $maxima = $db->query("SELECT name, max_score FROM grade_items WHERE class_id=" . $cid . " AND term_id=" . $t1 . " ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    check('Quiz 1 max is 20', $maxima['Quiz 1'], 20);
    check('Performance Task 1 max is 50', $maxima['Performance Task 1'], 50);
    check('Summative Test 1 max is 40', $maxima['Summative Test 1'], 40);
    check('Term Examination max is 60', $maxima['Term Examination'], 60);
    check_str('Quiz 1 renders as /20 not /2', trim_num($maxima['Quiz 1']), '20');
    check_str('Term Examination renders as /60 not /6', trim_num($maxima['Term Examination']), '60');
}

echo "\n-------------------------------------\n";
echo "RESULT: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);