<?php
// ================================================================
// GRADING POLICY CATALOGUE ASSERTIONS
// (run: php tests/policies_test.php)
//
// Guards the DO 015, s. 2026 / DO 017, s. 2026 policy templates:
//   - Senior High has exactly two tracks (Academic, TechPro)
//   - every selectable policy can actually build a gradebook
//   - component weights are the real ones and sum to 100
//   - descriptor bands are contiguous 0-100 with no gaps
// ================================================================
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../grade_engine.php';

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label = " . var_export($got, true) . " (expected " . var_export($want, true) . ")\n"; }
}

echo "== Two Senior High tracks only (DO 017, s. 2026) ==\n";
$tracks = $db->query("SELECT DISTINCT track FROM grading_policies WHERE track LIKE 'Senior High%' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
sort($tracks);
check('SHS tracks present', $tracks, ['Senior High - Academic', 'Senior High - Both Tracks', 'Senior High - TechPro']);
$strandy = $db->query("SELECT COUNT(*) FROM grading_policies WHERE is_active=1 AND (name LIKE '%STEM%' OR name LIKE '%HUMSS%' OR name LIKE '%ABM%' OR name LIKE '%TVL%' OR name LIKE '%Strand%')")->fetchColumn();
check('no strand-era policies remain (STEM/HUMSS/ABM/TVL/Strand)', (int)$strandy, 0);

echo "\n== No dead ends: every numerical policy can build a gradebook ==\n";
// This is the regression that mattered: a policy with a name and a
// description but no component rows produced a class whose gradebook said
// "Nothing to grade yet".
$dead = [];
foreach ($db->query("SELECT id, name FROM grading_policies WHERE is_active=1 AND transmutation_mode <> 'none'") as $p) {
    $n = (int)$db->query("SELECT COUNT(*) FROM grading_components WHERE policy_id=" . (int)$p['id'])->fetchColumn();
    if ($n === 0) $dead[] = $p['name'];
}
check('no policy is missing its components', $dead, []);

echo "\n== Component weights are the DO 015, s. 2026 Table 10 values ==\n";
$expected = [
    'DO 015 s.2026 - SHS Academic: Core & Academic Electives'                 => '20/50/30',
    'DO 015 s.2026 - SHS Academic: Arts, Sports, Health & Wellness'          => '20/60/20',
    'DO 015 s.2026 - SHS Academic: Field Experience / Arts Apprenticeship'  => '15/70/15',
    'DO 015 s.2026 - SHS Academic: Research Electives & Design Innovation'   => '40/60',
    'DO 015 s.2026 - SHS TechPro: TechPro Electives'                         => '15/65/20',
    'DO 015 s.2026 - SHS Work Immersion (both tracks)'                       => '20/80',
    'DO 015 s.2026 - KS2/KS3 Core Subjects'                                  => '20/50/30',
    'DO 015 s.2026 - KS2/KS3 MAPEH / EPP-TLE'                                => '20/60/20',
    'Legacy DO 8 s.2015 (historical classes)'                                => '25/50/25',
];
$actual = [];
foreach ($db->query("SELECT id, name FROM grading_policies WHERE is_active=1") as $p) {
    $actual[$p['name']] = policy_weight_label($db, $p['id']);
}
foreach ($expected as $name => $weights) {
    check($weights . '  <- ' . $name, $actual[$name] ?? '(missing)', $weights);
}

echo "\n== Weights sum to 100 ==\n";
$bad = [];
foreach ($db->query("SELECT id, name FROM grading_policies WHERE is_active=1 AND transmutation_mode <> 'none'") as $p) {
    $sum = (float)$db->query("SELECT COALESCE(SUM(weight),0) FROM grading_components WHERE policy_id=" . (int)$p['id'])->fetchColumn();
    if (abs($sum - 100) > 0.01) $bad[] = $p['name'] . ' = ' . trim_num($sum);
}
check('every policy totals 100%', $bad, []);

echo "\n== Exam internal split (ST1 / ST2 / TE) ==\n";
function ex_split_of($db, $policy_name) {
    $q = $db->prepare("SELECT gc.ex_st1, gc.ex_st2, gc.ex_te FROM grading_components gc
        JOIN grading_policies p ON p.id = gc.policy_id
        WHERE p.name = ? AND gc.code = 'EX' LIMIT 1");
    $q->execute([$policy_name]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return $r['ex_st1'] . '/' . $r['ex_st2'] . '/' . $r['ex_te'];
}
check('SHS Academic Core exams are 30/30/40', ex_split_of($db, 'DO 015 s.2026 - SHS Academic: Core & Academic Electives'), '30/30/40');
check('TechPro electives exams are 30/30/40', ex_split_of($db, 'DO 015 s.2026 - SHS TechPro: TechPro Electives'), '30/30/40');
check('Field Experience is the Term Exam only', ex_split_of($db, 'DO 015 s.2026 - SHS Academic: Field Experience / Arts Apprenticeship'), '0/0/100');
check('Research electives have no exam component', ex_split_of($db, 'DO 015 s.2026 - SHS Academic: Research Electives & Design Innovation'), null);
check('Work Immersion has no exam component', ex_split_of($db, 'DO 015 s.2026 - SHS Work Immersion (both tracks)'), null);

echo "\n== Descriptor bands are contiguous 0-100 with no gaps ==\n";
$bad = [];
foreach ($db->query("SELECT id, name FROM grading_policies WHERE is_active=1") as $p) {
    $rows = $db->query("SELECT min_grade, max_grade FROM descriptor_rows WHERE policy_id=" . (int)$p['id'] . " ORDER BY max_grade DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 0) { $bad[] = $p['name'] . ' (no bands)'; continue; }
    $prev = 100.0;
    foreach ($rows as $r) {
        if (abs((float)$r['max_grade'] - $prev) > 0.02) { $bad[] = $p['name'] . ' gap at ' . $r['min_grade'] . '-' . $r['max_grade']; break; }
        $prev = (float)$r['min_grade'] - 0.01;
    }
    if (abs($prev) > 0.02) $bad[] = $p['name'] . ' does not reach 0';
}
check('all descriptor sets are contiguous 0-100', $bad, []);

echo "\n== DO 015 policies use the new bands; Legacy keeps the DO 8 scale ==\n";
$do015 = $db->query("SELECT DISTINCT d.label_en FROM descriptor_rows d JOIN grading_policies p ON p.id=d.policy_id
    WHERE p.source_doc LIKE 'DepEd Order No. 015%' ORDER BY d.min_grade DESC")->fetchAll(PDO::FETCH_COLUMN);
check('DO 015 bands', $do015, ['Advancing', 'Benchmarking', 'Connecting', 'Developing', 'Emerging']);
$stale = (int)$db->query("SELECT COUNT(*) FROM descriptor_rows WHERE label_en IN ('Very Satisfactory','Outstanding','Fairly Satisfactory','Did Not Meet Expectations')
    AND policy_id IN (SELECT id FROM grading_policies WHERE source_doc LIKE 'DepEd Order No. 015%')")->fetchColumn();
check('no DO 015 policy still uses the repealed DO 8 labels', (int)$stale, 0);
$legacy = $db->query("SELECT d.label_en FROM descriptor_rows d JOIN grading_policies p ON p.id=d.policy_id
    WHERE p.transmutation_mode='legacy_linear' ORDER BY d.min_grade DESC")->fetchAll(PDO::FETCH_COLUMN);
check('Legacy keeps the DO 8 labels', $legacy, ['Outstanding', 'Very Satisfactory', 'Satisfactory', 'Fairly Satisfactory', 'Did Not Meet Expectations']);

echo "\n== Transmutation table present where the mode needs it ==\n";
$bad = [];
foreach ($db->query("SELECT id, name FROM grading_policies WHERE is_active=1 AND transmutation_mode='adjusted_table'") as $p) {
    $n = (int)$db->query("SELECT COUNT(*) FROM transmutation_rows WHERE policy_id=" . (int)$p['id'])->fetchColumn();
    if ($n < 40) $bad[] = $p['name'] . ' has ' . $n . ' rows';
}
check('every adjusted_table policy has the full 40-row table', $bad, []);

echo "\n== Picker grouping ==\n";
$groups = grading_policy_groups($db, true);
$labels = array_column($groups, 'label');
check('groups are ordered JHS then SHS then Other', $labels, ['JHS / JHS Core', 'Senior High - Academic', 'Senior High - TechPro', 'Senior High - Both Tracks', 'Other']);
$total = 0;
foreach ($groups as $g) $total += count($g['policies']);
check('all 10 templates are offered', $total, 10);
foreach ($groups as $g) {
    foreach ($g['policies'] as $p) {
        if ($p['weights'] === '' && $p['transmutation_mode'] !== 'none') {
            $fail++; echo "  FAIL  " . $p['name'] . " has no weight label\n";
        }
    }
}
$pass++;
echo "  PASS  every numerical template exposes its weight label\n";

echo "\n== Class creation would build categories for every template ==\n";
// Simulates actions.php create_class: copy grading_components -> grade_categories.
$bad = [];
foreach ($db->query("SELECT id, name FROM grading_policies WHERE is_active=1 AND transmutation_mode <> 'none'") as $p) {
    $n = (int)$db->query("SELECT COUNT(*) FROM grading_components WHERE policy_id=" . (int)$p['id'])->fetchColumn();
    if ($n < 2) $bad[] = $p['name'];
}
check('every numerical template yields at least WW + PT categories', $bad, []);

echo "\n-------------------------------------\nRESULT: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
