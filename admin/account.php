<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require '../lib/Validator.php';
require '../lib/Logger.php';
require '../lib/auto_close.php';

requireAdmin();

// Auto-close resolved tickets older than 7 days
$autoClosed = autoCloseResolvedTickets($pdo);
if ($autoClosed > 0) {
    // Optional: could store in session for display, but for now just run silently
}

// Handle AJAX actions
$action = $_POST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'change_password':
                // CSRF validation
                $csrf_token = $_POST['csrf_token'] ?? '';
                if (!validateCSRFToken($csrf_token)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
                    exit;
                }

                // Get form data
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                // Validation
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit;
                }

                if ($newPassword !== $confirmPassword) {
                    echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
                    exit;
                }

                // Validate password strength
                if (!validatePasswordStrength($newPassword)) {
                    echo json_encode(['success' => false, 'message' => 'Password does not meet requirements']);
                    exit;
                }

                // Verify current password
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user || !password_verify($currentPassword, $user['password'])) {
                    echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                    exit;
                }

                // Update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $updateStmt->execute([$hashedPassword, $_SESSION['user_id']]);

                // Log the password change
                $logger = new Logger($pdo);
                $logger->logEntityAction('password_changed', 'user', $_SESSION['user_id'], [
                    'changed_by' => $_SESSION['user_id'],
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ], 'medium');

                echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
                break;

            default:
                echo json_encode(['error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => htmlspecialchars($e->getMessage())]);
    }
    exit;
}

// Get user profile information
function getUserProfile($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.role,
            u.status,
            p.fullname,
            p.gmail,
            p.created_at,
            p.updated_at
        FROM users u
        LEFT JOIN personnels p ON u.personnel_id = p.id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get account statistics
function getAccountStats($pdo, $userId, $isAdmin = false) {
    if ($isAdmin) {
        // Admin statistics
        $stats = $pdo->prepare("
            SELECT
                COUNT(*) as total_tickets,
                SUM(CASE WHEN status = 'RESOLVED' THEN 1 ELSE 0 END) as resolved_tickets,
                SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) as closed_tickets,
                MAX(created_at) as last_ticket_created
            FROM tickets
            WHERE created_by = (SELECT personnel_id FROM users WHERE id = ?)
        ");
    } else {
        // Regular user statistics
        $stats = $pdo->prepare("
            SELECT
                COUNT(*) as my_tickets,
                SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) as open_tickets,
                SUM(CASE WHEN status = 'IN_PROGRESS' THEN 1 ELSE 0 END) as in_progress_tickets,
                SUM(CASE WHEN status = 'RESOLVED' THEN 1 ELSE 0 END) as resolved_tickets
            FROM tickets
            WHERE created_by = (SELECT personnel_id FROM users WHERE id = ?)
        ");
    }

    $stats->execute([$userId]);
    return $stats->fetch(PDO::FETCH_ASSOC);
}

// Password strength validation function
function validatePasswordStrength($password) {
    return strlen($password) >= 8 &&
           preg_match('/[A-Z]/', $password) &&
           preg_match('/[a-z]/', $password) &&
           preg_match('/\d/', $password) &&
           preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password);
}

// Get user profile and stats
$userProfile = getUserProfile($pdo, $_SESSION['user_id']);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$accountStats = getAccountStats($pdo, $_SESSION['user_id'], $isAdmin);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

  <title>FPIAP-Service Management and Response Ticketing System</title>
</head>
<body class="d-flex flex-column min-vh-100">

