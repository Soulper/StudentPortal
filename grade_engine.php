<?php
// ================================================================
// CONFIGURABLE GRADING ENGINE (DO 015 s.2026 aware)
// Single source of truth for all grade computation.
// Policies, weights, transmutation tables and descriptors are
// database rows managed in Admin > Grading Policies.
// ================================================================

// ---------- Policy loading ----------
// Memoized per request: the gradebook recomputes every student on each
// keystroke, and without this the same policy rows were re-queried once
// per student (4 queries each).
function class_policy($db, $class_id) {
    static $cache = [];
    $key = (int)$class_id;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $s = $db->prepare("SELECT c.*, p.id AS policy_id, p.name AS policy_name, p.description AS policy_desc,
                              p.source_doc, p.transmutation_mode
                       FROM classes c LEFT JOIN grading_policies p ON p.id = c.grading_policy_id
                       WHERE c.id = ?");
    $s->execute([$class_id]);
    $c = $s->fetch(PDO::FETCH_ASSOC);
    if (!$c) return $cache[$key] = null;

    $policy = [
        'class' => $c,
        'id' => $c['policy_id'] ?? null,
        'name' => $c['policy_name'] ?? null,
        'description' => $c['policy_desc'] ?? null,
        'source_doc' => $c['source_doc'] ?? null,
        'transmutation_mode' => $c['transmutation_mode'] ?? 'none',
        'components' => [],
        'transmutation' => [],
        'descriptors' => [],
    ];

    if ($c['policy_id']) {
        $comp = $db->prepare("SELECT * FROM grading_components WHERE policy_id=? ORDER BY sort_order, id");
        $comp->execute([$c['policy_id']]);
        $policy['components'] = $comp->fetchAll(PDO::FETCH_ASSOC);

        $tr = $db->prepare("SELECT * FROM transmutation_rows WHERE policy_id=? ORDER BY min_ig");
        $tr->execute([$c['policy_id']]);
        $policy['transmutation'] = $tr->fetchAll(PDO::FETCH_ASSOC);

        $d = $db->prepare("SELECT * FROM descriptor_rows WHERE policy_id=? ORDER BY min_grade");
        $d->execute([$c['policy_id']]);
        $policy['descriptors'] = $d->fetchAll(PDO::FETCH_ASSOC);
    }
    return $cache[$key] = $policy;
}

// ---------- Assignment submission policy ----------
function assignment_policy($db, $assignment_id) {
    static $default_exts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'png', 'zip'];
    $q = $db->prepare("SELECT requires_file, allowed_exts, max_size_mb, allow_late, cutoff_date, due_date FROM assignments WHERE id=?");
    $q->execute([$assignment_id]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) return null;
    $exts = trim((string)$a['allowed_exts']);
    return [
        'requires_file' => (int)$a['requires_file'] === 1,
        'allowed_exts' => $exts !== '' ? array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $exts))))) : $default_exts,
        'max_size_mb' => max(0.1, (float)$a['max_size_mb']),
        'allow_late' => (int)$a['allow_late'] === 1,
        'cutoff_date' => $a['cutoff_date'],
        'due_date' => $a['due_date'],
    ];
}

