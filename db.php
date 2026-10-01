<?php
// Deployable path: set DB_PATH to an absolute path on the host, e.g.
//   DB_PATH=/var/lib/studentportal/school_portal.db
// Defaults to the local file so development is unchanged.
$db_path = getenv('DB_PATH') ?: __DIR__ . '/school_portal.db';
try {
    $db_dir = dirname($db_path);
    if (!is_dir($db_dir)) @mkdir($db_dir, 0775, true);
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA foreign_keys = ON");
    $db->exec("PRAGMA journal_mode = WAL");
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Please try again later.');
}

// ---------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------
function db_column_exists($db, $table, $col) {
    $cols = $db->query("PRAGMA table_info(" . $table . ")")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) if ($c['name'] === $col) return true;
    return false;
}

function add_column($db, $table, $col, $def) {
    if (!db_column_exists($db, $table, $col)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $col $def");
    }
}

function db_table_exists($db, $table) {
    $r = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='" . $table . "'")->fetchColumn();
    return $r > 0;
}

function db_index_exists($db, $table, $index) {
    $r = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='" . $index . "'")->fetchColumn();
    return $r > 0;
}

// ---------------------------------------------------------------
// BASE TABLES
// ---------------------------------------------------------------
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role TEXT NOT NULL CHECK(role IN ('student','teacher')),
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
add_column($db, 'users', 'status', "TEXT NOT NULL DEFAULT 'active'");
add_column($db, 'users', 'is_admin', "INTEGER NOT NULL DEFAULT 0");

