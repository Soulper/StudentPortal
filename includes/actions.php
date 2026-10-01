<?php
// ================================================================
// POST ACTION DISPATCHER (dashboard.php)
// All actions verify CSRF + role before doing anything.
// ================================================================
function handle_post_actions($db, $role, $user_id) {
    $action = $_POST['action'] ?? '';

    // -- CSRF PROTECTION --
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
        flash('Your session expired. Please try again.', 'danger');
        header('Location: dashboard.php');
        exit;
    }

    // ================= TEACHER ACTIONS =================
    if ($role == 'teacher') {

        // DOWNLOAD ALL SUBMISSIONS (ZIP) - streams a file, so handled before the redirect flow
        if ($action == 'download_all_submissions') {
            $assignment_id = (int)($_POST['assignment_id'] ?? 0);
            $chk = $db->prepare("SELECT a.id, a.title, a.class_id FROM assignments a WHERE a.id=?");
            $chk->execute([$assignment_id]);
            $a = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$a || !teacher_owns_class($db, $user_id, $a['class_id'])) {
                flash('Unauthorized.', 'danger');
                header('Location: dashboard.php');
                exit;
            }
            $subs = $db->prepare("SELECT sub.file_url, sub.original_name, s.full_name, sub.is_late FROM submissions sub JOIN students s ON s.user_id = sub.student_id WHERE sub.assignment_id=? AND sub.file_url IS NOT NULL AND sub.file_url != '' ORDER BY s.full_name");
            $subs->execute([$assignment_id]);
            $rows = $subs->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                flash('No submitted files to download.', 'danger');
                header('Location: dashboard.php?page=submissions&id=' . $assignment_id);
                exit;
            }
            $zip_name = preg_replace('/[^A-Za-z0-9 _-]/', '', $a['title']);
            $zip_name = trim($zip_name) !== '' ? $zip_name : 'assignment_' . $assignment_id;
            $tmp = tempnam(sys_get_temp_dir(), 'subs_');
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                flash('Could not create the ZIP archive.', 'danger');
                header('Location: dashboard.php?page=submissions&id=' . $assignment_id);
                exit;
            }
            foreach ($rows as $r) {
                $src = __DIR__ . '/../uploads/' . $r['file_url'];
                if (!is_file($src)) continue;
                $orig = $r['original_name'] !== '' && $r['original_name'] !== null ? $r['original_name'] : $r['file_url'];
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $safe = preg_replace('/[^\w.\- ]+/u', '_', $orig);
                $safe = $safe !== '' ? $safe : 'submission';
                if ($ext === '' && strpos($safe, '.') === false) $safe .= '.file';
                $zip->addFile($src, $r['full_name'] . ($r['is_late'] ? ' (LATE)' : '') . ' - ' . $safe);
            }
            $zip->close();
            if (filesize($tmp) === 0) {
                @unlink($tmp);
                flash('No readable files found to download.', 'danger');
                header('Location: dashboard.php?page=submissions&id=' . $assignment_id);
                exit;
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_name . '_submissions.zip"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: private, no-store');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }

        // CREATE CLASS
        if ($action == 'create_class') {
            $subject = trim($_POST['subject'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $school_year = trim($_POST['school_year'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $policy_id = (int)($_POST['grading_policy_id'] ?? 0);
            $year_id = (int)($_POST['academic_year_id'] ?? 0);
            $join_code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

            $s = $db->prepare("INSERT INTO classes (teacher_id, subject, section, school_year, description, join_code, grading_policy_id, academic_year_id, current_term_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $first_term = 0;
            if ($year_id) {
                $t = $db->prepare("SELECT id FROM terms WHERE academic_year_id=? ORDER BY number LIMIT 1");
                $t->execute([$year_id]);
                $first_term = (int)$t->fetchColumn();
            }
            $s->execute([$user_id, $subject, $section, $school_year, $description, $join_code, $policy_id ?: null, $year_id ?: null, $first_term ?: null]);
            $cid = (int)$db->lastInsertId();

            // Auto-create categories from the chosen policy template
            if ($policy_id) {
                $comps = $db->prepare("SELECT * FROM grading_components WHERE policy_id=? ORDER BY sort_order");
                $comps->execute([$policy_id]);
                foreach ($comps->fetchAll(PDO::FETCH_ASSOC) as $comp) {
                    $db->prepare("INSERT INTO grade_categories (class_id, name, weight, term_id) VALUES (?,?,?,?)")
                        ->execute([$cid, $comp['name'], $comp['weight'], $first_term]);
                }
            }
            audit($db, 'create_class', 'classes', $cid, null, "$subject - $section");
            flash('Class created successfully! Join code: ' . $join_code);
            header('Location: dashboard.php');
            exit;
        }

        // UPDATE CLASS
        if ($action == 'update_class') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $s = $db->prepare("UPDATE classes SET subject=?, section=?, school_year=?, description=? WHERE id=? AND teacher_id=?");
            $s->execute([$_POST['subject'], $_POST['section'], $_POST['school_year'], $_POST['description'], $class_id, $user_id]);
            audit($db, 'update_class', 'classes', $class_id);
            flash('Class updated.');
            header("Location: dashboard.php?page=settings&id=$class_id");
            exit;
        }

        // ARCHIVE / RESTORE / DELETE CLASS
        if ($action == 'archive_class') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $db->prepare("UPDATE classes SET status='archived' WHERE id=? AND teacher_id=?")->execute([$class_id, $user_id]);
            audit($db, 'archive_class', 'classes', $class_id);
            flash('Class archived.');
            header('Location: dashboard.php');
            exit;
        }
        if ($action == 'restore_class') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $db->prepare("UPDATE classes SET status='active' WHERE id=? AND teacher_id=?")->execute([$class_id, $user_id]);
            flash('Class restored.');
            header('Location: dashboard.php');
            exit;
        }
        if ($action == 'delete_class') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $db->prepare("DELETE FROM classes WHERE id=? AND teacher_id=?")->execute([$class_id, $user_id]);
            audit($db, 'delete_class', 'classes', $class_id);
            flash('Class deleted.');
            header('Location: dashboard.php');
            exit;
        }

        // JOIN CODE
        if ($action == 'disable_join') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $db->prepare("UPDATE classes SET join_code=NULL WHERE id=? AND teacher_id=?")->execute([$class_id, $user_id]);
            flash('Join code disabled.');
            header("Location: dashboard.php?page=settings&id=$class_id");
            exit;
        }
        if ($action == 'new_code') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $db->prepare("UPDATE classes SET join_code=? WHERE id=? AND teacher_id=?")->execute([$code, $class_id, $user_id]);
            flash("New join code: $code");
            header("Location: dashboard.php?page=settings&id=$class_id");
            exit;
        }

        // GRADING SYSTEM (legacy toggle) — maps to a policy
        if ($action == 'set_grading') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $grading = $_POST['grading_system'] ?? 'standard';
            $db->prepare("UPDATE classes SET grading_system=? WHERE id=? AND teacher_id=?")->execute([$grading, $class_id, $user_id]);
            if ($grading == 'deped') {
                $year = $db->prepare("SELECT school_year FROM classes WHERE id=?");
                $year->execute([$class_id]);
                $sy = $year->fetchColumn();
                $pid = (int)$db->query("SELECT id FROM grading_policies WHERE transmutation_mode='adjusted_table' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
                if (!$pid) $pid = (int)$db->query("SELECT id FROM grading_policies WHERE transmutation_mode='legacy_linear' ORDER BY id LIMIT 1")->fetchColumn();
                $db->prepare("UPDATE classes SET grading_policy_id=? WHERE id=? AND teacher_id=?")->execute([$pid, $class_id, $user_id]);
            } else {
                $pid = (int)$db->query("SELECT id FROM grading_policies WHERE transmutation_mode='none' ORDER BY id LIMIT 1")->fetchColumn();
                $db->prepare("UPDATE classes SET grading_policy_id=? WHERE id=? AND teacher_id=?")->execute([$pid, $class_id, $user_id]);
            }
            audit($db, 'set_grading', 'classes', $class_id);
            flash('Grading system updated.');
            header("Location: dashboard.php?page=settings&id=$class_id");
            exit;
        }

        // SET POLICY (new)
        if ($action == 'set_policy') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $pid = (int)($_POST['grading_policy_id'] ?? 0);
            $db->prepare("UPDATE classes SET grading_policy_id=? WHERE id=? AND teacher_id=?")->execute([$pid ?: null, $class_id, $user_id]);
            audit($db, 'set_policy', 'classes', $class_id, null, (string)$pid);
            flash('Grading policy updated.');
            header("Location: dashboard.php?page=settings&id=$class_id");
            exit;
        }

        // SET CURRENT TERM (new)
        if ($action == 'set_term') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $term_id = (int)($_POST['term_id'] ?? 0);
            $db->prepare("UPDATE classes SET current_term_id=? WHERE id=? AND teacher_id=?")->execute([$term_id ?: null, $class_id, $user_id]);
            audit($db, 'set_term', 'classes', $class_id, null, (string)$term_id);
            flash('Active term updated.');
            header("Location: dashboard.php?page=grades&id=$class_id");
            exit;
        }

        // ADD / DELETE CATEGORY
        if ($action == 'add_category') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $name = trim($_POST['name'] ?? '');
            $weight = (float)($_POST['weight'] ?? 0);
            $term_id = (int)($_POST['term_id'] ?? 0);
            if (!$term_id) { $term_id = (int)$db->query("SELECT current_term_id FROM classes WHERE id=" . (int)$class_id)->fetchColumn(); }
            $s = $db->prepare("INSERT INTO grade_categories (class_id, name, weight, term_id) VALUES (?, ?, ?, ?)");
            $s->execute([$class_id, $name, $weight, $term_id]);
            audit($db, 'add_category', 'grade_categories', (int)$db->lastInsertId(), null, "$name ($weight%)");
            flash('Category added.');
            header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
            exit;
        }
        if ($action == 'delete_category') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $cat_id = (int)($_POST['category_id'] ?? 0);
            $term_id = (int)($_POST['term_id'] ?? 0);
            $db->prepare("DELETE FROM grade_categories WHERE id=? AND class_id=?")->execute([$cat_id, $class_id]);
            audit($db, 'delete_category', 'grade_categories', $cat_id);
            flash('Category deleted.');
            header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
            exit;
        }

        // ADD / DELETE ITEM
        if ($action == 'add_item') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $cat_id = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $max_score = (float)($_POST['max_score'] ?? 0);
            $ex_group = in_array($_POST['ex_group'] ?? '', ['st1', 'st2', 'te'], true) ? $_POST['ex_group'] : null;
            $term_id = (int)$db->query("SELECT term_id FROM grade_categories WHERE id=" . (int)$cat_id)->fetchColumn();
            $s = $db->prepare("INSERT INTO grade_items (category_id, class_id, name, max_score, term_id, ex_group) VALUES (?, ?, ?, ?, ?, ?)");
            $s->execute([$cat_id, $class_id, $name, $max_score, $term_id, $ex_group]);
            audit($db, 'add_item', 'grade_items', (int)$db->lastInsertId(), null, "$name (max $max_score)");
            flash('Item added.');
            header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
            exit;
        }
        if ($action == 'delete_item') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $item_id = (int)($_POST['item_id'] ?? 0);
            $term_id = (int)$db->query("SELECT term_id FROM grade_items WHERE id=" . (int)$item_id)->fetchColumn();
            $db->prepare("DELETE FROM grade_items WHERE id=? AND class_id=?")->execute([$item_id, $class_id]);
            audit($db, 'delete_item', 'grade_items', $item_id);
            flash('Item deleted.');
            header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
            exit;
        }

        // SAVE SCORES (single item, class-wide) — term-aware
        if ($action == 'save_scores') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $item_id = (int)($_POST['item_id'] ?? 0);
            $mx = $db->prepare("SELECT max_score, term_id FROM grade_items WHERE id=?");
            $mx->execute([$item_id]);
            $row = $mx->fetch(PDO::FETCH_ASSOC);
            if (!$row) { flash('Item not found.', 'danger'); header('Location: dashboard.php'); exit; }
            $max_score = (float)$row['max_score'];
            $term_id = (int)$row['term_id'];
            $student_ids = $_POST['student_id'] ?? [];
            if (!is_array($student_ids)) $student_ids = [$student_ids];
            $changed = 0;
            foreach ($student_ids as $sid) {
                $score_val = $_POST["score_$sid"] ?? '';
                if ($score_val === '' || $score_val === null) {
                    $db->prepare("DELETE FROM grade_scores WHERE grade_item_id=? AND student_id=?")->execute([$item_id, $sid]);
                } else {
                    $score_val = (float)$score_val;
                    if ($score_val > $max_score) { flash('Score cannot exceed the max score.', 'danger'); header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id"); exit; }
                    $exists = $db->prepare("SELECT COUNT(*) FROM grade_scores WHERE grade_item_id=? AND student_id=?");
                    $exists->execute([$item_id, $sid]);
                    if ($exists->fetchColumn() > 0) {
                        $db->prepare("UPDATE grade_scores SET score=?, term_id=? WHERE grade_item_id=? AND student_id=?")->execute([$score_val, $term_id, $item_id, $sid]);
                    } else {
                        $db->prepare("INSERT INTO grade_scores (grade_item_id, student_id, score, term_id) VALUES (?, ?, ?, ?)")->execute([$item_id, $sid, $score_val, $term_id]);
                    }
                    $changed++;
                }
            }
            if ($changed) audit($db, 'save_scores', 'grade_items', $item_id, null, "$changed score(s)");
            flash('Scores saved.');
            header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
            exit;
        }

        // SAVE STUDENT SCORES (all items for one student) — term-aware
        if ($action == 'save_student_scores') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $student_id = (int)($_POST['student_id'] ?? 0);
            $scores = $_POST['scores'] ?? [];
            $max_map = [];
            $term_map = [];
            $mx = $db->prepare("SELECT id, max_score, term_id FROM grade_items WHERE class_id=?");
            $mx->execute([$class_id]);
            foreach ($mx->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $max_map[(int)$m['id']] = (float)$m['max_score'];
                $term_map[(int)$m['id']] = (int)$m['term_id'];
            }
            foreach ($scores as $item_id => $score_val) {
                $item_id = (int)$item_id;
                $term_id = $term_map[$item_id] ?? 0;
                if ($score_val === '' || $score_val === null) {
                    $db->prepare("DELETE FROM grade_scores WHERE grade_item_id=? AND student_id=?")->execute([$item_id, $student_id]);
                } else {
                    $score_val = (float)$score_val;
                    if (isset($max_map[$item_id]) && $score_val > $max_map[$item_id]) { flash('Score cannot exceed the max score.', 'danger'); header("Location: dashboard.php?page=grades&id=$class_id&sid=$student_id"); exit; }
                    $exists = $db->prepare("SELECT COUNT(*) FROM grade_scores WHERE grade_item_id=? AND student_id=?");
                    $exists->execute([$item_id, $student_id]);
                    if ($exists->fetchColumn() > 0) {
                        $db->prepare("UPDATE grade_scores SET score=?, term_id=? WHERE grade_item_id=? AND student_id=?")->execute([$score_val, $term_id, $item_id, $student_id]);
                    } else {
                        $db->prepare("INSERT INTO grade_scores (grade_item_id, student_id, score, term_id) VALUES (?, ?, ?, ?)")->execute([$item_id, $student_id, $score_val, $term_id]);
                    }
                }
            }
            audit($db, 'save_student_scores', 'classes', $class_id, null, 'student ' . $student_id);
            flash('Scores saved.');
            header("Location: dashboard.php?page=grades&id=$class_id&sid=$student_id");
            exit;
        }

        // SAVE GRADEBOOK GRID (all students x all items at once) — term-aware
        if ($action == 'save_grid_scores') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $term_id = (int)($_POST['term_id'] ?? 0);
            $scores = $_POST['scores'] ?? [];
            if (!is_array($scores) || !$scores) { flash('No scores to save.', 'warning'); header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id"); exit; }

            // Valid items for this class only (id => [max, term])
            $items_ok = [];
            $mx = $db->prepare("SELECT id, max_score, term_id FROM grade_items WHERE class_id=?");
            $mx->execute([$class_id]);
            foreach ($mx->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $items_ok[(int)$m['id']] = ['max' => (float)$m['max_score'], 'term' => (int)$m['term_id']];
            }

            // Students must be members of this class
            $in_class = [];
            $cm = $db->prepare("SELECT student_id FROM class_members WHERE class_id=?");
            $cm->execute([$class_id]);
            foreach ($cm->fetchAll(PDO::FETCH_ASSOC) as $r) $in_class[(int)$r['student_id']] = true;

            $exists_stmt = $db->prepare("SELECT COUNT(*) FROM grade_scores WHERE grade_item_id=? AND student_id=?");
            $del_stmt = $db->prepare("DELETE FROM grade_scores WHERE grade_item_id=? AND student_id=?");
            $upd_stmt = $db->prepare("UPDATE grade_scores SET score=?, term_id=? WHERE grade_item_id=? AND student_id=?");
            $ins_stmt = $db->prepare("INSERT INTO grade_scores (grade_item_id, student_id, score, term_id) VALUES (?, ?, ?, ?)");

            $changed = 0; $skipped = 0;
            foreach ($scores as $item_id => $row) {
                $item_id = (int)$item_id;
                if (!isset($items_ok[$item_id]) || !is_array($row)) continue;
                $imax = $items_ok[$item_id]['max'];
                $iterm = $items_ok[$item_id]['term'];
                foreach ($row as $sid => $score_val) {
                    $sid = (int)$sid;
                    if (!isset($in_class[$sid])) continue;
                    if ($score_val === '' || $score_val === null) {
                        $exists_stmt->execute([$item_id, $sid]);
                        if ($exists_stmt->fetchColumn() > 0) { $del_stmt->execute([$item_id, $sid]); $changed++; }
                        continue;
                    }
                    $v = (float)$score_val;
                    if ($v < 0 || $v > $imax) { $skipped++; continue; }
                    $exists_stmt->execute([$item_id, $sid]);
                    if ($exists_stmt->fetchColumn() > 0) {
                        $upd_stmt->execute([$v, $iterm, $item_id, $sid]);
                    } else {
                        $ins_stmt->execute([$item_id, $sid, $v, $iterm]);
                    }
                    $changed++;
                }
            }
            audit($db, 'save_grid_scores', 'classes', $class_id, null, "$changed cell(s)");
            if ($skipped) {
                flash("$changed score(s) saved. $skipped cell(s) rejected because the value is outside 0 to the activity maximum.", 'warning');
            } else {
                flash("$changed score(s) saved.");
            }
            header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
            exit;
        }

        // SAVE GRADES (legacy report-card style, category_scores) — kept for compatibility
        if ($action == 'save_grades') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $scores = $_POST['score'] ?? [];
            foreach ($scores as $cat_id => $students) {
                foreach ($students as $sid => $val) {
                    if ($val === '' || $val === null) {
                        $db->prepare("DELETE FROM category_scores WHERE category_id=? AND student_id=?")->execute([$cat_id, $sid]);
                    } else {
                        $val = (float)$val;
                        if ($val > 100) { flash('Category score cannot exceed 100.', 'danger'); header("Location: dashboard.php?page=grades&id=$class_id"); exit; }
                        $exists = $db->prepare("SELECT COUNT(*) FROM category_scores WHERE category_id=? AND student_id=?");
                        $exists->execute([$cat_id, $sid]);
                        if ($exists->fetchColumn() > 0) {
                            $db->prepare("UPDATE category_scores SET score=? WHERE category_id=? AND student_id=?")->execute([$val, $cat_id, $sid]);
                        } else {
                            $db->prepare("INSERT INTO category_scores (category_id, student_id, score) VALUES (?, ?, ?)")->execute([$cat_id, $sid, $val]);
                        }
                    }
                }
            }
            flash('Grades saved.');
            header("Location: dashboard.php?page=grades&id=$class_id");
            exit;
        }

        // PUBLISH / UNPUBLISH TERM (new)
        if ($action == 'publish_term') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $term_id = (int)($_POST['term_id'] ?? 0);
            $db->prepare("INSERT OR IGNORE INTO class_term_status (class_id, term_id) VALUES (?, ?)")->execute([$class_id, $term_id]);
            audit($db, 'publish_term', 'classes', $class_id, null, "term $term_id");
            notify_class_students($db, $class_id, "Term grades have been published.", "dashboard.php?page=grades");
            flash('Term grade published. Students can now see it.');
            header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
            exit;
        }
        if ($action == 'unpublish_term') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $term_id = (int)($_POST['term_id'] ?? 0);
            $db->prepare("DELETE FROM class_term_status WHERE class_id=? AND term_id=?")->execute([$class_id, $term_id]);
            audit($db, 'unpublish_term', 'classes', $class_id, null, "term $term_id");
            flash('Term grade unpublished.');
            header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
            exit;
        }

        // RESET TO STANDARD (legacy)
        if ($action == 'reset_standard') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $db->prepare("UPDATE classes SET grading_system='standard' WHERE id=? AND teacher_id=?")->execute([$class_id, $user_id]);
            flash('Switched to standard grading.');
            header("Location: dashboard.php?page=grades&id=$class_id");
            exit;
        }

        // CREATE ASSIGNMENT
        if ($action == 'create_assignment') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $due_date = $_POST['due_date'] ?? '';
            $requires_file = isset($_POST['requires_file']) ? 1 : 0;
            $allowed_exts = strtolower(trim((string)($_POST['allowed_exts'] ?? '')));
            $allowed_exts = preg_replace('/\s+/', '', $allowed_exts);
            $max_size_mb = max(0.1, min(25, (float)($_POST['max_size_mb'] ?? 10)));
            $allow_late = isset($_POST['allow_late']) ? 1 : 0;
            $cutoff_date = trim($_POST['cutoff_date'] ?? '') ?: null;
            if (!$allow_late && $cutoff_date && $due_date && $cutoff_date < $due_date) {
                flash('Cutoff date must be on or after the due date.', 'danger');
                header("Location: dashboard.php?page=class&id=$class_id");
                exit;
            }
            $up = handle_upload($_FILES['attachment'] ?? null, 'assign_' . $class_id);
            if ($up['error']) { flash($up['error'], 'danger'); header("Location: dashboard.php?page=class&id=$class_id"); exit; }
            $attachment = $up['file'];
            $s = $db->prepare("INSERT INTO assignments (class_id, title, description, due_date, attachment, requires_file, allowed_exts, max_size_mb, allow_late, cutoff_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $s->execute([$class_id, $title, $description, $due_date, $attachment, $requires_file, $allowed_exts !== '' ? $allowed_exts : null, $max_size_mb, $allow_late, $cutoff_date]);
            notify_class_students($db, $class_id, "New assignment: $title", 'dashboard.php?page=assignments');
            flash('Assignment created.');
            header("Location: dashboard.php?page=class&id=$class_id");
            exit;
        }

        // GRADE SUBMISSION
        if ($action == 'grade_submission') {
            $sub_id = (int)($_POST['submission_id'] ?? 0);
            $score = (float)($_POST['score'] ?? 0);
            $feedback = trim($_POST['feedback'] ?? '');
            $chk = $db->prepare("SELECT a.id AS assignment_id, a.class_id FROM submissions s JOIN assignments a ON s.assignment_id=a.id WHERE s.id=?");
            $chk->execute([$sub_id]);
            $info_c = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$info_c || !teacher_owns_class($db, $user_id, $info_c['class_id'])) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $s = $db->prepare("UPDATE submissions SET score=?, feedback=? WHERE id=?");
            $s->execute([$score, $feedback, $sub_id]);
            $sub = $db->prepare("SELECT s.student_id, a.title FROM submissions s JOIN assignments a ON s.assignment_id = a.id WHERE s.id=?");
            $sub->execute([$sub_id]);
            $info = $sub->fetch(PDO::FETCH_ASSOC);
            if ($info) {
                notify($db, $info['student_id'], "Assignment graded: {$info['title']}", 'dashboard.php?page=grades', 'grade');
            }
            audit($db, 'grade_submission', 'submissions', $sub_id, null, (string)$score);
            flash('Submission graded.');
            header("Location: dashboard.php?page=submissions&id=" . $info_c['assignment_id']);
            exit;
        }

        // MARK ATTENDANCE (notify only on new records)
        if ($action == 'mark_attendance') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $date = $_POST['date'] ?? date('Y-m-d');
            $student_ids = $_POST['student_id'] ?? [];
            if (!is_array($student_ids)) $student_ids = [$student_ids];
            $subj = $db->prepare("SELECT subject FROM classes WHERE id=?");
            $subj->execute([$class_id]);
            $subject_name = $subj->fetchColumn();
            $new_records = 0;
            foreach ($student_ids as $sid) {
                $status = $_POST["status_$sid"] ?? 'absent';
                if (!in_array($status, ['present', 'late', 'absent', 'excused'], true)) $status = 'absent';
                $existing = $db->prepare("SELECT COUNT(*) FROM attendance WHERE class_id=? AND student_id=? AND date=?");
                $existing->execute([$class_id, $sid, $date]);
                if ($existing->fetchColumn() > 0) {
                    $db->prepare("UPDATE attendance SET status=? WHERE class_id=? AND student_id=? AND date=?")->execute([$status, $class_id, $sid, $date]);
                } else {
                    $db->prepare("INSERT INTO attendance (class_id, student_id, date, status) VALUES (?, ?, ?, ?)")->execute([$class_id, $sid, $date, $status]);
                    $new_records++;
                }
            }
            if ($new_records > 0) {
                notify_class_students($db, $class_id, "Attendance recorded for $subject_name", 'dashboard.php?page=attendance', 'attendance');
            }
            audit($db, 'mark_attendance', 'classes', $class_id, null, "$date ($new_records new)");
            flash('Attendance recorded.');
            header("Location: dashboard.php?page=attendance&id=$class_id");
            exit;
        }

        // CREATE ANNOUNCEMENT
        if ($action == 'create_announcement') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $up = handle_upload($_FILES['attachment'] ?? null, 'ann_' . $class_id);
            if ($up['error']) { flash($up['error'], 'danger'); header("Location: dashboard.php?page=class&id=$class_id"); exit; }
            $attachment = $up['file'];
            $s = $db->prepare("INSERT INTO announcements (class_id, title, content, attachment) VALUES (?, ?, ?, ?)");
            $s->execute([$class_id, $title, $content, $attachment]);
            $subj_name = $db->prepare("SELECT subject FROM classes WHERE id=?");
            $subj_name->execute([$class_id]);
            $sub = $subj_name->fetchColumn();
            notify_class_students($db, $class_id, "New announcement in $sub: $title", "dashboard.php?page=class&id=$class_id", 'announcement');
            flash('Announcement posted.');
            header("Location: dashboard.php?page=class&id=$class_id");
            exit;
        }

        // REMOVE STUDENT
        if ($action == 'remove_student') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            $student_id = (int)($_POST['student_id'] ?? 0);
            $db->prepare("DELETE FROM class_members WHERE class_id=? AND student_id=?")->execute([$class_id, $student_id]);
            audit($db, 'remove_student', 'classes', $class_id, null, 'student ' . $student_id);
            flash('Student removed.');
            header("Location: dashboard.php?page=class&id=$class_id");
            exit;
        }

        // EXPORT XLSX (per term, DO 015 ECR-style layout)
        if ($action == 'export_xlsx') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $term_id = (int)($_POST['term_id'] ?? 0);
            $class_info = $db->prepare("SELECT subject, section, school_year FROM classes WHERE id=? AND teacher_id=?");
            $class_info->execute([$class_id, $user_id]);
            $cinfo = $class_info->fetch(PDO::FETCH_ASSOC);
            if (!$cinfo) { flash('Class not found.', 'danger'); header('Location: dashboard.php'); exit; }
            if (!$term_id) { $term_id = (int)$db->query("SELECT current_term_id FROM classes WHERE id=" . (int)$class_id)->fetchColumn(); }

            $cats = $db->prepare("SELECT * FROM grade_categories WHERE class_id=? AND term_id=? ORDER BY id");
            $cats->execute([$class_id, $term_id]);
            $categories = $cats->fetchAll(PDO::FETCH_ASSOC);

            $students = $db->prepare("SELECT u.id, s.full_name FROM users u JOIN students s ON u.id = s.user_id JOIN class_members cm ON cm.student_id = u.id WHERE cm.class_id=? ORDER BY s.full_name");
            $students->execute([$class_id]);
            $students_list = $students->fetchAll(PDO::FETCH_ASSOC);

            $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Grades');

            $col = 1;
            $sheet->setCellValueByColumnAndRow($col++, 1, 'Student Name');
            foreach ($categories as $c) {
                $items_in_cat = $db->prepare("SELECT * FROM grade_items WHERE category_id=? ORDER BY id");
                $items_in_cat->execute([$c['id']]);
                $its = $items_in_cat->fetchAll(PDO::FETCH_ASSOC);
                foreach ($its as $it) {
                    $sheet->setCellValueByColumnAndRow($col++, 1, $c['name'] . ' - ' . $it['name'] . ' (' . $it['max_score'] . ')');
                }
                $sheet->setCellValueByColumnAndRow($col++, 1, $c['name'] . ' PS (%)');
                $sheet->setCellValueByColumnAndRow($col++, 1, $c['name'] . ' WS');
            }
            $sheet->setCellValueByColumnAndRow($col++, 1, 'Initial Grade');
            $sheet->setCellValueByColumnAndRow($col++, 1, 'Term Grade');
            $sheet->setCellValueByColumnAndRow($col++, 1, 'Descriptor');

            $row = 2;
            foreach ($students_list as $s) {
                $res = compute_term_grade($db, $class_id, $s['id'], $term_id);
                $comp_by_cat = [];
                foreach ($res['components'] as $c) $comp_by_cat[$c['category_id']] = $c;

                $col = 1;
                $sheet->setCellValueByColumnAndRow($col++, $row, $s['full_name']);
                foreach ($categories as $c) {
                    $items_in_cat = $db->prepare("SELECT * FROM grade_items WHERE category_id=? ORDER BY id");
                    $items_in_cat->execute([$c['id']]);
                    $its = $items_in_cat->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($its as $it) {
                        $sc = $db->prepare("SELECT score FROM grade_scores WHERE grade_item_id=? AND student_id=?");
                        $sc->execute([$it['id'], $s['id']]);
                        $val = $sc->fetchColumn();
                        $sheet->setCellValueByColumnAndRow($col++, $row, $val !== false ? (float)$val : '');
                    }
                    $comp = $comp_by_cat[(int)$c['id']] ?? null;
                    $sheet->setCellValueByColumnAndRow($col++, $row, $comp && $comp['ps'] !== null ? $comp['ps'] : '');
                    $sheet->setCellValueByColumnAndRow($col++, $row, $comp && $comp['ps'] !== null ? $comp['ws'] : '');
                }
                $sheet->setCellValueByColumnAndRow($col++, $row, $res['ig']);
                $sheet->setCellValueByColumnAndRow($col++, $row, $res['tg'] ?? '');
                $sheet->setCellValueByColumnAndRow($col++, $row, $res['descriptor']['label_en'] ?? '');
                $row++;
            }

            $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="grades_' . $cinfo['subject'] . '_' . $cinfo['section'] . '_term' . $term_id . '.xlsx"');
            $writer->save('php://output');
            exit;
        }

        // IMPORT XLSX (matches the export layout)
        if ($action == 'import_xlsx') {
            $class_id = (int)($_POST['class_id'] ?? 0);
            $term_id = (int)($_POST['term_id'] ?? 0);
            if (!teacher_owns_class($db, $user_id, $class_id)) { flash('Unauthorized.', 'danger'); header('Location: dashboard.php'); exit; }
            if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] != UPLOAD_ERR_OK) {
                flash('Please upload a valid XLSX file.', 'danger');
                header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
                exit;
            }
            try {
                $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['xlsx_file']['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();
            } catch (Exception $e) {
                flash('Invalid XLSX file.', 'danger');
                header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
                exit;
            }
            if (count($rows) < 2) { flash('File has no data.', 'danger'); header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id"); exit; }
            $headers = $rows[0];
            $item_cols = [];
            for ($i = 1; $i < count($headers) - 3; $i++) {
                $col_name = trim($headers[$i] ?? '');
                if (!preg_match('/ - /', $col_name)) continue;
                $parts = explode(' - ', $col_name);
                if (count($parts) != 2) continue;
                $cat_name = trim($parts[0]);
                $item_part = $parts[1];
                $item_name = preg_replace('/\s*\(.*\)\s*$/', '', $item_part);
                $cat = $db->prepare("SELECT id FROM grade_categories WHERE class_id=? AND name=? AND term_id=?");
                $cat->execute([$class_id, $cat_name, $term_id]);
                $cid = $cat->fetchColumn();
                if (!$cid) continue;
                $item = $db->prepare("SELECT id FROM grade_items WHERE category_id=? AND name=?");
                $item->execute([$cid, trim($item_name)]);
                $iid = $item->fetchColumn();
                if ($iid) $item_cols[$i] = $iid;
            }
            for ($r = 1; $r < count($rows); $r++) {
                $row = $rows[$r];
                $student_name = trim($row[0] ?? '');
                if (!$student_name) continue;
                $stu = $db->prepare("SELECT u.id FROM users u JOIN students s ON u.id=s.user_id JOIN class_members cm ON cm.student_id=u.id WHERE cm.class_id=? AND s.full_name=?");
                $stu->execute([$class_id, $student_name]);
                $sid = $stu->fetchColumn();
                if (!$sid) continue;
                foreach ($item_cols as $col_idx => $iid) {
                    if (isset($row[$col_idx]) && $row[$col_idx] !== '') {
                        $val = (float)$row[$col_idx];
                        $existing = $db->prepare("SELECT COUNT(*) FROM grade_scores WHERE grade_item_id=? AND student_id=?");
                        $existing->execute([$iid, $sid]);
                        if ($existing->fetchColumn() > 0) {
                            $db->prepare("UPDATE grade_scores SET score=? WHERE grade_item_id=? AND student_id=?")->execute([$val, $iid, $sid]);
                        } else {
                            $db->prepare("INSERT INTO grade_scores (grade_item_id, student_id, score, term_id) VALUES (?, ?, ?, ?)")->execute([$iid, $sid, $val, $term_id]);
                        }
                    }
                }
            }
            audit($db, 'import_xlsx', 'classes', $class_id);
            flash('XLSX imported successfully.');
            header("Location: dashboard.php?page=grades&id=$class_id&term=$term_id");
            exit;
        }

        // UPDATE PROFILE (teacher)
        if ($action == 'update_profile') {
            $office_hours = trim($_POST['office_hours'] ?? '');
            $contact_email = trim($_POST['contact_email'] ?? '');
            $up = handle_upload($_FILES['photo'] ?? null, 'teacher_' . $user_id, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            if ($up['error']) { flash($up['error'], 'danger'); header('Location: dashboard.php?page=profile'); exit; }
            if ($up['file']) {
                $db->prepare("UPDATE teachers SET profile_photo=? WHERE user_id=?")->execute([$up['file'], $user_id]);
            }
            $db->prepare("UPDATE teachers SET office_hours=?, contact_email=? WHERE user_id=?")->execute([$office_hours, $contact_email, $user_id]);
            flash('Profile updated.');
            header('Location: dashboard.php?page=profile');
            exit;
        }
    }

    // ================= STUDENT ACTIONS =================
    if ($role == 'student') {

        // JOIN CLASS
        if ($action == 'join_class') {
            $code = strtoupper(trim($_POST['join_code'] ?? ''));
            $class = $db->prepare("SELECT id, status FROM classes WHERE join_code=?");
            $class->execute([$code]);
            $c = $class->fetch(PDO::FETCH_ASSOC);
            if (!$c || $c['status'] != 'active') {
                flash('Invalid or inactive join code.', 'danger');
            } else {
                $check = $db->prepare("SELECT COUNT(*) FROM class_members WHERE class_id=? AND student_id=?");
                $check->execute([$c['id'], $user_id]);
                if ($check->fetchColumn() > 0) {
                    flash('You are already in this class.');
                } else {
                    $db->prepare("INSERT INTO class_members (class_id, student_id) VALUES (?, ?)")->execute([$c['id'], $user_id]);
                    flash('Joined class successfully!');
                }
            }
            header('Location: dashboard.php?page=classes');
            exit;
        }

        // SUBMIT ASSIGNMENT
        if ($action == 'submit_assignment') {
            $assignment_id = (int)($_POST['assignment_id'] ?? 0);
            $class_id = (int)($_POST['class_id'] ?? 0);
            $member = $db->prepare("SELECT COUNT(*) FROM class_members WHERE class_id=? AND student_id=?");
            $member->execute([$class_id, $user_id]);
            $valid = $db->prepare("SELECT COUNT(*) FROM assignments WHERE id=? AND class_id=?");
            $valid->execute([$assignment_id, $class_id]);
            if ($member->fetchColumn() == 0 || $valid->fetchColumn() == 0) {
                flash('You are not enrolled in this class.', 'danger');
                header('Location: dashboard.php?page=classes');
                exit;
            }
            $policy = assignment_policy($db, $assignment_id);
            if (!$policy) {
                flash('Assignment not found.', 'danger');
                header('Location: dashboard.php?page=classes');
                exit;
            }
            // Late / cutoff enforcement (server time, due date inclusive)
            $now = date('Y-m-d');
            if (!$policy['allow_late'] && $policy['cutoff_date'] && $now > $policy['cutoff_date']) {
                flash('This assignment is closed for submissions.', 'danger');
                header('Location: dashboard.php?page=class&id=' . $class_id);
                exit;
            }
            $up = handle_upload($_FILES['file'] ?? null, 'sub_' . $user_id . '_' . $assignment_id, $policy['allowed_exts'], $policy['max_size_mb']);
            if ($up['error']) {
                flash($up['error'], 'danger');
            } elseif ($policy['requires_file'] && !$up['file']) {
                flash('A file is required for this assignment.', 'danger');
            } elseif (!$up['file']) {
                flash('Please select a file to upload.', 'danger');
            } else {
                $is_late = $now > $policy['due_date'] ? 1 : 0;
                $original_name = trim((string)($_FILES['file']['name'] ?? ''));
                $original_name = substr($original_name, 0, 255);
                $existing = $db->prepare("SELECT COUNT(*) FROM submissions WHERE assignment_id=? AND student_id=?");
                $existing->execute([$assignment_id, $user_id]);
                if ($existing->fetchColumn() > 0) {
                    $db->prepare("UPDATE submissions SET file_url=?, original_name=?, is_late=?, submitted_at=CURRENT_TIMESTAMP WHERE assignment_id=? AND student_id=?")->execute([$up['file'], $original_name, $is_late, $assignment_id, $user_id]);
                } else {
                    $db->prepare("INSERT INTO submissions (assignment_id, student_id, file_url, original_name, is_late) VALUES (?, ?, ?, ?, ?)")->execute([$assignment_id, $user_id, $up['file'], $original_name, $is_late]);
                    // Notify the class teacher
                    $t = $db->prepare("SELECT c.teacher_id, c.subject, a.title FROM classes c JOIN assignments a ON a.class_id=c.id WHERE a.id=?");
                    $t->execute([$assignment_id]);
                    $ti = $t->fetch(PDO::FETCH_ASSOC);
                    if ($ti) {
                        $sn = $db->prepare("SELECT full_name FROM students WHERE user_id=?");
                        $sn->execute([$user_id]);
                        notify($db, $ti['teacher_id'], ($is_late ? 'Late submission: ' : 'New submission: ') . "{$ti['title']} ({$ti['subject']}) by " . $sn->fetchColumn(), "dashboard.php?page=submissions&id=$assignment_id", 'submission');
                    }
                }
                flash($is_late ? 'Assignment submitted (late).' : 'Assignment submitted.');
            }
            header('Location: dashboard.php?page=class&id=' . $class_id);
            exit;
        }

        // UPDATE PROFILE (student)
        if ($action == 'update_profile') {
            $phone = trim($_POST['phone'] ?? '');
            $up = handle_upload($_FILES['photo'] ?? null, 'student_' . $user_id, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            if ($up['error']) { flash($up['error'], 'danger'); header('Location: dashboard.php?page=profile'); exit; }
            if ($up['file']) {
                $db->prepare("UPDATE students SET profile_photo=? WHERE user_id=?")->execute([$up['file'], $user_id]);
            }
            $db->prepare("UPDATE students SET phone=? WHERE user_id=?")->execute([$phone, $user_id]);
            flash('Profile updated.');
            header('Location: dashboard.php?page=profile');
            exit;
        }
    }

    // ================= BOTH ROLES =================

    // CHANGE PASSWORD (8+ characters)
    if ($action == 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $user = $db->prepare("SELECT password FROM users WHERE id=?");
        $user->execute([$user_id]);
        $hash = $user->fetchColumn();
        if (!password_verify($current, $hash)) {
            flash('Current password is incorrect.', 'danger');
        } elseif ($new !== $confirm) {
            flash('New passwords do not match.', 'danger');
        } elseif (strlen($new) < 8) {
            flash('Password must be at least 8 characters.', 'danger');
        } else {
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
            audit($db, 'change_password', 'users', $user_id);
            flash('Password changed.');
        }
        header('Location: dashboard.php?page=profile');
        exit;
    }

    // No action matched
    header('Location: dashboard.php');
    exit;
}