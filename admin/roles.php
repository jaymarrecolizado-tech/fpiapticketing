<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require '../lib/Logger.php';
require '../lib/PermissionManager.php';

requireAdmin();
$activePage = 'settings';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    try {
        switch ($action) {
            case 'get_roles':
                $roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($roles);
                break;

            case 'get_permissions':
                $perms = $pdo->query("SELECT * FROM permissions ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($perms);
                break;

            case 'get_role_permissions':
                $roleId = intval($_POST['role_id'] ?? 0);
                $perms = PermissionManager::getRolePermissions($pdo, $roleId);
                echo json_encode($perms);
                break;

            case 'save_role_permissions':
                $roleId = intval($_POST['role_id'] ?? 0);
                $permNames = $_POST['permissions'] ?? [];
                if (!$roleId) throw new Exception('Invalid role');
                PermissionManager::updateRolePermissions($pdo, $roleId, $permNames);
                $logger = new Logger($pdo);
                $logger->logEntityAction('role_permissions_updated', 'role', $roleId, ['permissions' => $permNames], 'medium');
                echo json_encode(['success' => true]);
                break;

            case 'add_role':
                $name = trim($_POST['name'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if (empty($name)) throw new Exception('Role name required');
                if (preg_match('/[^a-z0-9_]/i', $name)) throw new Exception('Role name: letters, numbers, underscores only');
                $stmt = $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
                $stmt->execute([$name, $desc]);
                $roleId = $pdo->lastInsertId();
                $logger = new Logger($pdo);
                $logger->logEntityAction('role_created', 'role', $roleId, ['name' => $name], 'medium');
                echo json_encode(['success' => true, 'id' => $roleId]);
                break;

            case 'delete_role':
                $roleId = intval($_POST['role_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT is_system FROM roles WHERE id = ?");
                $stmt->execute([$roleId]);
                $role = $stmt->fetch();
                if (!$role) throw new Exception('Role not found');
                if ($role['is_system']) throw new Exception('Cannot delete system role');
                // Check if any users have this role
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = (SELECT name FROM roles WHERE id = ?)");
                $stmt->execute([$roleId]);
                if ($stmt->fetchColumn() > 0) throw new Exception('Cannot delete role with assigned users');
                $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]);
                $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$roleId]);
                echo json_encode(['success' => true]);
                break;

            default:
                echo json_encode(['error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <title>Role Management - FPIAP-SMARTs</title>
    <style>
        .perm-badge { font-size: 0.75rem; margin: 2px; }
        .perm-category { font-weight: 600; color: #6c757d; text-transform: uppercase; font-size: 0.8rem; }
        .role-card { cursor: pointer; transition: all 0.2s; }
        .role-card:hover, .role-card.active { border-color: #0d6efd; background-color: #f8f9ff; }
        .role-card.active { box-shadow: 0 0 0 2px #0d6efd; }
    </style>
</head>
<body>
<?php require '../includes/admin_header.php'; ?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Role & Permission Management</h4>
    </div>

    <div class="row">
        <!-- Roles List -->
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Roles</h6>
                    <button class="btn btn-sm btn-light" onclick="showAddRoleModal()"><i class="bi bi-plus"></i> Add</button>
                </div>
                <div class="card-body p-2" id="rolesList"></div>
            </div>
        </div>

        <!-- Permissions -->
        <div class="col-lg-8 mb-4">
            <div class="card">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0" id="permTitle">Select a role to view permissions</h6>
                    <button class="btn btn-sm btn-success" id="saveBtn" style="display:none;" onclick="savePermissions()">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </div>
                <div class="card-body" id="permissionsArea">
                    <p class="text-muted text-center py-5">Click a role on the left to manage its permissions.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Role Name</label>
                    <input type="text" id="newRoleName" class="form-control" placeholder="e.g. supervisor" pattern="[a-zA-Z0-9_]+" required>
                    <div class="form-text">Letters, numbers, and underscores only.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" id="newRoleDesc" class="form-control" placeholder="Brief description of this role">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="addRole()">Create Role</button>
            </div>
        </div>
    </div>
</div>

<?php require '../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let allPermissions = [];
let selectedRoleId = null;
let selectedPerms = [];

// Load roles
function loadRoles() {
    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_roles'
    }).then(r => r.json()).then(roles => {
        const html = roles.map(r => `
            <div class="card role-card mb-2 p-3 ${r.id == selectedRoleId ? 'active' : ''}" onclick="selectRole(${r.id}, '${r.name}')" data-id="${r.id}">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong class="text-capitalize">${r.name}</strong>
                        ${r.is_system ? '<span class="badge bg-info ms-1" style="font-size:0.65rem">System</span>' : ''}
                        <br><small class="text-muted">${r.description || ''}</small>
                    </div>
                    ${!r.is_system ? `<button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteRole(${r.id})"><i class="bi bi-trash"></i></button>` : ''}
                </div>
            </div>
        `).join('');
        document.getElementById('rolesList').innerHTML = html || '<p class="text-muted text-center">No roles found.</p>';
    });
}

// Load all permissions
function loadPermissions() {
    return fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_permissions'
    }).then(r => r.json()).then(perms => { allPermissions = perms; });
}

// Select a role
function selectRole(roleId, roleName) {
    selectedRoleId = roleId;
    document.querySelectorAll('.role-card').forEach(c => c.classList.toggle('active', c.dataset.id == roleId));
    document.getElementById('permTitle').textContent = `Permissions for: ${roleName}`;
    document.getElementById('saveBtn').style.display = '';

    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_role_permissions&role_id=' + roleId
    }).then(r => r.json()).then(perms => {
        selectedPerms = perms;
        renderPermissions();
    });
}

// Render permissions by category
function renderPermissions() {
    const grouped = {};
    allPermissions.forEach(p => {
        if (!grouped[p.category]) grouped[p.category] = [];
        grouped[p.category].push(p);
    });

    let html = '';
    for (const [cat, perms] of Object.entries(grouped)) {
        html += `<div class="mb-3">`;
        html += `<div class="perm-category mb-2">${cat}</div>`;
        html += `<div class="d-flex flex-wrap">`;
        perms.forEach(p => {
            const checked = selectedPerms.includes(p.name) ? 'checked' : '';
            html += `<div class="form-check form-check-inline me-3 mb-1">
                <input class="form-check-input perm-check" type="checkbox" value="${p.name}" ${checked} id="perm_${p.name}">
                <label class="form-check-label" for="perm_${p.name}">${p.description || p.name}</label>
            </div>`;
        });
        html += `</div></div>`;
    }
    document.getElementById('permissionsArea').innerHTML = html;
}

// Save permissions
function savePermissions() {
    if (!selectedRoleId) return;
    const perms = Array.from(document.querySelectorAll('.perm-check:checked')).map(c => c.value);
    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=save_role_permissions&role_id=' + selectedRoleId + '&' + perms.map(p => 'permissions[]=' + encodeURIComponent(p)).join('&')
    }).then(r => r.json()).then(data => {
        if (data.error) { alert('Error: ' + data.error); return; }
        alert('Permissions saved successfully.');
    });
}

function showAddRoleModal() { new bootstrap.Modal(document.getElementById('addRoleModal')).show(); }

function addRole() {
    const name = document.getElementById('newRoleName').value.trim();
    const desc = document.getElementById('newRoleDesc').value.trim();
    if (!name) return alert('Enter a role name.');
    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=add_role&name=' + encodeURIComponent(name) + '&description=' + encodeURIComponent(desc)
    }).then(r => r.json()).then(data => {
        if (data.error) { alert('Error: ' + data.error); return; }
        bootstrap.Modal.getInstance(document.getElementById('addRoleModal')).hide();
        document.getElementById('newRoleName').value = '';
        document.getElementById('newRoleDesc').value = '';
        loadRoles();
    });
}

function deleteRole(roleId) {
    if (!confirm('Delete this role?')) return;
    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete_role&role_id=' + roleId
    }).then(r => r.json()).then(data => {
        if (data.error) { alert('Error: ' + data.error); return; }
        if (roleId == selectedRoleId) { selectedRoleId = null; document.getElementById('saveBtn').style.display = 'none'; document.getElementById('permTitle').textContent = 'Select a role to view permissions'; document.getElementById('permissionsArea').innerHTML = '<p class="text-muted text-center py-5">Click a role on the left to manage its permissions.</p>'; }
        loadRoles();
    });
}

// Init
loadPermissions().then(() => loadRoles());
</script>
</body>
</html>