// Format a number for display without trailing zeros *after a decimal point*.
// NOTE: do not use rtrim($n, '0') for this - it strips every trailing zero,
// which turns 20 into "2" and 50 into "5".
function trim_num($n) {
    $s = trim((string)$n);
    if ($s === '' || $s === '-') return '0';
    if (strpos($s, '.') === false && strpos($s, 'e') === false && strpos($s, 'E') === false) return $s;
    $s = rtrim(rtrim($s, '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}

// Spreadsheet-style column label: 0 -> A, 25 -> Z, 26 -> AA.
function col_letter($n) {
    $n = (int)$n;
    $s = '';
    do {
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26) - 1;
    } while ($n >= 0);
    return $s;
}

// ---------- Policy catalogue (grouped for the picker) ----------
// DepEd Order No. 017, s. 2026 collapses Senior High School into TWO
// tracks - Academic and Technical-Professional (TechPro) - with no
// strands. Electives sit in clusters, and learners may cross-track
// (Academic: 1 TechPro elective; TechPro: 4 Academic electives in
// Grade 12). Weights still vary by subject group within a track, so
// each group is its own policy template.
function grading_policy_track_order() {
    return [
        'JHS / JHS Core'          => 1,
        'Senior High - Academic'  => 2,
        'Senior High - TechPro'   => 3,
        'Senior High - Both Tracks' => 4,
        'Other'                   => 5,
    ];
}

function policy_weight_label($db, $policy_id) {
    $q = $db->prepare("SELECT weight FROM grading_components WHERE policy_id=? ORDER BY sort_order, id");
    $q->execute([(int)$policy_id]);
    $parts = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $w) {
        $parts[] = trim_num($w);
    }
    return $parts ? implode('/', $parts) : '';
}

// Returns [ ['label' => track, 'policies' => [ ... ]], ... ] in display
// order. Each policy carries a 'weights' string like "20/50/30" so the
// picker can show the weights before the teacher commits to a template.
function grading_policy_groups($db, $only_active = true) {
    $order = grading_policy_track_order();
    $sql = "SELECT * FROM grading_policies" . ($only_active ? " WHERE is_active=1" : "");
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, function ($a, $b) use ($order) {
        $ta = $order[$a['track']] ?? 99;
        $tb = $order[$b['track']] ?? 99;
        return $ta === $tb ? ((int)$a['id'] - (int)$b['id']) : ($ta - $tb);
    });
    $groups = [];
    foreach ($rows as $r) {
        $g = $r['track'] && isset($order[$r['track']]) ? $r['track'] : 'Other';
        $r['weights'] = policy_weight_label($db, $r['id']);
        $groups[$g][] = $r;
    }
    $out = [];
    foreach ($order as $label => $_) {
        if (!empty($groups[$label])) $out[] = ['label' => $label, 'policies' => $groups[$label]];
    }
    return $out;
}

function policy_components_summary($policy) {
    $out = [];
    foreach ($policy['components'] as $comp) {
        $out[] = $comp['name'] . ' ' . trim_num($comp['weight']) . '%';
    }
    return $out;
}

// ---------- Transmutation ----------
function transmute_ig($ig, $mode, $rows = []) {
    if ($mode === 'adjusted_table') {
        foreach ($rows as $r) {
            if ($ig >= (float)$r['min_ig'] && $ig <= (float)$r['max_ig']) {
                return (int)$r['tg'];
            }
        }
        return $ig >= 100 ? 100 : 60;
    }
    if ($mode === 'zero_based') {
        return (int)round($ig, 0, PHP_ROUND_HALF_UP);
    }
    if ($mode === 'legacy_linear') {
        // Exact pre-2026 behavior preserved for historical classes
        if ($ig >= 100) return 100;
        elseif ($ig >= 73) return round(75 + ($ig - 73) * (25 / 27));
        elseif ($ig >= 70) return 75;
        else return 74;
    }
    // 'none' — no transmutation
    return round($ig, 2);
}

function descriptor_for($grade, $rows) {
    foreach ($rows as $r) {
        if ($grade >= (float)$r['min_grade'] && $grade <= (float)$r['max_grade']) {
            return ['label_en' => $r['label_en'], 'label_fil' => $r['label_fil']];
        }
    }
    return ['label_en' => 'Not Graded', 'label_fil' => 'Hindi pa Naitala'];
}

