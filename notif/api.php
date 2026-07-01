<?php
/**
 * Notification API Endpoint
 * 
 * Handles:
 * - Fetching unread notifications
 * - Marking notifications as read
 * - Getting unread count
 */

require '../config/db.php';
require '../config/auth.php';
require '../notif/notification.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = $_SESSION['user_id'];
$notifier = new NotificationManager($pdo);

try {
    // Get unread notifications
    if ($action == 'get_unread') {
        $limit = (int)($_GET['limit'] ?? 10);
        $notifications = $notifier->getUnreadNotifications($userId, $limit);
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'count' => count($notifications)
        ]);
    }

    // Get unread count
    elseif ($action == 'get_count') {
        $count = $notifier->getUnreadNotificationCount($userId);
        echo json_encode([
            'success' => true,
            'count' => $count
        ]);
    }

    // Mark as read
    elseif ($action == 'mark_read') {
        $notificationId = $_POST['notification_id'] ?? '';
        if (empty($notificationId)) {
            echo json_encode(['success' => false, 'message' => 'Missing notification ID']);
            exit;
        }

        $result = $notifier->markAsRead($notificationId);
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Marked as read' : 'Failed to mark as read'
        ]);
    }

    // Mark all as read
    elseif ($action == 'mark_all_read') {
        $result = $notifier->markAllAsRead($userId);
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'All marked as read' : 'Failed to mark all as read'
        ]);
    }

    else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
exit;
?>
