<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/DataExport.php';
require '../lib/Logger.php';

// Initialize components
$logger = new Logger($pdo);
$exporter = new DataExport($pdo, $logger);

// Log page access for system audit
$logger->log('data_export_page_access', ['description' => 'Accessed data_export.php page'], 'low', 'success');

function getFilterOptions($pdo, $dataType = 'tickets') {
    $options = [];

    if ($dataType === 'tickets') {
        // Status options
        $options['status'] = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];

        // Sites
        $stmt = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name");
        $options['sites'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Personnel (created by)
        $stmt = $pdo->query("SELECT id, fullname FROM personnels ORDER BY fullname");
        $options['personnel'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ISP
        $stmt = $pdo->query("SELECT DISTINCT isp FROM sites WHERE isp IS NOT NULL AND isp != '' ORDER BY isp");
        $options['isp'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Province
        $stmt = $pdo->query("SELECT DISTINCT province FROM sites WHERE province IS NOT NULL AND province != '' ORDER BY province");
        $options['province'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Municipality
        $stmt = $pdo->query("SELECT DISTINCT municipality FROM sites WHERE municipality IS NOT NULL AND municipality != '' ORDER BY municipality");
        $options['municipality'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Project
        $stmt = $pdo->query("SELECT DISTINCT project_name FROM sites WHERE project_name IS NOT NULL AND project_name != '' ORDER BY project_name");
        $options['project'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($dataType === 'sites') {
        // Province
        $stmt = $pdo->query("SELECT DISTINCT province FROM sites WHERE province IS NOT NULL AND province != '' ORDER BY province");
        $options['province'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // ISP
        $stmt = $pdo->query("SELECT DISTINCT isp FROM sites WHERE isp IS NOT NULL AND isp != '' ORDER BY isp");
        $options['isp'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Status
        $options['status'] = ['ACTIVE', 'ONGOING', 'EXPIRED', 'REMOVED', 'REBATES', 'SUPPORT'];

        // Project
        $stmt = $pdo->query("SELECT DISTINCT project_name FROM sites WHERE project_name IS NOT NULL AND project_name != '' ORDER BY project_name");
        $options['project'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($dataType === 'personnel') {
        // For personnel, minimal filters, but we can add search
        $options['search'] = [];
    }

    return ['success' => true, 'data' => $options];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'start_export':
                $dataType = $_POST['data_type'] ?? '';
                $filters = json_decode($_POST['filters'] ?? '{}', true);
                $options = json_decode($_POST['options'] ?? '{}', true);

                $result = $exporter->startExport($dataType, $filters, $options);
                echo json_encode($result);
                break;

            case 'get_filter_options':
                $dataType = $_POST['data_type'] ?? 'tickets';
                $result = getFilterOptions($pdo, $dataType);
                echo json_encode($result);
                break;

            case 'get_sites_for_filter':
                $stmt = $pdo->query("SELECT id, site_name, province FROM sites ORDER BY site_name");
                $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $sites]);
                break;

            case 'get_provinces_for_filter':
                $stmt = $pdo->query("SELECT DISTINCT province FROM sites WHERE province IS NOT NULL AND province != '' ORDER BY province");
                $provinces = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode(['success' => true, 'data' => $provinces]);
                break;

            case 'get_isps_for_filter':
                $stmt = $pdo->query("SELECT DISTINCT isp FROM sites WHERE isp IS NOT NULL AND isp != '' ORDER BY isp");
                $isps = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode(['success' => true, 'data' => $isps]);
                break;

            case 'get_export_history':
                $stmt = $pdo->prepare("
                    SELECT de.id, de.data_type, de.filename, de.file_size, de.total_records, de.status, de.error_message,
                           de.started_at, de.completed_at, p.fullname as exported_by
                    FROM data_exports de
                    LEFT JOIN personnels p ON de.created_by = p.id
                    ORDER BY de.started_at DESC
                    LIMIT 50
                ");
                $stmt->execute();
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $history]);
                break;

            case 'delete_export':
                $exportId = intval($_POST['export_id'] ?? 0);
                if ($exportId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid export ID']);
                    break;
                }

                $deleted = $exporter->deleteExport($exportId);
                if ($deleted) {
                    $logger->log('data_export_deleted', ['export_id' => $exportId], 'low', 'success');
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Export not found or unable to delete']);
                }
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle file downloads
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'download_export') {
        $filename = $_GET['file'] ?? '';
        handleSecureFileDownload($filename, $exporter, $pdo);
    }
    exit;
}

/**
 * Secure file download with access control
 */
function handleSecureFileDownload($filename, $exporter, $pdo) {
    // Validate filename (prevent directory traversal)
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
        http_response_code(400);
        die('Invalid filename');
    }

    $filepath = '../uploads/import_export/exports/' . $filename;

    // Check file exists and is readable
    if (!file_exists($filepath) || !is_readable($filepath)) {
        http_response_code(404);
        die('File not found');
    }

    // Check file age (max 24 hours for security)
    $fileAge = time() - filemtime($filepath);
    if ($fileAge > 24 * 60 * 60) {
        http_response_code(410);
        die('File has expired');
    }

    // Set security headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    // Clear output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Stream file
    readfile($filepath);
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

  <title>Data Export - FPIAP-SMARTs</title>
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
        <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
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
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4">
                <i class="bi bi-download me-2"></i>Data Export
            </h2>

            <!-- Export Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Export Data to CSV</h5>
                </div>
                <div class="card-body">
                    <form id="exportForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data Type</label>
                                <select class="form-select" id="exportDataType" name="data_type" required>
                                    <option value="">Select data type...</option>
                                    <option value="sites">Sites</option>
                                    <option value="personnel">Personnel</option>
                                    <option value="tickets">Tickets</option>
                                </select>
                            </div>
                        </div>

                        <!-- Dynamic filters based on data type -->
                        <div id="exportFilters" style="display: none;">
                            <h6 class="mb-3">Export Filters</h6>

                            <!-- Active Filter Indicators -->
                            <div id="activeFilters" class="d-flex flex-wrap gap-2 mb-3" style="display: none;"></div>

                            <!-- Tickets Filters -->
                            <div id="ticketsFilters" class="filter-group" style="display: none;">
                                <div class="row g-3">
                                    <!-- Date Range -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-calendar-range text-info me-1"></i>Date Range
                                        </label>
                                        <div class="input-group">
                                            <input type="date" class="form-control form-control-sm" id="dateFrom" placeholder="From">
                                            <input type="date" class="form-control form-control-sm" id="dateTo" placeholder="To">
                                        </div>
                                    </div>

                                    <!-- Status -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-circle-fill text-danger me-1"></i>Status
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="statusDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedStatus">All Status</span>
                                                <span class="badge bg-primary ms-2" id="statusCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search status..." id="statusSearch">
                                                <div id="statusSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('statusOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('statusOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('statusOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="statusOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('status')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('status')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Site -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-building text-primary me-1"></i>Site
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="siteDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedSite">All Sites</span>
                                                <span class="badge bg-primary ms-2" id="siteCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search sites..." id="siteSearch">
                                                <div id="siteSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('siteOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('siteOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('siteOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="siteOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('site')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('site')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Created By -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-person text-success me-1"></i>Created By
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="createdByDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedCreatedBy">All Personnel</span>
                                                <span class="badge bg-primary ms-2" id="createdByCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search personnel..." id="createdBySearch">
                                                <div id="createdBySearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('createdByOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('createdByOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('createdByOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="createdByOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('createdBy')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('createdBy')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ISP -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-wifi text-warning me-1"></i>ISP
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="ispDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedIsp">All ISPs</span>
                                                <span class="badge bg-primary ms-2" id="ispCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search ISPs..." id="ispSearch">
                                                <div id="ispSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('ispOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('ispOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('ispOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="ispOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('isp')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('isp')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Province -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-geo-alt text-secondary me-1"></i>Province
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="provinceDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedProvince">All Provinces</span>
                                                <span class="badge bg-primary ms-2" id="provinceCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search provinces..." id="provinceSearch">
                                                <div id="provinceSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('provinceOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('provinceOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('provinceOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="provinceOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('province')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('province')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Municipality -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-house text-muted me-1"></i>Municipality
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="municipalityDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedMunicipality">All Municipalities</span>
                                                <span class="badge bg-primary ms-2" id="municipalityCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search municipalities..." id="municipalitySearch">
                                                <div id="municipalitySearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('municipalityOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('municipalityOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('municipalityOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="municipalityOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('municipality')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('municipality')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Project -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-folder text-info me-1"></i>Project
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="projectDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedProject">All Projects</span>
                                                <span class="badge bg-primary ms-2" id="projectCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search projects..." id="projectSearch">
                                                <div id="projectSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('projectOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('projectOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('projectOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="projectOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('project')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('project')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Sites Filters -->
                            <div id="sitesFilters" class="filter-group" style="display: none;">
                                <div class="row g-3">
                                    <!-- Province -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-geo-alt text-secondary me-1"></i>Province
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="siteProvinceDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedSiteProvince">All Provinces</span>
                                                <span class="badge bg-primary ms-2" id="siteProvinceCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search provinces..." id="siteProvinceSearch">
                                                <div id="siteProvinceSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('siteProvinceOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('siteProvinceOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('siteProvinceOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="siteProvinceOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('siteProvince')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('siteProvince')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ISP -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-wifi text-warning me-1"></i>ISP
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="siteIspDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedSiteIsp">All ISPs</span>
                                                <span class="badge bg-primary ms-2" id="siteIspCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search ISPs..." id="siteIspSearch">
                                                <div id="siteIspSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('siteIspOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('siteIspOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('siteIspOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="siteIspOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('siteIsp')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('siteIsp')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Status -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-circle-fill text-danger me-1"></i>Status
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="siteStatusDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedSiteStatus">All Status</span>
                                                <span class="badge bg-primary ms-2" id="siteStatusCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search status..." id="siteStatusSearch">
                                                <div id="siteStatusSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('siteStatusOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('siteStatusOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('siteStatusOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="siteStatusOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('siteStatus')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('siteStatus')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Project -->
                                    <div class="col-lg-2 col-md-4 col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            <i class="bi bi-folder text-info me-1"></i>Project
                                        </label>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="siteProjectDropdown" data-bs-toggle="dropdown">
                                                <span id="selectedSiteProject">All Projects</span>
                                                <span class="badge bg-primary ms-2" id="siteProjectCount" style="display: none;">0</span>
                                            </button>
                                            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
                                                <input type="text" class="form-control form-control-sm mb-2" placeholder="Search projects..." id="siteProjectSearch">
                                                <div id="siteProjectSearchResults" class="text-muted small mb-1" style="display: none;"></div>
                                                <div class="d-flex gap-1 mb-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="selectAllVisible('siteProjectOptions')" title="Select all visible options">
                                                        <i class="bi bi-check-all"></i> All
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="selectNone('siteProjectOptions')" title="Clear all selections">
                                                        <i class="bi bi-x"></i> None
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info flex-fill" onclick="invertSelection('siteProjectOptions')" title="Invert current selection">
                                                        <i class="bi bi-arrow-repeat"></i> Invert
                                                    </button>
                                                </div>
                                                <div id="siteProjectOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                                                <hr class="my-2">
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('siteProject')">Apply</button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('siteProject')">Cancel</button>
                                                    </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Personnel Filters -->
                            <div id="personnelFilters" class="filter-group" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Search</label>
                                        <input type="text" class="form-control" id="exportPersonnelSearch" name="search" placeholder="Search by name or gmail">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Export Options -->
                        <div class="mb-3">
                            <h6 class="mb-3">Export Options</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="includeHeaders" name="include_headers" checked>
                                        <label class="form-check-label" for="includeHeaders">
                                            Include column headers
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="utf8BOM" name="utf8_bom">
                                        <label class="form-check-label" for="utf8BOM">
                                            Include UTF-8 BOM (for Excel compatibility)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-download me-2"></i>Export Data
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearExportFilters()">
                                <i class="bi bi-x-circle me-2"></i>Clear Filters
                            </button>
                        </div>
                    </form>

                    <!-- Export Progress -->
                    <div id="exportProgress" class="mt-4" style="display: none;">
                        <h6>Export Progress</h6>
                        <div class="progress mb-3">
                            <div class="progress-bar bg-success" id="exportProgressBar" style="width: 0%"></div>
                        </div>
                        <div class="text-center">
                            <div class="spinner-border spinner-border-sm text-success" role="status">
                                <span class="visually-hidden">Exporting...</span>
                            </div>
                            <span id="exportStatusText" class="ms-2">Preparing export...</span>
                        </div>
                    </div>

                    <!-- Export Result -->
                    <div id="exportResult" class="mt-4" style="display: none;">
                        <div class="alert alert-success">
                            <h6 class="alert-heading">
                                <i class="bi bi-check-circle me-2"></i>Export Completed Successfully!
                            </h6>
                            <p class="mb-2">
                                <strong id="exportRecordCount">0</strong> records exported to
                                <strong id="exportFilename">file.csv</strong>
                                (<span id="exportFileSize">0 KB</span>)
                            </p>
                            <div class="d-flex gap-2">
                                <a href="#" id="downloadExportLink" class="btn btn-success btn-sm">
                                    <i class="bi bi-download me-2"></i>Download File
                                </a>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetExportForm()">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Export More Data
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export History -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Export History</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="exportHistoryTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Data Type</th>
                                    <th>Records</th>
                                    <th>File Size</th>
                                    <th>Exported By</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="exportHistoryBody">
                                <!-- Export history will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global variables for filters
let currentFilters = {};
let filterOptions = {};

// Export functionality JavaScript
// Handle export data type change
function handleExportDataTypeChange(event) {
    const dataType = event.target.value;
    const filtersSection = document.getElementById('exportFilters');
    const ticketsFilters = document.getElementById('ticketsFilters');
    const sitesFilters = document.getElementById('sitesFilters');
    const personnelFilters = document.getElementById('personnelFilters');

    if (dataType) {
        filtersSection.style.display = 'block';

        // Hide all filter groups
        [ticketsFilters, sitesFilters, personnelFilters].forEach(group => {
            group.style.display = 'none';
        });

        // Show relevant filter group and load filter options
        switch (dataType) {
            case 'tickets':
                ticketsFilters.style.display = 'block';
                loadFilterOptions('tickets');
                break;
            case 'sites':
                sitesFilters.style.display = 'block';
                loadFilterOptions('sites');
                break;
            case 'personnel':
                personnelFilters.style.display = 'block';
                break;
        }
    } else {
        filtersSection.style.display = 'none';
    }
}

// Load filter options from server
async function loadFilterOptions(dataType) {
    try {
        const response = await fetch('data_export.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get_filter_options&data_type=${dataType}`
        });

        const result = await response.json();
        if (result.success) {
            filterOptions = result.data;
            populateAllFilters(dataType);
        }
    } catch (error) {
        console.error('Failed to load filter options:', error);
    }
}

// Populate all filters for a data type
function populateAllFilters(dataType) {
    if (dataType === 'tickets') {
        populateStatusFilter(filterOptions.status || []);
        populateSiteFilter(filterOptions.sites || []);
        populateCreatedByFilter(filterOptions.personnel || []);
        populateIspFilter(filterOptions.isp || []);
        populateProvinceFilter(filterOptions.province || []);
        populateMunicipalityFilter(filterOptions.municipality || []);
        populateProjectFilter(filterOptions.project || []);
    } else if (dataType === 'sites') {
        populateSiteProvinceFilter(filterOptions.province || []);
        populateSiteIspFilter(filterOptions.isp || []);
        populateSiteStatusFilter(filterOptions.status || []);
        populateSiteProjectFilter(filterOptions.project || []);
    }
}

// Populate individual filters
function populateStatusFilter(options) {
    const container = document.getElementById('statusOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option}" id="status_${option}">
                <label class="form-check-label" for="status_${option}">${option}</label>
            </div>
        `;
    });
    setupSearch('statusSearch', 'statusOptions');
    setupEventDelegation('statusOptions');
}

function populateSiteFilter(options) {
    const container = document.getElementById('siteOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option.id}" id="site_${option.id}">
                <label class="form-check-label" for="site_${option.id}">${option.site_name}</label>
            </div>
        `;
    });
    setupSearch('siteSearch', 'siteOptions');
    setupEventDelegation('siteOptions');
}

function populateCreatedByFilter(options) {
    const container = document.getElementById('createdByOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option.id}" id="createdBy_${option.id}">
                <label class="form-check-label" for="createdBy_${option.id}">${option.fullname}</label>
            </div>
        `;
    });
    setupSearch('createdBySearch', 'createdByOptions');
    setupEventDelegation('createdByOptions');
}

function populateIspFilter(options) {
    const container = document.getElementById('ispOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option}" id="isp_${option}">
                <label class="form-check-label" for="isp_${option}">${option}</label>
            </div>
        `;
    });
    setupSearch('ispSearch', 'ispOptions');
    setupEventDelegation('ispOptions');
}

function populateProvinceFilter(options) {
    const container = document.getElementById('provinceOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option}" id="province_${option}">
                <label class="form-check-label" for="province_${option}">${option}</label>
            </div>
        `;
    });
    setupSearch('provinceSearch', 'provinceOptions');
    setupEventDelegation('provinceOptions');
}

function populateMunicipalityFilter(options) {
    const container = document.getElementById('municipalityOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option}" id="municipality_${option}">
                <label class="form-check-label" for="municipality_${option}">${option}</label>
            </div>
        `;
    });
    setupSearch('municipalitySearch', 'municipalityOptions');
    setupEventDelegation('municipalityOptions');
}

function populateProjectFilter(options) {
    const container = document.getElementById('projectOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option}" id="project_${option}">
                <label class="form-check-label" for="project_${option}">${option}</label>
            </div>
        `;
    });
    setupSearch('projectSearch', 'projectOptions');
    setupEventDelegation('projectOptions');
}

function populateSiteProvinceFilter(options) {
    const container = document.getElementById('siteProvinceOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option}" id="siteProvince_${option}">
                <label class="form-check-label" for="siteProvince_${option}">${option}</label>
            </div>
        `;
    });
    setupSearch('siteProvinceSearch', 'siteProvinceOptions');
    setupEventDelegation('siteProvinceOptions');
}

function populateSiteIspFilter(options) {
    const container = document.getElementById('siteIspOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option}" id="siteIsp_${option}">
                <label class="form-check-label" for="siteIsp_${option}">${option}</label>
            </div>
        `;
    });
    setupSearch('siteIspSearch', 'siteIspOptions');
    setupEventDelegation('siteIspOptions');
}

function populateSiteStatusFilter(options) {
    const container = document.getElementById('siteStatusOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option}" id="siteStatus_${option}">
                <label class="form-check-label" for="siteStatus_${option}">${option}</label>
            </div>
        `;
    });
    setupSearch('siteStatusSearch', 'siteStatusOptions');
    setupEventDelegation('siteStatusOptions');
}

