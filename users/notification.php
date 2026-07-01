<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

try {
    // Include auth first to properly initialize session
    require_once '../config/auth.php';
    require_once '../config/db.php';
    require_once '../notif/notification.php';

    if (!isset($_SESSION['user_id'])) {
        echo '<li class="dropdown-item text-center text-muted small">Please log in</li>';
        ob_end_flush();
        exit;
    }

    $userId = intval($_SESSION['user_id']);

    if (!isset($pdo)) {
        throw new Exception('Database connection failed');
    }

    $notifier = new NotificationManager($pdo);
    $notifications = $notifier->getUnreadNotifications($userId, 10);

    if (empty($notifications)) {
        echo '<li class="dropdown-item text-center text-muted small">No new notifications</li>';
    } else {
        echo '<li class="dropdown-header">Notifications</li>';

        foreach ($notifications as $notif) {
            $icon = 'bi-info-circle';
            $badgeClass = 'text-primary';

            switch ($notif['type']) {
                case 'ticket_created':
                    $icon = 'bi-ticket-perforated';
                    $badgeClass = 'text-success';
                    break;
                case 'ticket_created_confirmation':
                    $icon = 'bi-check-circle';
                    $badgeClass = 'text-success';
                    break;
                case 'user_created':
                    $icon = 'bi-person-plus';
                    $badgeClass = 'text-info';
                    break;
                case 'personnel_created':
                    $icon = 'bi-person-badge';
                    $badgeClass = 'text-warning';
                    break;
                case 'site_created':
                    $icon = 'bi-geo-alt';
                    $badgeClass = 'text-secondary';
                    break;
                case 'ticket_resolved':
                    $icon = 'bi-check-circle';
                    $badgeClass = 'text-success';
                    break;
                case 'ticket_closed':
                    $icon = 'bi-lock';
                    $badgeClass = 'text-secondary';
                    break;
                case 'ticket_auto_closed':
                    $icon = 'bi-clock';
                    $badgeClass = 'text-info';
                    break;
            }

            $timeAgo = time() - strtotime($notif['created_at']);
            if ($timeAgo < 60) {
                $timeStr = 'Just now';
            } elseif ($timeAgo < 3600) {
                $timeStr = floor($timeAgo / 60) . 'm ago';
            } elseif ($timeAgo < 86400) {
                $timeStr = floor($timeAgo / 3600) . 'h ago';
            } else {
                $timeStr = floor($timeAgo / 86400) . 'd ago';
            }

            $notifId = intval($notif['id']);
            $title = htmlspecialchars($notif['title'], ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars($notif['message'], ENT_QUOTES, 'UTF-8');

            echo '<li><a class="dropdown-item notification-item unread" href="#" data-notification-id="' . $notifId . '"><div class="d-flex align-items-start"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') . ' me-2 mt-1"></i><div class="flex-grow-1"><div class="fw-semibold small">' . $title . '</div><div class="text-muted small">' . $message . '</div><div class="text-muted x-small mt-1">' . htmlspecialchars($timeStr, ENT_QUOTES, 'UTF-8') . '</div></div></div></a></li>';
        }

        echo '<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-center small" href="notifications.php">View All</a></li>';
    }

} catch (Exception $e) {
    error_log("Notification Error: " . $e->getMessage());
    echo '<li class="dropdown-item text-danger small">Loading error</li>';
}

$output = ob_get_clean();
echo $output;