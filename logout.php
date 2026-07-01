<?php
/**
 * Logout Handler - eJobOrder System
 * Securely destroys session and redirects to login
 */

require_once 'config/db.php';
require_once 'config/auth.php';
require_once 'lib/Logger.php';

// Log logout event before destroying session
if (isset($_SESSION['user_id'])) {
    $logger = new Logger($pdo);
    $logger->logAuthEvent('logout', [
        'user_id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? 'unknown',
        'session_duration' => time() - ($_SESSION['login_time'] ?? time())
    ]);
}

// Call logout function from auth.php
logoutUser();
?>
