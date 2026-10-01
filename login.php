<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Throttle: per-IP AND per-email (5 failures in 15 minutes)
        $attempts = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE (ip=? OR email=?) AND attempted_at >= datetime('now', '-15 minutes')");
        $attempts->execute([$ip, $email]);
        if ($attempts->fetchColumn() >= 5) {
            $error = 'Too many failed attempts. Please try again in 15 minutes.';
        } elseif ($email && $password) {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                if (($user['status'] ?? 'active') === 'disabled') {
                    $error = 'This account has been disabled. Contact the administrator.';
                } elseif (($user['status'] ?? 'active') === 'pending') {
                    $error = 'Your account is pending approval. Please wait for the administrator to activate it.';
                } else {
                    $db->prepare("DELETE FROM login_attempts WHERE ip=? OR email=?")->execute([$ip, $email]);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['is_admin'] = (int)($user['is_admin'] ?? 0);

                    if ($user['role'] == 'teacher') {
                        $s = $db->prepare("SELECT full_name FROM teachers WHERE user_id = ?");
                        $s->execute([$user['id']]);
                        $info = $s->fetch(PDO::FETCH_ASSOC);
                    } else {
                        $s = $db->prepare("SELECT full_name FROM students WHERE user_id = ?");
                        $s->execute([$user['id']]);
                        $info = $s->fetch(PDO::FETCH_ASSOC);
                    }
                    $_SESSION['full_name'] = $info['full_name'] ?? 'User';
                    audit($db, 'login', 'users', $user['id']);

                    header('Location: ' . ($_SESSION['is_admin'] ? 'admin.php' : 'dashboard.php'));
                    exit;
                }
            } else {
                $db->prepare("INSERT INTO login_attempts (ip, email) VALUES (?, ?)")->execute([$ip, $email]);
                $error = 'Invalid email or password.';
            }
        } else {
            $error = 'Please fill in all fields.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - School Portal</title>
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
            <h3>School Portal</h3>
            <small>Sign in to your account</small>
        </div>
        <div class="card-body auth-body-card">
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <div class="mb-3 text-start">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="you@school.com" required autocomplete="email">
                </div>
                <div class="mb-4 text-start">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-brand w-100 fw-semibold">Sign In</button>
            </form>
            <p class="mt-3 mb-0 text-center small"><a href="register.php">Create an account</a></p>
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