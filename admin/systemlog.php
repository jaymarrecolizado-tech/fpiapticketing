<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Logger.php';

// Security: Require admin authentication for ALL access (including AJAX)
requireAdmin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper function to build filter conditions and parameters
function buildLogFilters($input, $isExport = false) {
    $params = [];
    $conditions = [];

    if (!empty($input['search'])) {
        $conditions[] = "(sl.description LIKE ? OR sl.action LIKE ? OR JSON_EXTRACT(sl.details, '$.ticket_number') LIKE ?)";
        $params[] = "%{$input['search']}%";
        $params[] = "%{$input['search']}%";
        $params[] = "%{$input['search']}%";
    }

    if (!empty($input['user_id'])) {
        $conditions[] = "sl.user_id = ?";
        $params[] = (int)$input['user_id'];
    }

    if (!empty($input['action_filter'])) {
        $conditions[] = "sl.action = ?";
        $params[] = $input['action_filter'];
    }

    if (!empty($input['severity'])) {
        $conditions[] = "sl.severity = ?";
        $params[] = $input['severity'];
    }

    if (!empty($input['date_from'])) {
        if ($isExport) {
            $conditions[] = "sl.timestamp >= ?";
            $params[] = $input['date_from'] . ' 00:00:00';
        } else {
            $conditions[] = "DATE(sl.timestamp) >= ?";
            $params[] = $input['date_from'];
        }
    }

    if (!empty($input['date_to'])) {
        if ($isExport) {
            $conditions[] = "sl.timestamp <= ?";
            $params[] = $input['date_to'] . ' 23:59:59';
        } else {
            $conditions[] = "DATE(sl.timestamp) <= ?";
            $params[] = $input['date_to'];
        }
    }

    return [
        'where' => !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '',
        'params' => $params
    ];
}

