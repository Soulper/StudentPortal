<?php
require_once __DIR__ . '/vendor/autoload.php';

// Align the app clock with the school (local) timezone, not PHP's default UTC,
// so due-date / cutoff / is-late comparisons match the dates teachers enter.
date_default_timezone_set('Asia/Manila');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/grade_engine.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ---------------------------------------------------------------
// SESSION / AUTH HELPERS
// ---------------------------------------------------------------
function current_user_id() { return $_SESSION['user_id'] ?? 0; }
function current_role() { return $_SESSION['role'] ?? ''; }
function is_admin_user() { return !empty($_SESSION['is_admin']); }
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}
function require_admin() {
    require_login();
    if (empty($_SESSION['is_admin'])) {
        header('Location: dashboard.php');
        exit;
    }
}

function csrf_token() {
    return $_SESSION['csrf'] ?? '';
}

// ---------------------------------------------------------------
// UI HELPER — clean inline stroke icons (feather-style, 24x24)
// ---------------------------------------------------------------
function icon($name, $size = 22) {
    static $icons = [
        'school'   => '<path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/>',
        'book'     => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'award'    => '<circle cx="12" cy="8" r="6"/><path d="M15.5 13l1.5 9-5-3-5 3 1.5-9"/>',
        'clipboard'=> '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>',
        'layers'   => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'inbox'    => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    ];
    $d = isset($icons[$name]) ? $icons[$name] : $icons['book'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$size . '" height="' . (int)$size
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
        . ' stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

function flash($msg, $type = 'success') {
    if (!in_array($type, ['success', 'danger', 'info', 'warning'], true)) $type = 'info';
    $_SESSION['flash'] = $msg;
    $_SESSION['flash_type'] = $type;
}

// ---------------------------------------------------------------
// SETTINGS
// ---------------------------------------------------------------
function setting($db, $key, $default = '') {
    $s = $db->prepare("SELECT value FROM settings WHERE key=?");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting($db, $key, $value) {
    $s = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    $s->execute([$key, $value]);
}

// ---------------------------------------------------------------
// NOTIFICATIONS & AUDIT
// ---------------------------------------------------------------
function notify($db, $user_id, $message, $link = '#', $type = 'general') {
    $s = $db->prepare("INSERT INTO notifications (user_id, message, link, type) VALUES (?, ?, ?, ?)");
    $s->execute([$user_id, $message, $link, $type]);
}

function notify_class_students($db, $class_id, $message, $link = '#') {
    $m = $db->prepare("SELECT student_id FROM class_members WHERE class_id=?");
    $m->execute([$class_id]);
    foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        notify($db, $sid, $message, $link);
    }
}

function audit($db, $action, $target_type = null, $target_id = null, $old_value = null, $new_value = null) {
    $s = $db->prepare("INSERT INTO audit_logs (user_id, action, target_type, target_id, old_value, new_value, ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $s->execute([current_user_id() ?: null, $action, $target_type, $target_id, $old_value, $new_value, $_SERVER['REMOTE_ADDR'] ?? '']);
}

function unread_count($db, $user_id) {
    $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $s->execute([$user_id]);
    return (int)$s->fetchColumn();
}

// ---------------------------------------------------------------
// ACCESS CHECKS
// ---------------------------------------------------------------
function teacher_owns_class($db, $teacher_id, $class_id) {
    $s = $db->prepare("SELECT COUNT(*) FROM classes WHERE id=? AND teacher_id=?");
    $s->execute([$class_id, $teacher_id]);
    return $s->fetchColumn() > 0;
}

function student_in_class($db, $student_id, $class_id) {
    $s = $db->prepare("SELECT COUNT(*) FROM class_members WHERE class_id=? AND student_id=?");
    $s->execute([$class_id, $student_id]);
    return $s->fetchColumn() > 0;
}

// ---------------------------------------------------------------
// DEPLOYMENT PATHS
// ---------------------------------------------------------------
// Where uploaded files live. Set UPLOAD_DIR on the host, e.g.
//   UPLOAD_DIR=/var/lib/studentportal/uploads
// Defaults to the local folder so development is unchanged. It must be
// writable by the web server, and must sit OUTSIDE the document root on
// a real deployment so files are only ever served through download.php.
function upload_dir() {
    static $dir = null;
    if ($dir === null) {
        $dir = getenv('UPLOAD_DIR') ?: __DIR__ . '/uploads';
        $dir = rtrim($dir, '/\\');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
    return $dir;
}

// Absolute URL of the deployment, used to build links. Optional.
function app_base_url() {
    $b = getenv('APP_URL') ?: '';
    return rtrim($b, '/');
}

// ---------------------------------------------------------------
// FILE UPLOAD (extension allowlist + MIME sniffing)
// ---------------------------------------------------------------
function handle_upload($file, $prefix, $allowed_exts = null, $max_size_mb = 10) {
    if ($allowed_exts === null) {
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar', '7z'];
    }
    if (!isset($file) || !is_array($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['file' => '', 'error' => ''];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['file' => '', 'error' => 'Upload failed (code ' . (int)$file['error'] . ').'];
    }
    if ($file['size'] > $max_size_mb * 1048576) {
        return ['file' => '', 'error' => 'File too large (max ' . $max_size_mb . 'MB).'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowed_exts, true)) {
        return ['file' => '', 'error' => 'File type not allowed: ' . ($ext !== '' ? '.' . $ext : 'unknown')];
    }
    // MIME sniff (best-effort; empty/mismatch is rejected for executables)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $ok_mimes = [
        'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/gif' => ['gif'],
        'image/webp' => ['webp'], 'application/pdf' => ['pdf'],
        'text/plain' => ['txt'], 'text/csv' => ['csv'],
        'application/zip' => ['zip'], 'application/x-7z-compressed' => ['7z'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/msword' => ['doc'], 'application/vnd.ms-excel' => ['xls'], 'application/vnd.ms-powerpoint' => ['ppt'],
        'application/x-rar-compressed' => ['rar'], 'application/vnd.rar' => ['rar'],
        'application/octet-stream' => ['doc', 'xls', 'ppt', 'rar', 'zip'],
    ];
    if (isset($ok_mimes[$mime])) {
        if (!in_array($ext, $ok_mimes[$mime], true) && !in_array($mime, ['application/octet-stream'], true)) {
            return ['file' => '', 'error' => 'File content does not match its extension.'];
        }
    }
    if (!is_dir(upload_dir())) mkdir(upload_dir(), 0775, true);
    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], upload_dir() . '/' . $name)) {
        return ['file' => '', 'error' => 'Could not save the uploaded file.'];
    }
    return ['file' => $name, 'error' => ''];
}