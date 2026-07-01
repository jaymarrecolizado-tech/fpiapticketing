<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/validator.php';
require '../lib/sanitizer.php';
require '../notif/notification.php';

// ===== SECURITY: Enforce admin authentication =====
requireAdmin();

// Status constants
define('PERSONNEL_STATUS_ACTIVE', 'ACTIVE');
define('PERSONNEL_STATUS_INACTIVE', 'INACTIVE');

function normalizePersonnelStatus($status) {
    $status = strtoupper(trim((string)$status));
    return in_array($status, [PERSONNEL_STATUS_ACTIVE, PERSONNEL_STATUS_INACTIVE]) ? $status : PERSONNEL_STATUS_ACTIVE;
}

$action = $_POST['action'] ?? '';

// Only keep minimal actions needed for the simplified table structure
if ($action == 'add_form') {
  ?>
  <div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Add New Personnel</h5>
      <button type="button" class="btn-close btn-close-white" onclick="closePanel()"></button>
    </div>
    <div class="card-body">
      <form id="addPersonnelForm" onsubmit="savePersonnel(event)">
        <div class="mb-3">
          <label class="form-label">Full Name</label>
          <input type="text" name="fullname" class="form-control" required maxlength="150">
        </div>
        <div class="mb-3">
          <label class="form-label">Gmail</label>
          <input type="email" name="gmail" class="form-control" required maxlength="150">
        </div>
        <!-- status is not included on create; DB default is 'Active' -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
        <button type="submit" class="btn btn-success w-100">Save Personnel</button>
      </form>
    </div>
  </div>
  <?php
  exit;
}

elseif ($action == 'view_details') {
  $id = $_POST['id'];
  $stmt = $pdo->prepare("SELECT id, fullname, gmail, status, created_at, updated_at FROM personnels WHERE id = ?");
  $stmt->execute([$id]);
  $personnel = $stmt->fetch(PDO::FETCH_ASSOC);
  ?>
  <div class="card">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Personnel Details</h5>
      <button type="button" class="btn-close btn-close-white" onclick="closePanel()"></button>
    </div>
    <div class="card-body">
      <p><strong>Full Name:</strong> <?php echo htmlspecialchars($personnel['fullname']); ?></p>
      <?php $normalizedStatus = normalizePersonnelStatus($personnel['status'] ?? ''); ?>
      <p><strong>Gmail:</strong> <?php echo htmlspecialchars($personnel['gmail']); ?></p>
      <p><strong>Status:</strong> <span class="badge bg-<?php echo ($normalizedStatus === PERSONNEL_STATUS_ACTIVE) ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst(strtolower($normalizedStatus))); ?></span></p>
      <p><strong>Created:</strong> <?php echo htmlspecialchars($personnel['created_at']); ?></p>
      <p><strong>Updated:</strong> <?php echo htmlspecialchars($personnel['updated_at']); ?></p>
      <button class="btn btn-warning w-100" onclick="loadEditForm(<?php echo $personnel['id']; ?>)">Edit</button>
    </div>
  </div>
  <?php
  exit;
}

elseif ($action == 'edit_form') {
  $id = $_POST['id'];
  $stmt = $pdo->prepare("SELECT id, fullname, gmail, status FROM personnels WHERE id = ?");
  $stmt->execute([$id]);
  $personnel = $stmt->fetch(PDO::FETCH_ASSOC);
  ?>
  <div class="card">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Edit Personnel</h5>
      <button type="button" class="btn-close" onclick="closePanel()"></button>
    </div>
    <div class="card-body">
      <form id="editPersonnelForm" onsubmit="updatePersonnel(event)">
        <input type="hidden" name="id" value="<?php echo $personnel['id']; ?>">
        <div class="mb-3">
          <label class="form-label">Full Name</label>
          <input type="text" name="fullname" class="form-control" value="<?php echo htmlspecialchars($personnel['fullname']); ?>" required maxlength="150">
        </div>
        <div class="mb-3">
          <label class="form-label">Gmail</label>
          <input type="email" name="gmail" class="form-control" value="<?php echo htmlspecialchars($personnel['gmail']); ?>" required maxlength="150">
        </div>
        <div class="mb-3">
          <label class="form-label">Status</label>
          <?php $editStatus = normalizePersonnelStatus($personnel['status'] ?? ''); ?>
          <select name="status" class="form-select">
            <option value="ACTIVE" <?php echo $editStatus === PERSONNEL_STATUS_ACTIVE ? 'selected' : ''; ?>>Active</option>
            <option value="INACTIVE" <?php echo $editStatus === PERSONNEL_STATUS_INACTIVE ? 'selected' : ''; ?>>Inactive</option>
          </select>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
        <button type="submit" class="btn btn-primary w-100">Update Personnel</button>
      </form>
    </div>
  </div>
  <?php
  exit;
}

