<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require '../notif/notification.php';
require '../lib/Logger.php';
require '../lib/auto_close.php';
require_once '../lib/TicketHistory.php';

requireAdmin();

// Auto-close resolved tickets older than 7 days
$logger = new Logger($pdo);
$autoClosed = autoCloseResolvedTickets($pdo, $logger);
if ($autoClosed > 0) {
    // Optional: could store in session for display, but for now just run silently
}

// Check for success message from edit_ticket.php redirect
$successMessage = '';
$updatedTicketId = '';
if (isset($_GET['success']) && $_GET['success'] == '1' && isset($_GET['updated'])) {
    $updatedTicketId = intval($_GET['updated']);
    $ticketNumber = isset($_GET['ticket_number']) ? htmlspecialchars($_GET['ticket_number']) : $updatedTicketId;
    $successMessage = 'Ticket - ' . $ticketNumber . ' has been updated successfully!';
}

$action = $_POST['action'] ?? '';

// Return filter options for frontend
if ($action === 'get_filters') {
  // ===== CSRF Validation =====
  $csrf_token = $_POST['csrf_token'] ?? '';
  if (!validateCSRFToken($csrf_token)) {
    echo json_encode(['error' => 'Invalid security token']);
    exit;
  }

  try {
    $statuses = $pdo->query("SELECT DISTINCT status FROM tickets WHERE status IS NOT NULL ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
    $sites = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll(PDO::FETCH_ASSOC);

    // additional filter option lists
    $createdByNames = $pdo->query("SELECT DISTINCT p.id, p.fullname FROM personnels p INNER JOIN tickets t ON t.created_by = p.id ORDER BY p.fullname")->fetchAll(PDO::FETCH_ASSOC);
    $isps = $pdo->query("SELECT DISTINCT isp FROM sites WHERE isp IS NOT NULL AND isp != '' ORDER BY isp")->fetchAll(PDO::FETCH_COLUMN);
    $projects = $pdo->query("SELECT DISTINCT project_name FROM sites WHERE project_name IS NOT NULL AND project_name != '' ORDER BY project_name")->fetchAll(PDO::FETCH_COLUMN);
    $provinces = $pdo->query("SELECT DISTINCT province FROM sites WHERE province IS NOT NULL AND province != '' ORDER BY province")->fetchAll(PDO::FETCH_COLUMN);
    $municipalities = $pdo->query("SELECT DISTINCT municipality FROM sites WHERE municipality IS NOT NULL AND municipality != '' ORDER BY municipality")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'statuses' => $statuses,
        'sites' => $sites,
        'created_by' => $createdByNames,
        'isps' => $isps,
        'projects' => $projects,
        'provinces' => $provinces,
        'municipalities' => $municipalities
    ]);
  } catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

if ($action === 'get_tickets') {
  $page = max(1, intval($_POST['page'] ?? 1));
  $perPage = intval($_POST['per_page'] ?? 25);
  $search = trim($_POST['search'] ?? '');
  // Multi-select arrays
  $statusArray = $_POST['status'] ?? [];
  if (!is_array($statusArray)) { $statusArray = $statusArray !== '' ? [$statusArray] : []; }
  $siteIdArray = $_POST['site_id'] ?? [];
  if (!is_array($siteIdArray)) { $siteIdArray = array_map('intval', $siteIdArray); }
  $createdByArray = $_POST['created_by'] ?? [];
  if (!is_array($createdByArray)) { $createdByArray = array_map('intval', $createdByArray); }
  $dateFrom = trim($_POST['date_from'] ?? '');
  $dateTo   = trim($_POST['date_to'] ?? '');
  $durationMin = $_POST['duration_min'] ?? '';
  $durationMax = $_POST['duration_max'] ?? '';
  // Multi-select isp
  $ispArray = $_POST['isp'] ?? [];
  if (!is_array($ispArray)) { $ispArray = $ispArray !== '' ? [$ispArray] : []; }
  $projectArray = $_POST['project'] ?? [];
  if (!is_array($projectArray)) { $projectArray = $projectArray !== '' ? [$projectArray] : []; }
  $provinceArray = $_POST['province'] ?? [];
  if (!is_array($provinceArray)) { $provinceArray = $provinceArray !== '' ? [$provinceArray] : []; }
  $municipalityArray = $_POST['municipality'] ?? [];
  if (!is_array($municipalityArray)) { $municipalityArray = $municipalityArray !== '' ? [$municipalityArray] : []; }

  // Sorting parameters
  $sortBy = trim($_POST['sort_by'] ?? 'created_at');
  $sortOrder = strtoupper(trim($_POST['sort_order'] ?? 'DESC'));

  // Validate sort parameters
  $validSortColumns = ['ticket_number', 'site_name', 'isp', 'subject', 'status', 'priority', 'created_at', 'created_by_name', 'duration'];
  $validSortOrders = ['ASC', 'DESC'];

  if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'created_at';
  }
  if (!in_array($sortOrder, $validSortOrders)) {
    $sortOrder = 'DESC';
  }

  $params = [];
  $conditions = [];

  if (!empty($statusArray)) {
      $placeholders = implode(',', array_fill(0, count($statusArray), '?'));
      $conditions[] = "t.status IN ($placeholders)";
      foreach ($statusArray as $v) {
          $params[] = trim($v);
      }
  }
  if (!empty($siteIdArray)) {
      $placeholders = implode(',', array_fill(0, count($siteIdArray), '?'));
      $conditions[] = "t.site_id IN ($placeholders)";
      foreach ($siteIdArray as $v) {
          $params[] = $v;
      }
  }
  if ($search !== '') { $conditions[] = '(t.ticket_number LIKE ? OR t.subject LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

  // ticket-specific filters
  if (!empty($createdByArray)) {
      $placeholders = implode(',', array_fill(0, count($createdByArray), '?'));
      $conditions[] = "t.created_by IN ($placeholders)";
      foreach ($createdByArray as $v) {
          $params[] = $v;
      }
  }
  if ($dateFrom !== '') { $conditions[] = 'DATE(t.created_at) >= ?'; $params[] = $dateFrom; }
  if ($dateTo !== '') { $conditions[] = 'DATE(t.created_at) <= ?'; $params[] = $dateTo; }
  if (is_numeric($durationMin)) { $conditions[] = 't.duration >= ?'; $params[] = intval($durationMin); }
  if (is_numeric($durationMax)) { $conditions[] = 't.duration <= ?'; $params[] = intval($durationMax); }

  // site-related filters (requires join)
  if (!empty($ispArray)) {
      $placeholders = implode(',', array_fill(0, count($ispArray), '?'));
      $conditions[] = "s.isp IN ($placeholders)";
      foreach ($ispArray as $v) {
          $params[] = trim($v);
      }
  }
  if (!empty($projectArray)) {
      $placeholders = implode(',', array_fill(0, count($projectArray), '?'));
      $conditions[] = "s.project_name IN ($placeholders)";
      foreach ($projectArray as $v) {
          $params[] = trim($v);
      }
  }
  if (!empty($provinceArray)) {
      $placeholders = implode(',', array_fill(0, count($provinceArray), '?'));
      $conditions[] = "s.province IN ($placeholders)";
      foreach ($provinceArray as $v) {
          $params[] = trim($v);
      }
  }
  if (!empty($municipalityArray)) {
      $placeholders = implode(',', array_fill(0, count($municipalityArray), '?'));
      $conditions[] = "s.municipality IN ($placeholders)";
      foreach ($municipalityArray as $v) {
          $params[] = trim($v);
      }
  }

  $where = '';
  if (!empty($conditions)) $where = 'WHERE ' . implode(' AND ', $conditions);

  try {
    // always join sites (and personnels for name) so filters on site fields work in count and data queries
    $joinClause = "FROM tickets t LEFT JOIN sites s ON t.site_id = s.id LEFT JOIN personnels p ON t.created_by = p.id";

    $countSql = "SELECT COUNT(*) " . $joinClause . " " . ($where ? $where : '');
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());

        $offset = ($page - 1) * $perPage;

        // Some MariaDB/MySQL setups don't accept bound parameters for LIMIT/OFFSET reliably.
        // Safely inject integers after validation.
        $limit = intval($perPage);
        $offsetInt = intval($offset);

            $sql = "SELECT t.*, s.site_name, s.isp, p.fullname AS created_by_name " . $joinClause . " " .
          ($where ? $where : '') .
          " ORDER BY {$sortBy} {$sortOrder}, t.created_at DESC LIMIT {$limit} OFFSET {$offsetInt}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
      $r['created_at_fmt'] = date('M d, Y H:i', strtotime($r['created_at']));
      
      // Calculate real-time duration for OPEN/IN_PROGRESS, use stored duration for RESOLVED/CLOSED
      if (in_array($r['status'], ['CLOSED', 'RESOLVED'])) {
          $r['duration_minutes'] = $r['duration']; // Use stored value for resolved/closed tickets
      } else {
          // Real-time calculation for OPEN/IN_PROGRESS tickets
          // Use the same timezone as database (+08:00) for consistency
          $created = new DateTime($r['created_at'], new DateTimeZone('+08:00'));
          $now = new DateTime('now', new DateTimeZone('+08:00'));
          $interval = $created->diff($now);
          $r['duration_minutes'] = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
      }
    }

    echo json_encode([
      'success' => true,
      'total' => $total,
      'page' => $page,
      'per_page' => $perPage,
      'pages' => ceil($total / $perPage),
      'tickets' => $rows,
      'sort_by' => $sortBy,
      'sort_order' => $sortOrder
    ]);
  } catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  }
  exit;
}

