<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

  <title>FPIAP-Service Management and Response Ticketing System</title>
</head>
<body class="d-flex flex-column min-vh-100">

<nav class="navbar sticky-top navbar-expand-lg navbar-light shadow-sm" style="background-color: #0ef;">
  <div class="container-fluid">

    <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
      <img src="../assets/freewifilogo.png" alt="Logo" width="100" height="100" class="me-2">
      <img src="../assets/FPIAP-SMARTs.png" alt="Logo" width="100" height="100" class="me-2">
      
      <div class="d-flex flex-column ms-0">
        <span class="fw-bold">FPIAP-SMARTs</span>
      </div>
    </a>

  <hr class="mx-0 my-2 opacity-25">

  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
    <span class="navbar-toggler-icon"></span>
  </button>

  <!-- Navigation Links -->
  <div class="collapse navbar-collapse" id="mainNavbar">
    <ul class="navbar-nav me-auto mb-2 mb-lg-0">

    <li class="nav-item">
      <a class="nav-link" href="dashboard.php">Dashboard</a>
    </li>

    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      Tickets
      </a>
      <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
      <li><a class="dropdown-item" href="view_tickets.php">View Tickets</a></li>
      <li><a class="dropdown-item" href="ticket.php">Create Ticket</a></li> 
      </ul>
    </li>

    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      Sites
      </a>
      <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
      <li><a class="dropdown-item" href="site.php">Manage Sites</a></li>
      <li><a class="dropdown-item" href="#">Sites Report</a></li> 
      </ul>
    </li>

    <li class="nav-item">
      <a class="nav-link" href="#">Reports</a>
    </li>
               
    </ul>

    <!-- Right Icons -->
    <ul class="navbar-nav ms-auto align-items-center">

    <!-- Notification Bell -->
    <li class="nav-item dropdown me-3">
          <a id="notificationBell" class="nav-link position-relative dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-bell fs-5"></i>
            <span id="notificationBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger visually-hidden">0</span>
          </a>
          <ul id="notificationDropdown" class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationBell">
            <li class="dropdown-item text-center text-muted small">Loading...</li>
          </ul>
    </li>

    <!-- Profile Dropdown -->
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
      <i class="bi bi-person-circle fs-4 me-1"></i>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item" href="#">My Account</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="../logout.php">Logout</a></li>
      </ul>
    </li>

    </ul>
  </div>
  </div>
</nav>




<footer class="bg-dark text-light text-center py-3 mt-auto">
  <div class="container">
  <small>
    <?php echo date('Y'); ?> &copy; FREE PUBLIC INTERNET ACCESS PROGRAM - SERVICE MANAGEMENT AND RESPONSE TICKETING SYSTEM (FPIAP-SMARTs). All Rights Reserved.
  </small>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Initialize on page load
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