<nav class="navbar sticky-top navbar-expand-lg navbar-light shadow-sm" style="background-color: #0ef;">
  <div class="container-fluid">

    <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
      <img src="../assets/freewifilogo.png" alt="Logo" width="100" height="100" class="me-2">
      <img src="../assets/FPIAP-SMARTs.png" alt="Logo" width="100" height="100" class="me-2">
      
      <div class="d-flex flex-column ms-0">
        <span class="fw-bold">FPIAP-SMARTs</span>
        <span class="fw-bold small align-self-center">ADMIN PANEL</span>
      </div>
    </a>

  <hr class="mx-0 my-2 opacity-25">

  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
    <span class="navbar-toggler-icon"></span>
  </button>

  <!-- Navigation Links -->
  <div class="collapse navbar-collapse" id="mainNavbar">
    <ul class="navbar-nav me-auto mb-2 mb-lg-0">

    <li class="nav-item">
      <a class="nav-link" href="dashboard.php">Dashboard</a>
    </li>

    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      Tickets
      </a>
      <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
      <li><a class="dropdown-item" href="viewtickets.php">View Tickets</a></li>
      <li><a class="dropdown-item" href="ticket.php">Create Ticket</a></li> 
      </ul>
    </li>

    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      Sites
      </a>
      <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
      <li><a class="dropdown-item" href="site.php">Manage Sites</a></li>
      <li><a class="dropdown-item" href="site_report.php">Sites Report</a></li> 
      </ul>
    </li>

    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      Reports
      </a>
      <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
      <li><a class="dropdown-item" href="ticket_report.php">Ticket Report</a></li>
      <li><a class="dropdown-item" href="site_report.php">Sites Report</a></li>
      </ul>
    </li>
        

    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            Setting
        </a>
        <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
        <li><a class="dropdown-item" href="personnel.php">Personnels</a></li>
        <li><a class="dropdown-item" href="user.php">User's Management</a></li>
        <li><a class="dropdown-item" href="systemlog.php">System Log</a></li>
        <li><a class="dropdown-item" href="backup.php">Backup Management</a></li>
        <li><a class="dropdown-item" href="data_export.php">Data Export</a></li>
        <li><a class="dropdown-item" href="history.php">History</a></li>
        </ul>
    </li>
        
    </ul>

    <!-- Right Icons -->
    <ul class="navbar-nav ms-auto align-items-center">

    <!-- Notification Bell -->
    <li class="nav-item dropdown me-3">
          <a id="notificationBell" class="nav-link position-relative dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-bell fs-5"></i>
            <span id="notificationBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger visually-hidden">0</span>
          </a>
          <ul id="notificationDropdown" class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationBell">
            <li class="dropdown-item text-center text-muted small">Loading...</li>
          </ul>
    </li>

    <!-- Account Name Display -->
    <li class="nav-item d-flex align-items-center me-3">
      <div class="d-flex flex-column text-end">
        <span class="fw-semibold text-dark small"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown User'); ?></span>
      </div>
    </li>

    <!-- Profile Dropdown -->
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
      <i class="bi bi-person-circle fs-4 me-1"></i>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item active" href="account.php">My Account</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="../logout.php">Logout</a></li>
      </ul>
    </li>

    </ul>
  </div>
  </div>
</nav>

