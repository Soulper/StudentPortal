<?php
// ================================================================
// SUBMISSION POLICY ASSERTIONS (run: php tests/submission_test.php)
// ================================================================
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label = " . var_export($got, true) . " (expected " . var_export($want, true) . ")\n"; }
}

// ---- Create a throwaway assignment to test policies ----
$db->exec("DELETE FROM assignments WHERE title = 'POLICY_TEST'");
$db->prepare("INSERT INTO assignments (class_id, title, due_date, requires_file, allowed_exts, max_size_mb, allow_late, cutoff_date) VALUES (1, 'POLICY_TEST', date('now','+1 day'), 1, 'PDF, Docx', 5, 0, date('now','+2 day'))")->execute();
$aid = (int)$db->lastInsertId();

echo "== assignment_policy: defaults & parsing ==\n";
$pol = assignment_policy($db, $aid);
check('requires_file on', $pol['requires_file'], true);
check('custom exts lowercased+trimmed', $pol['allowed_exts'], ['pdf', 'docx']);
check('max size 5', $pol['max_size_mb'], 5.0);
check('allow_late off', $pol['allow_late'], false);
check('cutoff set', $pol['cutoff_date'] !== null, true);

$db->prepare("INSERT INTO assignments (class_id, title, due_date) VALUES (1, 'POLICY_TEST_DEFAULT', date('now','+1 day'))")->execute();
$dpol = assignment_policy($db, (int)$db->lastInsertId());
check('default requires_file', $dpol['requires_file'], true);
check('default allowed_exts non-empty', count($dpol['allowed_exts']) > 0, true);
check('default max 10', $dpol['max_size_mb'], 10.0);
check('default allow_late', $dpol['allow_late'], true);
check('default cutoff null', $dpol['cutoff_date'], null);

echo "== handle_upload: enforcement ==\n";
// No file given -> empty (action layer turns this into "A file is required")
$no = handle_upload(null, 't');
check('no file -> empty', $no, ['file' => '', 'error' => '']);
// Reject extension not in the allowed list
$fake = ['error' => 0, 'size' => 100, 'name' => 'evil.php', 'tmp_name' => 'nope'];
$bad_ext = handle_upload($fake, 't', ['pdf', 'txt'], 10);
check('disallowed ext rejected', $bad_ext['error'] !== '', true);
// Reject oversize (real temp file)
$tmp = tempnam(sys_get_temp_dir(), 'subtest');
file_put_contents($tmp, str_repeat('x', 2048));
$big = ['error' => 0, 'size' => 6000000, 'name' => 'big.txt', 'tmp_name' => $tmp];
$big_r = handle_upload($big, 't', ['txt'], 5);
check('oversize rejected (5MB cap)', $big_r['error'] !== '', true);
// (valid-file happy path is covered by the HTTP smoke test:
//  move_uploaded_file() only accepts genuine HTTP uploads)
unlink($tmp);

// cleanup
$db->prepare("DELETE FROM assignments WHERE id IN (?, ?)")->execute([$aid, (int)$db->lastInsertId()]);

echo "\n-------------------------------------\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);