<?php
// ========================================
// TEACHER PAGE FUNCTIONS
// ========================================
function teacher_dashboard() {
    global $db, $user_id; ?>
    <div class="page-header">
        <div>
            <h4 class="page-title">My Classes</h4>
            <p class="page-subtitle">Manage your classes, grades, and attendance</p>
        </div>
        <a href="dashboard.php?page=create_class" class="btn btn-sm btn-accent">+ New Class</a>
    </div>
    <?php
    $classes = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM class_members WHERE class_id=c.id) as student_count FROM classes c WHERE c.teacher_id=? AND c.status='active' ORDER BY c.created_at DESC");
    $classes->execute([$user_id]);
    $active = $classes->fetchAll(PDO::FETCH_ASSOC);

    $archived = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM class_members WHERE class_id=c.id) as student_count FROM classes c WHERE c.teacher_id=? AND c.status='archived' ORDER BY c.created_at DESC");
    $archived->execute([$user_id]);
    $archived_list = $archived->fetchAll(PDO::FETCH_ASSOC);

    $total_students = 0;
    foreach ($active as $c) $total_students += $c['student_count'];

    echo '<div class="row g-3 mb-3">';
    echo '<div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon">' . icon('book', 20) . '</div><p class="stat-value">' . count($active) . '</p><span class="stat-label">Active Classes</span></div></div>';
    echo '<div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon">' . icon('users', 20) . '</div><p class="stat-value">' . $total_students . '</p><span class="stat-label">Total Students</span></div></div>';
    echo '</div>';

    if ($active) {
        echo '<div class="row g-3">';
        $ai = 0;
        foreach ($active as $c) {
            $policy = class_policy($db, $c['id']);
            $label = $policy ? htmlspecialchars($policy['name']) : ($c['grading_system'] == 'deped' ? 'DepEd' : 'Standard');
            echo '<div class="col-md-4 col-sm-6">';
            echo '<div class="card class-card shadow-sm accent-' . ($ai++ % 6) . '">';
            echo '<div class="card-body">';
            echo '<h5 class="card-title mb-1">' . htmlspecialchars($c['subject']) . '</h5>';
            echo '<p class="card-text small text-muted mb-1">' . htmlspecialchars($c['section']) . ' &middot; ' . htmlspecialchars($c['school_year']) . '</p>';
            echo '<p class="card-text small text-muted mb-3">' . $c['student_count'] . ' students &middot; <span class="badge bg-info text-dark">' . $label . '</span></p>';
            echo '<div class="d-flex gap-2 flex-wrap">';
            echo '<a href="dashboard.php?page=class&id=' . $c['id'] . '" class="btn btn-sm btn-brand">Open</a>';
            echo '<a href="dashboard.php?page=grades&id=' . $c['id'] . '" class="btn btn-sm btn-outline-primary">Grades</a>';
            echo '<a href="dashboard.php?page=attendance&id=' . $c['id'] . '" class="btn btn-sm btn-outline-primary">Attendance</a>';
            echo '<a href="dashboard.php?page=settings&id=' . $c['id'] . '" class="btn btn-sm btn-outline-secondary">Settings</a>';
            echo '</div></div></div></div>';
        }
        echo '</div>';
    } else {
        echo '<div class="card"><div class="empty-state"><div class="empty-icon">' . icon('school', 26) . '</div><h6>No classes yet</h6><p>Create your first class to start recording grades.</p><a href="dashboard.php?page=create_class" class="btn btn-accent">+ New Class</a></div></div>';
    }

    if ($archived_list) {
        echo '<hr><h5 class="fw-bold text-muted mt-4">Archived Classes</h5><div class="row g-2">';
        foreach ($archived_list as $c) {
            echo '<div class="col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><small class="text-muted">' . htmlspecialchars($c['subject']) . ' - ' . htmlspecialchars($c['section']) . '</small> <form method="POST" class="d-inline float-end"><input type="hidden" name="action" value="restore_class"><input type="hidden" name="class_id" value="' . $c['id'] . '"><button type="submit" class="btn btn-sm btn-outline-secondary">Restore</button></form></div></div></div>';
        }
        echo '</div>';
    }
}

// Renders the grading-policy <select>, grouped by track with the component
// weights shown inline ("20/50/30") so the teacher can see what they are
// choosing before they commit. Each option carries the full name and
// description as a tooltip because a native <select> cannot wrap or
// ellipsize, and the labels are long.
function policy_picker($db, $name, $selected_id, $include_blank = null) {
    $groups = grading_policy_groups($db, true);
    echo '<select name="' . htmlspecialchars($name) . '" class="form-select" title="Grading weights and descriptors come from the policy template.">';
    if ($include_blank !== null) {
        echo '<option value=""' . ($selected_id ? '' : ' selected') . '>' . htmlspecialchars($include_blank) . '</option>';
    }
    $auto = true;
    foreach ($groups as $g) {
        echo '<optgroup label="' . htmlspecialchars($g['label']) . '">';
        foreach ($g['policies'] as $p) {
            $sel = '';
            if ($selected_id === null) {
                if ($auto) { $sel = ' selected'; $auto = false; }
            } elseif ((int)$selected_id === (int)$p['id']) {
                $sel = ' selected';
            }
            $label = htmlspecialchars($p['name']);
            if ($p['weights'] !== '') $label .= ' • ' . htmlspecialchars($p['weights']);
            $tip = $p['name'] . ($p['weights'] !== '' ? ' — weights ' . $p['weights'] : '') . '. ' . $p['description'];
            echo '<option value="' . (int)$p['id'] . '"' . $sel . ' title="' . htmlspecialchars($tip) . '">' . $label . '</option>';
        }
        echo '</optgroup>';
    }
    if (!$groups) echo '<option value="">No policy templates available</option>';
    echo '</select>';
}

