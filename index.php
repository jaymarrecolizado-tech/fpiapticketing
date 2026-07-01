<?php
/**
 * Login Page - eJobOrder System
 * Secure authentication entry point with CSRF protection, rate limiting, and account lockout
 */

require_once 'config/db.php';
require_once 'config/auth.php';
require_once 'lib/Validator.php';
require_once 'lib/Sanitizer.php';
require_once 'lib/Logger.php';

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: users/dashboard.php");
    }
    exit;
}

// Handle logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logoutUser();
    exit;
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ===== SECURITY: CSRF Token Validation =====
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // ===== INPUT VALIDATION =====
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        // Validate inputs are not empty
        if (empty($username) || empty($password)) {
            $error = 'Username and password are required.';
        }
        // Validate username format (should be email)
        elseif (!Validator::email($username)) {
            $error = 'Please enter a valid email address.';
        }
        // Validate password length
        elseif (strlen($password) < 1 || strlen($password) > 255) {
            $error = 'Invalid password provided.';
        }
        else {
            // ===== RATE LIMITING: Check login attempts =====
            $ip_address = $_SERVER['REMOTE_ADDR'];
            $attempt_key = "login_attempts_{$ip_address}";
            $lockout_key = "login_lockout_{$ip_address}";

            // Check if IP is currently locked out
            if (isset($_SESSION[$lockout_key]) && $_SESSION[$lockout_key] > time()) {
                $remaining = ceil(($_SESSION[$lockout_key] - time()) / 60);
                $error = "Too many failed login attempts. Please try again in {$remaining} minute(s).";
            }
            // Check login attempt count
            elseif (isset($_SESSION[$attempt_key]) && $_SESSION[$attempt_key] >= 5) {
                // Lock out for 15 minutes
                $_SESSION[$lockout_key] = time() + (15 * 60);
                $error = "Too many failed login attempts. Please try again in 15 minutes.";
            }
            else {
                // ===== DATABASE AUTHENTICATION =====
                try {
                    // Normalize username for database query (trim and handle unicode)
                    $username_sanitized = Sanitizer::normalize($username);

                    // Query to find user by email (username is gmail from personnel)
                    // Join with personnel table to get email
                    $stmt = $pdo->prepare("
                        SELECT u.id, u.password, u.role, u.status, u.personnel_id, p.fullname, p.gmail
                        FROM users u
                        JOIN personnels p ON u.personnel_id = p.id
                        WHERE p.gmail = ? LIMIT 1
                    ");
                    $stmt->execute([$username_sanitized]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    // User found and credentials valid
                    if ($user && password_verify($password, $user['password'])) {
                        // Check if account is active
                        if ($user['status'] !== 'active') {
                            $error = 'Your account is inactive. Please contact an administrator.';
                            // Record failed attempt (account inactive is a security event)
                            $_SESSION[$attempt_key] = ($_SESSION[$attempt_key] ?? 0) + 1;

                            // Log inactive account access attempt
                            $logger = new Logger($pdo);
                            $logger->logAuthEvent('login_failure', [
                                'username' => $user['gmail'],
                                'user_id' => $user['id'],
                                'reason' => 'account_inactive'
                            ]);
                        } else {
                            // ===== SUCCESSFUL LOGIN: Create session =====
                            // Regenerate session ID to prevent session fixation attacks
                            session_regenerate_id(true);

                            // Set session variables
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['personnel_id'] = $user['personnel_id'];
                            $_SESSION['username'] = $user['fullname'];
                            $_SESSION['email'] = $user['gmail'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['login_time'] = time();

                            // Clear login attempts on successful login
                            unset($_SESSION[$attempt_key]);
                            unset($_SESSION[$lockout_key]);

                            // Log successful login
                            $logger = new Logger($pdo);
                            $logger->logAuthEvent('login_success', [
                                'username' => $user['gmail'],
                                'user_id' => $user['id'],
                                'role' => $user['role']
                            ]);

                            // Redirect to appropriate dashboard
                            if ($user['role'] === 'admin') {
                                header("Location: admin/dashboard.php");
                            } else {
                                header("Location: users/dashboard.php");
                            }
                            exit;
                        }
                    } else {
                        // Invalid credentials - increment failed attempt counter
                        $_SESSION[$attempt_key] = ($_SESSION[$attempt_key] ?? 0) + 1;
                        $error = 'Invalid email or password.';

                        // Log failed login attempt
                        $logger = new Logger($pdo);
                        $logger->logAuthEvent('login_failure', [
                            'username' => $username_sanitized,
                            'reason' => 'invalid_credentials',
                            'attempt_count' => $_SESSION[$attempt_key]
                        ]);
                    }
                } catch (PDOException $e) {
                    // Database error - don't reveal details to user
                    error_log('Login database error: ' . $e->getMessage());
                    $error = 'An error occurred during login. Please try again.';
                }
            }
        }
    }
}

// Generate CSRF token for form
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FPIAP SMARTs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light" style="background: url('assets/FPIAP-SMARTs.png') no-repeat center center fixed; background-size: 43% auto;">
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="card shadow-lg" style="width: 100%; max-width: 500px;">
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <img src="assets/freewifilogo.png" alt="Free WiFi Logo" class="mb-3" width="200" height="200">
                    <p class="text-muted small">Free Public Internet Access Program - Service Management and Response Ticketing System 
                        <br>DICT - Region II</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                        <strong>Login Failed:</strong> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" novalidate>
                    <div class="mb-3">
                        <label for="username" class="form-label">Email Address</label>
                        <input 
                            type="email" 
                            class="form-control" 
                            id="username" 
                            name="username" 
                            placeholder="Enter your email" 
                            required 
                            autocomplete="email"
                            value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                        >
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input 
                            type="password" 
                            class="form-control" 
                            id="password" 
                            name="password" 
                            placeholder="Enter your password" 
                            required 
                            autocomplete="current-password"
                        >
                    </div>

                    <!-- CSRF Token (hidden) -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                        Sign In
                    </button>
                </form>

                <div class="text-center mt-4">
                    <small class="text-muted">
                        🔒 Secure Login
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
