<?php
/**
 * PermissionManager - RBAC permission checking.
 *
 * Usage:
 *   $pm = new PermissionManager($pdo);
 *   $pm->loadPermissions($_SESSION['user_id']);
 *   if ($pm->has('tickets.edit')) { ... }
 *   if ($pm->hasAny(['tickets.edit', 'tickets.delete'])) { ... }
 */

class PermissionManager
{
    private $pdo;
    private $permissions = [];
    private $role = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Load permissions for a user from the database.
     */
    public function loadPermissions($userId)
    {
        $this->permissions = [];

        $stmt = $this->pdo->prepare("
            SELECT r.name AS role_name, GROUP_CONCAT(p.name) AS perm_list
            FROM users u
            JOIN roles r ON u.role = r.name
            LEFT JOIN role_permissions rp ON r.id = rp.role_id
            LEFT JOIN permissions p ON rp.permission_id = p.id
            WHERE u.id = ?
            GROUP BY r.name
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->role = $row['role_name'];
            if ($row['perm_list']) {
                $this->permissions = explode(',', $row['perm_list']);
            }
        }

        return $this;
    }

    /**
     * Check if current user has a specific permission.
     * Admin role always has all permissions.
     */
    public function has($permission)
    {
        if ($this->role === 'admin') {
            return true;
        }
        return in_array($permission, $this->permissions);
    }

    /**
     * Check if user has any of the given permissions.
     */
    public function hasAny(array $permissions)
    {
        if ($this->role === 'admin') {
            return true;
        }
        return !empty(array_intersect($permissions, $this->permissions));
    }

    /**
     * Check if user has all of the given permissions.
     */
    public function hasAll(array $permissions)
    {
        if ($this->role === 'admin') {
            return true;
        }
        return empty(array_diff($permissions, $this->permissions));
    }

    /**
     * Get all permissions for the current user.
     */
    public function getAll()
    {
        return $this->permissions;
    }

    /**
     * Get the user's role name.
     */
    public function getRole()
    {
        return $this->role;
    }

    /**
     * Get all available roles.
     */
    public static function getAllRoles(PDO $pdo)
    {
        return $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all available permissions.
     */
    public static function getAllPermissions(PDO $pdo)
    {
        return $pdo->query("SELECT * FROM permissions ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get permissions assigned to a role.
     */
    public static function getRolePermissions(PDO $pdo, $roleId)
    {
        $stmt = $pdo->prepare("
            SELECT p.name
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Update permissions for a role.
     */
    public static function updateRolePermissions(PDO $pdo, $roleId, array $permissionNames)
    {
        // Delete existing
        $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]);

        // Insert new
        $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ?");
        foreach ($permissionNames as $permName) {
            $stmt->execute([$roleId, $permName]);
        }
    }

    /**
     * Get all users with their roles (for user management display).
     */
    public static function getUsersWithRoles(PDO $pdo)
    {
        $stmt = $pdo->query("
            SELECT u.id, u.role, u.status, p.fullname, p.gmail
            FROM users u
            JOIN personnels p ON u.personnel_id = p.id
            ORDER BY p.fullname
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
