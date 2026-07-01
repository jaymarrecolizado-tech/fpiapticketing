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
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

  <title>FPIAP-Service Management and Response Ticketing System</title>
</head>
<body class="d-flex flex-column min-vh-100" style="background-color: #f8f9fa;">

<?php // navigation ?>
<nav class="navbar sticky-top navbar-expand-lg navbar-light shadow-sm" style="background-color: #0ef;">
  <div class="container-fluid">

    <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
      <img src="../assets/freewifilogo.png" alt="Logo" width="100" height="100" class="me-2">
      <img src="../assets/FPIAP-SMARTs.png" alt="Logo" width="100" height="100" class="me-2">
      <div class="d-flex flex-column ms-0">
        <span class="fw-bold">FPIAP-SMARTs</span>
        <span class="fw-bold small align-self-center">ADMIN PANEL</span>
      </div>
    </a>
    <hr class="mx-0 my-2 opacity-25">
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Tickets</a>
          <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
            <li><a class="dropdown-item" href="viewtickets.php">View Tickets</a></li>
            <li><a class="dropdown-item" href="ticket.php">Create Ticket</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Sites</a>
          <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
            <li><a class="dropdown-item" href="site.php">Manage Sites</a></li>
            <li><a class="dropdown-item" href="site_report.php">Sites Report</a></li>
          </ul>
        </li>
        
            <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            Reports
            </a>
                <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                    <li><a class="dropdown-item" href="ticket_report.php">Ticket Report</a></li>
                    <li><a class="dropdown-item" href="#"></a></li> 
                </ul>
            </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Setting</a>
          <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
            <li><a class="dropdown-item" href="personnel.php">Personnels</a></li>
            <li><a class="dropdown-item" href="user.php">User's Management</a></li>
            <li><a class="dropdown-item" href="systemlog.php">System Log</a></li>
            <li><a class="dropdown-item" href="backup.php">Backup Management</a></li>
            <li><a class="dropdown-item" href="data_export.php">Data Export</a></li>
            <li><a class="dropdown-item" href="history.php">History</a></li>
          </ul>
        </li>
      </ul>
      <ul class="navbar-nav ms-auto align-items-center">
        <li class="nav-item dropdown me-3">
          <a id="notificationBell" class="nav-link position-relative dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-bell fs-5"></i>
            <span id="notificationBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger visually-hidden">0</span>
          </a>
          <ul id="notificationDropdown" class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationBell">
            <li class="dropdown-item text-center text-muted small">Loading...</li>
          </ul>
        </li>
        
        <li class="nav-item d-flex align-items-center me-3">
          <div class="d-flex flex-column text-end">
            <span class="fw-semibold text-dark small"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown User'); ?></span>
          </div>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle fs-4 me-1"></i>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="account.php">My Account</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="../logout.php">Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

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

<footer class="bg-dark text-light text-center py-3 mt-auto">
  <div class="container">
    <?php echo date('Y'); ?> &copy; FREE PUBLIC INTERNET ACCESS PROGRAM - SERVICE MANAGEMENT AND RESPONSE TICKETING SYSTEM (FPIAP-SMARTs). All Rights Reserved.
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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

    // Fetch notifications for the bell
    fetchNotifications();
    setInterval(fetchNotifications, 60000);

    const bellToggle = document.getElementById('notificationBell');
    if (bellToggle) {
        bellToggle.addEventListener('show.bs.dropdown', fetchNotifications);
    }
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

// Fetch notifications from notification.php
async function fetchNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    const badge = document.getElementById('notificationBadge');
    if (!dropdown) return;
    try {
        const resp = await fetch('notification.php', { method: 'GET', cache: 'no-cache' });
        if (!resp.ok) throw new Error('Network response not ok');
        const html = await resp.text();
        
        if (html && html.trim().length > 0) {
            dropdown.innerHTML = html;
        } else {
            dropdown.innerHTML = '<li class="dropdown-item text-center text-muted small">No notifications</li>';
        }

        // Attach click handlers to notification items
        dropdown.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function(e) {
                const notificationId = this.getAttribute('data-notification-id');
                if (notificationId) {
                    fetch('../notif/api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=mark_read&notification_id=' + notificationId
                    }).catch(err => console.error('Failed to mark notification as read:', err));
                    this.classList.remove('unread');
                }
            });
        });

        const unread = dropdown.querySelectorAll('.notification-item.unread, li[data-unread="1"]').length;
        if (unread > 0) {
            badge.textContent = String(unread);
            badge.classList.remove('visually-hidden');
        } else {
            badge.classList.add('visually-hidden');
        }
    } catch (err) {
        dropdown.innerHTML = '<li class="dropdown-item text-danger small">Error loading notifications</li>';
        if (badge) badge.classList.add('visually-hidden');
        console.error('Failed to load notifications:', err);
    }
}
</script>

</body>
</html>