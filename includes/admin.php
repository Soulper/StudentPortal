<?php
// ================================================================
// ADMIN PANEL — PAGE FUNCTIONS + POST HANDLER (admin.php)
// ================================================================

function handle_admin_post($db) {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
        flash('Your session expired. Please try again.', 'danger');
        header('Location: admin.php');
        exit;
    }

    // ---- USERS ----
    if ($action == 'user_toggle_admin') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid == current_user_id()) { flash('You cannot change your own admin status.', 'danger'); }
        else {
            $cur = (int)$db->query("SELECT is_admin FROM users WHERE id=" . $uid)->fetchColumn();
            $db->prepare("UPDATE users SET is_admin=? WHERE id=?")->execute([$cur ? 0 : 1, $uid]);
            audit($db, 'toggle_admin', 'users', $uid, (string)$cur, $cur ? '0' : '1');
            flash('Admin status updated.');
        }
        header('Location: admin.php?page=users');
        exit;
    }
    if ($action == 'user_toggle_status') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid == current_user_id()) { flash('You cannot disable your own account.', 'danger'); }
        else {
            $cur = $db->query("SELECT status FROM users WHERE id=" . $uid)->fetchColumn();
            $new = $cur == 'active' ? 'disabled' : 'active';
            $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new, $uid]);
            audit($db, 'toggle_status', 'users', $uid, $cur, $new);
            flash('User status updated.');
        }
        header('Location: admin.php?page=users');
        exit;
    }
    if ($action == 'user_reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $newpass = $_POST['new_password'] ?? '';
        if (strlen($newpass) < 8) { flash('Password must be at least 8 characters.', 'danger'); }
        else {
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newpass, PASSWORD_DEFAULT), $uid]);
            audit($db, 'reset_password', 'users', $uid, null, 'password reset');
            flash('Password reset.');
        }
        header('Location: admin.php?page=users');
        exit;
    }

    // ---- ACADEMIC YEARS & TERMS ----
    if ($action == 'add_year') {
        $label = trim($_POST['label'] ?? '');
        if (!$label) { flash('Year label required.', 'danger'); }
        else {
            $db->prepare("INSERT INTO academic_years (label, start_date, end_date, is_active) VALUES (?,?,?,0)")
                ->execute([$label, $_POST['start_date'] ?: null, $_POST['end_date'] ?: null]);
            audit($db, 'add_year', 'academic_years', (int)$db->lastInsertId(), null, $label);
            flash('Academic year added.');
        }
        header('Location: admin.php?page=years');
        exit;
    }
    if ($action == 'set_active_year') {
        $yid = (int)($_POST['year_id'] ?? 0);
        $db->exec("UPDATE academic_years SET is_active=0");
        $db->prepare("UPDATE academic_years SET is_active=1 WHERE id=?")->execute([$yid]);
        set_setting($db, 'active_academic_year_id', (string)$yid);
        audit($db, 'set_active_year', 'academic_years', $yid);
        flash('Active academic year updated.');
        header('Location: admin.php?page=years');
        exit;
    }
    if ($action == 'add_term') {
        $yid = (int)($_POST['year_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        if (!$label) { flash('Term label required.', 'danger'); }
        else {
            $num = (int)$db->query("SELECT COALESCE(MAX(number),0)+1 FROM terms WHERE academic_year_id=" . $yid)->fetchColumn();
            $db->prepare("INSERT INTO terms (academic_year_id, number, label, start_date, end_date, weight, status) VALUES (?,?,?,?,?,?, 'open')")
                ->execute([$yid, $num, $label, $_POST['start_date'] ?: null, $_POST['end_date'] ?: null, (float)($_POST['weight'] ?? 1)]);
            audit($db, 'add_term', 'terms', (int)$db->lastInsertId(), null, $label);
            flash('Term added.');
        }
        header('Location: admin.php?page=years');
        exit;
    }
    if ($action == 'toggle_term_status') {
        $tid = (int)($_POST['term_id'] ?? 0);
        $cur = $db->query("SELECT status FROM terms WHERE id=" . $tid)->fetchColumn();
        $new = $cur == 'open' ? 'closed' : 'open';
        $db->prepare("UPDATE terms SET status=? WHERE id=?")->execute([$new, $tid]);
        audit($db, 'toggle_term_status', 'terms', $tid, $cur, $new);
        flash('Term status updated.');
        header('Location: admin.php?page=years');
        exit;
    }

    // ---- POLICIES ----
    if ($action == 'add_policy') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { flash('Policy name required.', 'danger'); }
        else {
            $mode = in_array($_POST['transmutation_mode'] ?? '', ['adjusted_table', 'zero_based', 'legacy_linear', 'none'], true) ? $_POST['transmutation_mode'] : 'none';
            $db->prepare("INSERT INTO grading_policies (name, description, source_doc, key_stage, subject_group, transmutation_mode, is_active) VALUES (?,?,?,?,?,?,1)")
                ->execute([$name, $_POST['description'] ?? '', $_POST['source_doc'] ?? '', $_POST['key_stage'] ?? '', $_POST['subject_group'] ?? '', $mode]);
            audit($db, 'add_policy', 'grading_policies', (int)$db->lastInsertId(), null, $name);
            flash('Policy created.');
        }
        header('Location: admin.php?page=policies');
        exit;
    }
    if ($action == 'policy_toggle') {
        $pid = (int)($_POST['policy_id'] ?? 0);
        $cur = (int)$db->query("SELECT is_active FROM grading_policies WHERE id=" . $pid)->fetchColumn();
        $db->prepare("UPDATE grading_policies SET is_active=? WHERE id=?")->execute([$cur ? 0 : 1, $pid]);
        audit($db, 'toggle_policy', 'grading_policies', $pid, (string)$cur, $cur ? '0' : '1');
        flash('Policy ' . ($cur ? 'deactivated' : 'activated') . '.');
        header('Location: admin.php?page=policies');
        exit;
    }
    if ($action == 'policy_save_components') {
        $pid = (int)($_POST['policy_id'] ?? 0);
        $comps = $_POST['comp'] ?? [];
        foreach ($comps as $cid => $c) {
            $weight = (float)($c['weight'] ?? 0);
            $db->prepare("UPDATE grading_components SET name=?, weight=?, ex_st1=?, ex_st2=?, ex_te=? WHERE id=? AND policy_id=?")
                ->execute([
                    trim($c['name'] ?? ''),
                    $weight,
                    isset($c['ex_st1']) && $c['ex_st1'] !== '' ? (float)$c['ex_st1'] : null,
                    isset($c['ex_st2']) && $c['ex_st2'] !== '' ? (float)$c['ex_st2'] : null,
                    isset($c['ex_te']) && $c['ex_te'] !== '' ? (float)$c['ex_te'] : null,
                    (int)$cid, $pid,
                ]);
        }
        audit($db, 'save_components', 'grading_policies', $pid);
        flash('Components saved.');
        header('Location: admin.php?page=policies&id=' . $pid);
        exit;
    }
    if ($action == 'policy_save_transmutation') {
        $pid = (int)($_POST['policy_id'] ?? 0);
        $tgs = $_POST['tg'] ?? [];
        foreach ($tgs as $rid => $tg) {
            $tg = (int)$tg;
            if ($tg < 60 || $tg > 100) continue;
            $db->prepare("UPDATE transmutation_rows SET tg=? WHERE id=? AND policy_id=?")->execute([$tg, (int)$rid, $pid]);
        }
        audit($db, 'save_transmutation', 'grading_policies', $pid);
        flash('Transmutation table saved.');
        header('Location: admin.php?page=policies&id=' . $pid);
        exit;
    }
    if ($action == 'policy_save_descriptors') {
        $pid = (int)($_POST['policy_id'] ?? 0);
        $desc = $_POST['desc'] ?? [];
        foreach ($desc as $rid => $d) {
            $db->prepare("UPDATE descriptor_rows SET label_en=?, label_fil=?, min_grade=?, max_grade=? WHERE id=? AND policy_id=?")
                ->execute([trim($d['label_en'] ?? ''), trim($d['label_fil'] ?? ''), (float)($d['min'] ?? 0), (float)($d['max'] ?? 0), (int)$rid, $pid]);
        }
        audit($db, 'save_descriptors', 'grading_policies', $pid);
        flash('Descriptors saved.');
        header('Location: admin.php?page=policies&id=' . $pid);
        exit;
    }
    if ($action == 'policy_delete') {
        $pid = (int)($_POST['policy_id'] ?? 0);
        $in_use = (int)$db->query("SELECT COUNT(*) FROM classes WHERE grading_policy_id=" . $pid)->fetchColumn();
        if ($in_use > 0) { flash('This policy is in use by classes and cannot be deleted.', 'danger'); }
        else {
            $db->prepare("DELETE FROM grading_policies WHERE id=?")->execute([$pid]);
            audit($db, 'delete_policy', 'grading_policies', $pid);
            flash('Policy deleted.');
        }
        header('Location: admin.php?page=policies');
        exit;
    }

    // ---- CALENDAR ----
    if ($action == 'add_event') {
        $title = trim($_POST['title'] ?? '');
        $date = $_POST['event_date'] ?? '';
        if (!$title || !$date) { flash('Title and date are required.', 'danger'); }
        else {
            $db->prepare("INSERT INTO calendar_events (title, event_date, end_date, event_type, term_id, description, created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$title, $date, $_POST['end_date'] ?: null, $_POST['event_type'] ?: 'event', (int)($_POST['term_id'] ?? 0) ?: null, $_POST['description'] ?? '', current_user_id()]);
            audit($db, 'add_event', 'calendar_events', (int)$db->lastInsertId(), null, $title);
            flash('Event added.');
        }
        header('Location: admin.php?page=calendar');
        exit;
    }
    if ($action == 'delete_event') {
        $eid = (int)($_POST['event_id'] ?? 0);
        $db->prepare("DELETE FROM calendar_events WHERE id=?")->execute([$eid]);
        audit($db, 'delete_event', 'calendar_events', $eid);
        flash('Event deleted.');
        header('Location: admin.php?page=calendar');
        exit;
    }

    // ---- SCHOOL ANNOUNCEMENTS ----
    if ($action == 'add_school_ann') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $audience = in_array($_POST['audience'] ?? '', ['all', 'students', 'teachers'], true) ? $_POST['audience'] : 'all';
        if (!$title || !$content) { flash('Title and content are required.', 'danger'); }
        else {
            $db->prepare("INSERT INTO school_announcements (title, content, audience, posted_by) VALUES (?,?,?,?)")
                ->execute([$title, $content, $audience, current_user_id()]);
            audit($db, 'add_school_ann', 'school_announcements', (int)$db->lastInsertId(), null, $title);
            flash('School announcement posted.');
        }
        header('Location: admin.php?page=announcements');
        exit;
    }
    if ($action == 'delete_school_ann') {
        $aid = (int)($_POST['ann_id'] ?? 0);
        $db->prepare("DELETE FROM school_announcements WHERE id=?")->execute([$aid]);
        audit($db, 'delete_school_ann', 'school_announcements', $aid);
        flash('Announcement deleted.');
        header('Location: admin.php?page=announcements');
        exit;
    }

    // ---- SETTINGS ----
    if ($action == 'save_settings') {
        set_setting($db, 'school_name', trim($_POST['school_name'] ?? 'School Portal'));
        set_setting($db, 'registration_mode', in_array($_POST['registration_mode'] ?? '', ['open', 'approval'], true) ? $_POST['registration_mode'] : 'open');
        audit($db, 'save_settings');
        flash('Settings saved.');
        header('Location: admin.php?page=settings');
        exit;
    }

    header('Location: admin.php');
    exit;
}

