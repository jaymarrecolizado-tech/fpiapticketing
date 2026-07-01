<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require '../lib/Validator.php';
require '../lib/Logger.php';

requireLogin();

// Handle AJAX actions
$action = $_POST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    try {
        switch ($action) {
            case 'change_password':
                $csrf_token = $_POST['csrf_token'] ?? '';
                if (!validateCSRFToken($csrf_token)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
                    exit;
                }

                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit;
                }
                if ($newPassword !== $confirmPassword) {
                    echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
                    exit;
                }
                if (strlen($newPassword) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                    exit;
                }

                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user || !password_verify($currentPassword, $user['password'])) {
                    echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                    exit;
                }

                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $updateStmt->execute([$hashedPassword, $_SESSION['user_id']]);

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

// Get user profile
$stmt = $pdo->prepare("
    SELECT u.id, u.role, u.status, p.fullname, p.gmail, p.created_at, p.updated_at
    FROM users u LEFT JOIN personnels p ON u.personnel_id = p.id
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

// Get ticket stats
$stats = $pdo->prepare("
    SELECT COUNT(*) as total, SUM(CASE WHEN status='OPEN' THEN 1 ELSE 0 END) as open_count,
           SUM(CASE WHEN status='RESOLVED' THEN 1 ELSE 0 END) as resolved_count
    FROM tickets WHERE created_by = (SELECT personnel_id FROM users WHERE id = ?)
");
$stats->execute([$_SESSION['user_id']]);
$accountStats = $stats->fetch(PDO::FETCH_ASSOC);

$activePage = 'dashboard';
?>
<?php require __DIR__ . '/../includes/user_header.php'; ?>

<main class="flex-grow-1 overflow-auto">
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-lg-8 col-12 pe-3">
            <div id="alertContainer"></div>

            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">My Account</h5>
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

            <!-- Change Password -->
            <div class="card shadow-sm mt-4">
                <div class="card-header bg-warning">
                    <h5 class="mb-0">Change Password</h5>
                </div>
                <div class="card-body">
                    <form id="passwordForm" onsubmit="changePassword(event)">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Current Password</label>
                            <input type="password" class="form-control" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">New Password</label>
                            <input type="password" class="form-control" name="new_password" required minlength="6">
                            <small class="text-muted">Minimum 6 characters</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Confirm New Password</label>
                            <input type="password" class="form-control" name="confirm_password" required minlength="6">
                        </div>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-key"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Stats Sidebar -->
        <div class="col-lg-4 col-12 ps-3">
            <div class="card shadow-sm" style="position: sticky; top: 20px;">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">My Stats</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Tickets</span>
                        <span class="badge bg-primary"><?php echo (int)($accountStats['total'] ?? 0); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Open</span>
                        <span class="badge bg-danger"><?php echo (int)($accountStats['open_count'] ?? 0); ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Resolved</span>
                        <span class="badge bg-success"><?php echo (int)($accountStats['resolved_count'] ?? 0); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<script>
function changePassword(e) {
    e.preventDefault();
    const form = document.getElementById('passwordForm');
    const formData = new FormData(form);
    formData.append('action', 'change_password');

    fetch('account.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            form.reset();
        } else {
            showAlert(data.message, 'danger');
        }
    })
    .catch(err => showAlert('Error: ' + err.message, 'danger'));
}

function showAlert(message, type) {
    const container = document.getElementById('alertContainer');
    container.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show">' + message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    setTimeout(() => { container.innerHTML = ''; }, 5000);
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
