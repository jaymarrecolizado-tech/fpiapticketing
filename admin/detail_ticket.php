<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';

requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo "<p>Invalid ticket id</p>";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT t.*, s.site_name, p.fullname AS created_by_name
                           FROM tickets t
                           LEFT JOIN sites s ON t.site_id = s.id
                           LEFT JOIN personnels p ON t.created_by = p.id
                           WHERE t.id = ?");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        echo "<p>Ticket not found</p>";
        exit;
    }
} catch (PDOException $e) {
    echo "<p>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

function calculateDuration($created_at, $solved_date, $status) {
    $start = new DateTime($created_at);
    
    if ($status === 'CLOSED' && $solved_date) {
        $end = new DateTime($solved_date);
    } else {
        $end = new DateTime();
    }
    
    $interval = $start->diff($end);
    
    $days = $interval->days;
    $hours = $interval->h;
    $minutes = $interval->i;
    
    return sprintf("%02d %02d %02d", $days, $hours, $minutes);
}

function calculateDurationMinutes($created_at, $stored_duration, $status) {
    if ($status === 'CLOSED') {
        return $stored_duration; // Use stored value for closed tickets
    } else {
        // Real-time calculation for OPEN/IN_PROGRESS
        // Use the same timezone as database (+08:00) for consistency
        $created = new DateTime($created_at, new DateTimeZone('+08:00'));
        $now = new DateTime('now', new DateTimeZone('+08:00'));
        $interval = $created->diff($now);
        return ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    }
}

function formatDurationDisplay($minutes) {
    if ($minutes < 0) $minutes = 0;
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;
    
    $parts = [];
    if ($days > 0) $parts[] = $days . ' Day' . ($days > 1 ? 's' : '');
    if ($hours > 0) $parts[] = $hours . ' Hr' . ($hours > 1 ? 's' : '');
    if ($mins > 0 || empty($parts)) $parts[] = $mins . ' Min' . ($mins > 1 ? 's' : '');
    
    return implode(' ', $parts);
}

function escapeHtml($s) {
    if ($s === null) return '';
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
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
          <a class="nav-link active dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Tickets</a>
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
            <span class="fw-semibold text-dark small"><?php echo escapeHtml($_SESSION['username'] ?? 'Unknown User'); ?></span>
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
        <!-- Left Column: Ticket Details -->
        <div class="col-lg-8 col-12 pe-3" style="transition: all 0.3s ease;">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Ticket #<?php echo escapeHtml($ticket['ticket_number']); ?></h5>
                </div>
                <div class="card-body">
                    <!-- Subject -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold">Subject</h6>
                        <p class="mb-0"><?php echo escapeHtml($ticket['subject']); ?></p>
                    </div>

                    <hr>

                    <!-- Site Information -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold">Site</h6>
                        <p class="mb-0"><?php echo escapeHtml($ticket['site_name']); ?></p>
                    </div>

                    <hr>


                    <!-- Duration -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold">Duration</h6>
                        <p class="mb-0"><span class="badge bg-info"><?php echo formatDurationDisplay(calculateDurationMinutes($ticket['created_at'], $ticket['duration'], $ticket['status'])); ?></span></p>
                    </div>

                    <hr>

                    <!-- Notes -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold">Notes</h6>
                        <div style="padding: 12px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #05b6f1; min-height: 60px;">
                            <p class="mb-0" style="white-space: pre-wrap; word-wrap: break-word;">
                                <?php echo escapeHtml($ticket['notes']) ?: '<span class="text-muted">No notes</span>'; ?>
                            </p>
                        </div>
                    </div>

                    <hr>

                    <!-- Action Buttons -->
                    <div class="d-flex gap-2">
                        <?php if ($ticket['status'] !== 'CLOSED'): ?>
                        <a href="edit_ticket.php?id=<?php echo escapeHtml($ticket['id']); ?>" class="btn btn-warning flex-fill">
                            <i class="bi bi-pencil-square"></i> Edit Ticket
                        </a>
                        <?php endif; ?>
                        <a href="viewtickets.php" class="btn btn-secondary flex-fill">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Summary Panel -->
        <div class="col-lg-4 col-12 ps-3">
            <div class="card shadow-sm" style="position: sticky; top: 20px;">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Ticket Information</h5>
                </div>
                <div class="card-body">
                    <!-- Ticket Number -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Ticket Number</h6>
                        <small class="fw-bold d-block text-primary"><?php echo escapeHtml($ticket['ticket_number']); ?></small>
                    </div>

                    <hr>

                    <!-- Status -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Status</h6>
                        <div>
                            <?php 
                            $statusClass = match($ticket['status']) {
                                'OPEN' => 'bg-danger',
                                'IN_PROGRESS' => 'bg-warning',
                                'RESOLVED' => 'bg-info',
                                'CLOSED' => 'bg-success',
                                default => 'bg-secondary'
                            };
                            ?>
                            <span class="badge <?php echo $statusClass; ?> fs-6"><?php echo escapeHtml($ticket['status']); ?></span>
                        </div>
                    </div>

                    <hr>

                    <!-- Solved Date (only for RESOLVED/CLOSED tickets) -->
                    <?php if (($ticket['status'] === 'CLOSED' || $ticket['status'] === 'RESOLVED') && $ticket['solved_date']): ?>
                    <div class="mb-3">
                        <h6 class="text-muted small">Solved Date</h6>
                        <small><?php echo escapeHtml($ticket['solved_date']); ?></small>
                    </div>

                    <hr>
                    <?php endif; ?>

                    <!-- Created Information -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Created By</h6>
                        <small><?php echo escapeHtml($ticket['created_by_name'] ?? 'Unknown'); ?></small>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <h6 class="text-muted small">Created At</h6>
                        <small><?php echo escapeHtml($ticket['created_at']); ?></small>
                    </div>

                    <hr>

                    <!-- Updated At -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Updated At</h6>
                        <small><?php echo escapeHtml($ticket['updated_at'] ?? 'N/A'); ?></small>
                    </div>

                    <hr>

                    <!-- Quick Stats -->
                    <div class="mt-4">
                        <h6 class="text-muted small fw-bold">Quick Stats</h6>
                        <div class="row g-2">
                            <div class="col-6">
                                <div style="padding: 12px; background: #e7f3ff; border-radius: 4px; text-align: center;">
                                    <small class="text-muted d-block">Duration</small>
                                    <small class="fw-bold text-primary"><?php echo formatDurationDisplay(calculateDurationMinutes($ticket['created_at'], $ticket['duration'], $ticket['status'])); ?></small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div style="padding: 12px; background: #f0f8ff; border-radius: 4px; text-align: center;">
                                    <small class="text-muted d-block">Site</small>
                                    <small class="fw-bold text-primary" style="overflow: hidden; text-overflow: ellipsis; display: block; white-space: nowrap;"><?php echo escapeHtml(substr($ticket['site_name'], 0, 15)); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
    // Initialize notifications on page load
    document.addEventListener('DOMContentLoaded', function() {
        fetchNotifications();
        setInterval(fetchNotifications, 60000);

        const bellToggle = document.getElementById('notificationBell');
        if (bellToggle) {
            bellToggle.addEventListener('show.bs.dropdown', fetchNotifications);
        }
    });

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