elseif ($action == 'save') {
  // Validate CSRF token
  $csrf_token = $_POST['csrf_token'] ?? '';
  if (!validateCSRFToken($csrf_token)) {
    echo 'Error: Invalid security token. Please try again.';
    exit;
  }

  try {
    // Minimal validation: fullname + gmail
    $fullname = trim($_POST['fullname'] ?? '');
    $gmail = trim($_POST['gmail'] ?? '');

    if (!Validator::name($fullname, 150)) {
      echo 'Error: Invalid full name. Use letters, spaces, hyphens, apostrophes (2-150 characters).';
      exit;
    }

    if (!Validator::email($gmail)) {
      echo 'Error: Invalid email address.';
      exit;
    }

    // Sanitize
    $fullname = Sanitizer::normalize($fullname);
    $gmail = Sanitizer::normalize($gmail);

    // Check duplicate email
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM personnels WHERE UPPER(gmail) = ?");
    $stmtCheck->execute([strtoupper($gmail)]);
    if ($stmtCheck->fetchColumn() > 0) {
      echo 'Error: A personnel with this email already exists.';
      exit;
    }

    // Insert fullname, gmail, status consistently as uppercase constants (timestamps automatic)
    $stmt = $pdo->prepare("INSERT INTO personnels (fullname, gmail, status) VALUES (?, ?, ?)");
    $stmt->execute([$fullname, $gmail, PERSONNEL_STATUS_ACTIVE]);

    // Get inserted personnel ID for notifications
    $personnelId = $pdo->lastInsertId();
    
    // Send notification
    $creatorStmt = $pdo->prepare("SELECT p.id, p.fullname FROM personnels p JOIN users u ON p.id = u.personnel_id WHERE u.id = ?");
    $creatorStmt->execute([$_SESSION['user_id']]);
    $creator = $creatorStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($creator) {
      $notifier = new NotificationManager($pdo);
      $notifier->notifyPersonnelCreated($personnelId, $creator);
    }

    echo 'success';
  } catch (PDOException $e) {
    echo 'Error: Database error occurred. Please try again.';
    error_log('Personnel save error: ' . $e->getMessage());
  }
  exit;
}

