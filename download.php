<?php
// ================================================================
// AUTHENTICATED FILE DOWNLOAD (replaces direct uploads/ URLs)
// Validates that the requester owns or is related to the file.
// ================================================================
require_once __DIR__ . '/config.php';
require_login();

$file = $_GET['f'] ?? '';
if ($file === '') {
    http_response_code(400);
    echo 'No file specified.';
    exit;
}

// Only the stored random name may be requested (no path traversal)
if (basename($file) !== $file) {
    http_response_code(400);
    echo 'Invalid file name.';
    exit;
}

$path = upload_dir() . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$uid = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

// ---- Access checks ----
$allowed = false;

// 1. Submission file: the student who submitted, or the class teacher
$q = $db->prepare("SELECT s.student_id, a.class_id FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE s.file_url = ?");
$q->execute([$file]);
if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $allowed = ((int)$r['student_id'] === $uid) || teacher_owns_class($db, $uid, $r['class_id']);
}

// 2. Assignment attachment: enrolled students or class teacher
if (!$allowed) {
    $q = $db->prepare("SELECT a.class_id FROM assignments a WHERE a.attachment = ?");
    $q->execute([$file]);
    if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        $allowed = student_in_class($db, $uid, $r['class_id']) || teacher_owns_class($db, $uid, $r['class_id']);
    }
}

// 3. Announcement attachment: enrolled students or class teacher
if (!$allowed) {
    $q = $db->prepare("SELECT a.class_id FROM announcements a WHERE a.attachment = ?");
    $q->execute([$file]);
    if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        $allowed = student_in_class($db, $uid, $r['class_id']) || teacher_owns_class($db, $uid, $r['class_id']);
    }
}

// 4. Profile photo: the owner or any admin
if (!$allowed) {
    $q1 = $db->prepare("SELECT user_id FROM teachers WHERE profile_photo = ?");
    $q1->execute([$file]);
    $q2 = $db->prepare("SELECT user_id FROM students WHERE profile_photo = ?");
    $q2->execute([$file]);
    $owner = $q1->fetchColumn() ?: $q2->fetchColumn();
    if ($owner !== false && ((int)$owner === $uid || !empty($_SESSION['is_admin']))) {
        $allowed = true;
    }
}

// 5. Admins can fetch anything
if (!$allowed && !empty($_SESSION['is_admin'])) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    echo 'You do not have permission to access this file.';
    exit;
}

// ---- Serve ----
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime_map = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed', '7z' => 'application/x-7z-compressed',
];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $file . '"');
readfile($path);
exit;