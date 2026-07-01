<?php
/**
 * NotificationManager - Handles in-app notifications for create operations
 * 
 * Manages notifications for:
 * - Ticket creation (admin notification + creator confirmation)
 * - Ticket status updates (resolved/closed notifications to creator)
 * - User creation (admin notification)
 * - Personnel creation (admin notification)
 * - Site creation (admin notification)
 */
class NotificationManager {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Send notification for ticket creation
     * - Notifies all active admins
     * - Sends confirmation to ticket creator ONLY if creator is not an admin
     * 
     * @param int $ticketId The ticket ID
     * @param array $createdBy Personnel info (id, fullname)
     * @param int $creatorUserId The user ID of the creator (for role checking)
     */
    public function notifyTicketCreated($ticketId, $createdBy, $creatorUserId = null) {
        try {
            error_log("[NOTIFY_TICKET] Starting notification for ticket ID: $ticketId, Creator ID: {$createdBy['id']}, Creator User ID: $creatorUserId");
            
            // Get ticket details
            $ticket = $this->getTicketDetails($ticketId);
            if (!$ticket) {
                throw new Exception("Ticket not found: $ticketId");
            }
            error_log("[NOTIFY_TICKET] Ticket found: {$ticket['ticket_number']}");

            // Notify all active admins
            $admins = $this->getAdminUsers();
            error_log("[NOTIFY_TICKET] Found " . count($admins) . " admin users");
            
            foreach ($admins as $admin) {
                $this->createInAppNotification($admin['id'], 'ticket_created', [
                    'title' => "New Ticket Created: {$ticket['ticket_number']}",
                    'message' => "Ticket {$ticket['ticket_number']} has been created for site {$ticket['site_name']} by {$createdBy['fullname']}.",
                    'related_id' => $ticket['id']
                ]);
            }

            // Notify the ticket creator (confirmation) ONLY if creator is NOT admin
            $creatorUser = $this->getUserByPersonnelId($createdBy['id']);
            if ($creatorUser) {
                $isCreatorAdmin = $this->isUserAdmin($creatorUserId ?? $creatorUser['id']);
                
                if (!$isCreatorAdmin) {
                    error_log("[NOTIFY_TICKET] Creator is not admin. Sending confirmation to creator user ID: {$creatorUser['id']}");
                    $this->createInAppNotification($creatorUser['id'], 'ticket_created_confirmation', [
                        'title' => "Ticket Created Successfully",
                        'message' => "Your ticket {$ticket['ticket_number']} for site {$ticket['site_name']} has been created successfully.",
                        'related_id' => $ticket['id']
                    ]);
                } else {
                    error_log("[NOTIFY_TICKET] Creator is admin. Skipping confirmation notification.");
                }
            } else {
                error_log("[NOTIFY_TICKET] Could NOT find creator user for personnel ID: {$createdBy['id']}");
            }

            return true;
        } catch (Exception $e) {
            error_log("[NOTIFY_TICKET] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when ticket status changes to RESOLVED or CLOSED
     * - Notifies the ticket creator
     * 
     * @param int $ticketId The ticket ID
     * @param string $newStatus The new status (RESOLVED or CLOSED)
     */
    public function notifyTicketStatusUpdate($ticketId, $newStatus) {
        try {
            if (!in_array($newStatus, ['RESOLVED', 'CLOSED'])) {
                return false; // Only notify for these statuses
            }

            error_log("[NOTIFY_STATUS] Starting notification for ticket ID: $ticketId, Status: $newStatus");
            
            // Get ticket details including creator
            $ticket = $this->getTicketDetailsWithCreator($ticketId);
            if (!$ticket) {
                throw new Exception("Ticket not found: $ticketId");
            }
            error_log("[NOTIFY_STATUS] Ticket found: {$ticket['ticket_number']}, Creator Personnel ID: {$ticket['created_by']}");

            // Get the user ID of the creator
            $creatorUser = $this->getUserByPersonnelId($ticket['created_by']);
            if (!$creatorUser) {
                error_log("[NOTIFY_STATUS] Could not find user for personnel ID: {$ticket['created_by']}");
                return false;
            }

            $title = $newStatus === 'RESOLVED' ? 'Ticket Resolved' : 'Ticket Closed';
            $message = "Your ticket {$ticket['ticket_number']} for site {$ticket['site_name']} is now {$newStatus}.";

            $type = $newStatus === 'RESOLVED' ? 'ticket_resolved' : 'ticket_closed';

            $this->createInAppNotification($creatorUser['id'], $type, [
                'title' => $title,
                'message' => $message,
                'related_id' => $ticket['id']
            ]);

            return true;
        } catch (Exception $e) {
            error_log("[NOTIFY_STATUS] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification for auto-closed tickets
     * - Notifies the ticket creator that their resolved ticket was auto-closed after 7 days
     *
     * @param int $ticketId The ticket ID that was auto-closed
     * @return bool Success status
     */
    public function notifyTicketAutoClosed($ticketId) {
        try {
            error_log("[NOTIFY_AUTO_CLOSE] Starting notification for auto-closed ticket ID: $ticketId");

            // Get ticket details including creator
            $ticket = $this->getTicketDetailsWithCreator($ticketId);
            if (!$ticket) {
                throw new Exception("Ticket not found: $ticketId");
            }
            error_log("[NOTIFY_AUTO_CLOSE] Ticket found: {$ticket['ticket_number']}, Creator Personnel ID: {$ticket['created_by']}");

            // Get the user ID of the creator
            $creatorUser = $this->getUserByPersonnelId($ticket['created_by']);
            if (!$creatorUser) {
                error_log("[NOTIFY_AUTO_CLOSE] Could not find user for personnel ID: {$ticket['created_by']}");
                return false;
            }

            $this->createInAppNotification($creatorUser['id'], 'ticket_auto_closed', [
                'title' => 'Ticket Auto-Closed',
                'message' => "Your ticket {$ticket['ticket_number']} for site {$ticket['site_name']} has been automatically closed after 7 days in resolved status.",
                'related_id' => $ticket['id']
            ]);

            return true;
        } catch (Exception $e) {
            error_log("[NOTIFY_AUTO_CLOSE] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification for user creation
     * - Notifies all active admins
     */
    public function notifyUserCreated($userId, $createdBy) {
        try {
            // Get user details
            $user = $this->getUserDetails($userId);
            if (!$user) {
                throw new Exception("User not found: $userId");
            }

            // Notify all active admins (except the user being created if they're admin)
            $admins = $this->getAdminUsers();
            foreach ($admins as $admin) {
                if ($admin['id'] != $userId) {
                    $this->createInAppNotification($admin['id'], 'user_created', [
                        'title' => "New User Account Created",
                        'message' => "User account for {$user['fullname']} ({$user['email']}) has been created by {$createdBy['fullname']}.",
                        'related_id' => $user['id']
                    ]);
                }
            }

            return true;
        } catch (Exception $e) {
            error_log("Notification error (user): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification for personnel creation
     * - Notifies all active admins
     */
    public function notifyPersonnelCreated($personnelId, $createdBy) {
        try {
            // Get personnel details
            $personnel = $this->getPersonnelDetails($personnelId);
            if (!$personnel) {
                throw new Exception("Personnel not found: $personnelId");
            }

            // Notify all active admins
            $admins = $this->getAdminUsers();
            foreach ($admins as $admin) {
                $this->createInAppNotification($admin['id'], 'personnel_created', [
                    'title' => "New Personnel Added",
                    'message' => "Personnel {$personnel['fullname']} ({$personnel['gmail']}) has been added by {$createdBy['fullname']}.",
                    'related_id' => $personnel['id']
                ]);
            }

            return true;
        } catch (Exception $e) {
            error_log("Notification error (personnel): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification for site creation
     * - Notifies all active admins
     */
    public function notifySiteCreated($siteId, $createdBy) {
        try {
            // Get site details
            $site = $this->getSiteDetails($siteId);
            if (!$site) {
                throw new Exception("Site not found: $siteId");
            }

            // Notify all active admins
            $admins = $this->getAdminUsers();
            foreach ($admins as $admin) {
                $this->createInAppNotification($admin['id'], 'site_created', [
                    'title' => "New Site Added",
                    'message' => "Site {$site['site_name']} in {$site['municipality']}, {$site['province']} has been added by {$createdBy['fullname']}.",
                    'related_id' => $site['id']
                ]);
            }

            return true;
        } catch (Exception $e) {
            error_log("Notification error (site): " . $e->getMessage());
            return false;
        }
    }

    /**     * Send notifications for bulk ticket creation
     * - Notifies all admins once
     * - Optionally notifies creator once if they are not admin
     */
    public function notifyBulkTicketsCreated(array $createdTickets, array $createdBy, $creatorUserId = null) {
        try {
            $count = count($createdTickets);
            $ticketNumbers = array_column($createdTickets, 'number');
            $title = "Bulk Tickets Created ({$count})";
            $message = "{$count} tickets were created by {$createdBy['fullname']}";

            $admins = $this->getAdminUsers();
            foreach ($admins as $admin) {
                $this->createInAppNotification($admin['id'], 'ticket_bulk_created', [
                    'title' => $title,
                    'message' => $message,
                    'related_id' => $createdTickets[0]['id'] ?? null
                ]);
            }

            $creatorUser = $this->getUserByPersonnelId($createdBy['id']);
            if ($creatorUser && !$this->isUserAdmin($creatorUserId ?? $creatorUser['id'])) {
                $this->createInAppNotification($creatorUser['id'], 'ticket_bulk_created_confirmation', [
                    'title' => 'Bulk Ticket Creation Completed',
                    'message' => "Your {$count} tickets were created successfully: " . implode(', ', $ticketNumbers),
                    'related_id' => $createdTickets[0]['id'] ?? null
                ]);
            }

            return true;
        } catch (Exception $e) {
            error_log("[NOTIFY_BULK] Error: " . $e->getMessage());
            return false;
        }
    }

    /**     * Create an in-app notification in the database
     */
    private function createInAppNotification($userId, $type, $data) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, related_id)
                VALUES (?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([
                $userId,
                $type,
                $data['title'],
                $data['message'],
                $data['related_id'] ?? null
            ]);

            if ($result) {
                error_log("[NOTIFICATION] Created for user $userId - Type: $type - Title: {$data['title']}");
            } else {
                error_log("[NOTIFICATION] FAILED to create for user $userId - Type: $type");
            }

            return $result;
        } catch (Exception $e) {
            error_log("[NOTIFICATION] Exception creating in-app notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all active admin users
     */
    private function getAdminUsers() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.id, p.gmail as email, p.fullname
                FROM users u
                JOIN personnels p ON u.personnel_id = p.id
                WHERE u.role = 'admin' AND u.status = 'active'
            ");
            $stmt->execute();
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("[GET_ADMINS] Query returned " . count($admins) . " admins");
            return $admins;
        } catch (Exception $e) {
            error_log("[GET_ADMINS] Error fetching admin users: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user by personnel ID
     */
    private function getUserByPersonnelId($personnelId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.id, p.gmail as email, p.fullname
                FROM users u
                JOIN personnels p ON u.personnel_id = p.id
                WHERE u.personnel_id = ? AND u.status = 'active'
            ");
            $stmt->execute([$personnelId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching user by personnel ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a user has admin role
     * 
     * @param int $userId The user ID to check
     * @return bool True if user is admin, false otherwise
     */
    private function isUserAdmin($userId) {
        try {
            if (!$userId) {
                return false;
            }
            $stmt = $this->pdo->prepare("
                SELECT role FROM users WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user && $user['role'] === 'admin';
        } catch (Exception $e) {
            error_log("[NOTIFY_TICKET] Error checking admin role: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get ticket details
     */
    private function getTicketDetails($ticketId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT t.id, t.ticket_number, s.site_name, s.location_name
                FROM tickets t
                JOIN sites s ON t.site_id = s.id
                WHERE t.id = ?
            ");
            $stmt->execute([$ticketId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching ticket details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get ticket details including creator personnel ID
     */
    private function getTicketDetailsWithCreator($ticketId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT t.id, t.ticket_number, t.created_by, s.site_name, s.location_name
                FROM tickets t
                JOIN sites s ON t.site_id = s.id
                WHERE t.id = ?
            ");
            $stmt->execute([$ticketId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching ticket details with creator: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user details
     */
    private function getUserDetails($userId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.id, p.gmail as email, p.fullname
                FROM users u
                JOIN personnels p ON u.personnel_id = p.id
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching user details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get personnel details
     */
    private function getPersonnelDetails($personnelId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, fullname, gmail
                FROM personnels
                WHERE id = ?
            ");
            $stmt->execute([$personnelId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching personnel details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get site details
     */
    private function getSiteDetails($siteId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, site_name, location_name, province, municipality
                FROM sites
                WHERE id = ?
            ");
            $stmt->execute([$siteId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching site details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get unread notifications count for a user
     */
    public function getUnreadNotificationCount($userId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM notifications
                WHERE user_id = ? AND is_read = FALSE
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            error_log("Error fetching unread notification count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get recent unread notifications for a user
     */
    public function getUnreadNotifications($userId, $limit = 10) {
        try {
            // LIMIT cannot use parameter binding, validate and use literal
            $limit = (int)$limit;
            if ($limit < 1) $limit = 10;
            
            $stmt = $this->pdo->prepare("
                SELECT id, type, title, message, related_id, created_at
                FROM notifications
                WHERE user_id = ? AND is_read = FALSE
                ORDER BY created_at DESC
                LIMIT " . $limit . "
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching unread notifications: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($notificationId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE notifications
                SET is_read = TRUE
                WHERE id = ?
            ");
            return $stmt->execute([$notificationId]);
        } catch (Exception $e) {
            error_log("Error marking notification as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead($userId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE notifications
                SET is_read = TRUE
                WHERE user_id = ? AND is_read = FALSE
            ");
            return $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log("Error marking all notifications as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all notifications for a user (with pagination)
     */
    public function getAllNotifications($userId, $limit = 50, $offset = 0) {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;
            if ($limit < 1) $limit = 50;
            if ($offset < 0) $offset = 0;
            
            $stmt = $this->pdo->prepare("
                SELECT id, type, title, message, related_id, created_at, is_read
                FROM notifications
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT " . $limit . " OFFSET " . $offset . "
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching all notifications: " . $e->getMessage());
            return [];
        }
    }
}
?>