// Handle AJAX requests before authentication check
if (!empty($action)) {
    // Get audit logs with filtering
    if ($action === 'get_logs') {
        // ===== CSRF Validation =====
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrf_token)) {
            echo json_encode(['success' => false, 'message' => 'Invalid security token']);
            exit;
        }

        try {
            $page = max(1, (int)($_POST['page'] ?? 1));
            $perPage = (int)($_POST['per_page'] ?? 50);

            // Build query with filters using shared function
            $filters = buildLogFilters($_POST);
            $where = $filters['where'];
            $params = $filters['params'];

            // Get total count
            $countSql = "SELECT COUNT(*) FROM system_logs sl $where";
            $stmt = $pdo->prepare($countSql);
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();

            // Get logs with user/personnel info
            $offset = ($page - 1) * $perPage;
            $sql = "
                SELECT
                    sl.*,
                    COALESCE(p.fullname, 'System') as username,
                    COALESCE(p.fullname, 'Unknown') as fullname,
                    COALESCE(p.gmail, '') as email
                FROM system_logs sl
                LEFT JOIN users u ON sl.user_id = u.id
                LEFT JOIN personnels p ON sl.personnel_id = p.id
                $where
                ORDER BY sl.timestamp DESC
                LIMIT $perPage OFFSET $offset
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pages = ceil($total / $perPage);
            $response = [
                'success' => true,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
                'logs' => $logs
            ];
            echo json_encode($response);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Get filter options
    if ($action === 'get_filters') {
        // ===== CSRF Validation =====
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrf_token)) {
            echo json_encode(['success' => false, 'message' => 'Invalid security token']);
            exit;
        }

        try {
            // Get unique actions
            $actions = $pdo->query("SELECT DISTINCT action FROM system_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

            // Get users for filter dropdown
            $users = $pdo->query("
                SELECT DISTINCT u.id, p.fullname
                FROM system_logs sl
                JOIN users u ON sl.user_id = u.id
                JOIN personnels p ON u.personnel_id = p.id
                WHERE sl.user_id IS NOT NULL
                ORDER BY p.fullname
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'actions' => $actions,
                'users' => $users
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Export logs functionality
    if (isset($_GET['action']) && $_GET['action'] === 'export_logs') {
        exportLogs();
        exit;
    }
}

function exportLogs() {
    global $pdo;

    // Map GET parameters to filter format
    $filterInput = [
        'search' => $_GET['search'] ?? '',
        'user_id' => $_GET['user_id'] ?? '',
        'action_filter' => $_GET['action_filter'] ?? '',
        'severity' => $_GET['level'] ?? '',
        'date_from' => $_GET['start_date'] ?? '',
        'date_to' => $_GET['end_date'] ?? ''
    ];

    // Build filters using shared function
    $filters = buildLogFilters($filterInput, true);
    $whereClause = $filters['where'];
    $params = $filters['params'];

    // Get logs for export
    $sql = "SELECT sl.*, COALESCE(p.fullname, 'System') as user_name
            FROM system_logs sl
            LEFT JOIN users u ON sl.user_id = u.id
            LEFT JOIN personnels p ON sl.personnel_id = p.id
            $whereClause
            ORDER BY sl.timestamp DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_logs_' . date('Y-m-d_H-i-s') . '.csv');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Create output stream
    $output = fopen('php://output', 'w');

    // Write CSV headers
    fputcsv($output, [
        'Timestamp',
        'User ID',
        'User Name',
        'Action',
        'Level',
        'Details',
        'IP Address'
    ]);

    // Write data rows
    foreach ($logs as $log) {
        $details = '';
        if (!empty($log['details'])) {
            try {
                $parsed = json_decode($log['details'], true);
                if (is_array($parsed)) {
                    $details = json_encode($parsed, JSON_UNESCAPED_UNICODE);
                } else {
                    $details = $log['details'];
                }
            } catch (Exception $e) {
                $details = $log['details'];
            }
        }

        fputcsv($output, [
            $log['timestamp'],
            $log['user_id'] ?: 'N/A',
            $log['user_name'],
            $log['action'],
            $log['severity'],
            $details,
            $log['ip_address'] ?: 'N/A'
        ]);
    }

    fclose($output);
    exit;
}

$logger = new Logger($pdo);
$logger->log('audit_log_accessed', [
    'page' => 'systemlog.php',
    'user_id' => $_SESSION['user_id'] ?? null
], 'low');

// Generate CSRF token for AJAX requests
$csrf_token = generateCSRFToken();

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
<!-- CSRF Token for AJAX requests -->
<input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

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
      <li><a class="dropdown-item" href="site_report.php">Sites Report</a></li>
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

<!-- Main Content -->
<main class="flex-grow-1 py-4">
  <div class="container-fluid px-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h3 mb-0">System Logs / Audit Logs</h1>
        <p class="text-muted mb-0">Monitor and track all system activities and user actions</p>
      </div>
      <div class="d-flex gap-2">
        <button id="refreshBtn" class="btn btn-outline-primary">
          <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <button id="exportBtn" class="btn btn-outline-success">
          <i class="bi bi-download"></i> Export
        </button>
      </div>
    </div>

    <!-- Filters Section -->
    <div class="card mb-4">
      <div class="card-header">
        <h5 class="mb-0">
          <i class="bi bi-funnel"></i> Filters
        </h5>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <!-- Date Range -->
          <div class="col-lg-3 col-md-6">
            <label class="form-label">Date Range</label>
            <div class="input-group">
              <input type="date" class="form-control" id="startDate">
              <span class="input-group-text">to</span>
              <input type="date" class="form-control" id="endDate">
            </div>
          </div>

          <!-- User/Personnel -->
          <div class="col-lg-2 col-md-6">
            <label class="form-label">User/Personnel</label>
            <select class="form-select" id="userFilter">
              <option value="">All Users</option>
            </select>
          </div>

          <!-- Action Type -->
          <div class="col-lg-2 col-md-6">
            <label class="form-label">Action Type</label>
            <select class="form-select" id="actionFilter">
              <option value="">All Actions</option>
            </select>
          </div>

          <!-- Log Level -->
          <div class="col-lg-2 col-md-6">
            <label class="form-label">Log Level</label>
            <select class="form-select" id="levelFilter">
              <option value="">All Levels</option>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>

          <!-- Search -->
          <div class="col-lg-3 col-md-6">
            <label class="form-label">Search</label>
            <div class="input-group">
              <input type="text" class="form-control" id="searchInput" placeholder="Search in details...">
              <button class="btn btn-outline-secondary" type="button" id="clearSearch">
                <i class="bi bi-x"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- Filter Actions -->
        <div class="d-flex gap-2 mt-3">
          <button id="applyFilters" class="btn btn-primary">
            <i class="bi bi-search"></i> Apply Filters
          </button>
          <button id="resetFilters" class="btn btn-outline-secondary">
            <i class="bi bi-x-circle"></i> Reset
          </button>
          <div class="ms-auto">
            <small class="text-muted" id="resultCount">Loading...</small>
          </div>
        </div>
      </div>
    </div>

    <!-- Logs Table -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
          <i class="bi bi-table"></i> Audit Logs
        </h5>
        <div class="d-flex align-items-center gap-2">
          <label class="form-label mb-0 me-2">Show:</label>
          <select class="form-select form-select-sm" id="pageSize" style="width: auto;">
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
          <span class="text-muted">entries</span>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="logsTable">
            <thead class="table-light">
              <tr>
                <th scope="col" style="width: 180px;">
                  <i class="bi bi-calendar-event"></i> Timestamp
                </th>
                <th scope="col" style="width: 120px;">
                  <i class="bi bi-person"></i> User
                </th>
                <th scope="col" style="width: 140px;">
                  <i class="bi bi-gear"></i> Action
                </th>
                <th scope="col" style="width: 100px;">
                  <i class="bi bi-flag"></i> Level
                </th>
                <th scope="col">
                  <i class="bi bi-info-circle"></i> Details
                </th>
                <th scope="col" style="width: 120px;">
                  <i class="bi bi-globe"></i> IP Address
                </th>
              </tr>
            </thead>
            <tbody id="logsTableBody">
              <tr>
                <td colspan="6" class="text-center py-4">
                  <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                  </div>
                  <div class="mt-2">Loading audit logs...</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <div class="card-footer">
        <div class="d-flex justify-content-between align-items-center">
          <div id="paginationInfo" class="text-muted small">
            Showing 0 to 0 of 0 entries
          </div>
          <nav aria-label="Audit logs pagination">
            <ul class="pagination pagination-sm mb-0" id="pagination">
              <!-- Pagination buttons will be generated here -->
            </ul>
          </nav>
        </div>
      </div>
    </div>

  </div>
</main>

<footer class="bg-dark text-light text-center py-3 mt-auto">
  <div class="container">
  <small>
    <?php echo date('Y'); ?> &copy; FREE PUBLIC INTERNET ACCESS PROGRAM - SERVICE MANAGEMENT AND RESPONSE TICKETING SYSTEM (FPIAP-SMARTs). All Rights Reserved.
  </small>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Global variables
    let currentPage = 1;
    let pageSize = 25;
    let totalRecords = 0;
    let isLoading = false;

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        initializePage();
        loadFilters();
        loadLogs();

        // Set default date range (last 30 days)
        const endDate = new Date();
        const startDate = new Date();
        startDate.setDate(startDate.getDate() - 30);

        document.getElementById('startDate').value = startDate.toISOString().split('T')[0];
        document.getElementById('endDate').value = endDate.toISOString().split('T')[0];
    });

    function initializePage() {
        // Event listeners
        document.getElementById('applyFilters').addEventListener('click', applyFilters);
        document.getElementById('resetFilters').addEventListener('click', resetFilters);
        document.getElementById('refreshBtn').addEventListener('click', loadLogs);
        document.getElementById('exportBtn').addEventListener('click', exportLogs);
        document.getElementById('clearSearch').addEventListener('click', clearSearch);
        document.getElementById('pageSize').addEventListener('change', changePageSize);
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });

        // Auto-refresh every 5 minutes
        setInterval(loadLogs, 300000);

        // Fetch notifications
        fetchNotifications();
        setInterval(fetchNotifications, 60000);

        const bellToggle = document.getElementById('notificationBell');
        if (bellToggle) {
            bellToggle.addEventListener('show.bs.dropdown', fetchNotifications);
        }
    }

    function loadFilters() {
        const csrfToken = document.getElementById('csrf_token').value;
        
        fetch('systemlog.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_filters&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populateUserFilter(data.users);
                populateActionFilter(data.actions);
            }
        })
        .catch(error => console.error('Error loading filters:', error));
    }

    function populateUserFilter(users) {
        const userFilter = document.getElementById('userFilter');
        userFilter.innerHTML = '<option value="">All Users</option>';
        users.forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = user.fullname;
            userFilter.appendChild(option);
        });
    }

    function populateActionFilter(actions) {
        const actionFilter = document.getElementById('actionFilter');
        actionFilter.innerHTML = '<option value="">All Actions</option>';
        actions.forEach(action => {
            const option = document.createElement('option');
            option.value = action;
            option.textContent = action.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            actionFilter.appendChild(option);
        });
    }

    function loadLogs() {
        if (isLoading) return;
        isLoading = true;

        const csrfToken = document.getElementById('csrf_token').value;
        const params = new URLSearchParams({
            action: 'get_logs',
            csrf_token: csrfToken,
            page: currentPage,
            per_page: pageSize,
            search: document.getElementById('searchInput').value,
            user_id: document.getElementById('userFilter').value,
            action_filter: document.getElementById('actionFilter').value,
            severity: document.getElementById('levelFilter').value,
            date_from: document.getElementById('startDate').value,
            date_to: document.getElementById('endDate').value
        });

        document.getElementById('logsTableBody').innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-2">Loading audit logs...</div>
                </td>
            </tr>
        `;

        fetch('systemlog.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderLogs(data.logs);
                updatePagination(data.total, data.page, data.per_page, data.pages);
                updateResultCount(data.total, data.page, data.per_page);
            } else {
                showError('Failed to load logs: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error loading logs:', error);
            showError('Failed to load audit logs. Please try again.');
        })
        .finally(() => {
            isLoading = false;
        });
    }

    function renderLogs(logs) {
        const tbody = document.getElementById('logsTableBody');

        if (!logs || logs.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center py-4 text-muted">
                        <i class="bi bi-info-circle fs-1"></i>
                        <div class="mt-2">No audit logs found matching your criteria.</div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = logs.map(log => {
            const timestamp = new Date(log.timestamp).toLocaleString();
            const levelClass = getLevelClass(log.severity || 'low');
            const userName = log.fullname || log.username || 'System';
            const details = log.description || 'No details available';

            return `
                <tr>
                    <td class="small">${timestamp}</td>
                    <td class="small">
                        <div class="fw-semibold">${userName}</div>
                        <div class="text-muted small">ID: ${log.user_id || 'N/A'}</div>
                    </td>
                    <td class="small">
                        <span class="badge bg-secondary">${log.action.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</span>
                    </td>
                    <td>
                        <span class="badge ${levelClass}">${(log.severity || 'low').toUpperCase()}</span>
                    </td>
                    <td class="small">
                        <div class="text-truncate" style="max-width: 400px;" title="${details}">
                            ${details}
                        </div>
                    </td>
                    <td class="small font-monospace">${log.ip_address || 'N/A'}</td>
                </tr>
            `;
        }).join('');
    }

    function getLevelClass(severity) {
        switch (severity) {
            case 'critical': return 'bg-danger';
            case 'high': return 'bg-warning text-dark';
            case 'medium': return 'bg-info';
            case 'low': return 'bg-light text-dark';
            default: return 'bg-secondary';
        }
    }

    function updatePagination(total, page, perPage, totalPages) {
        const pagination = document.getElementById('pagination');
        pagination.innerHTML = '';

        if (totalPages <= 1) return;

        // Previous button
        const prevBtn = createPaginationButton('Previous', page > 1, () => changePage(page - 1));
        if (page === 1) prevBtn.classList.add('disabled');
        pagination.appendChild(prevBtn);

        // Page numbers
        const startPage = Math.max(1, page - 2);
        const endPage = Math.min(totalPages, page + 2);

        if (startPage > 1) {
            pagination.appendChild(createPaginationButton('1', true, () => changePage(1)));
            if (startPage > 2) {
                const ellipsis = document.createElement('li');
                ellipsis.className = 'page-item disabled';
                ellipsis.innerHTML = '<span class="page-link">...</span>';
                pagination.appendChild(ellipsis);
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            const pageBtn = createPaginationButton(i.toString(), true, () => changePage(i));
            if (i === page) pageBtn.classList.add('active');
            pagination.appendChild(pageBtn);
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const ellipsis = document.createElement('li');
                ellipsis.className = 'page-item disabled';
                ellipsis.innerHTML = '<span class="page-link">...</span>';
                pagination.appendChild(ellipsis);
            }
            pagination.appendChild(createPaginationButton(totalPages.toString(), true, () => changePage(totalPages)));
        }

        // Next button
        const nextBtn = createPaginationButton('Next', page < totalPages, () => changePage(page + 1));
        if (page === totalPages) nextBtn.classList.add('disabled');
        pagination.appendChild(nextBtn);
    }

    function createPaginationButton(text, enabled, onClick) {
        const li = document.createElement('li');
        li.className = 'page-item' + (enabled ? '' : ' disabled');

        const link = document.createElement('a');
        link.className = 'page-link';
        link.href = '#';
        link.textContent = text;
        if (enabled) {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                onClick();
            });
        }

        li.appendChild(link);
        return li;
    }

    function updateResultCount(total, page, perPage) {
        const start = (page - 1) * perPage + 1;
        const end = Math.min(page * perPage, total);
        const info = `Showing ${start} to ${end} of ${total} entries`;
        document.getElementById('paginationInfo').textContent = info;
        document.getElementById('resultCount').textContent = `${total} total records`;
    }

    function changePage(page) {
        currentPage = page;
        loadLogs();
    }

    function changePageSize() {
        pageSize = parseInt(document.getElementById('pageSize').value);
        currentPage = 1;
        loadLogs();
    }

    function applyFilters() {
        currentPage = 1;
        loadLogs();
    }

    function resetFilters() {
        document.getElementById('startDate').value = '';
        document.getElementById('endDate').value = '';
        document.getElementById('userFilter').value = '';
        document.getElementById('actionFilter').value = '';
        document.getElementById('levelFilter').value = '';
        document.getElementById('searchInput').value = '';
        currentPage = 1;
        loadLogs();
    }

    function clearSearch() {
        document.getElementById('searchInput').value = '';
        applyFilters();
    }

    function exportLogs() {
        const params = new URLSearchParams({
            action: 'export_logs',
            start_date: document.getElementById('startDate').value,
            end_date: document.getElementById('endDate').value,
            user_id: document.getElementById('userFilter').value,
            action_filter: document.getElementById('actionFilter').value,
            level: document.getElementById('levelFilter').value,
            search: document.getElementById('searchInput').value
        });

        window.open('systemlog.php?' + params.toString(), '_blank');
    }

    function showError(message) {
        const tbody = document.getElementById('logsTableBody');
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4 text-danger">
                    <i class="bi bi-exclamation-triangle fs-1"></i>
                    <div class="mt-2">${message}</div>
                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="loadLogs()">Try Again</button>
                </td>
            </tr>
        `;
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