function teacher_create_class() {
    global $db; ?>
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h4 class="page-title mb-1">Create New Class</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_class">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Subject</label>
                                <input type="text" name="subject" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Section</label>
                                <input type="text" name="section" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">School Year</label>
                                <input type="text" name="school_year" class="form-control" placeholder="e.g. 2026-2027" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Academic Year (terms)</label>
                                <select name="academic_year_id" class="form-select">
                                    <?php
                                    $years = $db->query("SELECT * FROM academic_years ORDER BY start_date DESC");
                                    $has_year = false;
                                    foreach ($years as $y) { $has_year = true;
                                        echo '<option value="' . $y['id'] . '" ' . ($y['is_active'] ? 'selected' : '') . '>' . htmlspecialchars($y['label']) . ($y['is_active'] ? ' (current)' : '') . '</option>';
                                    }
                                    if (!$has_year) echo '<option value="">No academic years set up</option>';
                                    ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold small">Grading Policy (template)</label>
                                <?php policy_picker($db, 'grading_policy_id', null); ?>
                                <div class="form-text">Sets the component weights for the class. Senior High has two tracks only &mdash;
                                    <strong>Academic</strong> and <strong>TechPro</strong> (DepEd Order No. 017, s. 2026).</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold small">Description (optional)</label>
                                <textarea name="description" class="form-control" rows="1"></textarea>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-brand">Create Class</button>
                            <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                    <p class="small text-muted mt-3 mb-0">Choosing a policy template pre-creates its categories (e.g. Written Works 20%, Performance Tasks 50%, Exams 30%). You can edit weights and add categories later.</p>
                </div>
            </div>
        </div>
    </div>
<?php }