function populateSiteProjectFilter(options) {
    const container = document.getElementById('siteProjectOptions');
    container.innerHTML = '';
    options.forEach(option => {
        container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${option}" id="siteProject_${option}">
                <label class="form-check-label" for="siteProject_${option}">${option}</label>
            </div>
        `;
    });
    setupSearch('siteProjectSearch', 'siteProjectOptions');
    setupEventDelegation('siteProjectOptions');
}

// Search functionality
function setupSearch(searchId, containerId) {
    const searchInput = document.getElementById(searchId);
    if (!searchInput) return;

    let timeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            filterContainerOptions(containerId, this.value);
            updateSearchResults(containerId);
        }, 100);
    });
}

function filterContainerOptions(containerId, query) {
    const container = document.getElementById(containerId);
    const checks = container.querySelectorAll('.form-check');
    let visibleCount = 0;

    checks.forEach(check => {
        const label = check.querySelector('.form-check-label');
        const text = label.textContent.toLowerCase();
        const visible = text.includes(query.toLowerCase());
        check.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
    });

    return visibleCount;
}

function updateSearchResults(containerId) {
    const container = document.getElementById(containerId);
    const searchResults = document.getElementById(containerId.replace('Options', 'SearchResults'));
    const total = container.querySelectorAll('.form-check').length;
    const visible = container.querySelectorAll('.form-check:not([style*="display: none"])').length;

    if (searchResults) {
        if (total !== visible) {
            searchResults.textContent = `Showing ${visible} of ${total} options`;
            searchResults.style.display = 'block';
        } else {
            searchResults.style.display = 'none';
        }
    }
}

// Selection management
function selectAllVisible(containerId) {
    const container = document.getElementById(containerId);
    const visibleChecks = container.querySelectorAll('.form-check:not([style*="display: none"]) input[type="checkbox"]');
    visibleChecks.forEach(cb => cb.checked = true);
    updateSelectionCount(containerId);
}

function selectNone(containerId) {
    const container = document.getElementById(containerId);
    const checks = container.querySelectorAll('input[type="checkbox"]');
    checks.forEach(cb => cb.checked = false);
    updateSelectionCount(containerId);
}

function invertSelection(containerId) {
    const container = document.getElementById(containerId);
    const checks = container.querySelectorAll('input[type="checkbox"]');
    checks.forEach(cb => cb.checked = !cb.checked);
    updateSelectionCount(containerId);
}

function updateSelectionCount(containerId) {
    const container = document.getElementById(containerId);
    const checks = container.querySelectorAll('input[type="checkbox"]');
    const selected = container.querySelectorAll('input[type="checkbox"]:checked').length;
    const countElement = document.getElementById(containerId.replace('Options', 'SelectionCount'));
    if (countElement) {
        countElement.textContent = `${selected} selected`;
    }
}

// Event delegation
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

// Apply and clear filters
function applyMultiSelect(type) {
    const selected = getSelectedValues(type + 'Options');
    if (selected.length > 0) {
        currentFilters[type] = selected;
    } else {
        delete currentFilters[type];
    }
    updateSelectedDisplay(type, selected);
    updateActiveFilters();
    closeDropdownByType(type);
}

function clearMultiSelect(type) {
    selectNone(type + 'Options');
    delete currentFilters[type];
    updateSelectedDisplay(type, []);
    updateActiveFilters();
    closeDropdownByType(type);
}

function getSelectedValues(containerId) {
    const container = document.getElementById(containerId);
    const selected = [];
    const checks = container.querySelectorAll('input[type="checkbox"]:checked');
    checks.forEach(cb => selected.push(cb.value));
    return selected;
}

const filterLabelMap = {
    status: 'Status',
    site: 'Site',
    createdBy: 'Personnel',
    isp: 'ISP',
    province: 'Province',
    municipality: 'Municipality',
    project: 'Project',
    siteProvince: 'Province',
    siteIsp: 'ISP',
    siteStatus: 'Status',
    siteProject: 'Project'
};

function updateSelectedDisplay(type, selected) {
    const displayElement = document.getElementById('selected' + type.charAt(0).toUpperCase() + type.slice(1));
    const countElement = document.getElementById(type + 'Count');
    const labelName = filterLabelMap[type] || (type.charAt(0).toUpperCase() + type.slice(1));

    if (!displayElement) return;

    if (selected.length === 0) {
        displayElement.textContent = 'All ' + labelName + (labelName.endsWith('s') ? '' : 's');
        if (countElement) countElement.style.display = 'none';
    } else if (selected.length === 1) {
        // For single selection, show the name
        const container = document.getElementById(type + 'Options');
        const label = container.querySelector(`input[value="${selected[0]}"] + label`);
        displayElement.textContent = label ? label.textContent : selected[0];
        if (countElement) countElement.style.display = 'none';
    } else {
        displayElement.textContent = `${selected.length} selected`;
        if (countElement) {
            countElement.textContent = selected.length;
            countElement.style.display = 'inline-block';
        }
    }
}

function updateActiveFilters() {
    const activeFilters = document.getElementById('activeFilters');
    activeFilters.innerHTML = '';

    Object.keys(currentFilters).forEach(type => {
        const selected = currentFilters[type];
        if (selected && selected.length > 0) {
            const badge = document.createElement('span');
            badge.className = 'badge bg-primary me-2 mb-1';
            badge.innerHTML = `${filterLabelMap[type] || type}: ${selected.length} <button type="button" class="btn-close btn-close-white ms-1" onclick="removeFilter('${type}')" style="font-size: 0.6em;"></button>`;
            activeFilters.appendChild(badge);
        }
    });

    const hasActive = Object.keys(currentFilters).some(type => currentFilters[type] && currentFilters[type].length > 0);
    activeFilters.style.display = hasActive ? 'flex' : 'none';
}

function removeFilter(type) {
    clearMultiSelect(type);
}

function closeDropdownByType(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    if (dropdown) {
        const bsDropdown = bootstrap.Dropdown.getInstance(dropdown);
        if (bsDropdown) {
            bsDropdown.hide();
        } else {
            // Fallback
            dropdown.classList.remove('show');
            const menu = dropdown.nextElementSibling;
            if (menu) menu.classList.remove('show');
        }
    }
}

function resetFilters() {
    currentFilters = {};
    updateActiveFilters();

    const filterTypes = ['status', 'site', 'createdBy', 'isp', 'province', 'municipality', 'project', 'siteProvince', 'siteIsp', 'siteStatus', 'siteProject'];
    filterTypes.forEach(type => {
        const displayElement = document.getElementById('selected' + type.charAt(0).toUpperCase() + type.slice(1));
        const countElement = document.getElementById(type + 'Count');
        const labelName = filterLabelMap[type] || (type.charAt(0).toUpperCase() + type.slice(1));

        if (displayElement) {
            displayElement.textContent = 'All ' + labelName + (labelName.endsWith('s') ? '' : 's');
        }
        if (countElement) {
            countElement.style.display = 'none';
        }

        selectNone(type + 'Options');
    });
}

// Handle export form submission
async function handleExportSubmit(event) {
    event.preventDefault();

    const formData = new FormData(event.target);
    const dataType = formData.get('data_type');

    if (!dataType) {
        showAlert('Please select a data type', 'warning');
        return;
    }

    // Collect filters from currentFilters and form
    const filters = { ...currentFilters };

    // Map frontend filter keys to backend query keys
    if (dataType === 'tickets') {
        if (filters.site) {
            filters.site_id = filters.site;
            delete filters.site;
        }
        if (filters.createdBy) {
            filters.created_by = filters.createdBy;
            delete filters.createdBy;
        }
    } else if (dataType === 'sites') {
        if (filters.siteProvince) {
            filters.province = filters.siteProvince;
            delete filters.siteProvince;
        }
        if (filters.siteIsp) {
            filters.isp = filters.siteIsp;
            delete filters.siteIsp;
        }
        if (filters.siteStatus) {
            filters.status = filters.siteStatus;
            delete filters.siteStatus;
        }
        if (filters.siteProject) {
            filters.project = filters.siteProject;
            delete filters.siteProject;
        }
    }

    // Add date range if present
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    if (dateFrom) filters.date_from = dateFrom;
    if (dateTo) filters.date_to = dateTo;

    // For personnel, add search
    if (dataType === 'personnel') {
        const search = document.getElementById('exportPersonnelSearch').value;
        if (search) filters.search = search;
    }

    // Collect options
    const options = {
        include_headers: document.getElementById('includeHeaders').checked,
        utf8_bom: document.getElementById('utf8BOM').checked,
        delimiter: ',',
        enclosure: '"',
        escape: '\\'
    };

    try {
        // Show progress
        showExportProgress('Starting export...');

        const response = await fetch('data_export.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=start_export&data_type=${dataType}&filters=${encodeURIComponent(JSON.stringify(filters))}&options=${encodeURIComponent(JSON.stringify(options))}`
        });

        const result = await response.json();

        if (result.success) {
            showExportResult(result);
            loadExportHistory(); // Refresh history
        } else {
            hideExportProgress();
            showAlert('Export failed: ' + result.error, 'danger');
        }
    } catch (error) {
        hideExportProgress();
        showAlert('Export error: ' + error.message, 'danger');
    }
}