// ================================================================
// PAGES
// ================================================================
function admin_overview() {
    global $db;
    $teachers = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='teacher' AND status='active'")->fetchColumn();
    $students = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'")->fetchColumn();
    $classes = (int)$db->query("SELECT COUNT(*) FROM classes WHERE status='active'")->fetchColumn();
    $policies = (int)$db->query("SELECT COUNT(*) FROM grading_policies WHERE is_active=1")->fetchColumn();
    $events = (int)$db->query("SELECT COUNT(*) FROM calendar_events WHERE event_date >= date('now')")->fetchColumn();
    $unverified = (int)$db->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn();
    ?>
    <div class="page-header">
        <div>
            <h4 class="page-title">Admin Overview</h4>
            <p class="page-subtitle">School-wide snapshot</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon"><?php echo icon('book', 20); ?></div><p class="stat-value"><?= $teachers ?></p><span class="stat-label">Active Teachers</span></div></div>
        <div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon"><?php echo icon('users', 20); ?></div><p class="stat-value"><?= $students ?></p><span class="stat-label">Active Students</span></div></div>
        <div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon"><?php echo icon('school', 20); ?></div><p class="stat-value"><?= $classes ?></p><span class="stat-label">Active Classes</span></div></div>
        <div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon"><?php echo icon('layers', 20); ?></div><p class="stat-value"><?= $policies ?></p><span class="stat-label">Active Policies</span></div></div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-md-6"><div class="card shadow-sm"><div class="card-header fw-semibold">Upcoming Events (next 30 days)</div><div class="card-body p-0">
            <?php
            $evs = $db->query("SELECT * FROM calendar_events WHERE event_date BETWEEN date('now') AND date('now','+30 days') ORDER BY event_date LIMIT 8");
            $list = $evs->fetchAll(PDO::FETCH_ASSOC);
            if (!$list) { echo '<p class="text-muted small p-3 mb-0">No upcoming events.</p>'; }
            else {
                echo '<ul class="list-group list-group-flush">';
                foreach ($list as $e) {
                    echo '<li class="list-group-item d-flex justify-content-between"><span>' . htmlspecialchars($e['title']) . '</span><small class="text-muted">' . $e['event_date'] . '</small></li>';
                }
                echo '</ul>';
            }
            ?>
        </div></div></div>
        <div class="col-md-6"><div class="card shadow-sm"><div class="card-header fw-semibold">Latest Audit Activity</div><div class="card-body p-0">
            <?php
            $logs = $db->query("SELECT al.*, u.email FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.id DESC LIMIT 8");
            $rows = $logs->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) { echo '<p class="text-muted small p-3 mb-0">No activity yet.</p>'; }
            else {
                echo '<ul class="list-group list-group-flush">';
                foreach ($rows as $r) {
                    echo '<li class="list-group-item d-flex justify-content-between"><span><small>' . htmlspecialchars($r['action']) . '</small> <span class="text-muted">' . htmlspecialchars($r['email'] ?? 'system') . '</span></span><small class="text-muted">' . $r['created_at'] . '</small></li>';
                }
                echo '</ul>';
            }
            ?>
        </div></div></div>
    </div>
    <?php if ($unverified > 0) { ?>
        <div class="alert alert-warning mt-3"><?= $unverified ?> pending account(s) await approval.</div>
    <?php } ?>
