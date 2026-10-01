<?php
// ========================================
// STUDENT PAGE FUNCTIONS
// ========================================
function student_dashboard() {
    global $db, $user_id; ?>
    <div class="page-header">
        <div>
            <h4 class="page-title">My Dashboard</h4>
            <p class="page-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'student') ?></p>
        </div>
    </div>
    <?php
    $classes = $db->prepare("SELECT c.* FROM classes c JOIN class_members cm ON cm.class_id=c.id WHERE cm.student_id=? AND c.status='active'");
    $classes->execute([$user_id]);
    $enrolled = $classes->fetchAll(PDO::FETCH_ASSOC);

    $assigns = $db->prepare("SELECT a.*, c.subject FROM assignments a JOIN classes c ON a.class_id=c.id JOIN class_members cm ON cm.class_id=a.class_id WHERE cm.student_id=? AND a.due_date >= date('now') ORDER BY a.due_date LIMIT 5");
    $assigns->execute([$user_id]);
    $upcoming = $assigns->fetchAll(PDO::FETCH_ASSOC);

    $ann = $db->prepare("SELECT a.*, c.subject FROM announcements a JOIN classes c ON a.class_id=c.id JOIN class_members cm ON cm.class_id=a.class_id WHERE cm.student_id=? ORDER BY a.posted_at DESC LIMIT 1");
    $ann->execute([$user_id]);
    $latest_ann = $ann->fetch(PDO::FETCH_ASSOC);

    $att = $db->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE student_id=? GROUP BY status");
    $att->execute([$user_id]);
    $att_rows = $att->fetchAll(PDO::FETCH_ASSOC);
    $att_total = array_sum(array_column($att_rows, 'cnt'));
    $att_present = 0;
    foreach ($att_rows as $r) {
        if ($r['status'] == 'present' || $r['status'] == 'late') $att_present += (int)$r['cnt'];
    }
    $att_rate = $att_total > 0 ? round($att_present / $att_total * 100) . '%' : '--';

    // Overall average across published terms + needs-attention classes
    $overall = null;
    $need_attention = [];
    $published_tgs = [];
    foreach ($enrolled as $c) {
        $terms = class_terms($db, $c['id']);
        foreach ($terms as $t) {
            if (!class_is_published($db, $c['id'], $t['id'])) continue;
            $r = compute_term_grade($db, $c['id'], $user_id, $t['id']);
            if ($r && $r['tg'] !== null) {
                $published_tgs[] = $r['tg'];
                if ((float)$r['tg'] < 75) $need_attention[] = ['class' => $c, 'term' => $t, 'tg' => $r['tg']];
            }
        }
    }
    if ($published_tgs) $overall = round(array_sum($published_tgs) / count($published_tgs), 2);

    $overall_class = 'text-good';
    if ($overall === null) { $overall_class = ''; }
    elseif ($overall < 75) { $overall_class = 'text-bad'; }

    echo '<div class="row g-3 mb-3">';
    echo '<div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon">' . icon('school', 20) . '</div><p class="stat-value">' . count($enrolled) . '</p><span class="stat-label">Enrolled Classes</span></div></div>';
    echo '<div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon">' . icon('clipboard', 20) . '</div><p class="stat-value">' . count($upcoming) . '</p><span class="stat-label">Upcoming Tasks</span></div></div>';
    echo '<div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon">' . icon('calendar', 20) . '</div><p class="stat-value">' . $att_rate . '</p><span class="stat-label">Attendance Rate</span></div></div>';
    echo '<div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon">' . icon('award', 20) . '</div><p class="stat-value ' . $overall_class . '">' . ($overall ?? '--') . '</p><span class="stat-label">Overall Average</span></div></div>';
    echo '</div>';

    if ($need_attention) {
        echo '<div class="alert alert-warning"><strong>Needs attention:</strong> ';
        $parts = [];
        foreach ($need_attention as $n) $parts[] = htmlspecialchars($n['class']['subject']) . ' (' . htmlspecialchars($n['term']['label']) . '): ' . $n['tg'];
        echo implode(' &middot; ', $parts) . '</div>';
    }

    if ($enrolled) {
        echo '<h5 class="fw-bold mt-4 mb-3">My Classes</h5><div class="row g-3">';
        $ai = 0;
        foreach ($enrolled as $c) {
            echo '<div class="col-md-4 col-sm-6"><div class="card class-card shadow-sm accent-' . ($ai++ % 6) . '"><div class="card-body">';
            echo '<h6 class="card-title mb-1">' . htmlspecialchars($c['subject']) . '</h6>';
            echo '<small class="text-muted">' . htmlspecialchars($c['section']) . '</small><br>';
            echo '<a href="dashboard.php?page=class&id=' . $c['id'] . '" class="btn btn-sm btn-brand mt-3">Open</a>';
            echo '</div></div></div>';
        }
        echo '</div>';
    }

    if ($upcoming) {
        echo '<h5 class="fw-bold mt-3">Upcoming Assignments</h5><div class="list-group">';
        foreach ($upcoming as $a) {
            echo '<div class="list-group-item"><div class="fw-semibold">' . htmlspecialchars($a['title']) . '</div><small class="text-muted">' . htmlspecialchars($a['subject']) . ' | Due: ' . $a['due_date'] . '</small></div>';
        }
        echo '</div>';
    }

    if ($latest_ann) {
        echo '<div class="card shadow-sm mt-3 border-warning"><div class="card-body"><small class="text-warning fw-bold">LATEST ANNOUNCEMENT</small>';
        echo '<h6 class="fw-bold mb-1">' . htmlspecialchars($latest_ann['title']) . '</h6>';
        echo '<small>' . nl2br(htmlspecialchars($latest_ann['content'])) . '</small>';
        echo '<br><small class="text-muted">' . htmlspecialchars($latest_ann['subject']) . ' - ' . $latest_ann['posted_at'] . '</small></div></div>';
    }
}

