<?php
// ================================================================
// SCHOOL PORTAL — SLIM ROUTER
// Session/config/bootstrap comes from config.php.
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/teacher.php';
require_once __DIR__ . '/includes/student.php';

require_login();

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// ---- POST DISPATCH ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post_actions($db, $role, $user_id);
}

// ========================================
// PROFILE PAGE (BOTH ROLES)
// ========================================
function profile_page() {
    global $db, $user_id, $role;
    if ($role == 'teacher') {
        $info = $db->prepare("SELECT * FROM teachers WHERE user_id=?");
        $info->execute([$user_id]);
        $data = $info->fetch(PDO::FETCH_ASSOC);
    } else {
        $info = $db->prepare("SELECT * FROM students WHERE user_id=?");
        $info->execute([$user_id]);
        $data = $info->fetch(PDO::FETCH_ASSOC);
    }
    $user = $db->prepare("SELECT email FROM users WHERE id=?");
    $user->execute([$user_id]);
    $email = $user->fetchColumn();
    $photo = $data['profile_photo'] ?? 'default.png';
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <img src="download.php?f=<?= rawurlencode($photo) ?>" class="profile-photo" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['full_name']) ?>&background=0D1B3E&color=fff';">
                    <h5 class="fw-bold"><?= htmlspecialchars($_SESSION['full_name']) ?></h5>
                    <p class="text-muted small"><?= htmlspecialchars($email) ?> | <?= ucfirst($role) ?></p>
                </div>
            </div>
            <div class="card shadow-sm mt-3">
                <div class="card-header fw-semibold card-header-soft">Edit Profile</div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_profile">
                        <?php if ($role == 'teacher') { ?>
                            <div class="mb-2"><label class="small fw-semibold">Office Hours</label><input type="text" name="office_hours" class="form-control" value="<?= htmlspecialchars($data['office_hours'] ?? '') ?>"></div>
                            <div class="mb-2"><label class="small fw-semibold">Contact Email</label><input type="email" name="contact_email" class="form-control" value="<?= htmlspecialchars($data['contact_email'] ?? '') ?>"></div>
                        <?php } else { ?>
                            <div class="mb-2"><label class="small fw-semibold">Phone</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($data['phone'] ?? '') ?>"></div>
                        <?php } ?>
                        <div class="mb-3"><label class="small fw-semibold">Profile Photo</label><input type="file" name="photo" class="form-control form-control-sm"></div>
                        <button type="submit" class="btn btn-sm btn-brand">Update Profile</button>
                    </form>
                </div>
            </div>
            <div class="card shadow-sm mt-3">
                <div class="card-header fw-semibold card-header-soft">Change Password</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-2"><label class="small fw-semibold">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
                        <div class="mb-2"><label class="small fw-semibold">New Password</label><input type="password" name="new_password" class="form-control" required></div>
                        <div class="mb-2"><label class="small fw-semibold">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
                        <button type="submit" class="btn btn-sm btn-brand">Change Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php }

// ========================================
// HTML OUTPUT BEGINS
// ========================================