$db->exec("CREATE TABLE IF NOT EXISTS students (
    user_id INTEGER PRIMARY KEY,
    student_number TEXT NOT NULL UNIQUE,
    full_name TEXT NOT NULL,
    section TEXT,
    profile_photo TEXT DEFAULT 'default.png',
    phone TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$db->exec("CREATE TABLE IF NOT EXISTS teachers (
    user_id INTEGER PRIMARY KEY,
    employee_number TEXT NOT NULL UNIQUE,
    full_name TEXT NOT NULL,
    department TEXT,
    profile_photo TEXT DEFAULT 'default.png',
    office_hours TEXT,
    contact_email TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$db->exec("CREATE TABLE IF NOT EXISTS classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    teacher_id INTEGER NOT NULL,
    subject TEXT NOT NULL,
    section TEXT NOT NULL,
    school_year TEXT NOT NULL,
    description TEXT,
    join_code TEXT UNIQUE,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
    grading_system TEXT NOT NULL DEFAULT 'standard' CHECK(grading_system IN ('standard','deped')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(user_id) ON DELETE CASCADE
)");
add_column($db, 'classes', 'grading_policy_id', 'INTEGER');
add_column($db, 'classes', 'academic_year_id', 'INTEGER');
add_column($db, 'classes', 'current_term_id', 'INTEGER');

$db->exec("CREATE TABLE IF NOT EXISTS class_members (
    class_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (class_id, student_id),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE
)");

$db->exec("CREATE TABLE IF NOT EXISTS assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    due_date DATE NOT NULL,
    attachment TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
)");

$db->exec("CREATE TABLE IF NOT EXISTS submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignment_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    file_url TEXT,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    score NUMERIC(5,2),
    feedback TEXT,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE
)");
// Assignment submission policy (per-assignment file rules)
add_column($db, 'assignments', 'requires_file', "INTEGER NOT NULL DEFAULT 1");
add_column($db, 'assignments', 'allowed_exts', 'TEXT');
add_column($db, 'assignments', 'max_size_mb', "NUMERIC(5,2) NOT NULL DEFAULT 10");
add_column($db, 'assignments', 'allow_late', "INTEGER NOT NULL DEFAULT 1");
add_column($db, 'assignments', 'cutoff_date', 'DATE');
// Submission provenance
add_column($db, 'submissions', 'original_name', 'TEXT');
add_column($db, 'submissions', 'is_late', "INTEGER NOT NULL DEFAULT 0");

$db->exec("CREATE TABLE IF NOT EXISTS grade_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    weight NUMERIC(5,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
)");
add_column($db, 'grade_categories', 'term_id', 'INTEGER');

$db->exec("CREATE TABLE IF NOT EXISTS grade_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    class_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    max_score NUMERIC(5,2) NOT NULL DEFAULT 100,
    FOREIGN KEY (category_id) REFERENCES grade_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
)");
add_column($db, 'grade_items', 'term_id', 'INTEGER');
add_column($db, 'grade_items', 'ex_group', "TEXT");

$db->exec("CREATE TABLE IF NOT EXISTS grade_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    grade_item_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    score NUMERIC(5,2),
    FOREIGN KEY (grade_item_id) REFERENCES grade_items(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE
)");
add_column($db, 'grade_scores', 'term_id', 'INTEGER');

// One score per activity per student. Without this, a duplicated row is
// joined twice by compute_term_grade() and silently inflates the category
// percentage, so the constraint is enforced by the database as well as by
// the check-then-insert in the save paths.
if (!db_index_exists($db, 'grade_scores', 'uq_grade_scores_item_student')) {
    // Collapse any duplicates that slipped in before the index existed,
    // keeping the most recently written row for each pair.
    $db->exec(
        "DELETE FROM grade_scores WHERE id NOT IN (
            SELECT MAX(id) FROM grade_scores GROUP BY grade_item_id, student_id
        )");
    $db->exec("CREATE UNIQUE INDEX uq_grade_scores_item_student ON grade_scores (grade_item_id, student_id)");
}

$db->exec("CREATE TABLE IF NOT EXISTS category_scores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    score NUMERIC(5,2),
    FOREIGN KEY (category_id) REFERENCES grade_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE
)");

// Attendance: add 'excused' status (requires table rebuild; other tables do not reference it)
if (db_table_exists($db, 'attendance')) {
    $sql = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='attendance'")->fetchColumn();
    if (strpos($sql, "'excused'") === false) {
        $db->exec("PRAGMA foreign_keys=OFF");
        $db->exec("BEGIN");
        $db->exec("ALTER TABLE attendance RENAME TO attendance_old");
        $db->exec("CREATE TABLE attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            date DATE NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('present','late','absent','excused')),
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE
        )");
        $db->exec("INSERT INTO attendance (id, class_id, student_id, date, status) SELECT id, class_id, student_id, date, status FROM attendance_old");
        $db->exec("DROP TABLE attendance_old");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_attendance_unique ON attendance(class_id, student_id, date)");
        $db->exec("COMMIT");
        $db->exec("PRAGMA foreign_keys=ON");
    }
} else {
    $db->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_id INTEGER NOT NULL,
        student_id INTEGER NOT NULL,
        date DATE NOT NULL,
        status TEXT NOT NULL CHECK(status IN ('present','late','absent','excused')),
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE
    )");
}
$db->exec("CREATE INDEX IF NOT EXISTS idx_attendance_class_date ON attendance(class_id, date)");

$db->exec("CREATE TABLE IF NOT EXISTS announcements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    attachment TEXT,
    posted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
)");

$db->exec("CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    message TEXT NOT NULL,
    link TEXT DEFAULT '#',
    is_read INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");
add_column($db, 'notifications', 'type', "TEXT DEFAULT 'general'");
$db->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read)");

$db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
add_column($db, 'login_attempts', 'email', 'TEXT');
$db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_email ON login_attempts(email)");

// ---------------------------------------------------------------
// NEW: ACADEMIC STRUCTURE
// ---------------------------------------------------------------
$db->exec("CREATE TABLE IF NOT EXISTS academic_years (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL UNIQUE,
    start_date DATE,
    end_date DATE,
    is_active INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS terms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    academic_year_id INTEGER NOT NULL,
    number INTEGER NOT NULL,
    label TEXT NOT NULL,
    start_date DATE,
    end_date DATE,
    weight NUMERIC(5,2) NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
)");

// ---------------------------------------------------------------
// NEW: CONFIGURABLE GRADING POLICY
// ---------------------------------------------------------------
$db->exec("CREATE TABLE IF NOT EXISTS grading_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    source_doc TEXT,
    key_stage TEXT,
    subject_group TEXT,
    transmutation_mode TEXT NOT NULL DEFAULT 'none' CHECK(transmutation_mode IN ('adjusted_table','zero_based','legacy_linear','none')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
// DepEd Order No. 017, s. 2026 reduces Senior High School to TWO tracks
// (Academic and Technical-Professional). `track` groups the policy picker;
// NULL is treated as "Other".
add_column($db, 'grading_policies', 'track', "TEXT");

$db->exec("CREATE TABLE IF NOT EXISTS grading_components (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    policy_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    weight NUMERIC(5,2) NOT NULL DEFAULT 0,
    ex_st1 NUMERIC(5,2),
    ex_st2 NUMERIC(5,2),
    ex_te NUMERIC(5,2),
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (policy_id) REFERENCES grading_policies(id) ON DELETE CASCADE
)");

$db->exec("CREATE TABLE IF NOT EXISTS transmutation_rows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    policy_id INTEGER NOT NULL,
    min_ig NUMERIC(6,2) NOT NULL,
    max_ig NUMERIC(6,2) NOT NULL,
    tg INTEGER NOT NULL,
    FOREIGN KEY (policy_id) REFERENCES grading_policies(id) ON DELETE CASCADE
)");

$db->exec("CREATE TABLE IF NOT EXISTS descriptor_rows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    policy_id INTEGER NOT NULL,
    min_grade NUMERIC(5,2) NOT NULL,
    max_grade NUMERIC(5,2) NOT NULL,
    label_en TEXT NOT NULL,
    label_fil TEXT,
    FOREIGN KEY (policy_id) REFERENCES grading_policies(id) ON DELETE CASCADE
)");

// ---------------------------------------------------------------
// NEW: TERM PUBLISHING / AUDIT / CALENDAR / SCHOOL ANNOUNCEMENTS / SETTINGS
// ---------------------------------------------------------------
$db->exec("CREATE TABLE IF NOT EXISTS class_term_status (
    class_id INTEGER NOT NULL,
    term_id INTEGER NOT NULL,
    published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (class_id, term_id),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
)");

$db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    target_type TEXT,
    target_id INTEGER,
    old_value TEXT,
    new_value TEXT,
    ip TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(user_id)");

$db->exec("CREATE TABLE IF NOT EXISTS calendar_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    event_date DATE NOT NULL,
    end_date DATE,
    event_type TEXT NOT NULL DEFAULT 'event' CHECK(event_type IN ('holiday','exam','event','deadline','term_start','term_end','suspension')),
    term_id INTEGER,
    description TEXT,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE SET NULL
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_calendar_date ON calendar_events(event_date)");

$db->exec("CREATE TABLE IF NOT EXISTS school_announcements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    audience TEXT NOT NULL DEFAULT 'all' CHECK(audience IN ('all','students','teachers')),
    posted_by INTEGER,
    posted_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
)");

// ---------------------------------------------------------------
// REFERENCE DATA (shared by the seed and the migrations)
// ---------------------------------------------------------------

// Adjusted transmutation table, SY 2026-2027 only, per DepEd Order
// No. 015, s. 2026. Zero-based grading with no transmutation applies
// from SY 2027-2028.
function adjusted_transmutation_table() {
    return [
        [99.50, 100.00, 100], [98.32, 99.49, 99], [97.14, 98.31, 98], [95.96, 97.13, 97],
        [94.78, 95.95, 96], [93.60, 94.77, 95], [92.42, 93.59, 94], [91.24, 92.41, 93],
        [90.06, 91.23, 92], [88.88, 90.05, 91], [87.70, 88.87, 90], [86.52, 87.69, 89],
        [85.34, 86.51, 88], [84.16, 85.33, 87], [82.98, 84.15, 86], [81.80, 82.97, 85],
        [80.62, 81.79, 84], [79.44, 80.61, 83], [78.26, 79.43, 82], [77.08, 78.25, 81],
        [75.90, 77.07, 80], [74.72, 75.89, 79], [73.54, 74.71, 78], [72.36, 73.53, 77],
        [71.18, 72.35, 76], [70.00, 71.17, 75], [65.34, 69.99, 74], [60.67, 65.33, 73],
        [56.01, 60.66, 72], [51.34, 56.00, 71], [46.67, 51.33, 70], [42.01, 46.66, 69],
        [37.34, 42.00, 68], [32.68, 37.33, 67], [28.01, 32.67, 66], [23.35, 28.00, 65],
        [18.68, 23.34, 64], [14.01, 18.67, 63], [9.35, 14.00, 62], [4.68, 9.34, 61],
        [0.00, 4.67, 60],
    ];
}

// Qualitative descriptors. DO 015, s. 2026 replaced the DO 8 s. 2015
// scale; the old bands are retained only for historical "Legacy" classes
// whose grades were recorded under the repealed order.
function descriptor_bands_do015() {
    return [
        [90, 100.00, 'Advancing', 'Namumukod-tangi'],
        [80, 89.99, 'Benchmarking', 'Napamamalas'],
        [75, 79.99, 'Connecting', 'Natutungo'],
        [65, 74.99, 'Developing', 'Napauunlad'],
        [0, 64.99, 'Emerging', 'Nagsisimula'],
    ];
}
function descriptor_bands_do8() {
    return [
        [90, 100.00, 'Outstanding', 'Napakahusay'],
        [85, 89.99, 'Very Satisfactory', 'Mahusay'],
        [80, 84.99, 'Satisfactory', 'Gumaganap'],
        [75, 79.99, 'Fairly Satisfactory', 'Katamtaman'],
        [0, 74.99, 'Did Not Meet Expectations', 'Hindi Naabot'],
    ];
}

// The canonical policy catalogue. Used by seed_once() for fresh installs
// AND by migrate_grading_policies() for existing databases, so the two can
// never drift apart.
//
// Weights are DO 015, s. 2026 Table 10. Tracks are per DO 017, s. 2026
// (SHS = Academic + TechPro only, no strands). `ex` is the internal exam
// split as [ST1, ST2, TE] percentages of the Examinations weight, or null
// when the subject has no exam component. Field Experience is administered
// as the term exam only.
function grading_policy_catalog() {
    $WW = 'Written or Oral Works';
    $PT = 'Product or Performance Tasks';
    $EX = 'Examinations';
    $std_ex = [30, 30, 40];

    return [
        [
            'name' => 'DO 015 s.2026 - SHS Academic: Core & Academic Electives',
            'description' => 'Core subjects (Effective Communication, Life Skills, General Mathematics, General Science, Philippine History and Society) and Academic electives such as STEM, Business & Entrepreneurship, and Arts, Social Sciences & Humanities. ' . $WW . ' 20%, ' . $PT . ' 50%, ' . $EX . ' 30% (ST1 30%, ST2 30%, TE 40%).',
            'source_doc' => 'DepEd Order No. 015, s. 2026',
            'key_stage' => 'KS4', 'subject_group' => 'Academic Core/Electives',
            'track' => 'Senior High - Academic', 'transmutation_mode' => 'adjusted_table',
            'components' => [[$WW, 20, null], [$PT, 50, null], [$EX, 30, $std_ex]],
        ],
        [
            'name' => 'DO 015 s.2026 - SHS Academic: Arts, Sports, Health & Wellness',
            'description' => 'Arts, Sports, Health and Wellness elective cluster (reclassified under the Academic track by DO 017, s. 2026). ' . $WW . ' 20%, ' . $PT . ' 60%, ' . $EX . ' 20%.',
            'source_doc' => 'DepEd Order No. 015, s. 2026',
            'key_stage' => 'KS4', 'subject_group' => 'Arts/Sports/Health',
            'track' => 'Senior High - Academic', 'transmutation_mode' => 'adjusted_table',
            'components' => [[$WW, 20, null], [$PT, 60, null], [$EX, 20, $std_ex]],
        ],
        [
            'name' => 'DO 015 s.2026 - SHS Academic: Field Experience / Arts Apprenticeship',
            'description' => 'Field Experience cluster, Arts Apprenticeship and Creative Production & Innovation. ' . $WW . ' 15%, ' . $PT . ' 70%, ' . $EX . ' 15% - administered as the Term Examination only.',
            'source_doc' => 'DepEd Order No. 015, s. 2026',
            'key_stage' => 'KS4', 'subject_group' => 'Field Experience',
            'track' => 'Senior High - Academic', 'transmutation_mode' => 'adjusted_table',
            'components' => [[$WW, 15, null], [$PT, 70, null], [$EX, 15, [0, 0, 100]]],
        ],
        [
            'name' => 'DO 015 s.2026 - SHS Academic: Research Electives & Design Innovation',
            'description' => 'Research electives, Design and Innovation. ' . $WW . ' 40%, ' . $PT . ' 60%. No Examinations component.',
            'source_doc' => 'DepEd Order No. 015, s. 2026',
            'key_stage' => 'KS4', 'subject_group' => 'Research/Design',
            'track' => 'Senior High - Academic', 'transmutation_mode' => 'adjusted_table',
            'components' => [[$WW, 40, null], [$PT, 60, null]],
        ],
        [
            'name' => 'DO 015 s.2026 - SHS TechPro: TechPro Electives',
            'description' => 'TechPro Track electives across its clusters (Aesthetic, Wellness & Human Care; Agri-Fishery Business & Food Innovation; Artisanry & Creative Enterprise; Automotive & Small Engine; Construction & Building; Creative Arts & Design; Hospitality & Tourism; ICT Support & Computer Programming; Industrial Technologies; Maritime Transport). ' . $WW . ' 15%, ' . $PT . ' 65%, ' . $EX . ' 20%.',
            'source_doc' => 'DepEd Order No. 015, s. 2026',
            'key_stage' => 'KS4', 'subject_group' => 'TechPro Electives',
            'track' => 'Senior High - TechPro', 'transmutation_mode' => 'adjusted_table',
            'components' => [[$WW, 15, null], [$PT, 65, null], [$EX, 20, $std_ex]],
        ],
        [
            'name' => 'DO 015 s.2026 - SHS Work Immersion (both tracks)',
            'description' => 'Work Immersion / on-the-job training, Grade 11 or 12, both tracks. ' . $WW . ' 20%, ' . $PT . ' 80%. No Examinations component; Performance Tasks reflect the workplace supervisor evaluation.',
            'source_doc' => 'DepEd Order No. 015, s. 2026',
            'key_stage' => 'KS4', 'subject_group' => 'Work Immersion',
            'track' => 'Senior High - Both Tracks', 'transmutation_mode' => 'adjusted_table',
            'components' => [[$WW, 20, null], [$PT, 80, null]],
        ],
        [
            'name' => 'DO 015 s.2026 - KS2/KS3 Core Subjects',
            'description' => 'English, Filipino, Mathematics, Science, Araling Panlipunan, GMRC / Values Education (Grades 4-10). ' . $WW . ' 20%, ' . $PT . ' 50%, ' . $EX . ' 30%.',
            'source_doc' => 'DepEd Order No. 015, s. 2026',
            'key_stage' => 'KS2/KS3', 'subject_group' => 'Core',
            'track' => 'JHS / JHS Core', 'transmutation_mode' => 'adjusted_table',
            'components' => [[$WW, 20, null], [$PT, 50, null], [$EX, 30, $std_ex]],
        ],
        [
            'name' => 'DO 015 s.2026 - KS2/KS3 MAPEH / EPP-TLE',
            'description' => 'MAPEH, EPP / TLE (Grades 4-10). ' . $WW . ' 20%, ' . $PT . ' 60%, ' . $EX . ' 20%.',
            'source_doc' => 'DepEd Order No. 015, s. 2026',
            'key_stage' => 'KS2/KS3', 'subject_group' => 'MAPEH/TLE',
            'track' => 'JHS / JHS Core', 'transmutation_mode' => 'adjusted_table',
            'components' => [[$WW, 20, null], [$PT, 60, null], [$EX, 20, $std_ex]],
        ],
        [
            'name' => 'Legacy DO 8 s.2015 (historical classes)',
            'description' => 'Repealed order, kept for classes whose grades were recorded before SY 2026-2027. SHS core ' . $WW . ' 25%, ' . $PT . ' 50%, ' . $EX . ' 25%, with the original linear transmutation.',
            'source_doc' => 'DepEd Order No. 8, s. 2015 (repealed)',
            'key_stage' => 'All', 'subject_group' => 'All',
            'track' => 'Other', 'transmutation_mode' => 'legacy_linear',
            'components' => [[$WW, 25, null], [$PT, 50, null], [$EX, 25, $std_ex]],
        ],
        [
            'name' => 'Standard (custom categories)',
            'description' => 'Teacher-defined categories and weights. No transmutation.',
            'source_doc' => 'School-defined',
            'key_stage' => 'All', 'subject_group' => 'Custom',
            'track' => 'Other', 'transmutation_mode' => 'none',
            'components' => [],   // the teacher defines these
        ],
    ];
}

// Insert any catalogue policy that is missing, plus its components,
// transmutation rows and descriptors. Safe to run repeatedly.
function install_policy_from_catalog($db, $p) {
    $q = $db->prepare("SELECT id FROM grading_policies WHERE name=?");
    $q->execute([$p['name']]);
    $pid = (int)$q->fetchColumn();

    if (!$pid) {
        $db->prepare("INSERT INTO grading_policies (name, description, source_doc, key_stage, subject_group, track, transmutation_mode, is_active) VALUES (?,?,?,?,?,?,?,1)")
            ->execute([$p['name'], $p['description'], $p['source_doc'], $p['key_stage'],
                        $p['subject_group'], $p['track'], $p['transmutation_mode']]);
        $pid = (int)$db->lastInsertId();
    } else {
        // Keep the descriptive fields current without touching grades.
        $db->prepare("UPDATE grading_policies SET description=?, source_doc=?, key_stage=?, subject_group=?, track=? WHERE id=?")
            ->execute([$p['description'], $p['source_doc'], $p['key_stage'],
                       $p['subject_group'], $p['track'], $pid]);
    }

    $has = (int)$db->query("SELECT COUNT(*) FROM grading_components WHERE policy_id=" . $pid)->fetchColumn();
    if (!$has && $p['components']) {
        $sql = "INSERT INTO grading_components (policy_id, code, name, weight, ex_st1, ex_st2, ex_te, sort_order) VALUES (?,?,?,?,?,?,?,?)";
        $codes = ['WW', 'PT', 'EX'];
        $order = 0;
        foreach ($p['components'] as $c) {
            $order++;
            $ex = $c[2];
            $db->prepare($sql)->execute([
                $pid, $codes[$order - 1] ?? 'X' . $order, $c[0], $c[1],
                $ex ? $ex[0] : null, $ex ? $ex[1] : null, $ex ? $ex[2] : null,
                $order,
            ]);
        }
    }

    // Transmutation rows only matter for the adjusted-table policies.
    if ($p['transmutation_mode'] === 'adjusted_table') {
        $n = (int)$db->query("SELECT COUNT(*) FROM transmutation_rows WHERE policy_id=" . $pid)->fetchColumn();
        if (!$n) {
            $tr = "INSERT INTO transmutation_rows (policy_id, min_ig, max_ig, tg) VALUES (?,?,?,?)";
            foreach (adjusted_transmutation_table() as $row) {
                $db->prepare($tr)->execute([$pid, $row[0], $row[1], $row[2]]);
            }
        }
    }

    // Descriptors: DO 015 bands for current policies, DO 8 for the legacy one.
    $bands = (strpos($p['source_doc'], 'No. 8, s. 2015') !== false)
        ? descriptor_bands_do8()
        : descriptor_bands_do015();
    $d = "SELECT COUNT(*) FROM descriptor_rows WHERE policy_id=? AND label_en=?";
    $d = $db->prepare($d);
    $d->execute([$pid, $bands[0][2]]);
    if (!(int)$d->fetchColumn()) {
        $ins = "INSERT INTO descriptor_rows (policy_id, min_grade, max_grade, label_en, label_fil) VALUES (?,?,?,?,?)";
        foreach ($bands as $row) $db->prepare($ins)->execute([$pid, $row[0], $row[1], $row[2], $row[3]]);
    }
    return $pid;
}

// Policies that existed before the two-track restructure. The earlier
// names are mapped onto the canonical catalogue so an existing database
// adopts the row (keeping its id, and therefore its classes) instead of
// growing a duplicate. "drop" entries were dead ends - they carried a name
// and description but no components, so selecting them produced a class
// with an empty gradebook.
function superseded_grading_policies() {
    return [
        'DO 015 s.2026 - KS4 SHS Core / Academic Electives'
            => 'DO 015 s.2026 - SHS Academic: Core & Academic Electives',
        'DO 015 s.2026 - MAPEH / EPP-TLE'
            => 'DO 015 s.2026 - KS2/KS3 MAPEH / EPP-TLE',
        'DO 015 s.2026 - SHS Research Electives & Design Innovation'
            => 'DO 015 s.2026 - SHS Academic: Research Electives & Design Innovation',
        'DO 015 s.2026 - SHS Work Immersion'
            => 'DO 015 s.2026 - SHS Work Immersion (both tracks)',
    ];
}

function migrate_grading_policies($db) {
    // 1. Fold the pre-restructure policies into the catalogue.
    $byName = $db->prepare("SELECT id, is_active FROM grading_policies WHERE name=?");
    foreach (superseded_grading_policies() as $old => $new) {
        $byName->execute([$old]);
        $oldRow = $byName->fetch(PDO::FETCH_ASSOC);
        if (!$oldRow) continue;
        $byName->execute([$new]);
        $newRow = $byName->fetch(PDO::FETCH_ASSOC);

        if ($newRow) {
            // The replacement already exists - retire the old one.
            $db->prepare("UPDATE grading_policies SET is_active=0 WHERE id=?")->execute([$oldRow['id']]);
        } else {
            // Reuse the row so existing classes keep pointing at it.
            $db->prepare("UPDATE grading_policies SET name=? WHERE id=?")->execute([$new, $oldRow['id']]);
        }
    }

    // 2. Install anything from the catalogue that is still missing.
    foreach (grading_policy_catalog() as $p) {
        install_policy_from_catalog($db, $p);
    }

    // 3. Retire the pre-2026 descriptor labels on any DO 015 policy seeded
    //    before the new bands existed. Keyed on the current top band, so
    //    this is a no-op once it has run.
    $stale = $db->query(
        "SELECT DISTINCT p.id FROM grading_policies p
         JOIN descriptor_rows d ON d.policy_id = p.id
         WHERE p.source_doc LIKE 'DepEd Order No. 015%'
           AND d.label_en = 'Very Satisfactory'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($stale as $pid) {
        $db->prepare("DELETE FROM descriptor_rows WHERE policy_id=?")->execute([$pid]);
        $ins = "INSERT INTO descriptor_rows (policy_id, min_grade, max_grade, label_en, label_fil) VALUES (?,?,?,?,?)";
        foreach (descriptor_bands_do015() as $row) $db->prepare($ins)->execute([$pid, $row[0], $row[1], $row[2], $row[3]]);
    }

    // 4. Hard-delete retired rows that nothing references, so the catalogue
    //    does not accumulate ghosts in Admin > Policies.
    $retired = $db->query(
        "SELECT id FROM grading_policies
         WHERE is_active = 0
           AND id NOT IN (SELECT DISTINCT grading_policy_id FROM classes WHERE grading_policy_id IS NOT NULL)")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($retired as $pid) {
        $refs = (int)$db->query("SELECT COUNT(*) FROM grade_categories gc
            JOIN classes c ON c.id = gc.class_id WHERE c.grading_policy_id = " . (int)$pid)->fetchColumn();
        if (!$refs) $db->prepare("DELETE FROM grading_policies WHERE id=?")->execute([$pid]);
    }
}

// ---------------------------------------------------------------
// SEEDING (runs once)
// ---------------------------------------------------------------
function seed_once($db) {
    if ((int)$db->query("SELECT COUNT(*) FROM academic_years")->fetchColumn() > 0) {
        return; // already seeded
    }

    // ---- Legacy SY 2025-2026 (historical data holder) ----
    $db->prepare("INSERT INTO academic_years (label, start_date, end_date, is_active) VALUES (?, ?, ?, 0)")
        ->execute(['2025-2026', '2025-06-09', '2026-04-10']);
    $legacy_year = $db->lastInsertId();
    $db->prepare("INSERT INTO terms (academic_year_id, number, label, start_date, end_date, status) VALUES (?, 0, 'SY 2025-2026', '2025-06-09', '2026-04-10', 'closed')")
        ->execute([$legacy_year]);
    $legacy_term = $db->lastInsertId();

    // Attach existing (pre-upgrade) grades to the legacy term
    $db->exec("UPDATE grade_categories SET term_id = " . (int)$legacy_term . " WHERE term_id IS NULL");
    $db->exec("UPDATE grade_items SET term_id = " . (int)$legacy_term . " WHERE term_id IS NULL");
    $db->exec("UPDATE grade_scores SET term_id = " . (int)$legacy_term . " WHERE term_id IS NULL");

    // ---- Active SY 2026-2027 (three-term calendar, DO 009 s.2026) ----
    $db->prepare("INSERT INTO academic_years (label, start_date, end_date, is_active) VALUES (?, ?, ?, 1)")
        ->execute(['2026-2027', '2026-06-08', '2027-04-08']);
    $new_year = $db->lastInsertId();
    $term_defs = [
        [1, 'Term 1', '2026-06-08', '2026-09-04'],
        [2, 'Term 2', '2026-09-07', '2026-12-11'],
        [3, 'Term 3', '2027-01-04', '2027-04-08'],
    ];
    foreach ($term_defs as $t) {
        $db->prepare("INSERT INTO terms (academic_year_id, number, label, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, 'open')")
            ->execute([$new_year, $t[0], $t[1], $t[2], $t[3]]);
    }
    $t1 = (int)$db->query("SELECT id FROM terms WHERE academic_year_id=" . (int)$new_year . " AND number=1")->fetchColumn();

    // ---- Grading policies (canonical catalogue) ----
    // install_policy_from_catalog() also writes components, transmutation
    // rows and descriptors, so nothing further is needed here.
    $cat = grading_policy_catalog();
    $p_shs = install_policy_from_catalog($db, $cat[0]);      // SHS Academic Core
    $p_jhs = install_policy_from_catalog($db, $cat[6]);      // KS2/KS3 Core
    $p_mapeh = install_policy_from_catalog($db, $cat[7]);    // KS2/KS3 MAPEH
    $p_legacy = install_policy_from_catalog($db, $cat[8]);   // Legacy DO 8
    $p_standard = install_policy_from_catalog($db, $cat[9]); // Standard

    // ---- Settings ----
    $set = $db->prepare("INSERT INTO settings (key, value) VALUES (?,?)");
    $set->execute(['school_name', 'School Portal']);
    $set->execute(['registration_mode', 'open']);
    $set->execute(['active_academic_year_id', (string)$new_year]);
    $set->execute(['schema_version', '2']);

    // ---- Point existing classes at their policies/terms ----
    $db->exec("UPDATE classes SET grading_policy_id=" . (int)$p_legacy . ", current_term_id=" . (int)$legacy_term . ", academic_year_id=" . (int)$legacy_year . " WHERE grading_system='deped' AND grading_policy_id IS NULL");
    $db->exec("UPDATE classes SET grading_policy_id=" . (int)$p_standard . ", current_term_id=" . (int)$legacy_term . ", academic_year_id=" . (int)$legacy_year . " WHERE grading_system='standard' AND grading_policy_id IS NULL");

    // ---- Admin account ----
    $admins = $db->query("SELECT COUNT(*) FROM users WHERE is_admin=1")->fetchColumn();
    if (!$admins) {
        $pass = password_hash('password123', PASSWORD_DEFAULT);
        $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email=?");
        $chk->execute(['admin@school.com']);
        if ($chk->fetchColumn() == 0) {
            $db->prepare("INSERT INTO users (role, email, password, is_admin) VALUES ('teacher', ?, ?, 1)")->execute(['admin@school.com', $pass]);
            $uid = $db->lastInsertId();
            $db->prepare("INSERT INTO teachers (user_id, employee_number, full_name, department, contact_email) VALUES (?, ?, ?, ?, ?)")
                ->execute([$uid, 'ADM-001', 'School Administrator', 'Administration', 'admin@school.com']);
        } else {
            $db->prepare("UPDATE users SET is_admin=1 WHERE email=?")->execute(['admin@school.com']);
        }
    }

    // ---- Demo class for SY 2026-2027 (new grading system) ----
    $teacher_id = (int)$db->query("SELECT user_id FROM teachers LIMIT 1")->fetchColumn();
    if ($teacher_id && (int)$db->query("SELECT COUNT(*) FROM classes WHERE academic_year_id=" . (int)$new_year)->fetchColumn() == 0) {
        $db->prepare("INSERT INTO classes (teacher_id, subject, section, school_year, description, join_code, grading_system, grading_policy_id, academic_year_id, current_term_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$teacher_id, 'General Mathematics', 'G11-MATH-A26', '2026-2027', 'General Mathematics for Grade 11 STEM - SY 2026-2027', 'G11MATH27', 'deped', $p_shs, $new_year, $t1]);
        $cid = $db->lastInsertId();
        $students = $db->query("SELECT user_id FROM students ORDER BY user_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($students as $sid) {
            $db->prepare("INSERT INTO class_members (class_id, student_id) VALUES (?, ?)")->execute([$cid, $sid]);
        }
        $cats = [
            ['Written or Oral Works', 20],
            ['Product or Performance Tasks', 50],
            ['Examinations', 30],
        ];
        $cat_ids = [];
        foreach ($cats as $c) {
            $db->prepare("INSERT INTO grade_categories (class_id, name, weight, term_id) VALUES (?,?,?,?)")->execute([$cid, $c[0], $c[1], $t1]);
            $cat_ids[] = $db->lastInsertId();
        }
        $items = [
            [$cat_ids[0], 'Quiz 1', 20, null], [$cat_ids[0], 'Quiz 2', 20, null],
            [$cat_ids[1], 'Performance Task 1', 50, null], [$cat_ids[1], 'Performance Task 2', 50, null],
            [$cat_ids[2], 'Summative Test 1', 40, 'st1'], [$cat_ids[2], 'Summative Test 2', 40, 'st2'], [$cat_ids[2], 'Term Examination', 60, 'te'],
        ];
        $item_ids = [];
        foreach ($items as $i) {
            $db->prepare("INSERT INTO grade_items (category_id, class_id, name, max_score, term_id, ex_group) VALUES (?,?,?,?,?,?)")->execute([$i[0], $cid, $i[1], $i[2], $t1, $i[3]]);
            $item_ids[] = $db->lastInsertId();
        }
        $demo_scores = [
            [18, 17, 45, 40, 35, 32, 50],
            [16, 18, 42, 44, 30, 34, 48],
            [19, 19, 48, 45, 38, 36, 55],
        ];
        foreach ($students as $k => $sid) {
            foreach ($item_ids as $j => $iid) {
                $db->prepare("INSERT INTO grade_scores (grade_item_id, student_id, score, term_id) VALUES (?,?,?,?)")
                    ->execute([$iid, $sid, $demo_scores[$k][$j], $t1]);
            }
        }
        // Seed Term 2 categories (empty) so the term tabs make sense
        $db->prepare("INSERT INTO grade_categories (class_id, name, weight, term_id) VALUES (?,?,?,?)")->execute([$cid, 'Written or Oral Works', 20, (int)$db->query("SELECT id FROM terms WHERE academic_year_id=" . (int)$new_year . " AND number=2")->fetchColumn()]);
        $db->prepare("INSERT INTO grade_categories (class_id, name, weight, term_id) VALUES (?,?,?,?)")->execute([$cid, 'Product or Performance Tasks', 50, (int)$db->query("SELECT id FROM terms WHERE academic_year_id=" . (int)$new_year . " AND number=2")->fetchColumn()]);
        $db->prepare("INSERT INTO grade_categories (class_id, name, weight, term_id) VALUES (?,?,?,?)")->execute([$cid, 'Examinations', 30, (int)$db->query("SELECT id FROM terms WHERE academic_year_id=" . (int)$new_year . " AND number=2")->fetchColumn()]);
        $db->prepare("INSERT INTO grade_categories (class_id, name, weight, term_id) VALUES (?,?,?,?)")->execute([$cid, 'Written or Oral Works', 20, (int)$db->query("SELECT id FROM terms WHERE academic_year_id=" . (int)$new_year . " AND number=3")->fetchColumn()]);
        $db->prepare("INSERT INTO grade_categories (class_id, name, weight, term_id) VALUES (?,?,?,?)")->execute([$cid, 'Product or Performance Tasks', 50, (int)$db->query("SELECT id FROM terms WHERE academic_year_id=" . (int)$new_year . " AND number=3")->fetchColumn()]);
        $db->prepare("INSERT INTO grade_categories (class_id, name, weight, term_id) VALUES (?,?,?,?)")->execute([$cid, 'Examinations', 30, (int)$db->query("SELECT id FROM terms WHERE academic_year_id=" . (int)$new_year . " AND number=3")->fetchColumn()]);
    }

    // ---- Seed calendar events (DO 009 s.2026 key dates) ----
    $ev = $db->prepare("INSERT INTO calendar_events (title, event_date, event_type, term_id, description) VALUES (?,?,?,?,?)");
    $ev->execute(['Opening of Classes', '2026-06-08', 'term_start', $t1, 'First day of Term 1, SY 2026-2027 (DO 009 s.2026)']);
    $ev->execute(['Term 1 Examination', '2026-08-28', 'exam', $t1, 'Term 1 examination week (Aug 28 - Sep 1, 2026)']);
    $ev->execute(['Year-end Break', '2026-12-19', 'holiday', null, 'December 19 - 31, 2026']);
    $ev->execute(['Term 2 Examination', '2026-12-03', 'exam', null, 'Term 2 examinations (Dec 3-4, 2026)']);
    $ev->execute(['Resumption of Classes', '2027-01-04', 'term_start', null, 'Classes resume for Term 3']);
    $ev->execute(['Term 3 Examination', '2027-03-22', 'exam', null, 'Term 3 examinations (Mar 22-23, 2027)']);
    $ev->execute(['End of School Year Rites', '2027-04-06', 'event', null, 'EOSY rites (Apr 6-7, 2027)']);
}

seed_once($db);

// Keeps the policy catalogue in sync on existing installs. seed_once()
// early-returns when data already exists, so this is what actually applies
// new policies / components / descriptors to an established database.
migrate_grading_policies($db);
?>