// ---------- Term grade computation ----------
// Returns:
//  [ components: [...], ig, tg, mode, descriptor, coverage, total_weight, active_weight ]
// $policy: pass a pre-loaded class_policy() result to avoid re-loading it when
// computing a whole class (see compute_class_term_grades).
function compute_term_grade($db, $class_id, $student_id, $term_id, $policy = null) {
    if ($policy === null) $policy = class_policy($db, $class_id);
    $mode = $policy ? ($policy['class']['transmutation_mode'] ?: 'none') : 'none';
    $legacy = ($mode === 'legacy_linear');

    $cats = $db->prepare("SELECT gc.* FROM grade_categories gc WHERE gc.class_id=? AND gc.term_id=? ORDER BY gc.id");
    $cats->execute([$class_id, $term_id]);
    $categories = $cats->fetchAll(PDO::FETCH_ASSOC);

    $items = $db->prepare(
        "SELECT gi.*, gs.score AS score
         FROM grade_items gi
         LEFT JOIN grade_scores gs ON gs.grade_item_id = gi.id AND gs.student_id = ?
         WHERE gi.class_id = ? AND gi.term_id = ? AND gi.category_id IN (
            SELECT id FROM grade_categories WHERE class_id=? AND term_id=?
         )
         ORDER BY gi.id");
    $items->execute([$student_id, $class_id, $term_id, $class_id, $term_id]);
    $item_rows = $items->fetchAll(PDO::FETCH_ASSOC);

    $by_cat = [];
    foreach ($item_rows as $it) $by_cat[$it['category_id']][] = $it;

    // EX internal split from policy (ST1/ST2/TE)
    $ex_split = ['st1' => 30, 'st2' => 30, 'te' => 40];
    if ($policy) {
        foreach ($policy['components'] as $comp) {
            if ($comp['code'] === 'EX' && $comp['ex_st1'] !== null) {
                $ex_split = ['st1' => (float)$comp['ex_st1'], 'st2' => (float)$comp['ex_st2'], 'te' => (float)$comp['ex_te']];
            }
        }
    }

    $components = [];
    $ws_total = 0.0;
    $active_weight = 0.0;
    $total_weight = 0.0;
    $recorded_count = 0;
    $total_items = count($item_rows);

    foreach ($categories as $cat) {
        $its = $by_cat[$cat['id']] ?? [];
        $w = (float)$cat['weight'];
        $total_weight += $w;

        // Match category to the policy component code (WW/PT/EX/...) by name
        $code = '';
        if ($policy) {
            foreach ($policy['components'] as $pc) {
                if (trim($pc['name']) === trim($cat['name'])) { $code = $pc['code']; break; }
            }
        }

        $detail = [];
        $has_tags = false;
        foreach ($its as $it) {
            if (!empty($it['ex_group'])) $has_tags = true;
        }

        $ps = null; // null = no recorded evidence yet

        if ($has_tags) {
            // Combine tagged sub-groups (ST1/ST2/TE) using the policy split,
            // renormalized over groups that have recorded evidence.
            $groups = [];
            $s = 0.0; $m = 0.0;
            foreach ($its as $it) {
                $g = $it['ex_group'] ?: 'general';
                if (!isset($groups[$g])) $groups[$g] = ['s' => 0.0, 'm' => 0.0, 'has' => false];
                $groups[$g]['m'] += (float)$it['max_score'];
                $m += (float)$it['max_score'];
                if ($it['score'] !== null) {
                    $groups[$g]['s'] += (float)$it['score'];
                    $groups[$g]['has'] = true;
                    $s += (float)$it['score'];
                }
                $detail[] = $it;
            }
            $group_ps = [];
            $group_w = [];
            foreach ($groups as $g => $d) {
                if ($d['has']) {
                    $group_ps[$g] = $d['m'] > 0 ? ($d['s'] / $d['m']) * 100 : 0;
                    // A split of 0 (e.g. Field Experience = term exam only)
                    // must not zero a mis-tagged group; fall back to equal
                    // weighting rather than silently returning PS 0.
                    $group_w[$g] = (isset($ex_split[$g]) && $ex_split[$g] > 0)
                        ? $ex_split[$g]
                        : (100 / count($groups));
                }
            }
            if ($group_ps) {
                $sw = 0.0; $ww = 0.0;
                foreach ($group_ps as $g => $p) { $sw += $p * $group_w[$g]; $ww += $group_w[$g]; }
                $ps = $ww > 0 ? $sw / $ww : 0;
            }
        } else {
            $s = 0.0; $m = 0.0; $has = false;
            foreach ($its as $it) {
                if ($it['score'] !== null) { $s += (float)$it['score']; $m += (float)$it['max_score']; $has = true; }
                $detail[] = $it;
            }
            if ($has) $ps = $m > 0 ? ($s / $m) * 100 : 0;
        }

        if ($ps !== null) {
            $recorded_count += count(array_filter($its, fn($i) => $i['score'] !== null));
            $ws = $ps * $w / 100;
            $ws_total += $ws;
            $active_weight += $w;
            $components[] = [
                'category_id' => (int)$cat['id'],
                'code' => $code,
                'name' => $cat['name'],
                'weight' => $w,
                'ps' => round($ps, 2),
                'ws' => round($ws, 2),
                'score_total' => round($s, 2),
                'max_total' => round($m, 2),
                'recorded' => count(array_filter($its, fn($i) => $i['score'] !== null)),
                'item_count' => count($its),
                'ex_split' => $has_tags ? $ex_split : null,
                'items' => $detail,
            ];
        } else {
            $components[] = [
                'category_id' => (int)$cat['id'],
                'code' => $code,
                'name' => $cat['name'],
                'weight' => $w,
                'ps' => null,
                'ws' => 0,
                'score_total' => null,
                'max_total' => null,
                'recorded' => 0,
                'item_count' => count($its),
                'ex_split' => null,
                'items' => $detail,
            ];
        }
    }

    // Initial grade: weighted sum normalized over components with recorded evidence
    $ig = 0.0;
    if ($legacy) {
        // Preserve the exact pre-2026 computation for historical classes
        $t_w = 0.0;
        foreach ($components as $c) {
            $t_w += ($c['ps'] ?? 0) * $c['weight'] / 100;
        }
        $ig = $total_weight > 0 ? round($t_w, 2) : 0;
    } else {
        $ig = $active_weight > 0 ? round($ws_total * 100 / $active_weight, 2) : 0;
    }

    $tg = $ig > 0 ? transmute_ig($ig, $mode, $policy ? $policy['transmutation'] : []) : null;
    $descriptor = ($tg !== null && $policy) ? descriptor_for($tg, $policy['descriptors']) : null;

    return [
        'components' => $components,
        'ig' => $ig,
        'tg' => $tg,
        'mode' => $mode,
        'descriptor' => $descriptor,
        'coverage' => $total_items > 0 ? $recorded_count / $total_items : 0,
        'total_weight' => $total_weight,
        'active_weight' => $active_weight,
        'policy' => $policy,
    ];
}

// ---------- Teacher gradebook (all students, one term) ----------
// $only_students: restrict to these student ids (the live-recalc endpoint
// only needs the rows the teacher just touched).
function compute_class_term_grades($db, $class_id, $term_id, $only_students = null) {
    if ($only_students === null) {
        $s = $db->prepare("SELECT u.id FROM users u JOIN class_members cm ON cm.student_id = u.id WHERE cm.class_id=? ORDER BY u.id");
        $s->execute([$class_id]);
        $students = $s->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $students = array_values(array_unique(array_map('intval', (array)$only_students)));
    }
    $policy = class_policy($db, $class_id);
    $out = [];
    foreach ($students as $sid) {
        $out[(int)$sid] = compute_term_grade($db, $class_id, $sid, $term_id, $policy);
    }
    return $out;
}

// ---------- Terms for a class ----------
function class_terms($db, $class_id) {
    $s = $db->prepare("SELECT t.* FROM terms t JOIN classes c ON c.academic_year_id = t.academic_year_id WHERE c.id=? ORDER BY t.number");
    $s->execute([$class_id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function class_is_published($db, $class_id, $term_id) {
    $s = $db->prepare("SELECT COUNT(*) FROM class_term_status WHERE class_id=? AND term_id=?");
    $s->execute([$class_id, $term_id]);
    return $s->fetchColumn() > 0;
}

// ---------- Final grade (average of published term grades) ----------
function final_grade_from_terms($db, $class_id, $student_id, $only_published = true) {
    $terms = class_terms($db, $class_id);
    $sum = 0.0; $w = 0.0; $count = 0;
    foreach ($terms as $t) {
        if ($only_published && !class_is_published($db, $class_id, $t['id'])) continue;
        $res = compute_term_grade($db, $class_id, $student_id, $t['id']);
        if ($res['tg'] !== null) {
            $sum += $res['tg'] * (float)$t['weight'];
            $w += (float)$t['weight'];
            $count++;
        }
    }
    return $w > 0 ? round($sum / $w, 2) : null;
}

// ---------- "What do I need to get?" ----------
function target_requirements($db, $class_id, $student_id, $term_id, $target_tg) {
    $res = compute_term_grade($db, $class_id, $student_id, $term_id);
    $mode = $res['mode'];

    // Map target Term Grade to the Initial Grade needed
    $target_ig = (float)$target_tg;
    if ($mode === 'adjusted_table' && $res['policy']) {
        $rows = $res['policy']['transmutation'];
        foreach ($rows as $r) {
            if ((int)$r['tg'] === (int)$target_tg) { $target_ig = (float)$r['min_ig']; break; }
        }
        if ($target_tg >= 100) $target_ig = 99.5;
        elseif ($target_tg < 60) $target_ig = 0.0;
    }

    $ws_rec = 0.0; $w_rec = 0.0; $w_rem = 0.0; $rem_count = 0;
    foreach ($res['components'] as $c) {
        if ($c['ps'] !== null) {
            $ws_rec += $c['ps'] * $c['weight'] / 100;
            $w_rec += $c['weight'];
        } else {
            $w_rem += $c['weight'];
            $rem_count++;
        }
    }

    if ($w_rem <= 0) {
        return ['possible' => false, 'reason' => 'All assessments are already recorded for this term.', 'required_ps' => null, 'remaining_count' => 0, 'current' => $res];
    }

    $required_ps = (($target_ig * ($w_rec + $w_rem) / 100) - $ws_rec) * 100 / $w_rem;
    return [
        'possible' => true,
        'required_ps' => round(max(0, $required_ps), 2),
        'feasible' => $required_ps <= 100,
        'remaining_count' => $rem_count,
        'target_ig' => round($target_ig, 2),
        'current' => $res,
    ];
}

// Project a scenario: what if remaining (unrecorded) items average X%?
function project_grade($db, $class_id, $student_id, $term_id, $remaining_ps) {
    $res = compute_term_grade($db, $class_id, $student_id, $term_id);
    $ws_rec = 0.0; $w_rec = 0.0; $w_rem = 0.0;
    foreach ($res['components'] as $c) {
        if ($c['ps'] !== null) {
            $ws_rec += $c['ps'] * $c['weight'] / 100;
            $w_rec += $c['weight'];
        } else {
            $w_rem += $c['weight'];
        }
    }
    if ($w_rem <= 0) return $res;
    $new_ig = ($ws_rec + $remaining_ps * $w_rem / 100) * 100 / ($w_rec + $w_rem);
    $new_ig = round($new_ig, 2);
    $new_tg = transmute_ig($new_ig, $res['mode'], $res['policy'] ? $res['policy']['transmutation'] : []);
    $clone = $res;
    $clone['ig'] = $new_ig;
    $clone['tg'] = $new_tg;
    if ($res['policy']) $clone['descriptor'] = descriptor_for($new_tg, $res['policy']['descriptors']);
    return $clone;
}

// ================================================================
// LIVE GRADEBOOK WRITE PATH
// Used by the Excel-style gradebook: a teacher types a cell, the change
// is validated and written here, and the recomputed PS/IG/TG come back
// from the same engine that renders the page. No duplicated math.
// ================================================================

// Validate + persist a batch of edited cells.
// $changes: [ item_id => [ student_id => value|""|null, ... ], ... ]
// An empty value deletes the score (missing scores stay excluded from the
// computation, never counted as zero). Out-of-range / non-numeric values
// are reported back in $out['rejected'] instead of being silently dropped.
// $out['students'] lists the students whose row actually changed, i.e. the
// rows the caller needs to recompute.
function save_score_cells($db, $class_id, $term_id, $changes) {
    $class_id = (int)$class_id;
    $term_id = (int)$term_id;

    // Only items of THIS class and THIS term are writable.
    $maxes = [];
    $iq = $db->prepare("SELECT id, max_score FROM grade_items WHERE class_id=? AND term_id=?");
    $iq->execute([$class_id, $term_id]);
    foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $r) $maxes[(int)$r['id']] = (float)$r['max_score'];

    // Only enrolled students are writable.
    $members = [];
    $mq = $db->prepare("SELECT student_id FROM class_members WHERE class_id=?");
    $mq->execute([$class_id]);
    foreach ($mq->fetchAll(PDO::FETCH_COLUMN) as $sid) $members[(int)$sid] = true;

    $exists_stmt = $db->prepare("SELECT COUNT(*) FROM grade_scores WHERE grade_item_id=? AND student_id=?");
    $del_stmt = $db->prepare("DELETE FROM grade_scores WHERE grade_item_id=? AND student_id=?");
    $upd_stmt = $db->prepare("UPDATE grade_scores SET score=?, term_id=? WHERE grade_item_id=? AND student_id=?");
    $ins_stmt = $db->prepare("INSERT INTO grade_scores (grade_item_id, student_id, score, term_id) VALUES (?, ?, ?, ?)");

    $saved = 0;
    $touched = [];
    $rejected = [];

    foreach ((array)$changes as $item_id => $by_student) {
        $item_id = (int)$item_id;
        if (!is_array($by_student)) continue;
        $known = isset($maxes[$item_id]);

        foreach ($by_student as $sid => $val) {
            $sid = (int)$sid;
            $fail = function ($reason) use (&$rejected, $item_id, $sid, $val, $maxes) {
                $rejected[] = [
                    'item_id' => $item_id, 'student_id' => $sid, 'value' => $val,
                    'max' => $maxes[$item_id] ?? null, 'reason' => $reason,
                ];
            };

            if (!$known) { $fail('unknown activity'); continue; }
            if (!isset($members[$sid])) { $fail('student not enrolled'); continue; }

            $raw = is_string($val) ? trim($val) : $val;
            if ($raw === '' || $raw === null) {
                $exists_stmt->execute([$item_id, $sid]);
                if ($exists_stmt->fetchColumn() > 0) { $del_stmt->execute([$item_id, $sid]); $saved++; }
                $touched[$sid] = true;
                continue;
            }
            if (!is_numeric($raw)) { $fail('not a number'); continue; }

            $v = (float)$raw;
            if ($v < 0) { $fail('negative score'); continue; }
            if ($v > $maxes[$item_id]) { $fail('over max'); continue; }

            $exists_stmt->execute([$item_id, $sid]);
            if ($exists_stmt->fetchColumn() > 0) $upd_stmt->execute([$v, $term_id, $item_id, $sid]);
            else $ins_stmt->execute([$item_id, $sid, $v, $term_id]);
            $saved++;
            $touched[$sid] = true;
        }
    }

    return [
        'saved' => $saved,
        'rejected' => $rejected,
        'students' => array_keys($touched),
    ];
}

// Compact per-row payload the browser patches into the grid in place.
function grade_row_payload($result) {
    $ps = [];
    foreach ($result['components'] as $c) {
        $ps[(string)$c['category_id']] = $c['ps'];
    }
    return [
        'ps' => $ps,
        'ig' => $result['ig'],
        'tg' => $result['tg'],
        'desc' => $result['descriptor']['label_en'] ?? null,
        'active_weight' => $result['active_weight'],
        'total_weight' => $result['total_weight'],
    ];
}

// Mean score per activity across the class (null when nothing recorded).
// Missing scores are excluded rather than counted as zero.
function class_item_averages($db, $class_id, $term_id) {
    $q = $db->prepare(
        "SELECT gi.id, COUNT(gs.score) AS n, AVG(gs.score) AS avg
         FROM grade_items gi
         LEFT JOIN grade_scores gs ON gs.grade_item_id = gi.id
         WHERE gi.class_id=? AND gi.term_id=?
         GROUP BY gi.id");
    $q->execute([(int)$class_id, (int)$term_id]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = ((int)$r['n'] > 0) ? round((float)$r['avg'], 2) : null;
    }
    return $out;
}

// Mean PS per category across the class, from a batch of computed rows.
function class_category_averages($rows) {
    $acc = [];
    foreach ($rows as $r) {
        foreach ($r['components'] as $c) {
            if ($c['ps'] === null) continue;
            $k = $c['category_id'];
            if (!isset($acc[$k])) $acc[$k] = ['s' => 0.0, 'n' => 0];
            $acc[$k]['s'] += (float)$c['ps'];
            $acc[$k]['n']++;
        }
    }
    $out = [];
    foreach ($acc as $k => $v) $out[(string)$k] = $v['n'] ? round($v['s'] / $v['n'], 2) : null;
    return $out;
}

// Mean IG and mean TG across the class, from a batch of computed rows.
function class_grade_averages($rows) {
    $ig_s = 0.0; $ig_n = 0; $tg_s = 0.0; $tg_n = 0;
    foreach ($rows as $r) {
        if (($r['ig'] ?? 0) > 0) { $ig_s += (float)$r['ig']; $ig_n++; }
        if (($r['tg'] ?? null) !== null) { $tg_s += (float)$r['tg']; $tg_n++; }
    }
    return [
        'ig' => $ig_n ? round($ig_s / $ig_n, 2) : null,
        'tg' => $tg_n ? round($tg_s / $tg_n, 2) : null,
    ];
}