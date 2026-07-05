<?php
/**
 * Authentication and Security Configuration
 * Handles session configuration, CSRF token generation/validation, and authentication functions
 */

// Load security headers (must be before any output)
require_once __DIR__ . '/security_headers.php';

// Session configuration with secure flags
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0); // Set to 0 for localhost/HTTP, 1 for production with HTTPS
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', 3600); // 1 hour session timeout
    ini_set('session.name', 'JOBORDER_SESSID');
    session_start();
} else {
    $existingSessionId = session_id();
    if (empty($existingSessionId)) {
        session_start();
    }
}

/**
 * Generate CSRF Token
 * Creates a cryptographically secure token and stores it in session
 * 
 * @return string The CSRF token for use in forms
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF Token
 * Safely compares the provided token against the session token
 * 
 * @param string $token The token to validate (typically from POST data)
 * @return bool True if token is valid, false otherwise
 */
function validateCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require Login
 * Redirects to login page if user is not authenticated
 * 
 * @return void Redirects if not logged in
 */
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php");
        exit;
    }
}

/**
 * Require Admin Role
 * Redirects to login if user is not authenticated or does not have admin role
 * 
 * @return void Redirects if not admin
 */
function requireAdmin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php");
        exit;
    }
    if ($_SESSION['role'] !== 'admin') {
        header("Location: ../users/dashboard.php");
        exit;
    }
}

/**
 * Require Specific Role
 * Checks if user has a specific role
 * 
 * @param string $role The required role
 * @return bool True if user has the role
 */
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Get Current User ID
 * 
 * @return int|null The current user ID or null if not logged in
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get Current User Role
 * 
 * @return string|null The current user role or null if not logged in
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Logout User
 * Destroys session and redirects to login page
 * 
 * @return void
 */
function logoutUser() {
    session_destroy();
    header("Location: index.php");
    exit;
}

/**
 * Get or create a PermissionManager for the current user.
 * Loads permissions into session for caching.
 */
function getPermissionManager() {
    static $pm = null;
    if ($pm !== null) return $pm;

    require_once __DIR__ . '/../lib/PermissionManager.php';
    global $pdo;

    $pm = new PermissionManager($pdo);

    // Cache permissions in session for this request
    if (!isset($_SESSION['permissions_loaded'])) {
        $pm->loadPermissions($_SESSION['user_id']);
        $_SESSION['cached_permissions'] = $pm->getAll();
        $_SESSION['cached_role'] = $pm->getRole();
        $_SESSION['permissions_loaded'] = true;
    } else {
        $pm->permissions = $_SESSION['cached_permissions'] ?? [];
        $pm->role = $_SESSION['cached_role'] ?? $_SESSION['role'] ?? null;
    }

    return $pm;
}

/**
 * Check if current user has a specific permission.
 */
function hasPermission($permission) {
    return getPermissionManager()->has($permission);
}

/**
 * Require a specific permission. Shows 403 if denied.
 */
function requirePermission($permission) {
    if (!hasPermission($permission)) {
        http_response_code(403);
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Permission denied: ' . $permission]);
        } else {
            echo '<div class="container mt-5"><div class="alert alert-danger"><h4>Access Denied</h4><p>You do not have permission to access this page. Required: <code>' . htmlspecialchars($permission) . '</code></p><a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a></div></div>';
        }
        exit;
    }
}
?>