function student_classes() {
    global $db, $user_id; ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title">My Classes</h4>
        <button class="btn btn-sm btn-accent" data-bs-toggle="modal" data-bs-target="#joinModal">+ Join Class</button>
    </div>
    <?php
    $classes = $db->prepare("SELECT c.* FROM classes c JOIN class_members cm ON cm.class_id=c.id WHERE cm.student_id=? ORDER BY c.created_at DESC");
    $classes->execute([$user_id]);
    $list = $classes->fetchAll(PDO::FETCH_ASSOC);

    if ($list) {
        echo '<div class="row g-3">';
        foreach ($list as $c) {
            echo '<div class="col-md-4"><div class="card shadow-sm"><div class="card-body">';
            echo '<h5 class="fw-bold">' . htmlspecialchars($c['subject']) . '</h5>';
            echo '<p class="small text-muted mb-1">' . htmlspecialchars($c['section']) . ' | ' . htmlspecialchars($c['school_year']) . '</p>';
            echo '<a href="dashboard.php?page=class&id=' . $c['id'] . '" class="btn btn-sm btn-brand">Open</a>';
            echo '</div></div></div>';
        }
        echo '</div>';
    } else {
        echo '<div class="alert alert-info">You are not enrolled in any class yet. Click "Join Class" to add one.</div>';
    }
    ?>
    <div class="modal fade" id="joinModal">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="join_class">
                <div class="modal-header"><h5 class="modal-title">Join a Class</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="small text-muted">Enter the class join code provided by your teacher.</p>
                    <input type="text" name="join_code" class="form-control" placeholder="Enter code" required style="text-transform:uppercase;">
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-sm btn-brand">Join</button></div>
            </form>
        </div>
    </div>
<?php }

