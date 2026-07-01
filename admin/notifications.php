<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';

requireAdmin();

$userId = intval($_SESSION['user_id']);

// Handle AJAX actions
$action = $_POST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    try {
        require_once '../notif/notification.php';
        $notifier = new NotificationManager($pdo);
        
        switch ($action) {
            case 'mark_read':
                $notificationId = intval($_POST['notification_id'] ?? 0);
                if ($notificationId > 0) {
                    $notifier->markAsRead($notificationId);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['error' => 'Invalid notification ID']);
                }
                break;
            case 'mark_all_read':
                $notifier->markAllAsRead($userId);
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

// Get notifications for display
require_once '../notif/notification.php';
$notifier = new NotificationManager($pdo);
$notifications = $notifier->getAllNotifications($userId, 100); // Get up to 100 notifications

function getNotificationIcon($type) {
    switch ($type) {
        case 'ticket_created':
            return 'bi-ticket-perforated';
        case 'ticket_created_confirmation':
            return 'bi-check-circle';
        case 'user_created':
            return 'bi-person-plus';
        case 'personnel_created':
            return 'bi-person-badge';
        case 'site_created':
            return 'bi-geo-alt';
        case 'ticket_resolved':
            return 'bi-check-circle';
        case 'ticket_closed':
            return 'bi-lock';
        case 'ticket_auto_closed':
            return 'bi-clock';
        default:
            return 'bi-info-circle';
    }
}

function getNotificationClass($type) {
    switch ($type) {
        case 'ticket_created':
        case 'ticket_created_confirmation':
        case 'ticket_resolved':
            return 'text-success';
        case 'user_created':
            return 'text-info';
        case 'personnel_created':
            return 'text-warning';
        case 'site_created':
        case 'ticket_closed':
            return 'text-secondary';
        case 'ticket_auto_closed':
            return 'text-info';
        default:
            return 'text-primary';
    }
}

function formatTimeAgo($created_at) {
    $time = time() - strtotime($created_at);
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time / 60) . 'm ago';
    if ($time < 86400) return floor($time / 3600) . 'h ago';
    return floor($time / 86400) . 'd ago';
}
?>
<?php $activePage = 'dashboard'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>

<main class="flex-grow-1 overflow-auto">
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">Notifications</h1>
                    <p class="text-muted">View all your notifications</p>
                </div>
                <button id="markAllReadBtn" class="btn btn-primary">
                    <i class="bi bi-check-all"></i> Mark All as Read
                </button>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-bell-slash text-muted" style="font-size: 3rem;"></i>
                    <h4 class="text-muted mt-3">No notifications</h4>
                    <p class="text-muted">You don't have any notifications yet.</p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($notifications as $notif): ?>
                        <?php $icon = getNotificationIcon($notif['type']); $badgeClass = getNotificationClass($notif['type']); ?>
                        <div class="col-12 mb-3">
                            <div class="card notification-card <?php echo $notif['is_read'] ? '' : 'border-primary'; ?>" data-notification-id="<?php echo $notif['id']; ?>">
                                <div class="card-body">
                                    <div class="d-flex align-items-start">
                                        <i class="bi <?php echo $icon; ?> <?php echo $badgeClass; ?> me-3 mt-1" style="font-size: 1.2rem;"></i>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="card-title mb-1 <?php echo $notif['is_read'] ? 'text-muted' : 'fw-bold'; ?>">
                                                        <?php echo htmlspecialchars($notif['title']); ?>
                                                    </h6>
                                                    <p class="card-text small text-muted mb-2">
                                                        <?php echo htmlspecialchars($notif['message']); ?>
                                                    </p>
                                                    <small class="text-muted">
                                                        <?php echo formatTimeAgo($notif['created_at']); ?>
                                                    </small>
                                                </div>
                                                <?php if (!$notif['is_read']): ?>
                                                    <button class="btn btn-sm btn-outline-primary mark-read-btn" data-notification-id="<?php echo $notif['id']; ?>">
                                                        <i class="bi bi-check"></i> Mark Read
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
document.addEventListener('DOMContentLoaded', function() {
    // Mark individual notification as read
    document.querySelectorAll('.mark-read-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const notificationId = this.getAttribute('data-notification-id');
            markAsRead(notificationId, this);
        });
    });

    // Mark all as read
    document.getElementById('markAllReadBtn').addEventListener('click', function() {
        if (confirm('Mark all notifications as read?')) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('An error occurred');
            });
        }
    });

});

function markAsRead(notificationId, button) {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_read&notification_id=' + notificationId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const card = button.closest('.notification-card');
            card.classList.remove('border-primary');
            card.querySelector('.card-title').classList.remove('fw-bold');
            card.querySelector('.card-title').classList.add('text-muted');
            button.remove();
            fetchNotifications(); // Update bell count
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('An error occurred');
    });
}

</script>

</body>
</html>