<?php }

function admin_users() {
    global $db;
    $role_filter = $_GET['role'] ?? 'all';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title">User Management</h4>
        <form method="GET" class="d-flex gap-1">
            <input type="hidden" name="page" value="users">
            <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $role_filter=='all'?'selected':'' ?>>All roles</option>
                <option value="teacher" <?= $role_filter=='teacher'?'selected':'' ?>>Teachers</option>
                <option value="student" <?= $role_filter=='student'?'selected':'' ?>>Students</option>
            </select>
        </form>
    </div>
    <?php
    $where = $role_filter != 'all' ? "WHERE role='" . $db->quote($role_filter) . "'" : '';
    $users = $db->query("SELECT u.*, COALESCE(t.full_name, s.full_name) AS full_name, COALESCE(t.employee_number, s.student_number) AS number FROM users u LEFT JOIN teachers t ON t.user_id=u.id LEFT JOIN students s ON s.user_id=u.id " . $where . " ORDER BY u.id");
    $list = $users->fetchAll(PDO::FETCH_ASSOC);
    if (!$list) { echo '<div class="alert alert-info">No users found.</div>'; return; }
    ?>
    <div class="table-responsive">
        <table class="table table-hover table-sm">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Admin</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list as $u) {
                $status_color = $u['status'] == 'active' ? 'success' : ($u['status'] == 'pending' ? 'warning' : 'secondary'); ?>
                <tr>
                    <td><?= htmlspecialchars($u['full_name'] ?? '') ?> <small class="text-muted">(<?= htmlspecialchars($u['number'] ?? '-') ?>)</small></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><span class="badge bg-info"><?= htmlspecialchars($u['role']) ?></span></td>
                    <td><span class="badge bg-<?= $status_color ?>"><?= htmlspecialchars($u['status']) ?></span></td>
                    <td><?= $u['is_admin'] ? '<span class="badge bg-dark">Admin</span>' : '<span class="text-muted">-</span>' ?></td>
                    <td class="text-nowrap">
                        <form method="POST" class="d-inline"><input type="hidden" name="action" value="user_toggle_admin"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-dark"><?= $u['is_admin'] ? 'Revoke Admin' : 'Make Admin' ?></button></form>
                        <form method="POST" class="d-inline"><input type="hidden" name="action" value="user_toggle_status"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><?= $u['status'] == 'active' ? 'Disable' : 'Enable' ?></button></form>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#resetModal<?= $u['id'] ?>">Reset Pass</button>
                        <div class="modal fade" id="resetModal<?= $u['id'] ?>"><div class="modal-dialog"><form method="POST" class="modal-content">
                            <input type="hidden" name="action" value="user_reset_password"><input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <div class="modal-header"><h5 class="modal-title">Reset Password for <?= htmlspecialchars($u['email']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body"><label class="small fw-semibold">New Password (min 8 chars)</label><input type="text" name="new_password" class="form-control" required></div>
                            <div class="modal-footer"><button type="submit" class="btn btn-sm btn-brand">Reset</button></div>
                        </form></div></div>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
<?php }

function admin_years() {
    global $db; ?>
    <h4 class="page-title mb-1">Academic Years &amp; Terms</h4>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card shadow-sm"><div class="card-header fw-semibold">Add Academic Year</div><div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_year">
                    <div class="mb-2"><label class="small fw-semibold">Label</label><input type="text" name="label" class="form-control" placeholder="e.g. 2027-2028" required></div>
                    <div class="row g-2 mb-2">
                        <div class="col"><label class="small fw-semibold">Start</label><input type="date" name="start_date" class="form-control"></div>
                        <div class="col"><label class="small fw-semibold">End</label><input type="date" name="end_date" class="form-control"></div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-brand">Add Year</button>
                </form>
            </div></div>
        </div>
        <div class="col-md-8">
            <?php
            $years = $db->query("SELECT * FROM academic_years ORDER BY start_date DESC");
            foreach ($years as $y) {
                echo '<div class="card shadow-sm mb-3"><div class="card-header d-flex justify-content-between align-items-center card-header-soft">';
                echo '<span class="fw-bold">' . htmlspecialchars($y['label']) . ' ' . ($y['is_active'] ? '<span class="badge bg-success">current</span>' : '') . '</span>';
                if (!$y['is_active']) {
                    echo '<form method="POST" class="d-inline"><input type="hidden" name="action" value="set_active_year"><input type="hidden" name="year_id" value="' . $y['id'] . '"><button type="submit" class="btn btn-sm btn-outline-success">Set Active</button></form>';
                }
                echo '</div><div class="card-body">';
                $terms = $db->prepare("SELECT * FROM terms WHERE academic_year_id=? ORDER BY number");
                $terms->execute([$y['id']]);
                $tl = $terms->fetchAll(PDO::FETCH_ASSOC);
                if ($tl) {
                    echo '<table class="table table-sm mb-3"><thead><tr><th>Term</th><th>Dates</th><th>Weight</th><th>Status</th><th></th></tr></thead><tbody>';
                    foreach ($tl as $t) {
                        echo '<tr><td>' . htmlspecialchars($t['label']) . '</td><td><small>' . ($t['start_date'] ?? '-') . ' to ' . ($t['end_date'] ?? '-') . '</small></td>';
                        echo '<td>' . $t['weight'] . '</td><td><span class="badge bg-' . ($t['status'] == 'open' ? 'success' : 'secondary') . '">' . $t['status'] . '</span></td>';
                        echo '<td><form method="POST" class="d-inline"><input type="hidden" name="action" value="toggle_term_status"><input type="hidden" name="term_id" value="' . $t['id'] . '"><button type="submit" class="btn btn-sm btn-outline-secondary">' . ($t['status'] == 'open' ? 'Close' : 'Open') . '</button></form></td></tr>';
                    }
                    echo '</tbody></table>';
                }
                echo '<form method="POST" class="row g-2 align-items-end">';
                echo '<input type="hidden" name="action" value="add_term"><input type="hidden" name="year_id" value="' . $y['id'] . '">';
                echo '<div class="col-auto"><label class="small fw-semibold">New Term</label><input type="text" name="label" class="form-control form-control-sm" placeholder="e.g. Term 4" required></div>';
                echo '<div class="col-auto"><label class="small fw-semibold">Start</label><input type="date" name="start_date" class="form-control form-control-sm"></div>';
                echo '<div class="col-auto"><label class="small fw-semibold">End</label><input type="date" name="end_date" class="form-control form-control-sm"></div>';
                echo '<div class="col-auto"><label class="small fw-semibold">Weight</label><input type="number" name="weight" step="0.01" value="1" class="form-control form-control-sm" style="width:70px;"></div>';
                echo '<div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-primary">Add Term</button></div></form>';
                echo '</div></div>';
            }
            ?>
        </div>
    </div>
<?php }

function admin_policies() {
    global $db;
    $sel = (int)($_GET['id'] ?? 0);
    if (!$sel) {
        $sel = (int)$db->query("SELECT id FROM grading_policies ORDER BY id LIMIT 1")->fetchColumn();
    }
    $policies = $db->query("SELECT * FROM grading_policies ORDER BY id");
    $plist = $policies->fetchAll(PDO::FETCH_ASSOC);
    $policy = null;
    foreach ($plist as $p) { if ($p['id'] == $sel) $policy = $p; }
    ?>
    <h4 class="page-title mb-1">Grading Policies</h4>
    <div class="row g-3">
        <div class="col-md-3">
            <div class="list-group">
                <?php foreach ($plist as $p) {
                    echo '<a href="admin.php?page=policies&id=' . $p['id'] . '" class="list-group-item list-group-item-action ' . ($p['id'] == $sel ? 'active' : '') . '">' . htmlspecialchars($p['name']) . ($p['is_active'] ? '' : ' <span class="badge bg-secondary">off</span>') . '</a>';
                } ?>
            </div>
            <div class="card shadow-sm mt-3"><div class="card-header fw-semibold">New Policy</div><div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_policy">
                    <div class="mb-2"><input type="text" name="name" class="form-control form-control-sm" placeholder="Policy name" required></div>
                    <div class="mb-2"><input type="text" name="source_doc" class="form-control form-control-sm" placeholder="Source (e.g. DO 015 s.2026)"></div>
                    <div class="row g-2 mb-2">
                        <div class="col"><input type="text" name="key_stage" class="form-control form-control-sm" placeholder="Key stage"></div>
                        <div class="col"><input type="text" name="subject_group" class="form-control form-control-sm" placeholder="Subject group"></div>
                    </div>
                    <div class="mb-2">
                        <select name="transmutation_mode" class="form-select form-select-sm">
                            <option value="adjusted_table">Adjusted table (DO 015)</option>
                            <option value="zero_based">Zero-based (no transmutation)</option>
                            <option value="legacy_linear">Legacy linear (DO 8)</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                    <div class="mb-2"><textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Description"></textarea></div>
                    <button type="submit" class="btn btn-sm btn-brand">Create Policy</button>
                </form>
            </div></div>
            <?php if ($policy) { ?>
                <div class="card shadow-sm mt-3"><div class="card-body">
                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="policy_toggle"><input type="hidden" name="policy_id" value="<?= $policy['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-secondary w-100 mb-2"><?= $policy['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
                    <form method="POST" onsubmit="return confirm('Delete this policy permanently?')"><input type="hidden" name="action" value="policy_delete"><input type="hidden" name="policy_id" value="<?= $policy['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete Policy</button></form>
                </div></div>
            <?php } ?>
        </div>
        <div class="col-md-9">
            <?php if (!$policy) { echo '<div class="alert alert-info">Select a policy.</div>'; return; } ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold"><?= htmlspecialchars($policy['name']) ?> — Components</div>
                <div class="card-body">
                    <p class="small text-muted">Weights are the "template" used when a class applies this policy. Exam split (ST1/ST2/TE) is only used when the component has exam-type items. Unrecorded items are excluded (no zero penalty) except in legacy mode.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="policy_save_components">
                        <input type="hidden" name="policy_id" value="<?= $policy['id'] ?>">
                        <table class="table table-sm table-bordered">
                            <thead><tr><th>Name</th><th>Weight %</th><th>ST1 %</th><th>ST2 %</th><th>TE %</th></tr></thead>
                            <tbody>
                            <?php
                            $comps = $db->prepare("SELECT * FROM grading_components WHERE policy_id=? ORDER BY sort_order");
                            $comps->execute([$policy['id']]);
                            foreach ($comps->fetchAll(PDO::FETCH_ASSOC) as $c) {
                                echo '<tr><td><input type="text" name="comp[' . $c['id'] . '][name]" class="form-control form-control-sm" value="' . htmlspecialchars($c['name']) . '"></td>';
                                echo '<td style="width:90px;"><input type="number" step="0.01" name="comp[' . $c['id'] . '][weight]" class="form-control form-control-sm" value="' . $c['weight'] . '"></td>';
                                echo '<td style="width:80px;"><input type="number" step="0.01" name="comp[' . $c['id'] . '][ex_st1]" class="form-control form-control-sm" value="' . htmlspecialchars($c['ex_st1'] ?? '') . '"></td>';
                                echo '<td style="width:80px;"><input type="number" step="0.01" name="comp[' . $c['id'] . '][ex_st2]" class="form-control form-control-sm" value="' . htmlspecialchars($c['ex_st2'] ?? '') . '"></td>';
                                echo '<td style="width:80px;"><input type="number" step="0.01" name="comp[' . $c['id'] . '][ex_te]" class="form-control form-control-sm" value="' . htmlspecialchars($c['ex_te'] ?? '') . '"></td></tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                        <button type="submit" class="btn btn-sm btn-brand">Save Components</button>
                    </form>
                </div>
            </div>
            <?php if ($policy['transmutation_mode'] == 'adjusted_table' || $policy['transmutation_mode'] == 'legacy_linear') { ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold">Transmutation Table</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="policy_save_transmutation">
                        <input type="hidden" name="policy_id" value="<?= $policy['id'] ?>">
                        <div class="table-responsive" style="max-height:320px;overflow-y:auto;">
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>IG range</th><th style="width:90px;">TG</th></tr></thead>
                                <tbody>
                                <?php
                                $rows = $db->prepare("SELECT * FROM transmutation_rows WHERE policy_id=? ORDER BY min_ig DESC");
                                $rows->execute([$policy['id']]);
                                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                                    echo '<tr><td><small>' . number_format((float)$r['min_ig'], 2) . ' – ' . number_format((float)$r['max_ig'], 2) . '</small></td>';
                                    echo '<td><input type="number" min="60" max="100" name="tg[' . $r['id'] . ']" class="form-control form-control-sm" value="' . $r['tg'] . '"></td></tr>';
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-sm mt-2 btn-brand">Save Table</button>
                    </form>
                </div>
            </div>
            <?php } ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold">Descriptors</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="policy_save_descriptors">
                        <input type="hidden" name="policy_id" value="<?= $policy['id'] ?>">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>Min</th><th>Max</th><th>English label</th><th>Filipino label</th></tr></thead>
                                <tbody>
                                <?php
                                $desc = $db->prepare("SELECT * FROM descriptor_rows WHERE policy_id=? ORDER BY min_grade DESC");
                                $desc->execute([$policy['id']]);
                                foreach ($desc->fetchAll(PDO::FETCH_ASSOC) as $d) {
                                    echo '<tr><td style="width:80px;"><input type="number" step="0.01" name="desc[' . $d['id'] . '][min]" class="form-control form-control-sm" value="' . $d['min_grade'] . '"></td>';
                                    echo '<td style="width:80px;"><input type="number" step="0.01" name="desc[' . $d['id'] . '][max]" class="form-control form-control-sm" value="' . $d['max_grade'] . '"></td>';
                                    echo '<td><input type="text" name="desc[' . $d['id'] . '][label_en]" class="form-control form-control-sm" value="' . htmlspecialchars($d['label_en']) . '"></td>';
                                    echo '<td><input type="text" name="desc[' . $d['id'] . '][label_fil]" class="form-control form-control-sm" value="' . htmlspecialchars($d['label_fil'] ?? '') . '"></td></tr>';
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-sm btn-brand">Save Descriptors</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php }

function admin_calendar() {
    global $db; ?>
    <h4 class="page-title mb-1">School Calendar</h4>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card shadow-sm"><div class="card-header fw-semibold">Add Event</div><div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_event">
                    <div class="mb-2"><label class="small fw-semibold">Title</label><input type="text" name="title" class="form-control" required></div>
                    <div class="row g-2 mb-2">
                        <div class="col"><label class="small fw-semibold">Date</label><input type="date" name="event_date" class="form-control" required></div>
                        <div class="col"><label class="small fw-semibold">End (optional)</label><input type="date" name="end_date" class="form-control"></div>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-semibold">Type</label>
                        <select name="event_type" class="form-select">
                            <option value="event">Event</option>
                            <option value="holiday">Holiday</option>
                            <option value="exam">Exam</option>
                            <option value="deadline">Deadline</option>
                            <option value="term_start">Term Start</option>
                            <option value="term_end">Term End</option>
                            <option value="suspension">Suspension</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-semibold">Term (optional)</label>
                        <select name="term_id" class="form-select">
                            <option value="">None</option>
                            <?php
                            $terms = $db->query("SELECT t.*, y.label AS yl FROM terms t JOIN academic_years y ON y.id=t.academic_year_id ORDER BY t.academic_year_id DESC, t.number");
                            foreach ($terms as $t) echo '<option value="' . $t['id'] . '">' . htmlspecialchars($t['yl']) . ' - ' . htmlspecialchars($t['label']) . '</option>';
                            ?>
                        </select>
                    </div>
                    <div class="mb-2"><label class="small fw-semibold">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                    <button type="submit" class="btn btn-sm btn-brand">Add Event</button>
                </form>
            </div></div>
        </div>
        <div class="col-md-8">
            <?php
            $month = $_GET['month'] ?? date('Y-m');
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            $evs = $db->prepare("SELECT * FROM calendar_events WHERE event_date BETWEEN ? AND ? ORDER BY event_date");
            $evs->execute([$start, $end]);
            $list = $evs->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center">
                <form method="GET" class="d-flex gap-1">
                    <input type="hidden" name="page" value="calendar">
                    <input type="month" name="month" class="form-control form-control-sm" value="<?= $month ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Go</button>
                </form>
            </div><div class="card-body p-0">
            <?php if (!$list) { echo '<p class="text-muted small p-3 mb-0">No events this month.</p>'; }
            else {
                echo '<ul class="list-group list-group-flush">';
                foreach ($list as $e) {
                    echo '<li class="list-group-item d-flex justify-content-between align-items-center"><span><span class="badge bg-' . ($e['event_type'] == 'holiday' ? 'danger' : ($e['event_type'] == 'exam' ? 'warning' : 'info')) . '">' . htmlspecialchars($e['event_type']) . '</span> <strong>' . htmlspecialchars($e['title']) . '</strong> <small class="text-muted">' . $e['event_date'] . ($e['end_date'] ? ' → ' . $e['end_date'] : '') . '</small></span>';
                    echo '<form method="POST" class="d-inline"><input type="hidden" name="action" value="delete_event"><input type="hidden" name="event_id" value="' . $e['id'] . '"><button type="submit" class="btn btn-sm btn-outline-danger">&times;</button></form></li>';
                }
                echo '</ul>';
            }
            ?>
            </div></div>
        </div>
    </div>
<?php }

function admin_announcements() {
    global $db; ?>
    <h4 class="page-title mb-1">School Announcements</h4>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card shadow-sm"><div class="card-header fw-semibold">Post Announcement</div><div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_school_ann">
                    <div class="mb-2"><input type="text" name="title" class="form-control" placeholder="Title" required></div>
                    <div class="mb-2"><textarea name="content" class="form-control" rows="4" placeholder="Content" required></textarea></div>
                    <div class="mb-2">
                        <select name="audience" class="form-select">
                            <option value="all">Everyone</option>
                            <option value="students">Students only</option>
                            <option value="teachers">Teachers only</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-brand">Post</button>
                </form>
            </div></div>
        </div>
        <div class="col-md-8">
            <?php
            $anns = $db->query("SELECT sa.*, u.email FROM school_announcements sa LEFT JOIN users u ON u.id=sa.posted_by ORDER BY sa.id DESC");
            $list = $anns->fetchAll(PDO::FETCH_ASSOC);
            if (!$list) { echo '<div class="alert alert-info">No announcements yet.</div>'; }
            else {
                echo '<div class="list-group">';
                foreach ($list as $a) {
                    echo '<div class="list-group-item"><div class="d-flex justify-content-between"><div><div class="fw-semibold">' . htmlspecialchars($a['title']) . ' <span class="badge bg-secondary">' . htmlspecialchars($a['audience']) . '</span></div>';
                    echo '<small>' . nl2br(htmlspecialchars($a['content'])) . '</small><br><small class="text-muted">' . htmlspecialchars($a['email'] ?? 'system') . ' | ' . $a['posted_at'] . '</small></div>';
                    echo '<form method="POST" class="d-inline"><input type="hidden" name="action" value="delete_school_ann"><input type="hidden" name="ann_id" value="' . $a['id'] . '"><button type="submit" class="btn btn-sm btn-outline-danger">&times;</button></form></div></div>';
                }
                echo '</div>';
            }
            ?>
        </div>
    </div>
<?php }

function admin_audit() {
    global $db; ?>
    <h4 class="page-title mb-1">Audit Log</h4>
    <?php
    $limit = min(500, max(20, (int)($_GET['limit'] ?? 100)));
    $q = $db->prepare("SELECT al.*, u.email FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.id DESC LIMIT " . $limit);
    $q->execute();
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo '<div class="alert alert-info">No audit records yet.</div>'; return; }
    ?>
    <div class="table-responsive">
        <table class="table table-sm table-striped">
            <thead><tr><th>#</th><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>Old → New</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r) { ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><small><?= $r['created_at'] ?></small></td>
                    <td><small><?= htmlspecialchars($r['email'] ?? 'system') ?></small></td>
                    <td><code><?= htmlspecialchars($r['action']) ?></code></td>
                    <td><small><?= htmlspecialchars($r['target_type'] ?? '') ?> <?= $r['target_id'] ? '#' . $r['target_id'] : '' ?></small></td>
                    <td><small><?= htmlspecialchars($r['old_value'] ?? '') ?><?= $r['old_value'] !== null && $r['new_value'] !== null ? ' → ' : '' ?><?= htmlspecialchars($r['new_value'] ?? '') ?></small></td>
                    <td><small><?= htmlspecialchars($r['ip'] ?? '') ?></small></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
<?php }

function admin_settings() {
    global $db; ?>
    <h4 class="page-title mb-1">System Settings</h4>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm"><div class="card-header fw-semibold">General</div><div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_settings">
                    <div class="mb-2"><label class="small fw-semibold">School Name</label><input type="text" name="school_name" class="form-control" value="<?= htmlspecialchars(setting($db, 'school_name', 'School Portal')) ?>"></div>
                    <div class="mb-3">
                        <label class="small fw-semibold">Registration Mode</label>
                        <select name="registration_mode" class="form-select">
                            <option value="open" <?= setting($db, 'registration_mode', 'open') == 'open' ? 'selected' : '' ?>>Open (anyone can sign up)</option>
                            <option value="approval" <?= setting($db, 'registration_mode', 'open') == 'approval' ? 'selected' : '' ?>>Approval required (admin enables account)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-brand">Save Settings</button>
                </form>
            </div></div>
        </div>
    </div>
<?php }