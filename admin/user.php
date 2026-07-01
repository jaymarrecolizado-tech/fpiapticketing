<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Validator.php';
require '../lib/Sanitizer.php';
require '../lib/Logger.php';
require '../notif/notification.php';

// ===== SECURITY: Enforce admin authentication =====
requireAdmin();

$action = $_POST['action'] ?? '';

// Autocomplete Search for Personnel (Excluding those who already have user accounts)
if ($action == 'search_personnel') {
    $query = $_POST['query'] ?? '';
    
    // Validate and sanitize query
    if (strlen($query) > 100) {
        $query = substr($query, 0, 100);
    }
    if (!empty($query)) {
        $query = Sanitizer::normalize($query);
    }
    
    $page = (int)($_POST['page'] ?? 1);
    $limit = 5;
    $offset = ($page - 1) * $limit;
    
    try {
        // Base condition: Personnels NOT in users table
        $whereClause = "WHERE u.id IS NULL";
        $params = [];
        
        // Add search filter if query exists
        if (!empty($query)) {
            $whereClause .= " AND (p.fullname LIKE ? OR p.gmail LIKE ?)";
            $searchTerm = '%' . $query . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Count total results for pagination
        $sqlCount = "SELECT COUNT(*) 
                     FROM personnels p 
                     LEFT JOIN users u ON p.id = u.personnel_id 
                     $whereClause";
        $stmtCount = $pdo->prepare($sqlCount);
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();
        
        // Fetch paginated results
        $sql = "SELECT p.id, p.fullname, p.gmail 
                FROM personnels p 
                LEFT JOIN users u ON p.id = u.personnel_id 
                $whereClause 
                ORDER BY p.fullname ASC 
                LIMIT $limit OFFSET $offset";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format display name
        foreach ($results as &$row) {
            $row['display_text'] = $row['fullname'];
        }
        
        echo json_encode([
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Load Add User Form
if ($action == 'add_form') {
    ?>
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Add New User</h5>
            <button type="button" class="btn-close btn-close-white" onclick="closePanel()"></button>
        </div>
        <div class="card-body">
            <form id="addUserForm" onsubmit="saveUser(event)">
                <div class="mb-3">
                    <label class="form-label">Select Personnel</label>
                    <div class="position-relative">
                        <input type="text" id="personnelSearch" class="form-control" placeholder="Type name or email..." autocomplete="off">
                        <input type="hidden" name="personnel_id" id="selectedPersonnelId" required>
                        <div id="autocompleteList" class="list-group position-absolute w-100 shadow" style="z-index: 1000; display: none; max-height: 200px; overflow-y: auto;"></div>
                    </div>
                    <div id="selectedPersonnelDisplay" class="form-text text-success mt-1" style="display: none;">
                        Selected: <span id="selectedName"></span>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Username (Gmail)</label>
                    <input type="text" id="usernameDisplay" class="form-control" readonly placeholder="Auto-filled from personnel">
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>

                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                </div>

                <div class="mb-3">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select" required>
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <input type="hidden" name="status" value="active">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <button type="submit" class="btn btn-success w-100">Create Account</button>
            </form>
        </div>
    </div>
    <?php
    exit;
}

// Load Edit User Form
if ($action == 'edit_form') {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("SELECT u.*, p.fullname, p.gmail 
                           FROM users u 
                           JOIN personnels p ON u.personnel_id = p.id 
                           WHERE u.id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo '<div class="alert alert-danger">User not found.</div>';
        exit;
    }
    
    $displayName = $user['fullname'];
    ?>
    <div class="card">
        <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Edit User</h5>
            <button type="button" class="btn-close" onclick="closePanel()"></button>
        </div>
        <div class="card-body">
            <form id="editUserForm" onsubmit="updateUser(event)">
                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                
                <div class="mb-3">
                    <label class="form-label">Personnel</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($displayName); ?>" readonly>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Username (Gmail)</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['gmail']); ?>" readonly>
                </div>

                <div class="mb-3">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select" required>
                        <option value="user" <?php echo ($user['role'] == 'user') ? 'selected' : ''; ?>>User</option>
                        <option value="admin" <?php echo ($user['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" required>
                        <option value="active" <?php echo ($user['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($user['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <hr>
                <h6 class="text-muted">Reset Password (Optional)</h6>
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current">
                </div>

                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                <button type="submit" class="btn btn-primary w-100">Update User</button>
            </form>
        </div>
    </div>
    <?php
    exit;
}

// Load View User Details
if ($action == 'view_details') {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("SELECT u.*, p.fullname, p.gmail 
                           FROM users u 
                           JOIN personnels p ON u.personnel_id = p.id 
                           WHERE u.id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo '<div class="alert alert-danger">User not found.</div>';
        exit;
    }
    
    $displayName = $user['fullname'];
    ?>
    <div class="card">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">User Details</h5>
            <button type="button" class="btn-close btn-close-white" onclick="closePanel()"></button>
        </div>
        <div class="card-body">
            <div class="mb-3 text-center">
                <i class="bi bi-person-circle display-1 text-secondary"></i>
            </div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item"><strong>Name:</strong> <?php echo htmlspecialchars($displayName); ?></li>
                <li class="list-group-item"><strong>Username:</strong> <?php echo htmlspecialchars($user['gmail']); ?></li>
                <li class="list-group-item"><strong>Role:</strong> 
                    <span class="badge <?php echo ($user['role'] == 'admin') ? 'bg-danger' : 'bg-primary'; ?>">
                        <?php echo ucfirst($user['role']); ?>
                    </span>
                </li>
                <li class="list-group-item"><strong>Status:</strong> 
                    <span class="badge <?php echo ($user['status'] == 'active') ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo ucfirst($user['status']); ?>
                    </span>
                </li>

            </ul>
            <div class="mt-3">
                <button class="btn btn-warning w-100" onclick="loadEditForm(<?php echo $user['id']; ?>)">Edit Account</button>
            </div>
        </div>
    </div>
    <?php
    exit;
}

// Add User Action
if ($action == 'add_user') {
    // Validate CSRF token
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        echo 'Error: Invalid security token. Please try again.';
        exit;
    }

    $personnel_id = $_POST['personnel_id'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $status = $_POST['status'] ?? 'active';

    // VALIDATE ALL INPUTS
    if (!Validator::positiveInteger($personnel_id)) {
        echo 'Error: Invalid personnel selection.';
        exit;
    }
    
    if (!Validator::string($password, 8, 255)) {
        echo 'Error: Password must be 8-255 characters.';
        exit;
    }
    
    if ($password !== $confirm_password) {
        echo 'Error: Passwords do not match.';
        exit;
    }
    
    if (!Validator::inList($role, ['user', 'admin'])) {
        echo 'Error: Invalid role.';
        exit;
    }
    
    if (!Validator::inList($status, ['active', 'inactive'])) {
        echo 'Error: Invalid status.';
        exit;
    }

    try {
        // Check if user already exists
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE personnel_id = ?");
        $stmtCheck->execute([$personnel_id]);
        if ($stmtCheck->fetchColumn() > 0) {
            echo 'Error: This personnel already has a user account.';
            exit;
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (personnel_id, password, role, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$personnel_id, $hashed_password, $role, $status]);
        
        // Get inserted user ID for notifications
        $userId = $pdo->lastInsertId();
        
        // Send notification
        $creatorStmt = $pdo->prepare("SELECT p.id, p.fullname FROM personnels p JOIN users u ON p.id = u.personnel_id WHERE u.id = ?");
        $creatorStmt->execute([$_SESSION['user_id']]);
        $creator = $creatorStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($creator) {
            $notifier = new NotificationManager($pdo);
            $notifier->notifyUserCreated($userId, $creator);
        }
        
        // Log user creation
        try {
            $logger = new Logger($pdo);
            $logger->logEntityAction('user_created', 'user', $userId, [
                'personnel_id' => $personnel_id,
                'role' => $role,
                'status' => $status,
                'created_by' => $_SESSION['user_id']
            ], 'medium');
        } catch (Exception $e) {
            error_log('Failed to log user creation: ' . $e->getMessage());
        }
        
        echo 'success';
    } catch (PDOException $e) {
        echo 'Error: Database error occurred. Please try again.';
        error_log('User add error: ' . $e->getMessage());
    }
    exit;
}

// Edit User Action
if ($action == 'edit_user') {
    // Validate CSRF token
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        echo 'Error: Invalid security token. Please try again.';
        exit;
    }

    // VALIDATE ALL INPUTS
    $id = $_POST['id'] ?? '';
    $role = $_POST['role'] ?? '';
    $status = $_POST['status'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    if (!Validator::positiveInteger($id)) {
        echo 'Error: Invalid user ID.';
        exit;
    }
    
    if (!Validator::inList($role, ['user', 'admin'])) {
        echo 'Error: Invalid role.';
        exit;
    }
    
    if (!Validator::inList($status, ['active', 'inactive'])) {
        echo 'Error: Invalid status.';
        exit;
    }
    
    if (!empty($new_password) && !Validator::string($new_password, 8, 255)) {
        echo 'Error: Password must be 8-255 characters.';
        exit;
    }

    try {
        // Get old values for audit
        $oldStmt = $pdo->prepare("SELECT role, status FROM users WHERE id = ?");
        $oldStmt->execute([$id]);
        $oldUser = $oldStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET role = ?, status = ?, password = ? WHERE id = ?");
            $stmt->execute([$role, $status, $hashed_password, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET role = ?, status = ? WHERE id = ?");
            $stmt->execute([$role, $status, $id]);
        }
        
        // Log user update
        try {
            $logger = new Logger($pdo);
            $changes = [];
            if ($oldUser['role'] !== $role) {
                $changes['role'] = ['old' => $oldUser['role'], 'new' => $role];
            }
            if ($oldUser['status'] !== $status) {
                $changes['status'] = ['old' => $oldUser['status'], 'new' => $status];
            }
            if (!empty($new_password)) {
                $changes['password'] = 'changed';
            }
            
            $logger->logEntityAction('user_updated', 'user', $id, [
                'changes' => $changes,
                'updated_by' => $_SESSION['user_id']
            ], 'medium');
        } catch (Exception $e) {
            error_log('Failed to log user update: ' . $e->getMessage());
        }
        
        echo 'success';
    } catch (PDOException $e) {
        echo 'Error: Database error occurred. Please try again.';
        error_log('User edit error: ' . $e->getMessage());
    }
    exit;
}

// Toggle Status Action
if ($action == 'toggle_status') {
    // Validate CSRF token
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        echo 'Error: Invalid security token. Please try again.';
        exit;
    }

    // VALIDATE INPUTS
    $id = $_POST['id'] ?? '';
    $current_status = $_POST['current_status'] ?? '';
    
    if (!Validator::positiveInteger($id)) {
        echo 'Error: Invalid user ID.';
        exit;
    }
    
    if (!Validator::inList($current_status, ['active', 'inactive'])) {
        echo 'Error: Invalid status.';
        exit;
    }
    
    $new_status = ($current_status == 'active') ? 'inactive' : 'active';
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $id]);
        
        // Log status toggle
        try {
            $logger = new Logger($pdo);
            $logger->logEntityAction('user_status_changed', 'user', $id, [
                'old_status' => $current_status,
                'new_status' => $new_status,
                'changed_by' => $_SESSION['user_id']
            ], 'medium');
        } catch (Exception $e) {
            error_log('Failed to log user status change: ' . $e->getMessage());
        }
        
        echo 'success';
    } catch (PDOException $e) {
        echo 'Error: Database error occurred. Please try again.';
        error_log('User toggle error: ' . $e->getMessage());
    }
    exit;
}
?>
<?php $activePage = 'settings'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>

<main class="flex-grow-1 overflow-auto">
<div class="container-fluid mt-4">
    <div class="row">
        <!-- Table Column -->
        <div class="col-12" id="tableContainer" style="transition: all 0.3s ease;">
            <div id="alertContainer"></div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3>USER MANAGEMENT</h3>
                <button class="btn btn-primary" onclick="loadAddForm()">
                    <i class="bi bi-plus-lg"></i> Add New User
                </button>
            </div>
            
            <div class="mb-3">
                <input type="text" id="searchInput" class="form-control form-control-lg" placeholder="Search by Name, Gmail, or Role...">
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered table-hover shadow-sm">
                    <thead class="table-dark text-center">
                        <tr>
                            <th class="text-center">Personnel Name</th>
                            <th class="text-center">Gmail (Username)</th>
                            <th class="text-center">Role</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch users with personnel info
                        $sql = "SELECT u.*, p.fullname, p.gmail 
                                FROM users u 
                                JOIN personnels p ON u.personnel_id = p.id 
                                ORDER BY u.id DESC";
                        $stmt = $pdo->query($sql);
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $statusBadge = ($row['status'] == 'active') ? 'bg-success' : 'bg-secondary';
                            $roleBadge = ($row['role'] == 'admin') ? 'bg-danger' : 'bg-primary';
                            
                            $displayName = $row['fullname'];
                            
                            $toggleIcon = ($row['status'] == 'active') ? 'bi-toggle-on' : 'bi-toggle-off';
                            $toggleTitle = ($row['status'] == 'active') ? 'Deactivate' : 'Activate';
                            $toggleBtnClass = ($row['status'] == 'active') ? 'btn-outline-success' : 'btn-outline-secondary';
                            
                            echo "<tr>
                                <td>" . htmlspecialchars($displayName) . "</td>
                                <td>" . htmlspecialchars($row['gmail']) . "</td>
                                <td class='text-center'><span class='badge {$roleBadge}'>" . ucfirst($row['role']) . "</span></td>
                                <td class='text-center'><span class='badge {$statusBadge}'>" . ucfirst($row['status']) . "</span></td>
                                <td class='text-center'>
                                    <button class='btn btn-sm btn-info text-white' onclick='loadView({$row['id']})' title='View' data-bs-toggle='tooltip'><i class='bi bi-eye'></i></button>
                                    <button class='btn btn-sm btn-warning' onclick='loadEditForm({$row['id']})' title='Edit' data-bs-toggle='tooltip'><i class='bi bi-pencil'></i></button>
                                    <button class='btn btn-sm {$toggleBtnClass}' onclick='toggleStatus({$row['id']}, \"{$row['status']}\")' title='{$toggleTitle}' data-bs-toggle='tooltip'><i class='bi {$toggleIcon}'></i></button>
                                </td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Side Panel Column -->
        <div class="col-4 d-none" id="sidePanel" style="transition: all 0.3s ease;">
            <div id="sidePanelContent">
                <!-- Dynamic Content -->
            </div>
        </div>
    </div>
    </div>
</div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
    // Initialize Tooltips
    function initTooltips() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    document.addEventListener('DOMContentLoaded', initTooltips);

    // Search Filter
    document.getElementById('searchInput').addEventListener('keyup', function() {
        const searchValue = this.value.toLowerCase();
        const tableRows = document.querySelectorAll('tbody tr');
        tableRows.forEach(row => {
            const rowText = row.textContent.toLowerCase();
            row.style.display = rowText.includes(searchValue) ? '' : 'none';
        });
    });

    // Panel Management
    function showPanel() {
        document.getElementById('tableContainer').classList.remove('col-12');
        document.getElementById('tableContainer').classList.add('col-8');
        document.getElementById('sidePanel').classList.remove('d-none');
    }

    function closePanel() {
        document.getElementById('sidePanel').classList.add('d-none');
        document.getElementById('tableContainer').classList.remove('col-8');
        document.getElementById('tableContainer').classList.add('col-12');
        document.getElementById('sidePanelContent').innerHTML = '';
    }

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
            const alert = bootstrap.Alert.getOrCreateInstance(container.querySelector('.alert'));
            if(alert) alert.close();
        }, 3000);
    }

    // Load Forms
    function loadAddForm() {
        showPanel();
        fetch('user.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=add_form'
        })
        .then(res => res.text())
        .then(html => {
            document.getElementById('sidePanelContent').innerHTML = html;
            initAutocomplete();
        });
    }

    function loadEditForm(id) {
        showPanel();
        fetch('user.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=edit_form&id=' + id
        })
        .then(res => res.text())
        .then(html => document.getElementById('sidePanelContent').innerHTML = html);
    }

    function loadView(id) {
        showPanel();
        fetch('user.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=view_details&id=' + id
        })
        .then(res => res.text())
        .then(html => document.getElementById('sidePanelContent').innerHTML = html);
    }

    // Form Submissions
    function saveUser(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        formData.append('action', 'add_user');

        fetch('user.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(result => {
            if (result.trim() === 'success') {
                showAlert('User created successfully!');
                closePanel();
                setTimeout(() => location.reload(), 1500);
            } else {
                showAlert(result, 'error');
            }
        });
    }

    function updateUser(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        formData.append('action', 'edit_user');

        fetch('user.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(result => {
            if (result.trim() === 'success') {
                showAlert('User updated successfully!');
                closePanel();
                setTimeout(() => location.reload(), 1500);
            } else {
                showAlert(result, 'error');
            }
        });
    }

    function toggleStatus(id, currentStatus) {
        if (!confirm('Are you sure you want to change the status of this user?')) return;
        
        const formData = new FormData();
        formData.append('action', 'toggle_status');
        formData.append('id', id);
        formData.append('current_status', currentStatus);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch('user.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(result => {
            if (result.trim() === 'success') {
                showAlert('User status updated!');
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlert(result, 'error');
            }
        });
    }

    // Autocomplete Logic
    let currentPage = 1;
    let currentQuery = '';

    function initAutocomplete() {
        const input = document.getElementById('personnelSearch');
        const list = document.getElementById('autocompleteList');
        
        if(!input) return;

        // Show on focus
        input.addEventListener('focus', function() {
            // Only search/reset if list is hidden to prevent loop on button clicks
            if (document.getElementById('autocompleteList').style.display === 'none') {
                currentQuery = this.value;
                currentPage = 1;
                performSearch();
            }
        });

        // Update on type
        input.addEventListener('input', function() {
            currentQuery = this.value;
            currentPage = 1;
            performSearch();
        });

        // Hide on click outside
        document.addEventListener('click', function(e) {
            if (e.target !== input && !list.contains(e.target)) {
                list.style.display = 'none';
            }
        });
    }

    function performSearch() {
        const list = document.getElementById('autocompleteList');
        
        fetch('user.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=search_personnel&query=' + encodeURIComponent(currentQuery) + '&page=' + currentPage
        })
        .then(res => res.json())
        .then(data => {
            list.innerHTML = '';
            
            if (data.results && data.results.length > 0) {
                list.style.display = 'block';
                
                // Render items
                data.results.forEach(item => {
                    const a = document.createElement('a');
                    a.className = 'list-group-item list-group-item-action';
                    a.href = '#';
                    a.textContent = item.display_text;
                    a.onclick = function(e) {
                        e.preventDefault();
                        selectPersonnel(item);
                    };
                    list.appendChild(a);
                });

                // Render Pagination Controls
                if (data.pages > 1) {
                    const paginationDiv = document.createElement('div');
                    paginationDiv.className = 'd-flex justify-content-between p-2 border-top bg-light';
                    
                    const prevBtn = document.createElement('button');
                    prevBtn.type = 'button'; // Prevent form submission
                    prevBtn.className = 'btn btn-sm btn-outline-secondary';
                    prevBtn.innerHTML = '<i class="bi bi-chevron-left"></i> Prev';
                    prevBtn.disabled = data.page <= 1;
                    
                    // Prevent focus loss
                    prevBtn.onmousedown = function(e) { e.preventDefault(); };
                    
                    prevBtn.onclick = function(e) {
                        e.preventDefault();
                        e.stopPropagation(); // Prevent closing
                        if (currentPage > 1) {
                            currentPage--;
                            performSearch();
                            // No need to call focus() here if we used preventDefault on mousedown
                        }
                    };

                    const nextBtn = document.createElement('button');
                    nextBtn.type = 'button'; // Prevent form submission
                    nextBtn.className = 'btn btn-sm btn-outline-secondary';
                    nextBtn.innerHTML = 'Next <i class="bi bi-chevron-right"></i>';
                    nextBtn.disabled = data.page >= data.pages;
                    
                    // Prevent focus loss
                    nextBtn.onmousedown = function(e) { e.preventDefault(); };

                    nextBtn.onclick = function(e) {
                        e.preventDefault();
                        e.stopPropagation(); // Prevent closing
                        if (currentPage < data.pages) {
                            currentPage++;
                            performSearch();
                            // No need to call focus() here if we used preventDefault on mousedown
                        }
                    };

                    const info = document.createElement('span');
                    info.className = 'small text-muted align-self-center';
                    info.textContent = `Page ${data.page} of ${data.pages}`;

                    paginationDiv.appendChild(prevBtn);
                    paginationDiv.appendChild(info);
                    paginationDiv.appendChild(nextBtn);
                    list.appendChild(paginationDiv);
                }

            } else {
                // No results found
                if (currentQuery.length > 0) {
                    list.style.display = 'block';
                    list.innerHTML = '<div class="list-group-item text-muted">No results found</div>';
                } else if (document.activeElement === document.getElementById('personnelSearch')) {
                    // Focused but empty and no results (e.g. no available personnels)
                    list.style.display = 'block';
                    list.innerHTML = '<div class="list-group-item text-muted">No available personnel</div>';
                } else {
                    list.style.display = 'none';
                }
            }
        })
        .catch(err => {
            console.error(err);
        });
    }

    function selectPersonnel(item) {
        document.getElementById('personnelSearch').value = ''; // Clear search
        document.getElementById('selectedPersonnelId').value = item.id;
        document.getElementById('selectedName').textContent = item.display_text;
        document.getElementById('selectedPersonnelDisplay').style.display = 'block';
        document.getElementById('usernameDisplay').value = item.gmail;
        document.getElementById('autocompleteList').style.display = 'none';
    }

</script>
</body>
</html>