// Get single ticket details
if ($action === 'get_ticket') {
  // ===== CSRF Validation =====
  $csrf_token = $_POST['csrf_token'] ?? '';
  if (!validateCSRFToken($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
  }

  $id = intval($_POST['ticket_id'] ?? 0);
  if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ticket id']); exit; }
  try {
    $stmt = $pdo->prepare("SELECT t.*, s.site_name, p.fullname AS created_by_name FROM tickets t LEFT JOIN sites s ON t.site_id=s.id LEFT JOIN personnels p ON t.created_by=p.id WHERE t.id = ?");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) { echo json_encode(['success' => false, 'message' => 'Ticket not found']); exit; }
    echo json_encode(['success' => true, 'ticket' => $ticket]);
  } catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  }
  exit;
}

// Update ticket (editing) - allow setting status and notes
if ($action === 'update_ticket') {
  // ===== CSRF Validation =====
  $csrf_token = $_POST['csrf_token'] ?? '';
  if (!validateCSRFToken($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
  }

  $id = intval($_POST['ticket_id'] ?? 0);
  if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ticket id']); exit; }

  $status = Sanitizer::normalize($_POST['status'] ?? 'OPEN');
  $notes = Sanitizer::remarks($_POST['notes'] ?? '');

  // Validate inputs
  $validStatuses = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
  if (!Validator::inList($status, $validStatuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid ticket status']); exit;
  }
  if (!Validator::remarks($notes)) {
    echo json_encode(['success' => false, 'message' => 'Notes exceed maximum length']); exit;
  }

  try {
    // Get current ticket status before update
    $currentStmt = $pdo->prepare("SELECT status, ticket_number FROM tickets WHERE id = ?");
    $currentStmt->execute([$id]);
    $currentTicket = $currentStmt->fetch(PDO::FETCH_ASSOC);
    $oldStatus = $currentTicket['status'];
    $ticketNumber = $currentTicket['ticket_number'];

    if (in_array($status, ['CLOSED', 'RESOLVED'])) {
      $updateSql = "UPDATE tickets SET status = ?, notes = ?, solved_date = NOW(), duration = TIMESTAMPDIFF(MINUTE, created_at, NOW()), updated_at = NOW() WHERE id = ?";
    } else {
      $updateSql = "UPDATE tickets SET status = ?, notes = ?, solved_date = NULL, updated_at = NOW() WHERE id = ?";
    }
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute([$status, $notes, $id]);

    // Log ticket history
    $history = new TicketHistory($pdo);
    if ($oldStatus !== $status) {
        $history->logChange($id, $_SESSION['user_id'], 'status_changed', 'status', $oldStatus, $status);
    }
    if (!empty($notes)) {
        $history->logChange($id, $_SESSION['user_id'], 'updated', 'notes', null, $notes);
    }

    // Log ticket status change
    $logger = new Logger($pdo);
    $logger->logEntityAction('ticket_status_changed', 'ticket', $id, [
        'ticket_number' => $ticketNumber,
        'old_status' => $oldStatus,
        'new_status' => $status,
        'notes' => $notes
    ], 'low');

      // Create a notification for the ticket owner when status becomes RESOLVED or CLOSED
      if (in_array($status, ['RESOLVED', 'CLOSED'])) {
        $notificationManager = new NotificationManager($pdo);
        $notificationManager->notifyTicketStatusUpdate($id, $status);
      }

    echo json_encode(['success' => true, 'message' => 'Ticket updated successfully']);
  } catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
  }
  exit;
}

?>
<?php $activePage = 'tickets'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>


<div class="container-fluid mt-3">
  <div class="row">
    <!-- Table Column -->
    <div class="col-12" id="tableContainer" style="transition: all 0.3s ease;">
      <div id="alertContainer">
        <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="bi bi-check-circle me-2"></i><?php echo $successMessage; ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
      </div>
      
      <!-- Header -->
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>TICKETS MANAGEMENT</h3>
        <a href="ticket.php" class="btn btn-primary">
          <i class="bi bi-plus-lg"></i> Create Ticket
        </a>
      </div>

      <!-- Search Bar -->
      <div class="mb-4">
        <div class="position-relative">
          <i class="bi bi-search position-absolute" style="left: 12px; top: 50%; transform: translateY(-50%); color: #999; pointer-events: none;"></i>
          <input type="text" id="filterSearch" class="form-control form-control-lg ps-5" placeholder="Search ticket number or subject..." style="border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        </div>
      </div>

      <!-- Filter Section -->
      <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 10px;">
        <div class="card-body p-4">
          <!-- Filter Title -->
          <div class="d-flex align-items-center mb-4">
            <i class="bi bi-funnel text-primary me-2" style="font-size: 1.3rem;"></i>
            <h5 class="mb-0 fw-bold text-dark">Advanced Filters</h5>
            <span class="badge bg-light text-muted ms-2" style="font-size: 0.75rem;">Narrow your search</span>
          </div>

          <!-- Active Filter Indicators -->
          <div id="activeFilters" class="d-flex flex-wrap gap-2 mb-3" style="display: none;">
            <!-- Active filter badges will be inserted here -->
          </div>

          <!-- Filter Grid -->
          <div class="row g-3">
            <!-- Status Filter -->
            <div class="col-lg-2 col-md-4 col-sm-6">
              <label class="form-label fw-semibold small">
                <i class="bi bi-circle-fill text-danger me-1"></i>Status
              </label>
              <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="statusMultiDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                  <span id="selectedStatus">All Status</span>
                  <span class="badge bg-primary ms-2" id="statusCount" style="display: none;">0</span>
                </button>
                <div class="dropdown-menu p-3" aria-labelledby="statusMultiDropdown" style="min-width: 320px; max-height: auto;">
                  <input type="text" class="form-control form-control-sm mb-2" placeholder="Search status..." id="statusSearch">
                  <div id="statusSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                  <div class="d-flex gap-1 mb-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('statusOptions')" title="Select all visible options">
                      <i class="bi bi-check-all"></i> All
                    </button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('statusOptions')" title="Clear all selections">
                      <i class="bi bi-x"></i> None
                    </button>
                    <button class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('statusOptions')" title="Invert current selection">
                      <i class="bi bi-arrow-repeat"></i> Invert
                    </button>
                  </div>
                  <div id="statusOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                    <!-- Status checkboxes will be populated here -->
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="statusSelectionCount">0 selected</small>
                    <div class="d-flex gap-1">
                      <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('status')">Apply</button>
                      <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('status')">Cancel</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Site Filter -->
            <div class="col-lg-2 col-md-4 col-sm-6">
              <label class="form-label fw-semibold small">
                <i class="bi bi-building me-1"></i>Site
              </label>
              <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="siteMultiDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                  <span id="selectedSite">All Sites</span>
                  <span class="badge bg-primary ms-2" id="siteCount" style="display: none;">0</span>
                </button>
                <div class="dropdown-menu p-3" aria-labelledby="siteMultiDropdown" style="min-width: 320px; max-height: auto;">
                  <input type="text" class="form-control form-control-sm mb-2" placeholder="Search sites..." id="siteSearch">
                  <div id="siteSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                  <div class="d-flex gap-1 mb-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('siteOptions')" title="Select all visible options">
                      <i class="bi bi-check-all"></i> All
                    </button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('siteOptions')" title="Clear all selections">
                      <i class="bi bi-x"></i> None
                    </button>
                    <button class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('siteOptions')" title="Invert current selection">
                      <i class="bi bi-arrow-repeat"></i> Invert
                    </button>
                  </div>
                  <div id="siteOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                    <!-- Site checkboxes will be populated here -->
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="siteSelectionCount">0 selected</small>
                    <div class="d-flex gap-1">
                      <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('site')">Apply</button>
                      <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('site')">Cancel</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Created By Filter -->
            <div class="col-lg-2 col-md-4 col-sm-6">
              <label class="form-label fw-semibold small">
                <i class="bi bi-person me-1"></i>Created By
              </label>
              <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="createdByMultiDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                  <span id="selectedCreatedBy">All Users</span>
                  <span class="badge bg-primary ms-2" id="createdByCount" style="display: none;">0</span>
                </button>
                <div class="dropdown-menu p-3" aria-labelledby="createdByMultiDropdown" style="min-width: 320px; max-height: auto;">
                  <input type="text" class="form-control form-control-sm mb-2" placeholder="Search users..." id="createdBySearch">
                  <div id="createdBySearchResults" class="text-muted small mb-1" style="display: none;"></div>
                  <div class="d-flex gap-1 mb-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('createdByOptions')" title="Select all visible options">
                      <i class="bi bi-check-all"></i> All
                    </button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('createdByOptions')" title="Clear all selections">
                      <i class="bi bi-x"></i> None
                    </button>
                    <button class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('createdByOptions')" title="Invert current selection">
                      <i class="bi bi-arrow-repeat"></i> Invert
                    </button>
                  </div>
                  <div id="createdByOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                    <!-- Created By checkboxes will be populated here -->
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="createdBySelectionCount">0 selected</small>
                    <div class="d-flex gap-1">
                      <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('createdBy')">Apply</button>
                      <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('createdBy')">Cancel</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ISP Filter -->
            <div class="col-lg-2 col-md-4 col-sm-6">
              <label class="form-label fw-semibold small">
                <i class="bi bi-wifi me-1"></i>ISP
              </label>
              <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="ispMultiDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                  <span id="selectedIsp">All ISPs</span>
                  <span class="badge bg-primary ms-2" id="ispCount" style="display: none;">0</span>
                </button>
                <div class="dropdown-menu p-3" aria-labelledby="ispMultiDropdown" style="min-width: 320px; max-height: auto;">
                  <input type="text" class="form-control form-control-sm mb-2" placeholder="Search ISPs..." id="ispSearch">
                  <div id="ispSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                  <div class="d-flex gap-1 mb-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('ispOptions')" title="Select all visible options">
                      <i class="bi bi-check-all"></i> All
                    </button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('ispOptions')" title="Clear all selections">
                      <i class="bi bi-x"></i> None
                    </button>
                    <button class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('ispOptions')" title="Invert current selection">
                      <i class="bi bi-arrow-repeat"></i> Invert
                    </button>
                  </div>
                  <div id="ispOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                    <!-- ISP checkboxes will be populated here -->
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="ispSelectionCount">0 selected</small>
                    <div class="d-flex gap-1">
                      <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('isp')">Apply</button>
                      <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('isp')">Cancel</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Project Filter -->
            <div class="col-lg-2 col-md-4 col-sm-6">
              <label class="form-label fw-semibold small">
                <i class="bi bi-folder me-1"></i>Project
              </label>
              <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="projectMultiDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                  <span id="selectedProject">All Projects</span>
                  <span class="badge bg-primary ms-2" id="projectCount" style="display: none;">0</span>
                </button>
                <div class="dropdown-menu p-3" aria-labelledby="projectMultiDropdown" style="min-width: 320px; max-height: auto;">
                  <input type="text" class="form-control form-control-sm mb-2" placeholder="Search projects..." id="projectSearch">
                  <div id="projectSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                  <div class="d-flex gap-1 mb-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('projectOptions')" title="Select all visible options">
                      <i class="bi bi-check-all"></i> All
                    </button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('projectOptions')" title="Clear all selections">
                      <i class="bi bi-x"></i> None
                    </button>
                    <button class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('projectOptions')" title="Invert current selection">
                      <i class="bi bi-arrow-repeat"></i> Invert
                    </button>
                  </div>
                  <div id="projectOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                    <!-- Project checkboxes will be populated here -->
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="projectSelectionCount">0 selected</small>
                    <div class="d-flex gap-1">
                      <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('project')">Apply</button>
                      <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('project')">Cancel</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Province Filter -->
            <div class="col-lg-2 col-md-4 col-sm-6">
              <label class="form-label fw-semibold small">
                <i class="bi bi-geo-alt me-1"></i>Province
              </label>
              <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="provinceMultiDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                  <span id="selectedProvince">All Provinces</span>
                  <span class="badge bg-primary ms-2" id="provinceCount" style="display: none;">0</span>
                </button>
                <div class="dropdown-menu p-3" aria-labelledby="provinceMultiDropdown" style="min-width: 320px; max-height: auto;">
                  <input type="text" class="form-control form-control-sm mb-2" placeholder="Search provinces..." id="provinceSearch">
                  <div id="provinceSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                  <div class="d-flex gap-1 mb-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('provinceOptions')" title="Select all visible options">
                      <i class="bi bi-check-all"></i> All
                    </button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('provinceOptions')" title="Clear all selections">
                      <i class="bi bi-x"></i> None
                    </button>
                    <button class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('provinceOptions')" title="Invert current selection">
                      <i class="bi bi-arrow-repeat"></i> Invert
                    </button>
                  </div>
                  <div id="provinceOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                    <!-- Province checkboxes will be populated here -->
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="provinceSelectionCount">0 selected</small>
                    <div class="d-flex gap-1">
                      <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('province')">Apply</button>
                      <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('province')">Cancel</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Municipality Filter -->
            <div class="col-lg-2 col-md-4 col-sm-6">
              <label class="form-label fw-semibold small">
                <i class="bi bi-map me-1"></i>Municipality
              </label>
              <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="municipalityMultiDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                  <span id="selectedMunicipality">All Municipalities</span>
                  <span class="badge bg-primary ms-2" id="municipalityCount" style="display: none;">0</span>
                </button>
                <div class="dropdown-menu p-3" aria-labelledby="municipalityMultiDropdown" style="min-width: 320px; max-height: auto;">
                  <input type="text" class="form-control form-control-sm mb-2" placeholder="Search municipalities..." id="municipalitySearch">
                  <div id="municipalitySearchResults" class="text-muted small mb-1" style="display: none;"></div>
                  <div class="d-flex gap-1 mb-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('municipalityOptions')" title="Select all visible options">
                      <i class="bi bi-check-all"></i> All
                    </button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('municipalityOptions')" title="Clear all selections">
                      <i class="bi bi-x"></i> None
                    </button>
                    <button class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('municipalityOptions')" title="Invert current selection">
                      <i class="bi bi-arrow-repeat"></i> Invert
                    </button>
                  </div>
                  <div id="municipalityOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                    <!-- Municipality checkboxes will be populated here -->
                  </div>
                  <hr class="my-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted" id="municipalitySelectionCount">0 selected</small>
                    <div class="d-flex gap-1">
                      <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('municipality')">Apply</button>
                      <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('municipality')">Cancel</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Date Range Filter -->
            <div class="col-lg-2 col-md-4 col-sm-6">
              <label class="form-label fw-semibold small">
                <i class="bi bi-calendar-range me-1"></i>Date Range
              </label>
              <div class="d-flex gap-2">
                <input type="date" id="filterDateFrom" class="form-control form-control-sm" style="border-radius: 6px; border: 1px solid #d0d5dd;" title="From date">
                <input type="date" id="filterDateTo" class="form-control form-control-sm" style="border-radius: 6px; border: 1px solid #d0d5dd;" title="To date">
              </div>
            </div>

            <!-- Duration Filter -->
            <div class="col-lg-2 col-md-4 col-sm-6">
              <label class="form-label fw-semibold small">
                <i class="bi bi-hourglass-split me-1"></i>Duration (days)
              </label>
              <div class="d-flex gap-2">
                <input type="number" id="filterDurationMin" class="form-control form-control-sm" style="border-radius: 6px; border: 1px solid #d0d5dd;" placeholder="Min" title="Minimum duration in days" min="0" step="0.5">
                <input type="number" id="filterDurationMax" class="form-control form-control-sm" style="border-radius: 6px; border: 1px solid #d0d5dd;" placeholder="Max" title="Maximum duration in days" min="0" step="0.5">
              </div>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="d-flex gap-2 mt-4 justify-content-end flex-wrap">
            <button id="applyFilters" class="btn btn-primary px-4" style="border-radius: 6px; font-weight: 500; box-shadow: 0 2px 4px rgba(13, 110, 253, 0.25);" title="Apply filters">
              <i class="bi bi-check-circle-fill me-2"></i> Apply Filters
            </button>
            <button id="resetFilters" class="btn btn-outline-secondary px-4" style="border-radius: 6px; font-weight: 500; border: 1.5px solid #d0d5dd;" title="Clear filters">
              <i class="bi bi-arrow-clockwise me-2"></i> Reset
            </button>
          </div>
        </div>
      </div>

      <!-- Tickets Table -->
      <div class="table-responsive">
        <table class="table table-bordered table-hover shadow-sm">
          <thead class="table-dark text-center">
            <tr>
              <th class="sortable" data-sort="ticket_number">Ticket # <span class="sort-indicator"></span></th>
              <th class="sortable" data-sort="site_name">Site <span class="sort-indicator"></span></th>
              <th class="sortable" data-sort="isp">ISP <span class="sort-indicator"></span></th>
              <th class="sortable" data-sort="subject">Subject <span class="sort-indicator"></span></th>
              <th class="sortable" data-sort="status">Status <span class="sort-indicator"></span></th>
              <th class="sortable" data-sort="priority">Priority <span class="sort-indicator"></span></th>
              <th class="sortable" data-sort="created_at">Created <span class="sort-indicator"></span></th>
              <th class="sortable" data-sort="created_by_name">Created By <span class="sort-indicator"></span></th>
              <th class="sortable" data-sort="duration">Duration (minutes) <span class="sort-indicator"></span></th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="ticketsTable">
            <tr><td colspan="9" class="text-center py-4 text-muted"><i class="bi bi-hourglass-bottom"></i> Loading tickets...</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div>
          <label for="perPage" class="me-2">Records per page:</label>
          <select id="perPage" class="form-select d-inline-block" style="width: auto;">
            <option>10</option>
            <option selected>25</option>
            <option>50</option>
            <option>100</option>
          </select>
        </div>
        <div class="text-muted small">
          Showing <span id="recordStart">1</span> to <span id="recordEnd">25</span> of <span id="recordTotal">0</span> tickets
        </div>
        <div>
          <button id="prevPage" class="btn btn-sm btn-outline-secondary" title="Previous page">Previous</button>
          <span class="mx-2">Page <span id="currentPageNum">1</span> of <span id="totalPages">1</span></span>
          <button id="nextPage" class="btn btn-sm btn-outline-secondary" title="Next page">Next</button>
        </div>
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  // Define state variables first
  const ticketsState = { page: 1, perPage: 25, pages: 1, sortBy: 'created_at', sortOrder: 'DESC' };
  // filter state for multi-select components
  const filtersState = { isps: [], statuses: [], sites: [], createdBy: [], projects: [], provinces: [], municipalities: [] };

  // Initialize on page load
  document.addEventListener('DOMContentLoaded', function() {
      initMultiSelect();

      // initial load
      loadFilters().then(()=>loadTickets()).then(() => {
          // Highlight updated ticket if redirected from edit_ticket.php
          const urlParams = new URLSearchParams(window.location.search);
          const updatedTicketId = urlParams.get('updated');
          if (updatedTicketId) {
              setTimeout(() => {
                  const ticketRow = document.querySelector(`tr[data-ticket-id="${updatedTicketId}"]`);
                  if (ticketRow) {
                      ticketRow.classList.add('table-success');
                      ticketRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                      // Remove highlight after 3 seconds
                      setTimeout(() => {
                          ticketRow.classList.remove('table-success');
                      }, 3000);
                  }
              }, 300);
          }
      });
  });

  function initMultiSelect() {
    // Initialize search and event delegation for all filters
    const containers = ['statusOptions', 'siteOptions', 'createdByOptions', 'ispOptions', 'projectOptions', 'provinceOptions', 'municipalityOptions'];
    containers.forEach(containerId => {
      updateSelectionCount(containerId);
    });
  }

  function setupSearch(searchId, containerId) {
    const searchInput = document.getElementById(searchId);
    const container = document.getElementById(containerId);
    const resultsDivId = containerId.replace('Options', 'SearchResults');
    const resultsDiv = document.getElementById(resultsDivId);
    let searchTimeout;

    if (!searchInput) return;

    searchInput.addEventListener('input', function(e) {
      clearTimeout(searchTimeout);
      const query = e.target.value.toLowerCase().trim();

      searchTimeout = setTimeout(() => {
        const visibleCount = filterOptions(container, query);
        updateSearchResults(resultsDiv, container, query, visibleCount);
      }, 100);
    });
  }

  function filterOptions(container, query) {
    const checks = container.querySelectorAll('.form-check');
    let visibleCount = 0;

    checks.forEach(check => {
      const label = check.querySelector('.form-check-label');
      const text = label.textContent.toLowerCase();
      const matches = query === '' || text.includes(query);

      check.style.display = matches ? '' : 'none';
      if (matches) visibleCount++;
    });

    return visibleCount;
  }

  function updateSearchResults(resultsDiv, container, query, visibleCount) {
    const totalCount = container.querySelectorAll('.form-check').length;

    if (resultsDiv && query) {
      resultsDiv.textContent = `Showing ${visibleCount} of ${totalCount} options`;
      resultsDiv.style.display = visibleCount < totalCount ? '' : 'none';
    } else if (resultsDiv) {
      resultsDiv.style.display = 'none';
    }
  }

  function applyMultiSelect(type) {
    const container = document.getElementById(type + 'Options');
    if (!container) return;

    const selected = [];
    const checkboxes = container.querySelectorAll('input[type="checkbox"]:checked');

    checkboxes.forEach(cb => selected.push(cb.value));

    updateSelectedDisplay(type, selected);
    updateActiveFilters();

    // Close dropdown - use multiple methods for reliability
    closeDropdownByType(type);

    // Additional force close
    setTimeout(() => closeDropdownByType(type), 50);

    // Apply filters
    ticketsState.page = 1;
    loadTickets();
  }

  function closeDropdownByType(type) {
    const optionsContainer = document.getElementById(type + 'Options');
    if (!optionsContainer) return;

    const dropdownDiv = optionsContainer.closest('.dropdown');
    if (!dropdownDiv) return;

    const toggleButton = dropdownDiv.querySelector('.dropdown-toggle');
    const dropdownMenu = dropdownDiv.querySelector('.dropdown-menu');

    // Bootstrap API if available
    try {
      const bsDropdown = bootstrap.Dropdown.getInstance(toggleButton);
      if (bsDropdown) bsDropdown.hide();
    } catch (e) {
      // fallback
    }

    if (dropdownMenu) {
      dropdownMenu.classList.remove('show');
    }
    if (toggleButton) {
      toggleButton.setAttribute('aria-expanded', 'false');
      toggleButton.classList.remove('show');
    }

    setTimeout(() => {
      if (dropdownMenu && dropdownMenu.classList.contains('show')) {
        dropdownMenu.classList.remove('show');
      }
      if (toggleButton) {
        toggleButton.setAttribute('aria-expanded', 'false');
        toggleButton.classList.remove('show');
      }
    }, 10);
  }

  function clearMultiSelect(type) {
    // Map filter types to their container IDs and normalized type names
    const typeMap = {
      'createdBy': { containerId: 'createdByOptions', displayType: 'createdBy' },
      'status': { containerId: 'statusOptions', displayType: 'status' },
      'site': { containerId: 'siteOptions', displayType: 'site' },
      'isp': { containerId: 'ispOptions', displayType: 'isp' },
      'province': { containerId: 'provinceOptions', displayType: 'province' },
      'municipality': { containerId: 'municipalityOptions', displayType: 'municipality' },
      'project': { containerId: 'projectOptions', displayType: 'project' }
    };

    const mapping = typeMap[type];
    if (!mapping) return;

    const containerId = mapping.containerId;
    const displayType = mapping.displayType;

    const container = document.getElementById(containerId);
    if (!container) return;

    const checkboxes = container.querySelectorAll('input[type="checkbox"]');

    checkboxes.forEach(cb => cb.checked = false);

    updateSelectedDisplay(displayType, []);
    updateActiveFilters();
    updateSelectionCount(containerId);

    // Close dropdown - use multiple methods for reliability
    closeDropdownByType(type);

    // Additional force close
    setTimeout(() => closeDropdownByType(type), 50);
  }

  function selectAllVisible(containerId) {
    const container = document.getElementById(containerId);
    const visibleChecks = container.querySelectorAll('.form-check:not([style*="display: none"]) input[type="checkbox"]');
    visibleChecks.forEach(cb => cb.checked = true);
    updateSelectionCount(containerId);
  }

  function selectNone(containerId) {
    const container = document.getElementById(containerId);
    const allChecks = container.querySelectorAll('input[type="checkbox"]');
    allChecks.forEach(cb => cb.checked = false);
    updateSelectionCount(containerId);
  }

  function invertSelection(containerId) {
    const container = document.getElementById(containerId);
    const allChecks = container.querySelectorAll('input[type="checkbox"]');
    allChecks.forEach(cb => cb.checked = !cb.checked);
    updateSelectionCount(containerId);
  }

  function setupEventDelegation(containerId) {
    const container = document.getElementById(containerId);

    container.addEventListener('change', function(e) {
      if (e.target.type === 'checkbox') {
        updateSelectionCount(containerId);
      }
    });

    container.addEventListener('click', function(e) {
      if (e.target.classList.contains('form-check-label')) {
        const checkbox = e.target.previousElementSibling;
        if (checkbox && checkbox.type === 'checkbox') {
          checkbox.checked = !checkbox.checked;
          updateSelectionCount(containerId);
        }
      }
    });
  }

  function updateSelectionCount(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const checkedCount = container.querySelectorAll('input[type="checkbox"]:checked').length;
    const totalCount = container.querySelectorAll('input[type="checkbox"]').length;

    // Update the selection count display
    const countDisplayId = containerId.replace('Options', 'SelectionCount');
    const countDisplay = document.getElementById(countDisplayId);
    if (countDisplay) {
      countDisplay.textContent = `${checkedCount} of ${totalCount} selected`;
    }
  }

  function updateSelectedDisplay(type, selected) {
    const displaySpan = document.getElementById('selected' + type.charAt(0).toUpperCase() + type.slice(1));
    const countBadge = document.getElementById(type + 'Count');

    if (!displaySpan) return;

    if (selected.length === 0) {
      const typeLabel = type === 'createdBy' ? 'Users' : type.charAt(0).toUpperCase() + type.slice(1) + 's';
      displaySpan.textContent = 'All ' + typeLabel;
      if (countBadge) countBadge.style.display = 'none';
    } else {
      displaySpan.textContent = selected.length + ' selected';
      if (countBadge) {
        countBadge.textContent = selected.length;
        countBadge.style.display = '';
      }
    }
  }

  function getFilters() {
    const filters = {};

    // Status
    const statusSelected = getSelectedValues('statusOptions');
    if (statusSelected.length > 0) filters.status = statusSelected;

    // Site
    const siteSelected = getSelectedValues('siteOptions');
    if (siteSelected.length > 0) filters.site_id = siteSelected;

    // Created By
    const createdBySelected = getSelectedValues('createdByOptions');
    if (createdBySelected.length > 0) filters.created_by = createdBySelected;

    // ISP
    const ispSelected = getSelectedValues('ispOptions');
    if (ispSelected.length > 0) filters.isp = ispSelected;

    // Province
    const provinceSelected = getSelectedValues('provinceOptions');
    if (provinceSelected.length > 0) filters.province = provinceSelected;

    // Municipality
    const municipalitySelected = getSelectedValues('municipalityOptions');
    if (municipalitySelected.length > 0) filters.municipality = municipalitySelected;

    // Project
    const projectSelected = getSelectedValues('projectOptions');
    if (projectSelected.length > 0) filters.project = projectSelected;

    // Dates
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    if (dateFrom) filters.date_from = dateFrom;
    if (dateTo) filters.date_to = dateTo;

    // Duration (convert days to minutes: 1 day = 1440 minutes)
    const durationMin = document.getElementById('filterDurationMin').value;
    const durationMax = document.getElementById('filterDurationMax').value;
    if (durationMin) filters.duration_min = parseFloat(durationMin) * 1440; // Convert days to minutes
    if (durationMax) filters.duration_max = parseFloat(durationMax) * 1440; // Convert days to minutes

    return filters;
  }

  function getSelectedValues(containerId) {
    const container = document.getElementById(containerId);
    const checkboxes = container.querySelectorAll('input[type="checkbox"]:checked');
    return Array.from(checkboxes).map(cb => cb.value);
  }

  function updateActiveFilters() {
    const filters = getFilters();
    const activeContainer = document.getElementById('activeFilters');
    activeContainer.innerHTML = '';

    let hasFilters = false;

    // Status
    if (filters.status && filters.status.length > 0) {
      activeContainer.innerHTML += `
        <span class="badge bg-primary d-flex align-items-center gap-1">
          Status: ${filters.status.join(', ')}
          <button class="btn-close btn-close-white" onclick="removeFilter('status')" style="font-size: 0.6rem;"></button>
        </span>
      `;
      hasFilters = true;
    }

    // Site
    if (filters.site_id && filters.site_id.length > 0) {
      activeContainer.innerHTML += `
        <span class="badge bg-info d-flex align-items-center gap-1">
          Sites: ${filters.site_id.length} selected
          <button class="btn-close btn-close-white" onclick="removeFilter('site')" style="font-size: 0.6rem;"></button>
        </span>
      `;
      hasFilters = true;
    }

    // Created By
    if (filters.created_by && filters.created_by.length > 0) {
      activeContainer.innerHTML += `
        <span class="badge bg-success d-flex align-items-center gap-1">
          Users: ${filters.created_by.length} selected
          <button class="btn-close btn-close-white" onclick="removeFilter('created_by')" style="font-size: 0.6rem;"></button>
        </span>
      `;
      hasFilters = true;
    }

    // ISP
    if (filters.isp && filters.isp.length > 0) {
      activeContainer.innerHTML += `
        <span class="badge bg-warning d-flex align-items-center gap-1">
          ISP: ${filters.isp.join(', ')}
          <button class="btn-close btn-close-white" onclick="removeFilter('isp')" style="font-size: 0.6rem;"></button>
        </span>
      `;
      hasFilters = true;
    }

    // Province
    if (filters.province && filters.province.length > 0) {
      activeContainer.innerHTML += `
        <span class="badge bg-secondary d-flex align-items-center gap-1">
          Province: ${filters.province.join(', ')}
          <button class="btn-close btn-close-white" onclick="removeFilter('province')" style="font-size: 0.6rem;"></button>
        </span>
      `;
      hasFilters = true;
    }

    // Municipality
    if (filters.municipality && filters.municipality.length > 0) {
      activeContainer.innerHTML += `
        <span class="badge bg-dark d-flex align-items-center gap-1">
          Municipality: ${filters.municipality.join(', ')}
          <button class="btn-close btn-close-white" onclick="removeFilter('municipality')" style="font-size: 0.6rem;"></button>
        </span>
      `;
      hasFilters = true;
    }

    // Project
    if (filters.project && filters.project.length > 0) {
      activeContainer.innerHTML += `
        <span class="badge bg-light text-dark d-flex align-items-center gap-1">
          Project: ${filters.project.join(', ')}
          <button class="btn-close" onclick="removeFilter('project')" style="font-size: 0.6rem;"></button>
        </span>
      `;
      hasFilters = true;
    }

    // Date range
    if (filters.date_from || filters.date_to) {
      let dateText = '';
      if (filters.date_from && filters.date_to) {
        dateText = `${filters.date_from} to ${filters.date_to}`;
      } else if (filters.date_from) {
        dateText = `From ${filters.date_from}`;
      } else if (filters.date_to) {
        dateText = `To ${filters.date_to}`;
      }
      activeContainer.innerHTML += `
        <span class="badge bg-danger d-flex align-items-center gap-1">
          Date: ${dateText}
          <button class="btn-close btn-close-white" onclick="removeFilter('date')" style="font-size: 0.6rem;"></button>
        </span>
      `;
      hasFilters = true;
    }

    // Duration (display in days, not minutes)
    if (filters.duration_min || filters.duration_max) {
      let durationText = '';
      const minDays = filters.duration_min ? (filters.duration_min / 1440).toFixed(1) : null;
      const maxDays = filters.duration_max ? (filters.duration_max / 1440).toFixed(1) : null;
      if (minDays && maxDays) {
        durationText = `${minDays}-${maxDays} days`;
      } else if (minDays) {
        durationText = `Min ${minDays} days`;
      } else if (maxDays) {
        durationText = `Max ${maxDays} days`;
      }
      activeContainer.innerHTML += `
        <span class="badge bg-info d-flex align-items-center gap-1">
          Duration: ${durationText}
          <button class="btn-close btn-close-white" onclick="removeFilter('duration')" style="font-size: 0.6rem;"></button>
        </span>
      `;
      hasFilters = true;
    }

    activeContainer.style.display = hasFilters ? '' : 'none';
  }

  function removeFilter(type) {
    if (type === 'status') {
      selectNone('statusOptions');
      applyMultiSelect('status');
    } else if (type === 'site') {
      selectNone('siteOptions');
      applyMultiSelect('site');
    } else if (type === 'created_by') {
      selectNone('createdByOptions');
      applyMultiSelect('createdBy');
    } else if (type === 'isp') {
      selectNone('ispOptions');
      applyMultiSelect('isp');
    } else if (type === 'province') {
      selectNone('provinceOptions');
      applyMultiSelect('province');
    } else if (type === 'municipality') {
      selectNone('municipalityOptions');
      applyMultiSelect('municipality');
    } else if (type === 'project') {
      selectNone('projectOptions');
      applyMultiSelect('project');
    } else if (type === 'date') {
      document.getElementById('filterDateFrom').value = '';
      document.getElementById('filterDateTo').value = '';
      ticketsState.page = 1;
      loadTickets();
    } else if (type === 'duration') {
      document.getElementById('filterDurationMin').value = '';
      document.getElementById('filterDurationMax').value = '';
      ticketsState.page = 1;
      loadTickets();
    }
  }

  function selectAllVisible(containerId) {
    const container = document.getElementById(containerId);
    const visibleChecks = container.querySelectorAll('.form-check:not([style*="display: none"]) input[type="checkbox"]');
    visibleChecks.forEach(cb => cb.checked = true);
    updateSelectionCount(containerId);
  }

  function selectNone(containerId) {
    const container = document.getElementById(containerId);
    const allChecks = container.querySelectorAll('input[type="checkbox"]');
    allChecks.forEach(cb => cb.checked = false);
    updateSelectionCount(containerId);
  }

  function invertSelection(containerId) {
    const container = document.getElementById(containerId);
    const allChecks = container.querySelectorAll('input[type="checkbox"]');
    allChecks.forEach(cb => cb.checked = !cb.checked);
    updateSelectionCount(containerId);
  }

  function setupEventDelegation(containerId) {
    const container = document.getElementById(containerId);

    container.addEventListener('change', function(e) {
        if (e.target.type === 'checkbox') {
            updateSelectionCount(containerId);
        }
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('form-check-label')) {
            const checkbox = e.target.previousElementSibling;
            if (checkbox && checkbox.type === 'checkbox') {
                checkbox.checked = !checkbox.checked;
                updateSelectionCount(containerId);
            }
        }
    });
  }

  function updateSelectionCount(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const checkedCount = container.querySelectorAll('input[type="checkbox"]:checked').length;
    const totalCount = container.querySelectorAll('input[type="checkbox"]').length;

    // Update the selection count display
    const countDisplayId = containerId.replace('Options', 'SelectionCount');
    const countDisplay = document.getElementById(countDisplayId);
    if (countDisplay) {
        countDisplay.textContent = `${checkedCount} of ${totalCount} selected`;
    }
  }

  function updateSelectedDisplay(type, selected) {
    const displaySpan = document.getElementById('selected' + type.charAt(0).toUpperCase() + type.slice(1));
    const countBadge = document.getElementById(type + 'Count');

    if (!displaySpan) return;

    if (selected.length === 0) {
        const typeLabel = type === 'createdBy' ? 'Users' : type.charAt(0).toUpperCase() + type.slice(1) + 's';
        displaySpan.textContent = 'All ' + typeLabel;
        if (countBadge) countBadge.style.display = 'none';
    } else {
        displaySpan.textContent = selected.length + ' selected';
        if (countBadge) {
            countBadge.textContent = selected.length;
            countBadge.style.display = '';
        }
    }
  }
  function escapeHtml(s) {
    s = String(s);
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function formatDuration(minutes) {
    if (minutes < 0) minutes = 0;
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const mins = minutes % 60;
    
    let parts = [];
    if (days > 0) parts.push(days + ' Day' + (days > 1 ? 's' : ''));
    if (hours > 0) parts.push(hours + ' Hr' + (hours > 1 ? 's' : ''));
    if (mins > 0 || parts.length === 0) parts.push(mins + ' Min' + (mins > 1 ? 's' : ''));
    
    return parts.join(' ');
  }

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

  async function loadFilters(){
    const fd = new FormData();
    fd.append('action','get_filters');
    fd.append('csrf_token', '<?php echo htmlspecialchars(generateCSRFToken()); ?>');
    const res = await fetch('viewtickets.php', { method: 'POST', body: fd });
    const data = await res.json();

    // Populate all filters using dashboard.php pattern
    populateStatusFilter(data.statuses);
    populateSiteFilter(data.sites);
    populateCreatedByFilter(data.created_by);
    populateISPFilter(data.isps);
    populateProjectFilter(data.projects);
    populateProvinceFilter(data.provinces);
    populateMunicipalityFilter(data.municipalities);
  }

  function populateStatusFilter(statuses) {
    const container = document.getElementById('statusOptions');
    container.innerHTML = '';
    statuses.forEach(status => {
      container.innerHTML += `
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="status_${status}" value="${status}">
          <label class="form-check-label" for="status_${status}">
            ${escapeHtml(status)}
          </label>
        </div>
      `;
    });
    setupSearch('statusSearch', 'statusOptions');
    setupEventDelegation('statusOptions');
  }

  function populateSiteFilter(sites) {
    const container = document.getElementById('siteOptions');
    container.innerHTML = '';
    sites.forEach(site => {
      container.innerHTML += `
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="site_${site.id}" value="${site.id}">
          <label class="form-check-label" for="site_${site.id}">
            ${escapeHtml(site.site_name)}
          </label>
        </div>
      `;
    });
    setupSearch('siteSearch', 'siteOptions');
    setupEventDelegation('siteOptions');
  }

  function populateCreatedByFilter(personnel) {
    const container = document.getElementById('createdByOptions');
    container.innerHTML = '';
    personnel.forEach(person => {
      container.innerHTML += `
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="created_by_${person.id}" value="${person.id}">
          <label class="form-check-label" for="created_by_${person.id}">
            ${escapeHtml(person.fullname)}
          </label>
        </div>
      `;
    });
    setupSearch('createdBySearch', 'createdByOptions');
    setupEventDelegation('createdByOptions');
  }

  function populateISPFilter(ispList) {
    const container = document.getElementById('ispOptions');
    container.innerHTML = '';
    ispList.forEach(isp => {
      container.innerHTML += `
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="isp_${isp.replace(/\s+/g, '_')}" value="${isp}">
          <label class="form-check-label" for="isp_${isp.replace(/\s+/g, '_')}">
            ${escapeHtml(isp)}
          </label>
        </div>
      `;
    });
    setupSearch('ispSearch', 'ispOptions');
    setupEventDelegation('ispOptions');
  }

  function populateProjectFilter(projects) {
    const container = document.getElementById('projectOptions');
    container.innerHTML = '';
    projects.forEach(project => {
      container.innerHTML += `
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="project_${project.replace(/\s+/g, '_')}" value="${project}">
          <label class="form-check-label" for="project_${project.replace(/\s+/g, '_')}">
            ${escapeHtml(project)}
          </label>
        </div>
      `;
    });
    setupSearch('projectSearch', 'projectOptions');
    setupEventDelegation('projectOptions');
  }

  function populateProvinceFilter(provinces) {
    const container = document.getElementById('provinceOptions');
    container.innerHTML = '';
    provinces.forEach(province => {
      container.innerHTML += `
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="province_${province.replace(/\s+/g, '_')}" value="${province}">
          <label class="form-check-label" for="province_${province.replace(/\s+/g, '_')}">
            ${escapeHtml(province)}
          </label>
        </div>
      `;
    });
    setupSearch('provinceSearch', 'provinceOptions');
    setupEventDelegation('provinceOptions');
  }

  function populateMunicipalityFilter(municipalities) {
    const container = document.getElementById('municipalityOptions');
    container.innerHTML = '';
    municipalities.forEach(municipality => {
      container.innerHTML += `
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="municipality_${municipality.replace(/\s+/g, '_')}" value="${municipality}">
          <label class="form-check-label" for="municipality_${municipality.replace(/\s+/g, '_')}">
            ${escapeHtml(municipality)}
          </label>
        </div>
      `;
    });
    setupSearch('municipalitySearch', 'municipalityOptions');
    setupEventDelegation('municipalityOptions');
  }

  async function loadTickets(){
    const fd = new FormData();
    fd.append('action','get_tickets');
    fd.append('csrf_token', '<?php echo htmlspecialchars(generateCSRFToken()); ?>');
    fd.append('page', ticketsState.page);
    fd.append('per_page', document.getElementById('perPage').value);
    fd.append('search', document.getElementById('filterSearch').value.trim());

    // Get current filters from the enhanced filter system
    const currentFilters = getFilters();

    // Multi-select filters
    if (currentFilters.status) currentFilters.status.forEach(v => fd.append('status[]', v));
    if (currentFilters.site_id) currentFilters.site_id.forEach(v => fd.append('site_id[]', v));
    if (currentFilters.created_by) currentFilters.created_by.forEach(v => fd.append('created_by[]', v));
    if (currentFilters.isp) currentFilters.isp.forEach(v => fd.append('isp[]', v));
    if (currentFilters.project) currentFilters.project.forEach(v => fd.append('project[]', v));
    if (currentFilters.province) currentFilters.province.forEach(v => fd.append('province[]', v));
    if (currentFilters.municipality) currentFilters.municipality.forEach(v => fd.append('municipality[]', v));

    // Date and duration
    if (currentFilters.date_from) fd.append('date_from', currentFilters.date_from);
    if (currentFilters.date_to) fd.append('date_to', currentFilters.date_to);
    if (currentFilters.duration_min !== undefined) fd.append('duration_min', currentFilters.duration_min);
    if (currentFilters.duration_max !== undefined) fd.append('duration_max', currentFilters.duration_max);

    // Sorting
    fd.append('sort_by', ticketsState.sortBy);
    fd.append('sort_order', ticketsState.sortOrder);

    const resp = await fetch('viewtickets.php', { method: 'POST', body: fd });
    const json = await resp.json();
    if (!json.success) { document.getElementById('ticketsTable').innerHTML = `<tr><td colspan="9" class="text-danger">${escapeHtml(json.message||'Error')}</td></tr>`; return; }
    ticketsState.pages = json.pages;
    ticketsState.perPage = json.per_page;
    // Update sorting state from response
    ticketsState.sortBy = json.sort_by;
    ticketsState.sortOrder = json.sort_order;
    
    // Update pagination display
    document.getElementById('currentPageNum').textContent = json.page;
    document.getElementById('totalPages').textContent = json.pages;
    
    const offset = (json.page - 1) * json.per_page;
    const recordStart = json.total === 0 ? 0 : offset + 1;
    const recordEnd = Math.min(offset + json.per_page, json.total);
    document.getElementById('recordStart').textContent = recordStart;
    document.getElementById('recordEnd').textContent = recordEnd;
    document.getElementById('recordTotal').textContent = json.total;
    
    // Update button states
    document.getElementById('prevPage').disabled = json.page <= 1;
    document.getElementById('nextPage').disabled = json.page >= json.pages;
    
    renderTable(json.tickets);
    updateSortIndicators();
  }

  function updateSortIndicators() {
    // Remove all sorting classes
    document.querySelectorAll('.sortable').forEach(th => {
      th.classList.remove('sort-asc', 'sort-desc', 'sort-none');
    });

    // Add sorting class to current sort column
    const currentSortTh = document.querySelector(`.sortable[data-sort="${ticketsState.sortBy}"]`);
    if (currentSortTh) {
      const sortClass = ticketsState.sortOrder === 'ASC' ? 'sort-asc' : 'sort-desc';
      currentSortTh.classList.add(sortClass);
    }
  }

  function handleSort(column) {
    if (ticketsState.sortBy === column) {
      // Toggle sort order if same column
      ticketsState.sortOrder = ticketsState.sortOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
      // New column, default to DESC
      ticketsState.sortBy = column;
      ticketsState.sortOrder = 'DESC';
    }
    ticketsState.page = 1; // Reset to first page when sorting
    loadTickets();
  }

  function renderTable(rows){
    const tb = document.getElementById('ticketsTable');
    if(!rows.length){ tb.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-muted"><i class="bi bi-inbox"></i> No tickets found</td></tr>'; return; }
    tb.innerHTML = rows.map(r=>{
      const statusBg = statusClass(r.status);
      const priorityBg = priorityClass(r.priority);
      return `<tr class="align-middle" data-ticket-id="${r.id}">
        <td><a href="detail_ticket.php?id=${r.id}" class="text-primary fw-bold text-decoration-none">${escapeHtml(r.ticket_number)}</a></td>
        <td>${escapeHtml(r.site_name || 'N/A')}</td>
        <td>${escapeHtml(r.isp || 'N/A')}</td>
        <td>${escapeHtml(r.subject)}</td>
        <td><span class="badge ${statusBg}">${escapeHtml(r.status)}</span></td>
        <td><span class="badge ${priorityBg}">${escapeHtml(r.priority || 'medium')}</span></td>
        <td><small class="text-muted">${escapeHtml(r.created_at_fmt)}</small></td>
        <td><small class="text-muted">${escapeHtml(r.created_by_name || 'N/A')}</small></td>
        <td class="text-center"><span class="badge bg-light text-dark">${formatDuration(r.duration_minutes)}</span></td>
        <td class="text-center">
          <a class="btn btn-sm btn-info text-white" href="detail_ticket.php?id=${r.id}" title="View details"><i class="bi bi-eye"></i></a>
          ${r.status !== 'CLOSED' ? `<a class="btn btn-sm btn-warning" href="edit_ticket.php?id=${r.id}" title="Edit ticket"><i class="bi bi-pencil"></i></a>` : ''}
        </td>
      </tr>`;
    }).join('');
  }

  function statusClass(s){
    if(!s) return 'bg-secondary';
    s = s.toUpperCase();
    if(s==='OPEN') return 'bg-danger';
    if(s==='IN_PROGRESS') return 'bg-warning text-dark';
    if(s==='RESOLVED') return 'bg-info';
    if(s==='CLOSED') return 'bg-success';
    return 'bg-secondary';
  }

  function priorityClass(p){
    if(!p) return 'bg-secondary';
    p = p.toLowerCase();
    if(p==='critical') return 'bg-danger';
    if(p==='high') return 'bg-warning text-dark';
    if(p==='medium') return 'bg-info';
    if(p==='low') return 'bg-secondary';
    return 'bg-secondary';
  }

  document.getElementById('applyFilters').addEventListener('click', ()=>{ ticketsState.page = 1; loadTickets(); });
  document.getElementById('resetFilters').addEventListener('click', ()=>{
    // Clear all filter selections
    const containers = ['statusOptions', 'siteOptions', 'createdByOptions', 'ispOptions', 'projectOptions', 'provinceOptions', 'municipalityOptions'];
    containers.forEach(containerId => {
      selectNone(containerId);
      const type = containerId.replace('Options', '');
      const displayType = type === 'createdBy' ? 'createdBy' : type;
      updateSelectedDisplay(displayType, []);
    });

    // Clear date and duration filters
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterDurationMin').value = '';
    document.getElementById('filterDurationMax').value = '';
    document.getElementById('filterSearch').value = '';

    // Reset sorting to default
    ticketsState.sortBy = 'created_at';
    ticketsState.sortOrder = 'DESC';

    // Update active filters display
    updateActiveFilters();

    // Reload tickets
    ticketsState.page = 1;
    loadTickets();
  });
  document.getElementById('prevPage').addEventListener('click', ()=>{ if(ticketsState.page>1){ ticketsState.page--; loadTickets(); }});
  document.getElementById('nextPage').addEventListener('click', ()=>{ if(ticketsState.page < ticketsState.pages){ ticketsState.page++; loadTickets(); }});
  document.getElementById('perPage').addEventListener('change', ()=>{ ticketsState.page=1; loadTickets(); });

  // Add event listeners for date and duration filters to update active filters display
  ['filterDateFrom', 'filterDateTo', 'filterDurationMin', 'filterDurationMax'].forEach(id => {
    document.getElementById(id).addEventListener('change', updateActiveFilters);
  });

  // Add sorting event listeners
  document.querySelectorAll('.sortable').forEach(th => {
    th.addEventListener('click', function() {
      const column = this.getAttribute('data-sort');
      handleSort(column);
    });
  });

  </script>
<?php require __DIR__ . '/../includes/footer.php'; ?>