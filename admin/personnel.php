<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Validator.php';
require '../lib/Sanitizer.php';
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
<?php $activePage = 'settings'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>

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

<?php require __DIR__ . '/../includes/footer.php'; ?>
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



</script>
</body>
</html>