elseif ($action == 'update') {
  // Validate CSRF token
  $csrf_token = $_POST['csrf_token'] ?? '';
  if (!validateCSRFToken($csrf_token)) {
    echo 'Error: Invalid security token. Please try again.';
    exit;
  }

  try {
    $id = $_POST['id'] ?? '';
    if (!Validator::positiveInteger($id)) {
      echo 'Error: Invalid personnel ID.';
      exit;
    }

    $fullname = trim($_POST['fullname'] ?? '');
    $gmail = trim($_POST['gmail'] ?? '');
    $status = normalizePersonnelStatus($_POST['status'] ?? '');

    if (!Validator::name($fullname, 150)) {
      echo 'Error: Invalid full name. Use letters, spaces, hyphens, apostrophes (2-150 characters).';
      exit;
    }

    if (!Validator::email($gmail)) {
      echo 'Error: Invalid email address.';
      exit;
    }

    if (!Validator::inList($status, [PERSONNEL_STATUS_ACTIVE, PERSONNEL_STATUS_INACTIVE])) {
      echo 'Error: Invalid status.';
      exit;
    }

    $fullname = Sanitizer::normalize($fullname);
    $gmail = Sanitizer::normalize($gmail);

    // Check duplicate email excluding current record
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM personnels WHERE UPPER(gmail) = ? AND id != ?");
    $stmtCheck->execute([strtoupper($gmail), $id]);
    if ($stmtCheck->fetchColumn() > 0) {
      echo 'Error: A personnel with this email already exists.';
      exit;
    }

    $stmt = $pdo->prepare("UPDATE personnels SET fullname = ?, gmail = ?, status = ? WHERE id = ?");
    $stmt->execute([$fullname, $gmail, $status, $id]);

    echo 'success';
  } catch (PDOException $e) {
    echo 'Error: Database error occurred. Please try again.';
    error_log('Personnel update error: ' . $e->getMessage());
  }
  exit;
}
?>
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
        <span class="fw-bold small align-self-center">ADMIN PANEL</span>
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
      <li><a class="dropdown-item" href="viewtickets.php">View Tickets</a></li>
      <li><a class="dropdown-item" href="ticket.php">Create Ticket</a></li> 
      </ul>
    </li>


    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      Sites
      </a>
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
        <a class="nav-link active dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            Setting
        </a>
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

    <!-- Account Name Display -->
    <li class="nav-item d-flex align-items-center me-3">
      <div class="d-flex flex-column text-end">
        <span class="fw-semibold text-dark small"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown User'); ?></span>
      </div>
    </li>

    <!-- Profile Dropdown -->
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

<div class="container-fluid mt-4">
  <div class="row">
    <!-- Table Column -->
    <div class="col-12" id="tableContainer" style="transition: all 0.3s ease;">
      <div id="alertContainer"></div>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>PERSONNEL'S MANAGEMENT</h3>
        <button class="btn btn-primary" onclick="loadAddForm()">
          <i class="bi bi-plus-lg"></i> Add New Personnel
        </button>
      </div>
            
      <div class="mb-3">
        <input type="text" id="searchInput" class="form-control form-control-lg" placeholder="Search by Name or Email...">
      </div>
            
      <div class="table-responsive">
        <table class="table table-bordered table-hover shadow-sm">
          <thead class="table-dark text-center">
            <tr>
              <th class="text-center">Name</th>
              <th class="text-center">Gmail</th>
              <th class="text-center">Status</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $sql = "SELECT id, fullname, gmail, status FROM personnels ORDER BY id DESC";
            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
              $statusBadge = (strtoupper($row['status']) == 'ACTIVE') ? 'bg-success' : 'bg-secondary';
              $fullname = htmlspecialchars($row['fullname']);
                            
              echo "<tr>
                <td>" . $fullname . "</td>
                <td>" . htmlspecialchars($row['gmail']) . "</td>
                <td><span class='badge {$statusBadge}'>{$row['status']}</span></td>
                <td class='text-center'>
                  <button class='btn btn-sm btn-info text-white' onclick='loadView({$row['id']})' title='View Details' data-bs-toggle='tooltip'><i class='bi bi-eye'></i></button>
                  <button class='btn btn-sm btn-warning' onclick='loadEditForm({$row['id']})' title='Edit Personnel' data-bs-toggle='tooltip'><i class='bi bi-pencil'></i></button>
                </td>
              </tr>";
            }
            ?>
  </tbody>
        </table>
      </div>
    </div>

    <!-- Side Panel Column -->
    <div class="col-4 d-none" id="sidePanel" style="transition: all 0.3s ease;">
      <div id="sidePanelContent">
        <!-- Dynamic Content Will Be Loaded Here -->
      </div>
    </div>
  </div>
</div>