function student_class_detail($class_id) {
    global $db, $user_id;
    $class = $db->prepare("SELECT * FROM classes WHERE id=? AND status='active'");
    $class->execute([$class_id]);
    $c = $class->fetch(PDO::FETCH_ASSOC);
    if (!$c) { echo '<div class="alert alert-danger">Class not found or inactive.</div>'; return; }

    $member = $db->prepare("SELECT COUNT(*) FROM class_members WHERE class_id=? AND student_id=?");
    $member->execute([$class_id, $user_id]);
    if ($member->fetchColumn() == 0) { echo '<div class="alert alert-danger">You are not enrolled in this class.</div>'; return; }

    $tab = $_GET['tab'] ?? 'stream';
    $policy = class_policy($db, $class_id);
    $grading_label = $policy ? '(' . htmlspecialchars($policy['name']) . ')' : '(Standard)';
    ?>
    <div class="page-header">
        <div>
            <a class="small d-inline-block mb-1" href="dashboard.php?page=classes">&larr; My Classes</a>
            <h4 class="page-title"><?= htmlspecialchars($c['subject']) ?></h4>
            <p class="page-subtitle"><?= htmlspecialchars($c['section']) ?> &middot; SY <?= htmlspecialchars($c['school_year']) ?> <?= $grading_label ?></p>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?= $tab=='stream'?'active':'' ?>" href="dashboard.php?page=class&id=<?= $class_id ?>&tab=stream">Stream</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab=='assignments'?'active':'' ?>" href="dashboard.php?page=class&id=<?= $class_id ?>&tab=assignments">Assignments</a></li>
    </ul>

    <?php if ($tab == 'stream') {
        $anns = $db->prepare("SELECT * FROM announcements WHERE class_id=? ORDER BY posted_at DESC");
        $anns->execute([$class_id]);
        echo '<h5 class="fw-bold mb-2">Announcements</h5>';
        $first = true;
        foreach ($anns as $a) {
            if ($first) { echo '<div class="list-group mt-2">'; $first = false; }
            echo '<div class="list-group-item"><div class="fw-semibold">' . htmlspecialchars($a['title']) . '</div>';
            echo '<small>' . nl2br(htmlspecialchars($a['content'])) . '</small>';
            if ($a['attachment']) echo '<br><a href="download.php?f=' . rawurlencode($a['attachment']) . '" target="_blank" class="small">Attachment</a>';
            echo '<br><small class="text-muted">' . $a['posted_at'] . '</small></div>';
        }
        if ($first) echo '<div class="alert alert-info mt-2">No announcements yet.</div>';
        if (!$first) echo '</div>';

        // Grade summary: published terms only
        echo '<div class="card shadow-sm mt-4"><div class="card-header fw-semibold card-header-soft">My Grades Summary</div><div class="card-body">';
        $terms = class_terms($db, $class_id);
        $published = [];
        foreach ($terms as $t) {
            if (class_is_published($db, $class_id, $t['id'])) $published[] = $t;
        }
        if ($published) {
            $term_tgs = [];
            foreach ($published as $t) {
                $r = compute_term_grade($db, $class_id, $user_id, $t['id']);
                $term_tgs[] = ['term' => $t, 'res' => $r];
            }
            foreach ($term_tgs as $tt) {
                $t = $tt['term']; $r = $tt['res'];
                echo '<div class="mb-3"><div class="d-flex justify-content-between align-items-center"><strong>' . htmlspecialchars($t['label']) . '</strong>';
                if ($r && $r['tg'] !== null) {
                    echo '<span class="fw-bold">TG ' . $r['tg'] . ' <span class="badge bg-primary ms-1">' . htmlspecialchars($r['descriptor']['label_en'] ?? '') . '</span></span>';
                } else { echo '<span class="text-muted small">No grade yet</span>'; }
                echo '</div>';
                if ($r) {
                    echo '<div class="row g-1 mt-1">';
                    foreach ($r['components'] as $comp) {
                        echo '<div class="col-auto"><span class="badge bg-light text-dark border">' . htmlspecialchars($comp['name']) . ': ' . ($comp['ps'] !== null ? $comp['ps'] . '%' : '&mdash;') . '</span></div>';
                    }
                    echo '<div class="col-auto"><span class="badge bg-light text-dark border">IG: ' . $r['ig'] . '</span></div>';
                    echo '</div>';
                }
                echo '</div>';
            }
        } else {
            echo '<p class="text-muted small mb-0">No grades published yet. Your teacher will publish term grades here.</p>';
        }
        echo '</div></div>';

        // Attendance summary
        $att = $db->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE class_id=? AND student_id=? GROUP BY status");
        $att->execute([$class_id, $user_id]);
        $att_data = $att->fetchAll(PDO::FETCH_ASSOC);
        echo '<div class="card shadow-sm mt-3"><div class="card-header fw-semibold card-header-soft">My Attendance</div><div class="card-body">';
        if ($att_data) {
            $total_att = array_sum(array_column($att_data, 'cnt'));
            foreach ($att_data as $a) {
                $pct = round(($a['cnt'] / $total_att) * 100);
                $color = $a['status'] == 'present' ? 'success' : ($a['status'] == 'late' ? 'warning' : ($a['status'] == 'excused' ? 'info' : 'danger'));
                echo '<div class="d-flex justify-content-between"><span class="text-' . $color . ' fw-semibold">' . ucfirst($a['status']) . '</span><span>' . $a['cnt'] . ' (' . $pct . '%)</span></div>';
            }
        } else {
            echo '<p class="text-muted small mb-0">No attendance records yet.</p>';
        }
        echo '</div></div>';

    } elseif ($tab == 'assignments') {
        $assigns = $db->prepare("SELECT * FROM assignments WHERE class_id=? ORDER BY due_date DESC");
        $assigns->execute([$class_id]);
        echo '<h5 class="fw-bold">Assignments</h5>';
        if ($assigns->rowCount() == 0) { echo '<div class="alert alert-info mt-2">No assignments yet.</div>'; }
        else {
            echo '<div class="list-group mt-2">';
            foreach ($assigns as $a) {
                $pol = assignment_policy($db, $a['id']);
                $now = date('Y-m-d');
                $closed = !$pol['allow_late'] && $pol['cutoff_date'] && $now > $pol['cutoff_date'];
                $sub = $db->prepare("SELECT * FROM submissions WHERE assignment_id=? AND student_id=?");
                $sub->execute([$a['id'], $user_id]);
                $existing_sub = $sub->fetch(PDO::FETCH_ASSOC);
                if ($existing_sub && $existing_sub['score'] !== null) $status = 'Graded: ' . $existing_sub['score'];
                elseif ($existing_sub && !empty($existing_sub['is_late'])) $status = 'Submitted (Late)';
                elseif ($existing_sub) $status = 'Submitted';
                else $status = 'Pending';
                if ($existing_sub && $existing_sub['score'] !== null) $status_color = 'success';
                elseif ($existing_sub) $status_color = 'warning';
                else $status_color = 'secondary';
                ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($a['title']) ?></div>
                            <small class="text-muted">Due: <?= $a['due_date'] ?></small>
                            <?php if ($a['attachment']) { ?> | <a href="download.php?f=<?= rawurlencode($a['attachment']) ?>" target="_blank" class="small">Attachment</a><?php } ?>
                            <?php if ($a['description']) { ?><br><small><?= nl2br(htmlspecialchars($a['description'])) ?></small><?php } ?>
                            <br><small class="text-muted">
                                <?php if ($pol['requires_file']) { ?>File required &middot; Accepted: <?= htmlspecialchars(implode(', ', $pol['allowed_exts'])) ?> &middot; Max <?= $pol['max_size_mb'] ?> MB<?php } else { ?>File optional<?php } ?>
                                &middot; <?= $pol['allow_late'] ? 'Late allowed' : ($closed ? 'Closed for submissions' : 'Late not allowed') ?>
                            </small>
                            <br><small class="text-muted"><i class="bi bi-shield-lock"></i> Your submission is visible only to you and your teacher.</small>
                            <?php if ($existing_sub) { ?><br><small class="text-muted">Submitted: <?= $existing_sub['submitted_at'] ?></small><?php } ?>
                            <?php if ($existing_sub && $existing_sub['feedback']) { ?><br><small class="text-muted">Feedback: <?= htmlspecialchars($existing_sub['feedback']) ?></small><?php } ?>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-<?= $status_color ?>"><?= $status ?></span>
                            <?php if ((!$existing_sub || $existing_sub['score'] === null) && !$closed) { ?>
                                <button class="btn btn-sm btn-outline-primary mt-1" data-bs-toggle="modal" data-bs-target="#subModal<?= $a['id'] ?>"><?= $existing_sub ? 'Resubmit' : 'Submit' ?></button>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="subModal<?= $a['id'] ?>">
                    <div class="modal-dialog">
                        <form method="POST" enctype="multipart/form-data" class="modal-content">
                            <input type="hidden" name="action" value="submit_assignment">
                            <input type="hidden" name="class_id" value="<?= $class_id ?>">
                            <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                            <div class="modal-header"><h5 class="modal-title">Submit: <?= htmlspecialchars($a['title']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <?php if ($pol['requires_file']) { ?>
                                    <p class="small text-muted mb-2">Accepted types: <strong><?= htmlspecialchars(implode(', ', $pol['allowed_exts'])) ?></strong> &middot; Max size: <strong><?= $pol['max_size_mb'] ?> MB</strong> &middot; Due: <strong><?= $a['due_date'] ?></strong></p>
                                    <input type="file" name="file" class="form-control" required accept=".<?= implode(',.', $pol['allowed_exts']) ?>">
                                <?php } else { ?>
                                    <input type="file" name="file" class="form-control">
                                <?php } ?>
                                <small class="text-muted d-block mt-2"><i class="bi bi-shield-lock"></i> Your file is visible only to you and your teacher.</small>
                            </div>
                            <div class="modal-footer"><button type="submit" class="btn btn-sm btn-brand">Submit</button></div>
                        </form>
                    </div>
                </div>
                <?php
            }
            echo '</div>';
        }
    }
}

function student_grades() {
    global $db, $user_id; ?>
    <h4 class="page-title mb-1">My Grades</h4>
    <?php
    $classes = $db->prepare("SELECT c.* FROM classes c JOIN class_members cm ON cm.class_id=c.id WHERE cm.student_id=? ORDER BY c.subject");
    $classes->execute([$user_id]);
    $enrolled = $classes->fetchAll(PDO::FETCH_ASSOC);

    if (!$enrolled) { echo '<div class="alert alert-info">You are not enrolled in any classes.</div>'; return; }

    $target_in = (float)($_GET['target'] ?? 0);

    foreach ($enrolled as $c) {
        $policy = class_policy($db, $c['id']);
        $label = $policy ? htmlspecialchars($policy['name']) : ($c['grading_system'] == 'deped' ? 'DepEd' : 'Standard');
        $terms = class_terms($db, $c['id']);
        $published = [];
        foreach ($terms as $t) {
            if (class_is_published($db, $c['id'], $t['id'])) $published[] = $t;
        }
        ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center card-header-soft">
                <span class="fw-bold"><?= htmlspecialchars($c['subject']) ?> — <?= htmlspecialchars($c['section']) ?></span>
                <span class="badge bg-info"><?= $label ?></span>
            </div>
            <div class="card-body">
                <?php
                if (!$published) {
                    echo '<p class="text-muted small mb-2">No grades published yet. Your teacher publishes term grades when ready.</p>';
                } else {
                    $term_tgs = [];
                    foreach ($published as $t) {
                        $r = compute_term_grade($db, $c['id'], $user_id, $t['id']);
                        $term_tgs[] = ['term' => $t, 'res' => $r];
                    }
                    echo '<div class="accordion mb-3" id="acc_' . $c['id'] . '">';
                    foreach ($term_tgs as $i => $tt) {
                        $t = $tt['term']; $r = $tt['res'];
                        echo '<div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button ' . ($i > 0 ? 'collapsed' : '') . '" type="button" data-bs-toggle="collapse" data-bs-target="#coll_' . $c['id'] . '_' . $t['id'] . '">';
                        echo htmlspecialchars($t['label']) . ' &mdash; ' . ($r && $r['tg'] !== null ? 'TG ' . $r['tg'] : 'No grade yet');
                        echo '</button></h2>';
                        echo '<div id="coll_' . $c['id'] . '_' . $t['id'] . '" class="accordion-collapse collapse ' . ($i == 0 ? 'show' : '') . '" data-bs-parent="#acc_' . $c['id'] . '">';
                        echo '<div class="accordion-body">';
                        if ($r && $r['components']) {
                            echo '<div class="table-responsive"><table class="table table-sm table-bordered mb-2" style="font-size:0.85rem;">';
                            echo '<thead><tr><th>Component</th><th>Score / Total</th><th>PS (%)</th><th>WS</th></tr></thead><tbody>';
                            foreach ($r['components'] as $comp) {
                                echo '<tr><td class="fw-semibold">' . htmlspecialchars($comp['name']) . ' <small class="text-muted">(' . $comp['weight'] . '%)</small></td>';
                                echo '<td>' . ($comp['score_total'] !== null ? $comp['score_total'] . ' / ' . $comp['max_total'] : '&mdash;') . '</td>';
                                echo '<td>' . ($comp['ps'] !== null ? $comp['ps'] : '&mdash;') . '</td>';
                                echo '<td>' . ($comp['ws'] !== null ? $comp['ws'] : '&mdash;') . '</td></tr>';
                            }
                            echo '<tr class="table-primary"><td class="fw-bold">Initial Grade (IG)</td><td></td><td></td><td class="fw-bold">' . $r['ig'] . '</td></tr>';
                            echo '<tr><td class="fw-bold">Term Grade (TG)</td><td></td><td></td><td class="fw-bold">' . ($r['tg'] ?? '&mdash;') . '</td></tr>';
                            if ($r['tg'] !== null) {
                                echo '<tr><td class="fw-bold">Descriptor</td><td></td><td></td><td><span class="badge bg-primary fs-6">' . htmlspecialchars($r['descriptor']['label_en'] ?? '') . '</span></td></tr>';
                            }
                            echo '</tbody></table></div>';
                        } else {
                            echo '<p class="text-muted small mb-0">No assessment records for this term.</p>';
                        }
                        echo '</div></div></div>';
                    }
                    echo '</div>';
                }

                // Target grade calculator (for the next upcoming / active term with missing items)
                $active_term = null;
                foreach ($terms as $t) {
                    if (!class_is_published($db, $c['id'], $t['id'])) {
                        if ($t['id'] == ($c['current_term_id'] ?? 0)) { $active_term = $t; break; }
                    }
                }
                if (!$active_term) {
                    foreach ($terms as $t) {
                        if (!class_is_published($db, $c['id'], $t['id'])) { $active_term = $t; break; }
                    }
                }
                if ($active_term) {
                    $probe = compute_term_grade($db, $c['id'], $user_id, $active_term['id']);
                    $has_missing = false;
                    if ($probe) foreach ($probe['components'] as $comp) {
                        if ($comp['ps'] === null) { $has_missing = true; break; }
                    }
                    if ($has_missing) {
                        $show_target = $target_in > 0 && $_GET['class'] == $c['id'];
                        ?>
                        <div class="border rounded p-3 bg-light">
                            <form method="GET" class="row g-2 align-items-end">
                                <input type="hidden" name="page" value="grades">
                                <input type="hidden" name="class" value="<?= $c['id'] ?>">
                                <div class="col-auto">
                                    <label class="small fw-semibold d-block">Target Term Grade for <?= htmlspecialchars($active_term['label']) ?></label>
                                    <input type="number" name="target" min="60" max="100" step="1" class="form-control form-control-sm" style="width:90px;" value="<?= $show_target ? (int)$target_in : '' ?>" placeholder="e.g. 90">
                                </div>
                                <div class="col-auto"><button type="submit" class="btn btn-sm btn-brand">Calculate</button></div>
                            </form>
                            <?php if ($show_target) {
                                $tr = target_requirements($db, $c['id'], $user_id, $active_term['id'], $target_in);
                                if ($tr['possible']) {
                                    echo '<p class="small mt-2 mb-1 fw-semibold">To reach <strong>' . (int)$target_in . '</strong>, you need an average of <strong>' . $tr['required_ps'] . '%</strong> on the remaining ' . $tr['remaining_count'] . ' component(s).</p>';
                                    echo '<p class="small text-muted mb-0">' . ($tr['feasible'] ? 'This is achievable.' : '<span class="text-danger">This target may not be reachable with 100% on the remaining assessments.</span>') . '</p>';
                                } else {
                                    echo '<p class="small text-muted mt-2 mb-0">' . htmlspecialchars($tr['reason'] ?? '') . '</p>';
                                }
                            } ?>
                        </div>
                        <?php
                    }
                }
                ?>
            </div>
        </div>
    <?php }
}

function student_attendance() {
    global $db, $user_id; ?>
    <h4 class="page-title mb-1">My Attendance</h4>
    <?php
    $att = $db->prepare("SELECT at.*, c.subject, c.section FROM attendance at JOIN classes c ON at.class_id=c.id WHERE at.student_id=? ORDER BY at.date DESC LIMIT 50");
    $att->execute([$user_id]);
    $records = $att->fetchAll(PDO::FETCH_ASSOC);

    if (!$records) { echo '<div class="alert alert-info">No attendance records found.</div>'; return; }

    echo '<div class="table-responsive"><table class="table table-hover table-sm">';
    echo '<thead><tr><th>Date</th><th>Subject</th><th>Section</th><th>Status</th></tr></thead><tbody>';
    foreach ($records as $r) {
        $color = $r['status'] == 'present' ? 'success' : ($r['status'] == 'late' ? 'warning' : ($r['status'] == 'excused' ? 'info' : 'danger'));
        echo '<tr><td>' . $r['date'] . '</td><td>' . htmlspecialchars($r['subject']) . '</td><td>' . htmlspecialchars($r['section']) . '</td>';
        echo '<td><span class="badge bg-' . $color . '">' . ucfirst($r['status']) . '</span></td></tr>';
    }
    echo '</tbody></table></div>';
}

function student_notifications() {
    global $db, $user_id;
    $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user_id]);
    $notifs = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC");
    $notifs->execute([$user_id]);
    $list = $notifs->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <h4 class="page-title mb-1">Notifications</h4>
    <?php if (!$list) { echo '<div class="alert alert-info">No notifications.</div>'; }
    else {
        echo '<div class="list-group">';
        foreach ($list as $n) {
            echo '<div class="list-group-item"><a href="' . htmlspecialchars($n['link']) . '" class="text-decoration-none">' . htmlspecialchars($n['message']) . '</a>';
            echo '<br><small class="text-muted">' . $n['created_at'] . '</small></div>';
        }
        echo '</div>';
    }
}