<main class="flex-grow-1 overflow-auto">
<div class="container-fluid mt-4">
    <div class="row">
        <!-- Profile Information Card -->
        <div class="col-lg-8 col-12 pe-3">
            <div id="alertContainer"></div>

            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Profile Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Full Name</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($userProfile['fullname'] ?? ''); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Email</label>
                                <input type="email" class="form-control" value="<?php echo htmlspecialchars($userProfile['gmail'] ?? ''); ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Role</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst($userProfile['role'] ?? '')); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Account Status</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst($userProfile['status'] ?? '')); ?>" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Account Created</label>
                                <input type="text" class="form-control" value="<?php echo $userProfile['created_at'] ? date('M d, Y H:i', strtotime($userProfile['created_at'])) : ''; ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Last Updated</label>
                                <input type="text" class="form-control" value="<?php echo $userProfile['updated_at'] ? date('M d, Y H:i', strtotime($userProfile['updated_at'])) : 'Never'; ?>" readonly>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Change Password Card -->
            <div class="card shadow-sm mt-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">Change Password</h5>
                </div>
                <div class="card-body">
                    <form id="passwordForm">
                        <div class="mb-3">
                            <label class="form-label">Current Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="currentPassword" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="newPassword" required>
                            <div class="password-strength mt-2">
                                <small class="text-muted">Password must contain:</small>
                                <ul class="text-muted small mb-0">
                                    <li id="length-check">At least 8 characters</li>
                                    <li id="uppercase-check">One uppercase letter</li>
                                    <li id="lowercase-check">One lowercase letter</li>
                                    <li id="number-check">One number</li>
                                    <li id="special-check">One special character</li>
                                </ul>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="confirmPassword" required>
                            <div class="invalid-feedback">Passwords do not match</div>
                        </div>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <button type="submit" class="btn btn-success" id="changePasswordBtn">
                            <i class="bi bi-check-circle"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Account Summary Card -->
        <div class="col-lg-4 col-12 ps-3">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Account Summary</h5>
                </div>
                <div class="card-body">
                    <?php if ($isAdmin): ?>
                        <div class="mb-3">
                            <h6 class="text-muted">Tickets Created</h6>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-primary fs-6 me-2"><?php echo intval($accountStats['total_tickets'] ?? 0); ?></span>
                                <small class="text-muted">total</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <h6 class="text-muted">Resolution Rate</h6>
                            <div class="d-flex align-items-center">
                                <?php
                                $total = intval($accountStats['total_tickets'] ?? 0);
                                $resolved = intval($accountStats['resolved_tickets'] ?? 0);
                                $closed = intval($accountStats['closed_tickets'] ?? 0);
                                $resolvedRate = $total > 0 ? round((($resolved + $closed) / $total) * 100) : 0;
                                ?>
                                <span class="badge bg-success fs-6 me-2"><?php echo $resolvedRate; ?>%</span>
                                <small class="text-muted">resolved</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <h6 class="text-muted">Last Ticket Created</h6>
                            <small class="text-muted">
                                <?php echo $accountStats['last_ticket_created'] ? date('M d, Y', strtotime($accountStats['last_ticket_created'])) : 'Never'; ?>
                            </small>
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <h6 class="text-muted">My Tickets</h6>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-primary fs-6 me-2"><?php echo intval($accountStats['my_tickets'] ?? 0); ?></span>
                                <small class="text-muted">total</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <h6 class="text-muted">Open Tickets</h6>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-warning fs-6 me-2"><?php echo intval($accountStats['open_tickets'] ?? 0); ?></span>
                                <small class="text-muted">pending</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <h6 class="text-muted">Resolved Tickets</h6>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-success fs-6 me-2"><?php echo intval($accountStats['resolved_tickets'] ?? 0); ?></span>
                                <small class="text-muted">completed</small>
                            </div>
                        </div>
                    <?php endif; ?>

                    <hr>

                    <div class="mb-3">
                        <h6 class="text-muted">Account Status</h6>
                        <span class="badge bg-success">Active</span>
                    </div>

                    <div class="mb-3">
                        <h6 class="text-muted">Member Since</h6>
                        <small class="text-muted">
                            <?php echo $userProfile['created_at'] ? date('F Y', strtotime($userProfile['created_at'])) : 'Unknown'; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</main>