<footer class="bg-dark text-light text-center py-3 mt-auto">
  <div class="container">
  <small>
    <?php echo date('Y'); ?> &copy; FREE PUBLIC INTERNET ACCESS PROGRAM - SERVICE MANAGEMENT AND RESPONSE TICKETING SYSTEM (FPIAP-SMARTs). All Rights Reserved.
  </small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Initialize Bootstrap tooltips
  document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl);
    });
  });
    
  // Search functionality
  document.getElementById('searchInput').addEventListener('keyup', function() {
    const searchValue = this.value.toLowerCase();
    const tableRows = document.querySelectorAll('tbody tr');
        
    tableRows.forEach(row => {
      const rowText = row.textContent.toLowerCase();
      if (rowText.includes(searchValue)) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });
  });
    
  function showPanel() {
    document.getElementById('tableContainer').classList.remove('col-12');
    document.getElementById('tableContainer').classList.add('col-8');
    document.getElementById('sidePanel').classList.remove('d-none');
  }

  function closePanel() {
    document.getElementById('sidePanel').classList.add('d-none');
    document.getElementById('tableContainer').classList.remove('col-8');
    document.getElementById('tableContainer').classList.add('col-12');
    document.getElementById('sidePanelContent').innerHTML = '';
  }

  function loadAddForm() {
    showPanel();
    fetch('personnel.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'action=add_form'
    })
    .then(response => response.text())
    .then(html => {
      document.getElementById('sidePanelContent').innerHTML = html;
    });
  }

  function loadView(id) {
    showPanel();
    fetch('personnel.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'action=view_details&id=' + id
    })
    .then(response => response.text())
    .then(html => {
      document.getElementById('sidePanelContent').innerHTML = html;
    });
  }

  function loadEditForm(id) {
    showPanel();
    fetch('personnel.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'action=edit_form&id=' + id
    })
    .then(response => response.text())
    .then(html => {
      document.getElementById('sidePanelContent').innerHTML = html;
    });
  }



  function showAlert(message, type) {
    const alertContainer = document.getElementById('alertContainer');
    const alertDiv = document.createElement('div');
    let alertClass = 'alert-success'; // Default to green
        
    if (type === 'error') {
      alertClass = 'alert-danger'; // Red
    } else if (type === 'update') {
      alertClass = 'alert-info'; // Blue
    }
        
    alertDiv.className = `alert ${alertClass} alert-dismissible fade show`;
    alertDiv.role = 'alert';
    alertDiv.innerHTML = `
      ${message}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
        
    alertContainer.appendChild(alertDiv);
        
    // Auto dismiss after 3 seconds
    setTimeout(() => {
      const bsAlert = new bootstrap.Alert(alertDiv);
      bsAlert.close();
    }, 3000);
  }

  function savePersonnel(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    formData.append('action', 'save');

    fetch('personnel.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.text())
    .then(result => {
      if (result.trim() === 'success') {
        showAlert('Personnel added successfully!', 'success');
        closePanel();
        setTimeout(() => location.reload(), 1500);
      } else {
        showAlert(result, 'error');
      }
    });
  }

  function updatePersonnel(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    formData.append('action', 'update');

    fetch('personnel.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.text())
    .then(result => {
      if (result.trim() === 'success') {
        showAlert('Personnel updated successfully!', 'update');
        closePanel();
        setTimeout(() => location.reload(), 1500);
      } else {
        showAlert(result, 'error');
      }
    });
  }



  // Notifications: fetch dropdown HTML and update unread badge
  async function fetchNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    const badge = document.getElementById('notificationBadge');
    if (!dropdown) return;
    try {
      const resp = await fetch('notification.php', { method: 'GET', cache: 'no-cache' });
      if (!resp.ok) throw new Error('Network response not ok');
      const html = await resp.text();
      
      dropdown.innerHTML = html;

      // Attach click handlers to notification items
      dropdown.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function(e) {
          const notificationId = this.getAttribute('data-notification-id');
          if (notificationId) {
            // Mark as read via API
            fetch('../notif/api.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: 'action=mark_read&notification_id=' + notificationId
            }).catch(err => console.error('Failed to mark notification as read:', err));
            
            // Remove unread class
            this.classList.remove('unread');
          }
        });
      });

      // count unread items if the server marks them with .notification-item.unread or data-unread="1"
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

  // Load notifications on page load and set up polling + dropdown trigger
  document.addEventListener('DOMContentLoaded', function() {
    // Initial notifications load and polling
    fetchNotifications();
    setInterval(fetchNotifications, 60000);

    // Refresh when dropdown is opened (Bootstrap 5 triggers show.bs.dropdown)
    const bellToggle = document.getElementById('notificationBell');
    if (bellToggle) {
      bellToggle.addEventListener('show.bs.dropdown', fetchNotifications);
    }
  });
</script>
</body>
</html>