function teacher_class_detail($class_id) {
    global $db, $user_id;
    $class = $db->prepare("SELECT * FROM classes WHERE id=? AND teacher_id=?");
    $class->execute([$class_id, $user_id]);
    $c = $class->fetch(PDO::FETCH_ASSOC);
    if (!$c) { echo '<div class="alert alert-danger">Class not found.</div>'; return; }

    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'stream';
    $policy = class_policy($db, $class_id);
    $grading_label = $policy ? '(' . htmlspecialchars($policy['name']) . ')' : '(Standard)';
    ?>
    <div class="page-header">
        <div>
            <a class="small d-inline-block mb-1" href="dashboard.php">&larr; My Classes</a>
            <h4 class="page-title"><?= htmlspecialchars($c['subject']) ?></h4>
            <p class="page-subtitle"><?= htmlspecialchars($c['section']) ?> &middot; SY <?= htmlspecialchars($c['school_year']) ?> <?= $grading_label ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="dashboard.php?page=grades&id=<?= $class_id ?>" class="btn btn-sm btn-outline-primary">Grades</a>
            <a href="dashboard.php?page=attendance&id=<?= $class_id ?>" class="btn btn-sm btn-outline-primary">Attendance</a>
            <a href="dashboard.php?page=settings&id=<?= $class_id ?>" class="btn btn-sm btn-outline-secondary">Settings</a>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="classTabs">
        <li class="nav-item"><a class="nav-link <?= $tab=='stream'?'active':'' ?>" href="dashboard.php?page=class&id=<?= $class_id ?>&tab=stream">Stream</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab=='assignments'?'active':'' ?>" href="dashboard.php?page=class&id=<?= $class_id ?>&tab=assignments">Assignments</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab=='students'?'active':'' ?>" href="dashboard.php?page=class&id=<?= $class_id ?>&tab=students">Students</a></li>
    </ul>

    <?php if ($tab == 'stream') {
        $anns = $db->prepare("SELECT * FROM announcements WHERE class_id=? ORDER BY posted_at DESC");
        $anns->execute([$class_id]);
        echo '<div class="mb-4">';
        echo '<div class="d-flex justify-content-between align-items-center mb-2"><h5 class="fw-bold mb-0">Announcements</h5>';
        echo '<button class="btn btn-sm btn-accent" data-bs-toggle="modal" data-bs-target="#annModal">+ Post</button></div>';
        $first = true;
        foreach ($anns as $a) {
            if ($first) { echo '<div class="list-group mt-2">'; $first = false; }
            echo '<div class="list-group-item"><div class="fw-semibold">' . htmlspecialchars($a['title']) . '</div>';
            echo '<small>' . nl2br(htmlspecialchars($a['content'])) . '</small>';
            if ($a['attachment']) echo '<br><a href="download.php?f=' . rawurlencode($a['attachment']) . '" target="_blank" class="small">View Attachment</a>';
            echo '<br><small class="text-muted">' . $a['posted_at'] . '</small></div>';
        }
        if (!$first) echo '</div>';
        ?>
        <div class="modal fade" id="annModal">
            <div class="modal-dialog">
                <form method="POST" enctype="multipart/form-data" class="modal-content">
                    <input type="hidden" name="action" value="create_announcement">
                    <input type="hidden" name="class_id" value="<?= $class_id ?>">
                    <div class="modal-header"><h5 class="modal-title">Post Announcement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-2"><input type="text" name="title" class="form-control" placeholder="Title" required></div>
                        <div class="mb-2"><textarea name="content" class="form-control" rows="3" placeholder="Content" required></textarea></div>
                        <div><input type="file" name="attachment" class="form-control form-control-sm"></div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-sm btn-brand">Post</button></div>
                </form>
            </div>
        </div>
        <?php
    } elseif ($tab == 'assignments') {
        $assignments = $db->prepare("SELECT * FROM assignments WHERE class_id=? ORDER BY due_date DESC");
        $assignments->execute([$class_id]);
        ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="fw-bold mb-0">Assignments</h5>
            <button class="btn btn-sm btn-accent" data-bs-toggle="modal" data-bs-target="#assignModal">+ Create</button>
        </div>
        <?php if ($assignments->rowCount() == 0) { echo '<div class="alert alert-info mt-2">No assignments yet.</div>'; }
        else { echo '<div class="list-group mt-2">';
            foreach ($assignments as $a) {
                $sub_count = $db->prepare("SELECT COUNT(*) FROM submissions WHERE assignment_id=?");
                $sub_count->execute([$a['id']]);
                $subs = $sub_count->fetchColumn();
                $late_count = $db->prepare("SELECT COUNT(*) FROM submissions WHERE assignment_id=? AND is_late=1");
                $late_count->execute([$a['id']]);
                $lts = $late_count->fetchColumn();
                echo '<div class="list-group-item"><div class="d-flex justify-content-between">';
                echo '<div><div class="fw-semibold">' . htmlspecialchars($a['title']);
                if (!empty($a['requires_file'])) echo ' <span class="badge bg-primary ms-1">file required</span>';
                if (empty($a['allow_late'])) echo ' <span class="badge bg-warning text-dark ms-1">late closed</span>';
                echo '</div><small class="text-muted">Due: ' . $a['due_date'] . '</small></div>';
                echo '<div><span class="badge bg-info">' . $subs . ' submissions</span>';
                if ($lts) echo ' <span class="badge bg-warning text-dark">' . $lts . ' late</span>';
                echo '<a href="dashboard.php?page=submissions&id=' . $a['id'] . '" class="btn btn-sm btn-outline-primary ms-2">View</a></div>';
                echo '</div></div>';
            }
            echo '</div>';
        }
        ?>
        <div class="modal fade" id="assignModal">
            <div class="modal-dialog">
                <form method="POST" enctype="multipart/form-data" class="modal-content">
                    <input type="hidden" name="action" value="create_assignment">
                    <input type="hidden" name="class_id" value="<?= $class_id ?>">
                    <div class="modal-header"><h5 class="modal-title">Create Assignment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-2"><input type="text" name="title" class="form-control" placeholder="Title" required></div>
                        <div class="mb-2"><textarea name="description" class="form-control" rows="3" placeholder="Description"></textarea></div>
                        <div class="mb-2"><label class="small fw-semibold">Due Date</label><input type="date" name="due_date" class="form-control" required></div>
                        <div class="mb-2"><input type="file" name="attachment" class="form-control form-control-sm" aria-label="Attachment (optional)"></div>
                        <hr>
                        <div class="mb-2"><label class="small fw-semibold d-block">Submission</label>
                            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="requires_file" id="reqFile" checked><label class="form-check-label small" for="reqFile">Students must upload a file</label></div>
                            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="allow_late" id="allowLate" checked><label class="form-check-label small" for="allowLate">Allow late (marked Late)</label></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-7"><label class="small fw-semibold">Allowed types (blank = default)</label><input type="text" name="allowed_exts" class="form-control form-control-sm" placeholder="pdf, docx, txt, jpg, zip"></div>
                            <div class="col-5"><label class="small fw-semibold">Max size (MB)</label><input type="number" name="max_size_mb" class="form-control form-control-sm" value="10" min="0.5" max="25" step="0.5"></div>
                        </div>
                        <div class="mb-1"><label class="small fw-semibold">Cutoff date (optional, only if late disabled)</label><input type="date" name="cutoff_date" class="form-control form-control-sm"></div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-sm btn-brand">Create</button></div>
                </form>
            </div>
        </div>
        <?php
    } elseif ($tab == 'students') {
        $search = $_GET['search'] ?? '';
        if ($search) {
            $students = $db->prepare("SELECT u.id, s.full_name, s.student_number, s.section FROM users u JOIN students s ON u.id=s.user_id JOIN class_members cm ON cm.student_id=u.id WHERE cm.class_id=? AND s.full_name LIKE ? ORDER BY s.full_name");
            $students->execute([$class_id, "%$search%"]);
        } else {
            $students = $db->prepare("SELECT u.id, s.full_name, s.student_number, s.section FROM users u JOIN students s ON u.id=s.user_id JOIN class_members cm ON cm.student_id=u.id WHERE cm.class_id=? ORDER BY s.full_name");
            $students->execute([$class_id]);
        }
        $students_list = $students->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="fw-bold mb-0">Students (<?= count($students_list) ?>)</h5>
            <form method="GET" class="d-flex gap-1 align-items-center">
                <input type="hidden" name="page" value="class">
                <input type="hidden" name="id" value="<?= $class_id ?>">
                <input type="hidden" name="tab" value="students">
                <input type="text" name="search" class="form-control form-control-sm" style="width:180px;" placeholder="Search name..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
            </form>
        </div>
        <?php if (count($students_list) == 0) { echo '<div class="alert alert-info mt-2">No students enrolled.</div>'; }
        else { ?>
            <div class="table-responsive mt-2">
                <table class="table table-hover">
                    <thead><tr><th>Name</th><th>ID</th><th>Section</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($students_list as $s) { ?>
                        <tr>
                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                            <td><?= htmlspecialchars($s['student_number']) ?></td>
                            <td><?= htmlspecialchars($s['section'] ?? '') ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Remove this student?')">
                                    <input type="hidden" name="action" value="remove_student">
                                    <input type="hidden" name="class_id" value="<?= $class_id ?>">
                                    <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php }
    }
}

function teacher_grades($class_id) {
    global $db, $user_id;
    $class = $db->prepare("SELECT * FROM classes WHERE id=? AND teacher_id=?");
    $class->execute([$class_id, $user_id]);
    $c = $class->fetch(PDO::FETCH_ASSOC);
    if (!$c) { echo '<div class="alert alert-danger">Class not found.</div>'; return; }

    $policy = class_policy($db, $class_id);
    $terms = class_terms($db, $class_id);
    $term_id = (int)($_GET['term'] ?? 0);
    if (!$term_id) $term_id = (int)($c['current_term_id'] ?? 0);
    if (!$term_id && $terms) $term_id = (int)$terms[0]['id'];
    $is_published = $term_id ? class_is_published($db, $class_id, $term_id) : false;

    $students = $db->prepare("SELECT u.id, s.full_name FROM users u JOIN students s ON u.id=s.user_id JOIN class_members cm ON cm.student_id=u.id WHERE cm.class_id=? ORDER BY s.full_name");
    $students->execute([$class_id]);
    $students_list = $students->fetchAll(PDO::FETCH_ASSOC);

    $cats = $db->prepare("SELECT * FROM grade_categories WHERE class_id=? AND term_id=? ORDER BY id");
    $cats->execute([$class_id, $term_id]);
    $categories = $cats->fetchAll(PDO::FETCH_ASSOC);

    $items_by_cat = [];
    $all_items = $db->prepare("SELECT * FROM grade_items WHERE class_id=? AND term_id=? ORDER BY id");
    $all_items->execute([$class_id, $term_id]);
    foreach ($all_items->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $items_by_cat[$it['category_id']][] = $it;
    }

    $score_map = [];
    $allscores = $db->prepare("SELECT gs.grade_item_id, gs.student_id, gs.score FROM grade_scores gs JOIN grade_items gi ON gs.grade_item_id = gi.id WHERE gi.class_id=? AND gi.term_id=?");
    $allscores->execute([$class_id, $term_id]);
    foreach ($allscores->fetchAll(PDO::FETCH_ASSOC) as $sc) {
        $score_map[$sc['grade_item_id'] . '_' . $sc['student_id']] = $sc['score'];
    }
    ?>
    <div class="page-header">
        <div>
            <a class="small d-inline-block mb-1" href="dashboard.php?page=class&id=<?= $class_id ?>">&larr; Back to Class</a>
            <h4 class="page-title">Grades: <?= htmlspecialchars($c['subject']) ?></h4>
            <p class="page-subtitle"><?= htmlspecialchars($c['section']) ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addCatModal">+ Category</button>
            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="export_xlsx"><input type="hidden" name="class_id" value="<?= $class_id ?>"><input type="hidden" name="term_id" value="<?= $term_id ?>"><button type="submit" class="btn btn-sm btn-outline-success">Export .xlsx</button></form>
            <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#importModal">Import .xlsx</button>
        </div>
    </div>

    <?php if ($policy) {
        $summary = policy_components_summary($policy);
        echo '<div class="alert alert-light border small py-2 mb-3">';
        echo '<strong>' . htmlspecialchars($policy['name']) . '</strong>';
        if ($summary) echo ' &mdash; ' . htmlspecialchars(is_array($summary) ? implode(', ', $summary) : $summary);
        echo ' &mdash; ' . ($policy['transmutation_mode'] == 'adjusted_table' ? 'DO 015 s.2026 adjusted transmutation' : ($policy['transmutation_mode'] == 'zero_based' ? 'Zero-based (no transmutation)' : ($policy['transmutation_mode'] == 'legacy_linear' ? 'Legacy DO 8 s.2015 linear' : 'No transmutation')));
        echo '</div>';
    } ?>

    <?php if (count($terms) > 1) { ?>
    <ul class="nav nav-pills mb-3">
        <?php foreach ($terms as $t) {
            $pub = class_is_published($db, $class_id, $t['id']);
            $active = $t['id'] == $term_id ? 'active' : '';
            echo '<li class="nav-item me-1 mb-1">';
            echo '<a class="nav-link ' . $active . '" href="dashboard.php?page=grades&id=' . $class_id . '&term=' . $t['id'] . '">' . htmlspecialchars($t['label']);
            if ($pub) echo ' <span class="badge bg-success text-white ms-1">published</span>';
            echo '</a></li>';
        } ?>
    </ul>
    <?php } ?>

    <div class="d-flex align-items-center gap-2 mb-3">
        <span class="badge bg-light text-dark border">Active term:
            <?php $cur = array_values(array_filter($terms, function($t) use ($term_id) { return $t['id'] == $term_id; }));
            echo $cur ? htmlspecialchars($cur[0]['label']) : 'None'; ?>
        </span>
        <span class="badge <?= $is_published ? 'bg-success' : 'bg-warning text-dark' ?>">
            <?= $is_published ? 'Published to students' : 'Not published' ?>
        </span>
        <?php if ($term_id) { ?>
            <?php if ($is_published) { ?>
            <form method="POST" class="d-inline"><input type="hidden" name="action" value="unpublish_term"><input type="hidden" name="class_id" value="<?= $class_id ?>"><input type="hidden" name="term_id" value="<?= $term_id ?>"><button type="submit" class="btn btn-sm btn-outline-warning">Unpublish</button></form>
            <?php } else { ?>
            <form method="POST" class="d-inline"><input type="hidden" name="action" value="publish_term"><input type="hidden" name="class_id" value="<?= $class_id ?>"><input type="hidden" name="term_id" value="<?= $term_id ?>"><button type="submit" class="btn btn-sm btn-success">Publish to Students</button></form>
            <?php } ?>
        <?php } ?>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCatModal"><div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_category"><input type="hidden" name="class_id" value="<?= $class_id ?>"><input type="hidden" name="term_id" value="<?= $term_id ?>">
            <div class="modal-header"><h5 class="modal-title">Add Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><input type="text" name="name" class="form-control" placeholder="Category name" required></div>
                <div><label class="small fw-semibold">Weight (%)</label><input type="number" step="0.01" name="weight" class="form-control" required></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-sm btn-brand">Add</button></div>
        </form>
    </div></div>

    <!-- Import Modal -->
    <div class="modal fade" id="importModal"><div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="import_xlsx"><input type="hidden" name="class_id" value="<?= $class_id ?>"><input type="hidden" name="term_id" value="<?= $term_id ?>">
            <div class="modal-header"><h5 class="modal-title">Import .xlsx</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="small text-muted">Export .xlsx first to get the correct format.</p>
                <input type="file" name="xlsx_file" accept=".xlsx" class="form-control" required>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-sm btn-brand">Import</button></div>
        </form>
    </div></div>

    <?php if (!$categories) {
        echo '<div class="alert alert-info">No categories for this term yet. Use "+ Category" to add one (or set a grading policy in Settings to auto-create its components).</div>';
        return;
    }
    ?>
<?php
    // ================================================================
    // 1) THE GRID COMES FIRST — entering scores is the whole job.
    // ================================================================
    $all = compute_class_term_grades($db, $class_id, $term_id);
    $item_avg = class_item_averages($db, $class_id, $term_id);
    $cat_avg = class_category_averages($all);
    $grade_avg = class_grade_averages($all);

    // Categories that actually have activities drive the grid columns.
    $grid_cats = [];
    foreach ($categories as $cat) {
        if (!empty($items_by_cat[$cat['id']])) $grid_cats[] = $cat;
    }
    $collapsed = isset($_GET['collapse'])
        ? array_values(array_filter(array_map('intval', explode(',', (string)$_GET['collapse'])),
            fn($cid) => in_array($cid, array_column($grid_cats, 'id'), true)))
        : [];
    $total_activities = array_sum(array_map(fn($c) => count($items_by_cat[$c['id']] ?? []), $grid_cats));
    $ci = 0;
    $letters = [];
    foreach ($grid_cats as $cat) {
        foreach ($items_by_cat[$cat['id']] as $it) {
            $letters[] = $ci;
            $ci++;
        }
    }
    ?>
    <?php if ($grid_cats) { ?>
    <style>
    <?php foreach ($grid_cats as $cat) { ?>
        #gradebookGrid.collapse-<?= (int)$cat['id'] ?> .gb-c-<?= (int)$cat['id'] ?> { display: none; }
    <?php } ?>
    </style>
    <?php } ?>
    <form method="POST" id="gradebookForm">
        <input type="hidden" name="action" value="save_grid_scores">
        <input type="hidden" name="class_id" value="<?= $class_id ?>">
        <input type="hidden" name="term_id" value="<?= $term_id ?>">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 card-header-soft">
                <span class="fw-bold">Gradebook &mdash; Enter / Edit Scores</span>
                <span class="small text-muted d-none d-lg-inline">
                    <kbd>Enter</kbd>/<kbd>&darr;</kbd> down &middot; <kbd>Tab</kbd>/<kbd>&rarr;</kbd> right &middot;
                    <kbd>Shift</kbd>+arrows select &middot; <kbd>Ctrl</kbd>+<kbd>V</kbd> paste from Excel &middot;
                    <kbd>Ctrl</kbd>+<kbd>Z</kbd> undo &middot; saves on its own
                </span>
            </div>
            <div class="card-body p-2">
                <?php if (!$grid_cats || !$students_list) { ?>
                    <div class="empty-state"><h6>Nothing to grade yet</h6><p>Add at least one activity, and make sure students are enrolled.</p></div>
                <?php } else { ?>
                <div class="table-responsive gb-scroll">
                    <table class="table table-sm table-bordered align-middle mb-2" id="gradebookGrid" data-class="<?= $class_id ?>" data-term="<?= $term_id ?>" data-api="grades_api.php">
                        <thead>
                            <tr>
                                <th rowspan="2" class="sticky-col gb-corner" data-col="name">Student</th>
                                <?php foreach ($grid_cats as $cat) {
                                    $its = $items_by_cat[$cat['id']];
                                    $span = count($its) + 1;
                                    $is_col = in_array((int)$cat['id'], $collapsed, true);
                                    ?>
                                    <th colspan="<?= $span ?>" class="gb-group gb-c-<?= (int)$cat['id'] ?><?= $is_col ? ' collapsed' : '' ?>">
                                        <button type="button" class="gb-collapse" data-cat="<?= (int)$cat['id'] ?>"
                                                aria-expanded="<?= $is_col ? 'false' : 'true' ?>"
                                                title="Show / hide this category">
                                            <span class="gb-caret" aria-hidden="true">&#9662;</span><?= htmlspecialchars($cat['name']) ?>
                                            <span class="gb-w"><?= trim_num($cat['weight']) ?>%</span>
                                        </button>
                                        <button type="button" class="gb-add-item" data-cat="<?= (int)$cat['id'] ?>"
                                                data-bs-toggle="modal" data-bs-target="#addItemModal<?= (int)$cat['id'] ?>"
                                                title="Add an activity to <?= htmlspecialchars($cat['name']) ?>" aria-label="Add an activity to <?= htmlspecialchars($cat['name']) ?>">+</button>
                                    </th>
                                <?php } ?>
                                <th rowspan="2" class="text-center gb-hi" data-col="ig">IG</th>
                                <th rowspan="2" class="text-center gb-hi" data-col="tg">TG</th>
                                <th rowspan="2" class="text-center gb-hi" data-col="desc">Descriptor</th>
                            </tr>
                            <tr>
                                <?php $ci = 0;
                                foreach ($grid_cats as $cat) {
                                    foreach ($items_by_cat[$cat['id']] as $it) {
                                        $typetag = $it['ex_group'] ? ' <small class="text-muted">' . strtoupper(htmlspecialchars($it['ex_group'])) . '</small>' : '';
                                        echo '<th class="text-center gb-c-' . (int)$cat['id'] . '" data-col="i' . $ci . '">'
                                            . '<span class="gb-colletter">' . col_letter($ci) . '</span>'
                                            . htmlspecialchars($it['name']) . $typetag
                                            . '<br><small class="text-muted">/' . trim_num($it['max_score']) . '</small></th>';
                                        $ci++;
                                    }
                                    echo '<th class="text-center gb-pshead gb-c-' . (int)$cat['id'] . '" data-col="p' . (int)$cat['id'] . '">PS%</th>';
                                } ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $ri = 0;
                        foreach ($students_list as $s) {
                            $sid = (int)$s['id'];
                            $r = $all[$sid] ?? null;
                            $comp_by_cat = [];
                            if ($r) foreach ($r['components'] as $comp) $comp_by_cat[$comp['category_id']] = $comp;
                            echo '<tr data-row="' . $ri . '">';
                            echo '<td class="sticky-col gb-name" data-col="name"><span class="gb-rn">' . ($ri + 1) . '</span>' . htmlspecialchars($s['full_name']) . '</td>';
                            $cj = 0;
                            foreach ($grid_cats as $cat) {
                                foreach ($items_by_cat[$cat['id']] as $it) {
                                    $val = $score_map[$it['id'] . '_' . $sid] ?? '';
                                    echo '<td class="gb-cell gb-c-' . (int)$cat['id'] . '" data-col="i' . $cj . '">'
                                        // type=text, not type=number: number inputs have no text
                                        // selection API, so select()/selectionStart (needed for
                                        // overtyping and caret-aware arrow keys) do not work on them.
                                        . '<input type="text" inputmode="decimal" autocomplete="off" spellcheck="false"'
                                        . ' max="' . trim_num($it['max_score']) . '"'
                                        . ' name="scores[' . (int)$it['id'] . '][' . $sid . ']"'
                                        . ' value="' . htmlspecialchars((string)$val) . '"'
                                        . ' class="gb-input" placeholder="&mdash;"'
                                        . ' data-ri="' . $ri . '" data-ci="' . $cj . '"'
                                        . ' data-item="' . (int)$it['id'] . '" data-student="' . $sid . '">'
                                        . '</td>';
                                    $cj++;
                                }
                                $comp = $comp_by_cat[(int)$cat['id']] ?? null;
                                $ps = $comp && $comp['ps'] !== null ? $comp['ps'] : null;
                                echo '<td class="text-center gb-ps gb-c-' . (int)$cat['id'] . '" data-ps="' . (int)$cat['id'] . '" data-col="p' . (int)$cat['id'] . '">'
                                    . ($ps !== null ? $ps : '&mdash;') . '</td>';
                            }
                            echo '<td class="text-center fw-bold gb-ig" data-col="ig">' . ($r ? $r['ig'] : '&mdash;') . '</td>';
                            echo '<td class="text-center fw-bold gb-tg" data-col="tg">' . ($r && $r['tg'] !== null ? $r['tg'] : '&mdash;') . '</td>';
                            echo '<td class="text-center gb-desc" data-col="desc">'
                                . ($r && !empty($r['descriptor']['label_en'])
                                    ? '<span class="badge bg-primary">' . htmlspecialchars($r['descriptor']['label_en']) . '</span>'
                                    : '&mdash;') . '</td>';
                            echo '</tr>';
                            $ri++;
                        } ?>
                        </tbody>
                        <tfoot>
                            <tr class="gb-avg-row">
                                <td class="sticky-col gb-avg-label" data-col="name">Class Average</td>
                                <?php $cj = 0;
                                foreach ($grid_cats as $cat) {
                                    foreach ($items_by_cat[$cat['id']] as $it) {
                                        $a = $item_avg[(int)$it['id']] ?? null;
                                        echo '<td class="gb-cell gb-c-' . (int)$cat['id'] . '" data-avg-item="' . (int)$it['id'] . '" data-col="i' . $cj . '">'
                                            . ($a !== null ? $a : '&mdash;') . '</td>';
                                        $cj++;
                                    }
                                    $a = $cat_avg[(string)$cat['id']] ?? null;
                                    echo '<td class="text-center gb-avg-ps gb-c-' . (int)$cat['id'] . '" data-avg-ps="' . (int)$cat['id'] . '" data-col="p' . (int)$cat['id'] . '">'
                                        . ($a !== null ? $a : '&mdash;') . '</td>';
                                } ?>
                                <td class="text-center fw-bold gb-avg-ig" data-col="ig"><?= $grade_avg['ig'] !== null ? $grade_avg['ig'] : '&mdash;' ?></td>
                                <td class="text-center fw-bold gb-avg-tg" data-col="tg"><?= $grade_avg['tg'] !== null ? $grade_avg['tg'] : '&mdash;' ?></td>
                                <td data-col="desc"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-1">
                    <span class="small text-muted" id="gbStatus" role="status" aria-live="polite">&nbsp;</span>
                    <span class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="gbUndo" disabled title="Undo (Ctrl+Z)">Undo</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="gbExpandAll">Expand all</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="gbCollapseAll">Collapse all</button>
                    </span>
                </div>
                <?php } ?>
            </div>
        </div>

        <?php if ($grid_cats && $students_list) { ?>
        <div class="d-flex justify-content-end align-items-center gap-3 py-3 sticky-save-bar">
            <span class="small text-muted d-none d-lg-inline">Need a full copy? Export &rarr; Import .xlsx.</span>
            <button type="submit" class="btn btn-brand" id="gbSaveBtn">Save All Scores</button>
        </div>
        <?php } ?>
    </form>

    <!-- ================================================================
         2) SETUP: weights + activities (collapsed by default)
         ================================================================ -->
    <details class="gb-setup mb-3">
        <summary>
            <span class="fw-bold">Activities &amp; weights</span>
            <span class="text-muted small">&mdash; <?= count($grid_cats) ?> categor<?= count($grid_cats) === 1 ? 'y' : 'ies' ?>,
                <?= $total_activities ?> activit<?= $total_activities === 1 ? 'y' : 'ies' ?></span>
        </summary>
        <div class="pt-3">
    <?php
    foreach ($categories as $cat) {
        $items_list = $items_by_cat[$cat['id']] ?? [];
        ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center card-header-soft">
                <span class="fw-bold"><?= htmlspecialchars($cat['name']) ?> <span class="fw-normal text-muted small">(<?= trim_num($cat['weight']) ?>%)</span></span>
                <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addItemModal<?= $cat['id'] ?>">+ Activity</button>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this category and all its activities?')">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="class_id" value="<?= $class_id ?>">
                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                        <input type="hidden" name="term_id" value="<?= $term_id ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete this category">&times;</button>
                    </form>
                </div>
            </div>
            <div class="card-body p-2">
                <?php if (!$items_list) {
                    echo '<p class="text-muted small mb-2">No activities yet. Click "+ Activity" to add one.</p>';
                } else { ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0" style="font-size:0.82rem;">
                            <thead>
                                <tr>
                                    <th style="min-width:130px;">Activity</th>
                                    <th style="width:45px;">Max</th>
                                    <th style="width:90px;">Type</th>
                                    <th style="width:75px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items_list as $item) { ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td class="text-center"><?= $item['max_score'] ?></td>
                                    <td class="text-center"><?= $item['ex_group'] ? '<span class="badge bg-secondary">' . strtoupper(htmlspecialchars($item['ex_group'])) . '</span>' : '<span class="text-muted">-</span>' ?></td>
                                    <td class="text-nowrap">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="class_id" value="<?= $class_id ?>">
                                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:0.7rem;" onclick="return confirm('Delete this activity?')">&times;</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </div>
        </div>

        <!-- Add Activity Modal -->
        <div class="modal fade" id="addItemModal<?= $cat['id'] ?>">
            <div class="modal-dialog">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="class_id" value="<?= $class_id ?>">
                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                    <div class="modal-header"><h5 class="modal-title">Add Activity to <?= htmlspecialchars($cat['name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-2"><input type="text" name="name" class="form-control" placeholder="Activity name (e.g. Quiz 1)" required></div>
                        <div class="row g-2">
                            <div class="col-6"><label class="small fw-semibold">Max Score</label><input type="number" step="0.01" name="max_score" class="form-control" required></div>
                            <div class="col-6">
                                <label class="small fw-semibold">Type (exams only)</label>
                                <select name="ex_group" class="form-select">
                                    <option value="">-</option>
                                    <option value="st1">Exam Part 1 (ST1)</option>
                                    <option value="st2">Exam Part 2 (ST2)</option>
                                    <option value="te">Exam Performance (TE)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-sm btn-brand">Add</button></div>
                </form>
            </div>
        </div>
    <?php } ?>
        </div>
    </details>
<?php
}

function teacher_attendance($class_id) {
    global $db, $user_id;
    $class = $db->prepare("SELECT * FROM classes WHERE id=? AND teacher_id=?");
    $class->execute([$class_id, $user_id]);
    $c = $class->fetch(PDO::FETCH_ASSOC);
    if (!$c) { echo '<div class="alert alert-danger">Class not found.</div>'; return; }

    $date = $_GET['date'] ?? date('Y-m-d');

    $students = $db->prepare("SELECT u.id, s.full_name FROM users u JOIN students s ON u.id=s.user_id JOIN class_members cm ON cm.student_id=u.id WHERE cm.class_id=? ORDER BY s.full_name");
    $students->execute([$class_id]);
    $students_list = $students->fetchAll(PDO::FETCH_ASSOC);

    $existing_att = $db->prepare("SELECT student_id, status FROM attendance WHERE class_id=? AND date=?");
    $existing_att->execute([$class_id, $date]);
    $att_map = [];
    foreach ($existing_att->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $att_map[$a['student_id']] = $a['status'];
    }

    ?>
    <div class="page-header">
        <div>
            <a class="small d-inline-block mb-1" href="dashboard.php?page=class&id=<?= $class_id ?>">&larr; Back to Class</a>
            <h4 class="page-title">Attendance: <?= htmlspecialchars($c['subject']) ?></h4>
            <p class="page-subtitle"><?= htmlspecialchars($c['section']) ?></p>
        </div>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <input type="hidden" name="page" value="attendance">
                <input type="hidden" name="id" value="<?= $class_id ?>">
                <label class="form-label small mb-0">Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= $date ?>">
            </div>
            <div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-primary">View</button></div>
        </form>
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="mark_attendance">
        <input type="hidden" name="class_id" value="<?= $class_id ?>">
        <input type="hidden" name="date" value="<?= $date ?>">
        <div class="table-responsive">
        <table class="table table-hover table-sm">
            <thead><tr><th>Student</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($students_list as $s) {
                $current = $att_map[$s['id']] ?? 'present';
                ?>
                <tr>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td>
                        <input type="hidden" name="student_id[]" value="<?= $s['id'] ?>">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="status_<?= $s['id'] ?>" value="present" id="p_<?= $s['id'] ?>" <?= $current=='present'?'checked':'' ?>>
                            <label class="form-check-label small" for="p_<?= $s['id'] ?>">Present</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="status_<?= $s['id'] ?>" value="late" id="l_<?= $s['id'] ?>" <?= $current=='late'?'checked':'' ?>>
                            <label class="form-check-label small" for="l_<?= $s['id'] ?>">Late</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="status_<?= $s['id'] ?>" value="excused" id="e_<?= $s['id'] ?>" <?= $current=='excused'?'checked':'' ?>>
                            <label class="form-check-label small" for="e_<?= $s['id'] ?>">Excused</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="status_<?= $s['id'] ?>" value="absent" id="a_<?= $s['id'] ?>" <?= $current=='absent'?'checked':'' ?>>
                            <label class="form-check-label small" for="a_<?= $s['id'] ?>">Absent</label>
                        </div>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <button type="submit" class="btn btn-brand">Save Attendance</button>
    </form>
<?php }

function teacher_settings($class_id) {
    global $db, $user_id;
    $class = $db->prepare("SELECT * FROM classes WHERE id=? AND teacher_id=?");
    $class->execute([$class_id, $user_id]);
    $c = $class->fetch(PDO::FETCH_ASSOC);
    if (!$c) { echo '<div class="alert alert-danger">Class not found.</div>'; return; }

    $policy = class_policy($db, $class_id);
    $terms = class_terms($db, $class_id);
    ?>
    <div class="page-header">
        <div>
            <a class="small d-inline-block mb-1" href="dashboard.php?page=class&id=<?= $class_id ?>">&larr; Back to Class</a>
            <h4 class="page-title">Settings: <?= htmlspecialchars($c['subject']) ?></h4>
            <p class="page-subtitle"><?= htmlspecialchars($c['section']) ?></p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Class Information</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_class">
                        <input type="hidden" name="class_id" value="<?= $class_id ?>">
                        <div class="mb-2"><label class="small fw-semibold">Subject</label><input type="text" name="subject" class="form-control" value="<?= htmlspecialchars($c['subject']) ?>"></div>
                        <div class="mb-2"><label class="small fw-semibold">Section</label><input type="text" name="section" class="form-control" value="<?= htmlspecialchars($c['section']) ?>"></div>
                        <div class="mb-2"><label class="small fw-semibold">School Year</label><input type="text" name="school_year" class="form-control" value="<?= htmlspecialchars($c['school_year']) ?>"></div>
                        <div class="mb-2"><label class="small fw-semibold">Description</label><textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($c['description'] ?? '') ?></textarea></div>
                        <button type="submit" class="btn btn-sm btn-brand">Update</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Join Code</div>
                <div class="card-body">
                    <p class="mb-1">Current code: <strong><?= $c['join_code'] ? htmlspecialchars($c['join_code']) : '<span class="text-muted">Disabled</span>' ?></strong></p>
                    <div class="d-flex gap-1">
                        <form method="POST"><input type="hidden" name="action" value="new_code"><input type="hidden" name="class_id" value="<?= $class_id ?>"><button type="submit" class="btn btn-sm btn-outline-primary">Generate New</button></form>
                        <?php if ($c['join_code']) { ?>
                            <form method="POST"><input type="hidden" name="action" value="disable_join"><input type="hidden" name="class_id" value="<?= $class_id ?>"><button type="submit" class="btn btn-sm btn-outline-warning">Disable</button></form>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header fw-semibold">Grading Policy</div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Currently: <strong><?= $policy ? htmlspecialchars($policy['name']) : 'Custom (no policy)' ?></strong></p>
                    <form method="POST" class="mb-2">
                        <input type="hidden" name="action" value="set_policy">
                        <input type="hidden" name="class_id" value="<?= $class_id ?>">
                        <div class="mb-2">
                            <?php policy_picker($db, 'grading_policy_id', $policy ? (int)$policy['id'] : null, 'Custom (no policy)'); ?>
                        </div>
                        <button type="submit" class="btn btn-sm btn-brand">Save Policy</button>
                    </form>
                    <?php if ($policy) { $summary = policy_components_summary($policy); if ($summary) echo '<p class="small text-muted mb-0">' . htmlspecialchars(is_array($summary) ? implode(', ', $summary) : $summary) . '</p>'; } ?>
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header fw-semibold">Active Term</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="set_term">
                        <input type="hidden" name="class_id" value="<?= $class_id ?>">
                        <div class="mb-2">
                            <select name="term_id" class="form-select">
                                <option value="">None</option>
                                <?php foreach ($terms as $t) {
                                    echo '<option value="' . $t['id'] . '" ' . ($c['current_term_id'] == $t['id'] ? 'selected' : '') . '>' . htmlspecialchars($t['label']) . '</option>';
                                } ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-brand">Set Active Term</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mt-3 border-danger">
                <div class="card-header fw-semibold text-danger">Danger Zone</div>
                <div class="card-body">
                    <form method="POST" style="display:inline;"><input type="hidden" name="action" value="archive_class"><input type="hidden" name="class_id" value="<?= $class_id ?>"><button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm('Archive this class?')">Archive</button></form>
                    <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete_class"><input type="hidden" name="class_id" value="<?= $class_id ?>"><button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('DELETE this class permanently? This cannot be undone.')">Delete</button></form>
                </div>
            </div>
        </div>
    </div>
<?php }

function teacher_submissions($assignment_id) {
    global $db, $user_id;
    $assign = $db->prepare("SELECT a.*, c.subject, c.section FROM assignments a JOIN classes c ON a.class_id=c.id WHERE a.id=? AND c.teacher_id=?");
    $assign->execute([$assignment_id, $user_id]);
    $a = $assign->fetch(PDO::FETCH_ASSOC);
    if (!$a) { echo '<div class="alert alert-danger">Assignment not found.</div>'; return; }

    $submissions = $db->prepare("SELECT sub.*, s.full_name, s.student_number FROM submissions sub JOIN students s ON sub.student_id = s.user_id WHERE sub.assignment_id=? ORDER BY s.full_name");
    $submissions->execute([$assignment_id]);
    $subs = $submissions->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="page-header">
        <div>
            <a class="small d-inline-block mb-1" href="dashboard.php?page=class&id=<?= $a['class_id'] ?>&tab=assignments">&larr; Back to Assignments</a>
            <h4 class="page-title"><?= htmlspecialchars($a['title']) ?></h4>
            <p class="page-subtitle"><?= htmlspecialchars($a['subject']) ?> &middot; <?= htmlspecialchars($a['section']) ?><?php if (!empty($a['requires_file'])) { ?> &middot; File submission required<?php } ?></p>
        </div>
        <div class="d-flex gap-2">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="download_all_submissions">
                <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                <button type="submit" class="btn btn-sm btn-outline-success">Download all (.zip)</button>
            </form>
        </div>
    </div>

    <?php if (!$subs) { echo '<div class="alert alert-info">No submissions yet.</div>'; } else { ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Student</th><th>Submitted</th><th>File</th><th>Score</th><th>Feedback</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($subs as $sub) { $form_id = 'gradeform_' . $sub['id']; ?>
                <tr>
                    <td><?= htmlspecialchars($sub['full_name']) ?><?php if (!empty($sub['is_late'])) echo ' <span class="badge bg-warning text-dark">Late</span>'; ?></td>
                    <td><small><?= $sub['submitted_at'] ?></small></td>
                    <td><?php if ($sub['file_url']) { ?><a href="download.php?f=<?= rawurlencode($sub['file_url']) ?>" target="_blank" class="small"><?= htmlspecialchars($sub['original_name'] ?: 'View File') ?></a><?php } else { ?><span class="text-muted small">No file</span><?php } ?></td>
                    <td><input type="number" step="0.01" name="score" form="<?= $form_id ?>" class="form-control form-control-sm" style="width:70px;" value="<?= htmlspecialchars($sub['score'] ?? '') ?>"></td>
                    <td><input type="text" name="feedback" form="<?= $form_id ?>" class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($sub['feedback'] ?? '') ?>"></td>
                    <td><button type="submit" form="<?= $form_id ?>" class="btn btn-sm btn-success">Grade</button></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php foreach ($subs as $sub) { ?>
        <form method="POST" id="gradeform_<?= $sub['id'] ?>" class="d-none">
            <input type="hidden" name="action" value="grade_submission">
            <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
        </form>
    <?php }
    } ?>

    <?php
    // Not-yet-submitted roster (only if file submission is required)
    if (!empty($a['requires_file'])) {
        $missing = $db->prepare("SELECT s.full_name, s.student_number FROM students s JOIN class_members cm ON cm.student_id = s.user_id WHERE cm.class_id=? AND s.user_id NOT IN (SELECT student_id FROM submissions WHERE assignment_id=?) ORDER BY s.full_name");
        $missing->execute([$a['class_id'], $assignment_id]);
        $missing_rows = $missing->fetchAll(PDO::FETCH_ASSOC);
        if ($missing_rows) {
            echo '<div class="card mt-3"><div class="card-header">Not yet submitted (' . count($missing_rows) . ')</div><div class="card-body py-2">';
            foreach ($missing_rows as $m) {
                echo '<span class="badge bg-light text-dark border me-1 mb-1">' . htmlspecialchars($m['full_name']) . '</span>';
            }
            echo '</div></div>';
        }
    }
    ?>
<?php }