// Show export progress
function showExportProgress(message) {
    document.getElementById('exportProgress').style.display = 'block';
    document.getElementById('exportResult').style.display = 'none';
    document.getElementById('exportStatusText').textContent = message;
    document.getElementById('exportProgressBar').style.width = '0%';
}

// Hide export progress
function hideExportProgress() {
    document.getElementById('exportProgress').style.display = 'none';
}

// Show export result
function showExportResult(result) {
    hideExportProgress();

    const resultDiv = document.getElementById('exportResult');
    const recordCount = document.getElementById('exportRecordCount');
    const filename = document.getElementById('exportFilename');
    const fileSize = document.getElementById('exportFileSize');
    const downloadLink = document.getElementById('downloadExportLink');

    recordCount.textContent = result.record_count.toLocaleString();
    filename.textContent = result.filename;
    fileSize.textContent = formatFileSize(result.file_size);

    downloadLink.href = `data_export.php?action=download_export&file=${encodeURIComponent(result.filename)}`;

    resultDiv.style.display = 'block';

    // Auto-hide download link after 30 minutes for security
    setTimeout(() => {
        downloadLink.style.pointerEvents = 'none';
        downloadLink.style.opacity = '0.5';
        downloadLink.textContent = 'Download expired (refresh page to export again)';
    }, 30 * 60 * 1000); // 30 minutes
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Clear export filters
function clearExportFilters() {
    resetFilters();
    // Clear date inputs
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    // Clear personnel search
    document.getElementById('exportPersonnelSearch').value = '';
}

// Reset export form
function resetExportForm() {
    document.getElementById('exportForm').reset();
    document.getElementById('exportResult').style.display = 'none';
    document.getElementById('exportFilters').style.display = 'none';
    clearExportFilters();
}

// Load export history
async function loadExportHistory() {
    try {
        const response = await fetch('data_export.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_export_history'
        });

        const result = await response.json();
        if (result.success) {
            const tbody = document.getElementById('exportHistoryBody');
            tbody.innerHTML = '';

            result.data.forEach(export_record => {
                const statusBadge = getStatusBadge(export_record.status);
                const actions = getExportActions(export_record);
                const exportedBy = export_record.exported_by || 'Unknown';

                tbody.innerHTML += `
                    <tr>
                        <td>${new Date(export_record.started_at).toLocaleString()}</td>
                        <td>${export_record.data_type}</td>
                        <td>${export_record.total_records.toLocaleString()}</td>
                        <td>${formatFileSize(export_record.file_size)}</td>
                        <td>${exportedBy}</td>
                        <td>${statusBadge}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            });
        }
    } catch (error) {
        console.error('Failed to load export history:', error);
    }
}

// Get status badge HTML
function getStatusBadge(status) {
    const badges = {
        'completed': '<span class="badge bg-success">Completed</span>',
        'failed': '<span class="badge bg-danger">Failed</span>',
        'processing': '<span class="badge bg-warning">Processing</span>',
        'pending': '<span class="badge bg-secondary">Pending</span>',
        'cancelled': '<span class="badge bg-dark">Cancelled</span>'
    };
    return badges[status] || `<span class="badge bg-secondary">${status}</span>`;
}

// Get export actions HTML
function getExportActions(export_record) {
    const downloadBtn = (export_record.status === 'completed' && export_record.filename)
        ? `<a href="data_export.php?action=download_export&file=${encodeURIComponent(export_record.filename)}" class="btn btn-sm btn-outline-primary me-1">Download</a>`
        : '';

    const deleteBtn = `<button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteExport(${export_record.id})">Delete</button>`;

    return `${downloadBtn}${deleteBtn}`;
}

async function deleteExport(exportId) {
    if (!confirm('Are you sure you want to delete this export history entry and file?')) {
        return;
    }

    try {
        const response = await fetch('data_export.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete_export&export_id=${encodeURIComponent(exportId)}`
        });
        const result = await response.json();

        if (result.success) {
            showAlert('Export entry deleted successfully.', 'success');
            loadExportHistory();
        } else {
            showAlert('Failed to delete export entry: ' + (result.error || 'Unknown error'), 'danger');
        }
    } catch (error) {
        console.error('Failed to delete export entry:', error);
        showAlert('An error occurred while deleting export entry.', 'danger');
    }
}

// Show alert function
function showAlert(message, type = 'info') {
    // Create alert element
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    document.body.appendChild(alertDiv);

    // Auto remove after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Set up export event listeners
    document.getElementById('exportDataType').addEventListener('change', handleExportDataTypeChange);
    document.getElementById('exportForm').addEventListener('submit', handleExportSubmit);

    // Load initial data
    loadExportHistory();

    // Initialize notifications
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