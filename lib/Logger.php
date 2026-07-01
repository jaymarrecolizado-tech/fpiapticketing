<?php
/**
 * Logger - System-wide audit logging utility
 *
 * Provides centralized logging functionality for tracking all system activities,
 * user actions, and security events for compliance and monitoring purposes.
 */
class Logger {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Log a general system event
     *
     * @param string $action The action being performed
     * @param array $details Additional details about the action
     * @param string $severity Log severity level (low, medium, high, critical)
     * @param string $status Success status (success, failure, warning)
     * @return bool Success status
     */
    public function log($action, $details = [], $severity = 'low', $status = 'success') {
        try {
            // Auto-detect user context
            $userId = $_SESSION['user_id'] ?? null;
            $personnelId = $_SESSION['personnel_id'] ?? null;

            // Get client info
            $ip = $this->getClientIP();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $sessionId = session_id();

            // Generate description if not provided
            $description = $details['description'] ?? $this->generateDescription($action, $details);

            // Insert log entry
            $stmt = $this->pdo->prepare("
                INSERT INTO system_logs
                (user_id, personnel_id, action, entity_type, entity_id, details, description, ip_address, user_agent, severity, status, session_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([
                $userId,
                $personnelId,
                $action,
                $details['entity_type'] ?? null,
                $details['entity_id'] ?? null,
                json_encode($details),
                $description,
                $ip,
                $userAgent,
                $severity,
                $status,
                $sessionId
            ]);

            return $result;
        } catch (Exception $e) {
            // Log to PHP error log as fallback
            error_log("Logger error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log an entity-specific action (ticket, user, site, etc.)
     *
     * @param string $action The action being performed
     * @param string $entityType Type of entity (ticket, user, site, personnel)
     * @param int $entityId ID of the entity
     * @param array $details Additional details
     * @param string $severity Log severity level
     * @return bool Success status
     */
    public function logEntityAction($action, $entityType, $entityId, $details = [], $severity = 'low') {
        $details['entity_type'] = $entityType;
        $details['entity_id'] = $entityId;
        return $this->log($action, $details, $severity);
    }

    /**
     * Log authentication events
     *
     * @param string $event Type of auth event (login_success, login_failure, logout)
     * @param array $details Additional details
     * @return bool Success status
     */
    public function logAuthEvent($event, $details = []) {
        $severity = ($event === 'login_failure') ? 'medium' : 'low';
        $status = (strpos($event, 'failure') !== false) ? 'failure' : 'success';
        return $this->log($event, $details, $severity, $status);
    }

    /**
     * Log security events
     *
     * @param string $event Type of security event
     * @param array $details Additional details
     * @param string $severity Severity level
     * @return bool Success status
     */
    public function logSecurityEvent($event, $details = [], $severity = 'high') {
        return $this->log($event, $details, $severity, 'warning');
    }

    /**
     * Generate a human-readable description for common actions
     *
     * @param string $action The action
     * @param array $details Action details
     * @return string Description
     */
    private function generateDescription($action, $details) {
        switch ($action) {
            case 'login_success':
                return "User logged in successfully";
            case 'login_failure':
                return "Failed login attempt: " . ($details['reason'] ?? 'unknown reason');
            case 'logout':
                return "User logged out";
            case 'ticket_created':
                return "Ticket #{$details['ticket_number']} created for site {$details['site_name']}";
            case 'ticket_status_changed':
                return "Ticket #{$details['ticket_number']} status changed from {$details['old_status']} to {$details['new_status']}";
            case 'user_created':
                $username = $details['username'] ?? ($details['personnel_id'] ?? 'unknown');
                return "User account created: {$username}";
            case 'user_updated':
                $username = $details['username'] ?? ($details['personnel_id'] ?? 'unknown');
                return "User account updated: {$username}";
            case 'personnel_created':
                return "Personnel record created: {$details['fullname']}";
            case 'site_created':
                return "Site created: {$details['site_name']}";
            case 'password_changed':
                return "Password changed for user";
            default:
                return ucfirst(str_replace('_', ' ', $action));
        }
    }

    /**
     * Get the client's real IP address
     *
     * @return string IP address
     */
    private function getClientIP() {
        // Handle proxies and IPv6
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Take first IP if comma-separated
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
?>