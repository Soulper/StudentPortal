<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';
$registration_mode = setting($db, 'registration_mode', 'open');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $id_number = trim($_POST['id_number'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($full_name && $email && $id_number && $password) {
        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $check = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetchColumn() > 0) {
                $error = 'Email already registered.';
            } else {
                $numcheck = $db->prepare("SELECT COUNT(*) FROM students WHERE student_number = ?");
                $numcheck->execute([$id_number]);
                if ($numcheck->fetchColumn() > 0) {
                    $error = 'That student number is already registered.';
                } else {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $status = $registration_mode === 'approval' ? 'pending' : 'active';
                    $db->prepare("INSERT INTO users (role, email, password, status) VALUES ('student', ?, ?, ?)")->execute([$email, $hashed, $status]);
                    $uid = $db->lastInsertId();
                    $db->prepare("INSERT INTO students (user_id, student_number, full_name) VALUES (?, ?, ?)")
                        ->execute([$uid, $id_number, $full_name]);
                    $success = $status === 'pending'
                        ? 'Account created! An administrator must approve it before you can sign in.'
                        : 'Account created successfully! You can now log in.';
                }
            }
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register - School Portal</title>
    <link rel="icon" href="logo.ico" type="image/x-icon">
    <script>(function(){var t=localStorage.getItem('theme');if(!t){t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-bs-theme',t);})();</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-card-wrap">
    <div class="card auth-card shadow">
        <div class="auth-brand">
            <img src="logo.ico" alt="School Portal">
            <h3>Create Account</h3>
            <small>Register as a student</small>
        </div>
        <div class="card-body auth-body-card">
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <div class="mb-2 text-start">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" placeholder="Juan Dela Cruz" required>
                </div>
                <div class="mb-2 text-start">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="you@school.com" required autocomplete="email">
                </div>
                <div class="mb-3 text-start">
                    <label class="form-label">Student Number</label>
                    <input type="text" name="id_number" class="form-control" placeholder="e.g. 2026-00123" required>
                </div>
                <div class="mb-4 text-start">
                    <label class="form-label">Password (min 8 characters)</label>
                    <input type="password" name="password" class="form-control" placeholder="••••••••" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-brand w-100 fw-semibold">Register</button>
            </form>
            <p class="mt-3 mb-0 text-center small"><a href="login.php">Already have an account? Sign in</a></p>
        </div>
    </div>
</div>
<button type="button" class="theme-toggle-float" data-theme-toggle aria-label="Switch to dark mode">
    <svg data-icon="light" class="d-none" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
    <svg data-icon="dark" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
</button>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="theme.js"></script>
</body>
</html>