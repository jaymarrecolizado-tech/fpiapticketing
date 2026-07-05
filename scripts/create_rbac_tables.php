<?php
/**
 * RBAC Migration
 * Creates roles, permissions, and role_permissions tables.
 * Seeds default roles and permissions.
 */
require_once __DIR__ . '/../config/db.php';

// --- roles table ---
$stmt = $pdo->query("SHOW TABLES LIKE 'roles'");
if ($stmt->rowCount() === 0) {
    $pdo->exec("
        CREATE TABLE `roles` (
            `id` int NOT NULL AUTO_INCREMENT,
            `name` varchar(50) NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = cannot delete',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_role_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "Created 'roles' table.\n";
} else {
    echo "Table 'roles' exists.\n";
}

// --- permissions table ---
$stmt = $pdo->query("SHOW TABLES LIKE 'permissions'");
if ($stmt->rowCount() === 0) {
    $pdo->exec("
        CREATE TABLE `permissions` (
            `id` int NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `category` varchar(50) NOT NULL DEFAULT 'general',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_perm_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "Created 'permissions' table.\n";
} else {
    echo "Table 'permissions' exists.\n";
}

// --- role_permissions table ---
$stmt = $pdo->query("SHOW TABLES LIKE 'role_permissions'");
if ($stmt->rowCount() === 0) {
    $pdo->exec("
        CREATE TABLE `role_permissions` (
            `role_id` int NOT NULL,
            `permission_id` int NOT NULL,
            PRIMARY KEY (`role_id`, `permission_id`),
            KEY `idx_rp_permission` (`permission_id`),
            CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "Created 'role_permissions' table.\n";
} else {
    echo "Table 'role_permissions' exists.\n";
}

// --- Seed default roles ---
$defaultRoles = [
    ['name' => 'admin',    'description' => 'Full system access',              'is_system' => 1],
    ['name' => 'manager',  'description' => 'Can manage tickets and reports',  'is_system' => 1],
    ['name' => 'operator', 'description' => 'Can create and update tickets',   'is_system' => 1],
    ['name' => 'user',     'description' => 'Basic access, view own tickets',  'is_system' => 1],
];

foreach ($defaultRoles as $role) {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
    $stmt->execute([$role['name']]);
    if ($stmt->fetch()) {
        continue;
    }
    $stmt = $pdo->prepare("INSERT INTO roles (name, description, is_system) VALUES (?, ?, ?)");
    $stmt->execute([$role['name'], $role['description'], $role['is_system']]);
    echo "  Seeded role: {$role['name']}\n";
}

// --- Seed default permissions ---
$defaultPermissions = [
    // Tickets
    ['name' => 'tickets.view',       'description' => 'View tickets',           'category' => 'tickets'],
    ['name' => 'tickets.create',     'description' => 'Create tickets',         'category' => 'tickets'],
    ['name' => 'tickets.edit',       'description' => 'Edit tickets',           'category' => 'tickets'],
    ['name' => 'tickets.delete',     'description' => 'Delete tickets',         'category' => 'tickets'],
    ['name' => 'tickets.assign',     'description' => 'Assign tickets',         'category' => 'tickets'],
    ['name' => 'tickets.close',      'description' => 'Close/reopen tickets',   'category' => 'tickets'],
    ['name' => 'tickets.comment',    'description' => 'Add comments',           'category' => 'tickets'],
    ['name' => 'tickets.attach',     'description' => 'Upload attachments',     'category' => 'tickets'],
    // Sites
    ['name' => 'sites.view',         'description' => 'View sites',             'category' => 'sites'],
    ['name' => 'sites.create',       'description' => 'Create sites',           'category' => 'sites'],
    ['name' => 'sites.edit',         'description' => 'Edit sites',             'category' => 'sites'],
    ['name' => 'sites.delete',       'description' => 'Delete sites',           'category' => 'sites'],
    ['name' => 'sites.import',       'description' => 'Import CSV',             'category' => 'sites'],
    // Reports
    ['name' => 'reports.view',       'description' => 'View reports',           'category' => 'reports'],
    ['name' => 'reports.export',     'description' => 'Export reports (CSV/PDF)','category' => 'reports'],
    // Admin
    ['name' => 'admin.users',        'description' => 'Manage users',           'category' => 'admin'],
    ['name' => 'admin.personnel',    'description' => 'Manage personnel',       'category' => 'admin'],
    ['name' => 'admin.roles',        'description' => 'Manage roles',           'category' => 'admin'],
    ['name' => 'admin.system_log',   'description' => 'View system logs',       'category' => 'admin'],
    ['name' => 'admin.backup',       'description' => 'Manage backups',         'category' => 'admin'],
    ['name' => 'admin.data_export',  'description' => 'Export data',            'category' => 'admin'],
    ['name' => 'admin.history',      'description' => 'View history',           'category' => 'admin'],
    ['name' => 'dashboard.view',     'description' => 'View dashboard',         'category' => 'dashboard'],
];

foreach ($defaultPermissions as $perm) {
    $stmt = $pdo->prepare("SELECT id FROM permissions WHERE name = ?");
    $stmt->execute([$perm['name']]);
    if ($stmt->fetch()) {
        continue;
    }
    $stmt = $pdo->prepare("INSERT INTO permissions (name, description, category) VALUES (?, ?, ?)");
    $stmt->execute([$perm['name'], $perm['description'], $perm['category']]);
}
echo "  Seeded " . count($defaultPermissions) . " permissions.\n";

// --- Assign permissions to roles ---
$rolePerms = [
    'admin' => array_column($defaultPermissions, 'name'), // all permissions
    'manager' => [
        'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.assign', 'tickets.close',
        'tickets.comment', 'tickets.attach',
        'sites.view', 'sites.create', 'sites.edit', 'sites.import',
        'reports.view', 'reports.export',
        'dashboard.view',
    ],
    'operator' => [
        'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.comment', 'tickets.attach',
        'sites.view', 'sites.create', 'sites.edit',
        'reports.view',
        'dashboard.view',
    ],
    'user' => [
        'tickets.view', 'tickets.create', 'tickets.comment', 'tickets.attach',
        'sites.view',
        'dashboard.view',
    ],
];

foreach ($rolePerms as $roleName => $permNames) {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
    $stmt->execute([$roleName]);
    $role = $stmt->fetch();
    if (!$role) continue;

    $roleId = $role['id'];
    foreach ($permNames as $permName) {
        $stmt = $pdo->prepare("SELECT id FROM permissions WHERE name = ?");
        $stmt->execute([$permName]);
        $perm = $stmt->fetch();
        if (!$perm) continue;

        // Skip if already assigned
        $stmt2 = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?");
        $stmt2->execute([$roleId, $perm['id']]);
        if ($stmt2->fetch()) continue;

        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
            ->execute([$roleId, $perm['id']]);
    }
    echo "  Assigned permissions to role: {$roleName}\n";
}

// --- Expand users.role enum to include new roles ---
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
$col = $stmt->fetch();
if ($col && strpos($col['Type'], 'manager') === false) {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','operator','user') NOT NULL DEFAULT 'user'");
    echo "Expanded users.role enum to include manager, operator.\n";
} else {
    echo "users.role enum already includes new roles.\n";
}

echo "\nRBAC migration complete.\n";
