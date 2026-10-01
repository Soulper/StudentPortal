<?php
// ================================================================
// RESET THE SEEDED DEMO PASSWORDS
//
//   php scripts/reset_demo_passwords.php
//
// The database seeds demo accounts with the well-known password
// "password123". That is fine for a local demo and NOT fine on a
// public URL. Run this once immediately after deploying, and again
// whenever you re-seed a live database.
//
// Every account still using a known demo password is given a strong
// random one, which is printed ONCE and not stored anywhere.
// ================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../db.php';

const DEMO_PASSWORDS = ['password123'];

// Accounts created by register.php require 8+ characters; generated
// passwords comfortably exceed that.
function random_password(int $len = 20): string {
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

$rows = $db->query("SELECT u.id, u.email, u.role, u.is_admin, u.password
                    FROM users u ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);

$upd = $db->prepare("UPDATE users SET password=? WHERE id=?");
$changed = [];
$already = [];

foreach ($rows as $u) {
    $isDemo = false;
    foreach (DEMO_PASSWORDS as $p) {
        if (password_verify($p, $u['password'])) { $isDemo = true; break; }
    }
    if (!$isDemo) { $already[] = $u['email']; continue; }

    $new = random_password();
    $upd->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    $changed[] = [
        'email' => $u['email'],
        'role' => $u['role'] . ($u['is_admin'] ? ' (ADMIN)' : ''),
        'password' => $new,
    ];
}

echo "\n============================================================\n";
if (!$changed) {
    echo " No accounts were using a demo password.\n";
    if ($already) {
        echo " The following already have their own password (left alone):\n";
        foreach ($already as $e) echo "   - $e\n";
    }
} else {
    echo " RESET " . count($changed) . " account(s).\n";
    echo " These passwords are shown ONCE - copy them now.\n";
    echo "============================================================\n";
    foreach ($changed as $c) {
        printf("  %-28s %-14s %s\n", $c['email'], $c['role'], $c['password']);
    }
    echo "============================================================\n";
    if ($already) {
        echo " Left unchanged (already have their own password):\n";
        foreach ($already as $e) echo "   - $e\n";
    }
    echo "\n Sign in with each account above, then change the password\n";
    echo " from Profile -> Change Password.\n";
}
echo "\n";