<footer class="bg-dark text-light text-center py-3 mt-auto">
  <div class="container">
  <small>
    <?php echo date('Y'); ?> &copy; FREE PUBLIC INTERNET ACCESS PROGRAM - SERVICE MANAGEMENT AND RESPONSE TICKETING SYSTEM (FPIAP-SMARTs). All Rights Reserved.
  </small>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        fetchNotifications();
        setInterval(fetchNotifications, 60000);

        const bellToggle = document.getElementById('notificationBell');
        if (bellToggle) {
            bellToggle.addEventListener('show.bs.dropdown', fetchNotifications);
        }

        // Initialize password form
        initPasswordForm();
    });

    // Password strength validation
    function validatePasswordStrength(password) {
        const checks = {
            length: password.length >= 8,
            uppercase: /[A-Z]/.test(password),
            lowercase: /[a-z]/.test(password),
            number: /\d/.test(password),
            special: /[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/.test(password)
        };

        // Update UI indicators
        Object.keys(checks).forEach(check => {
            const element = document.getElementById(`${check}-check`);
            if (element) {
                element.className = checks[check] ? 'text-success' : 'text-muted';
            }
        });

        return Object.values(checks).every(check => check);
    }

    // Initialize password form
    function initPasswordForm() {
        const newPasswordInput = document.getElementById('newPassword');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const passwordForm = document.getElementById('passwordForm');

        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', function() {
                validatePasswordStrength(this.value);
            });
        }

        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', function() {
                const newPassword = document.getElementById('newPassword').value;
                const isValid = this.value === newPassword;
                this.classList.toggle('is-invalid', !isValid && this.value.length > 0);
                this.classList.toggle('is-valid', isValid && this.value.length > 0);
            });
        }

        if (passwordForm) {
            passwordForm.addEventListener('submit', changePassword);
        }
    }

    // Change password function
    async function changePassword(event) {
        event.preventDefault();

        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        const submitBtn = document.getElementById('changePasswordBtn');

        // Basic validation
        if (!currentPassword || !newPassword || !confirmPassword) {
            showAlert('All fields are required', 'error');
            return;
        }

        if (newPassword !== confirmPassword) {
            showAlert('New passwords do not match', 'error');
            return;
        }

        if (!validatePasswordStrength(newPassword)) {
            showAlert('Password does not meet requirements', 'error');
            return;
        }

        // Disable button and show loading
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Updating...';

        try {
            const formData = new FormData();
            formData.append('action', 'change_password');
            formData.append('current_password', currentPassword);
            formData.append('new_password', newPassword);
            formData.append('confirm_password', confirmPassword);
            formData.append('csrf_token', csrfToken);

            const response = await fetch('account.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showAlert(result.message, 'success');
                document.getElementById('passwordForm').reset();
                // Reset password strength indicators
                document.querySelectorAll('#passwordForm ul li').forEach(li => {
                    li.className = 'text-muted';
                });
            } else {
                showAlert(result.message, 'error');
            }
        } catch (error) {
            showAlert('An error occurred. Please try again.', 'error');
        } finally {
            // Re-enable button
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Update Password';
        }
    }

    // Show alert function
    function showAlert(message, type = 'success') {
        const container = document.getElementById('alertContainer');
        const alertClass = type === 'error' ? 'alert-danger' : 'alert-success';
        const html = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        container.innerHTML = html;
        setTimeout(() => {
            const alert = document.querySelector('.alert');
            if (alert) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            }
        }, 5000);
    }

    // Fetch notifications from notification.php
    async function fetchNotifications() {
        const dropdown = document.getElementById('notificationDropdown');
        const badge = document.getElementById('notificationBadge');
        if (!dropdown) return;
        try {
            const resp = await fetch('notification.php', { method: 'GET', cache: 'no-cache' });
            if (!resp.ok) throw new Error('Network response not ok');
            const html = await resp.text();
            
            if (html && html.trim().length > 0) {
                dropdown.innerHTML = html;
            } else {
                dropdown.innerHTML = '<li class="dropdown-item text-center text-muted small">No notifications</li>';
            }

            // Attach click handlers to notification items
            dropdown.querySelectorAll('.notification-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    const notificationId = this.getAttribute('data-notification-id');
                    if (notificationId) {
                        fetch('../notif/api.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=mark_read&notification_id=' + notificationId
                        }).catch(err => console.error('Failed to mark notification as read:', err));
                        this.classList.remove('unread');
                    }
                });
            });

            const unread = dropdown.querySelectorAll('.notification-item.unread, li[data-unread="1"]').length;
            if (unread > 0) {
                badge.textContent = String(unread);
                badge.classList.remove('visually-hidden');
            } else {
                badge.classList.add('visually-hidden');
            }
        } catch (err) {
            dropdown.innerHTML = '<li class="dropdown-item text-danger small">Error loading notifications</li>';
            if (badge) badge.classList.add('visually-hidden');
            console.error('Failed to load notifications:', err);
        }
    }
</script>

</body>
</html>