// Navbar identity chip data
$__chip_name = $_SESSION['full_name'] ?? 'User';
$__chip_role = ($role == 'teacher') ? 'Teacher' : 'Student';
if (is_admin_user()) { $__chip_role .= ' · Admin'; }
$__chip_parts = preg_split('/\s+/', trim(preg_replace('/[^A-Za-z ]/', '', $__chip_name)));
$__chip_ini = strtoupper(substr($__chip_parts[0] ?? 'U', 0, 1) . (isset($__chip_parts[1]) ? substr($__chip_parts[1], 0, 1) : ''));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>School Portal</title>
    <link rel="icon" href="logo.ico" type="image/x-icon">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <script>(function(){var t=localStorage.getItem('theme');if(!t){t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-bs-theme',t);})();</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
    <div class="container">
        <a class="navbar-brand fw-bold" href="dashboard.php"><img src="logo.ico" alt="School Portal" width="24" height="24" class="me-2">School Portal</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <?php if (is_admin_user()) { ?>
                    <li class="nav-item"><a class="nav-link" href="admin.php">Admin Panel</a></li>
                <?php } ?>
                <?php if ($role == 'teacher') { ?>
                    <li class="nav-item"><a class="nav-link <?= $page=='dashboard'?'active':'' ?>" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page=='profile'?'active':'' ?>" href="dashboard.php?page=profile">Profile</a></li>
                <?php } else { ?>
                    <li class="nav-item"><a class="nav-link <?= $page=='dashboard'?'active':'' ?>" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page=='classes'?'active':'' ?>" href="dashboard.php?page=classes">Classes</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page=='grades'?'active':'' ?>" href="dashboard.php?page=grades">Grades</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page=='attendance'?'active':'' ?>" href="dashboard.php?page=attendance">Attendance</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page=='notifications'?'active':'' ?>" href="dashboard.php?page=notifications">Notifications</a></li>
                    <li class="nav-item"><a class="nav-link <?= $page=='profile'?'active':'' ?>" href="dashboard.php?page=profile">Profile</a></li>
                <?php } ?>
                <?php $unread = $role == 'student' ? unread_count($db, $user_id) : 0; ?>
                <?php if ($unread > 0) { ?>
                    <li class="nav-item"><a class="nav-link" href="dashboard.php?page=notifications"><span class="badge bg-danger"><?= $unread ?></span> New</a></li>
                <?php } ?>
                <li class="nav-item d-none d-lg-flex align-items-center ms-lg-2">
                    <span class="nav-user-chip">
                        <span class="user-avatar"><?= htmlspecialchars($__chip_ini) ?></span>
                        <span class="user-meta">
                            <strong><?= htmlspecialchars($__chip_name) ?></strong>
                            <small><?= htmlspecialchars($__chip_role) ?></small>
                        </span>
                    </span>
                </li>
                <li class="nav-item ms-lg-1">
                    <form method="POST" action="logout.php" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
                    </form>
                </li>
                <li class="nav-item ms-lg-1">
                    <button type="button" class="theme-toggle-btn" data-theme-toggle aria-label="Switch to dark mode">
                        <svg data-icon="light" class="d-none" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                        <svg data-icon="dark" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <?php
    if (isset($_SESSION['flash'])) {
        echo '<div class="alert alert-' . $_SESSION['flash_type'] . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['flash']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        unset($_SESSION['flash'], $_SESSION['flash_type']);
    }

    // ========================================
    // PAGE ROUTING
    // ========================================
    if ($role == 'teacher') {
        switch ($page) {
            case 'create_class':
                teacher_create_class();
                break;
            case 'class':
                $class_id = (int)($_GET['id'] ?? 0);
                if ($class_id) teacher_class_detail($class_id);
                else echo '<div class="alert alert-danger">No class ID provided.</div>';
                break;
            case 'grades':
                $class_id = (int)($_GET['id'] ?? 0);
                if ($class_id) teacher_grades($class_id);
                else echo '<div class="alert alert-danger">No class ID provided.</div>';
                break;
            case 'attendance':
                $class_id = (int)($_GET['id'] ?? 0);
                if ($class_id) teacher_attendance($class_id);
                else echo '<div class="alert alert-danger">No class ID provided.</div>';
                break;
            case 'settings':
                $class_id = (int)($_GET['id'] ?? 0);
                if ($class_id) teacher_settings($class_id);
                else echo '<div class="alert alert-danger">No class ID provided.</div>';
                break;
            case 'submissions':
                $assignment_id = (int)($_GET['id'] ?? 0);
                if ($assignment_id) teacher_submissions($assignment_id);
                else echo '<div class="alert alert-danger">No assignment ID provided.</div>';
                break;
            case 'profile':
                profile_page();
                break;
            default:
                teacher_dashboard();
        }
    } else {
        switch ($page) {
            case 'classes':
                student_classes();
                break;
            case 'class':
                $class_id = (int)($_GET['id'] ?? 0);
                if ($class_id) student_class_detail($class_id);
                else echo '<div class="alert alert-danger">No class ID provided.</div>';
                break;
            case 'grades':
                student_grades();
                break;
            case 'attendance':
                student_attendance();
                break;
            case 'notifications':
                student_notifications();
                break;
            case 'profile':
                profile_page();
                break;
            default:
                student_dashboard();
        }
    }
    ?>
</div>

<footer class="text-center text-muted py-4 mt-5 border-top">
    <small>&copy; <?= date('Y') ?> School Portal &middot; Research Student Information System. All rights reserved.</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="script.js"></script>
<script src="theme.js"></script>
</body>
</html>