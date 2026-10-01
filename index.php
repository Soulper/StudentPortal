<?php
// Front door. Sends signed-in users to their dashboard and everyone else
// to the login page, so visiting "/" does something sensible instead of
// returning a 404 from the built-in PHP server.
require_once __DIR__ . '/config.php';

header('Location: ' . (isset($_SESSION['user_id']) ? 'dashboard.php' : 'login.php'));
exit;
