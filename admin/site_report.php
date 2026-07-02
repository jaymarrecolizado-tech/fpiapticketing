<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require '../lib/Validator.php';

// Security
requireAdmin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// AJAX helper: return list of sites for filtering
if ($action === 'get_sites') {
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT id, ap_site_code, site_name FROM sites ORDER BY ap_site_code, site_name");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// AJAX helper: return all filter options (mimic dashboard behavior)
if ($action === 'get_filter_options') {
    header('Content-Type: application/json');
    $statuses = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
    $sites = $pdo->query("SELECT id, ap_site_code, site_name FROM sites ORDER BY ap_site_code, site_name")->fetchAll(PDO::FETCH_ASSOC);
    $personnel = $pdo->query("SELECT id, fullname FROM personnels ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);
    $isp = $pdo->query("SELECT DISTINCT isp FROM sites WHERE isp IS NOT NULL AND isp != '' ORDER BY isp")->fetchAll(PDO::FETCH_COLUMN);
    $provinces = $pdo->query("SELECT DISTINCT province FROM sites WHERE province IS NOT NULL AND province != '' ORDER BY province")->fetchAll(PDO::FETCH_COLUMN);
    $municipalities = $pdo->query("SELECT DISTINCT municipality FROM sites WHERE municipality IS NOT NULL AND municipality != '' ORDER BY municipality")->fetchAll(PDO::FETCH_COLUMN);
    $projects = $pdo->query("SELECT DISTINCT project_name FROM sites WHERE project_name IS NOT NULL AND project_name != '' ORDER BY project_name")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode([
        'statuses' => $statuses,
        'sites' => $sites,
        'personnel' => $personnel,
        'isp' => $isp,
        'provinces' => $provinces,
        'municipalities' => $municipalities,
        'projects' => $projects
    ]);
    exit;
}

// ===== GENERATE REPORT BACKEND =====
if ($action === 'generate_report') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }

    $reportType = $_POST['report_type'] ?? '';
    $filters = json_decode($_POST['filters'] ?? '{}', true);
    $sortBy = $_POST['sort_by'] ?? '';
    $sortDirection = $_POST['sort_direction'] ?? 'ASC';

    try {
        $reportData = generateReport($reportType, $filters, $pdo, $sortBy, $sortDirection);
        echo json_encode(['success' => true, 'data' => $reportData]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ===== EXPORT REPORT =====
elseif ($action === 'export') {
    $reportType = $_GET['type'] ?? '';
    $format = $_GET['format'] ?? 'csv'; // csv or pdf
    $filters = json_decode($_GET['filters'] ?? '{}', true);
    $sortBy = $_GET['sort_by'] ?? '';
    $sortDirection = $_GET['sort_direction'] ?? 'ASC';

    try {
        $reportData = generateReport($reportType, $filters, $pdo, $sortBy, $sortDirection);
        
        if ($format === 'csv') {
            exportCSV($reportData, $reportType);
        } elseif ($format === 'pdf') {
            exportPDF($reportData, $reportType);
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
    exit;
}

// ===== REPORT GENERATION FUNCTION =====
function generateReport($type, $filters, $pdo, $sortBy = '', $sortDirection = 'ASC') {
    // Whitelist of allowed columns per report type
    $allowedColumns = [
        '1_site_summary' => ['ap_site_code', 'site_name', 'province', 'municipality', 
                           'isp', 'total_tickets', 'open_tickets', 'in_progress_tickets',
                           'resolved_tickets', 'closed_tickets', 'avg_resolution_days',
                           'resolved_closed_aging_tickets', 'unresolved_aging_tickets'],
        '3_isp_performance' => ['isp', 'num_sites', 'total_tickets', 'avg_tickets_per_site',
                               'open_tickets', 'in_progress_tickets', 'resolved_tickets',
                               'closed_tickets', 'avg_resolution_days', 'resolved_closed_aging_tickets',
                               'unresolved_aging_tickets', 'open_ticket_site_rate'],
        '5_project_report' => ['project_name', 'num_sites', 'total_tickets', 'new_tickets',
                              'open_tickets', 'resolved_tickets', 'avg_resolution_days',
                              'aging_tickets', 'success_rate_percent'],
        '7_aging_tickets' => ['ap_site_code', 'site_name', 'province', 'municipality',
                             'resolved_closed_aging_tickets', 'unresolved_aging_tickets'],
        '8_monthly_activity' => ['month', 'new_tickets', 'resolved_tickets', 'pending_tickets',
                                'avg_resolution_days', 'resolved_closed_aging_tickets',
                                'unresolved_aging_tickets', 'sites_affected']
    ];
    
    // Validate sort direction
    if (!in_array(strtoupper($sortDirection), ['ASC', 'DESC'])) {
        $sortDirection = 'ASC';
    }
    
    $data = [];

    switch ($type) {
        case '1_site_summary':
            $data = getSiteSummaryReport($pdo, $filters);
            break;
        case '3_isp_performance':
            $data = getISPPerformanceReport($pdo, $filters);
            break;
        case '5_project_report':
            $data = getProjectReport($pdo, $filters);
            break;
        case '7_aging_tickets':
            $data = getAgingTicketsReport($pdo, $filters);
            break;
        case '8_monthly_activity':
            $data = getMonthlyActivityReport($pdo, $filters);
            break;

        default:
            throw new Exception('Unknown report type');
    }
    
    // Apply sort in PHP if needed
    if (!empty($sortBy) && !empty($data)) {
        // Validate sort column is allowed for this report type
        if (!isset($allowedColumns[$type]) || !in_array($sortBy, $allowedColumns[$type])) {
            throw new Exception('Invalid sort column for this report type');
        }
        
        // Validate sort direction
        if (!in_array(strtoupper($sortDirection), ['ASC', 'DESC'])) {
            throw new Exception('Invalid sort direction');
        }
        
        usort($data, function($a, $b) use ($sortBy, $sortDirection) {
            $aVal = $a[$sortBy] ?? '';
            $bVal = $b[$sortBy] ?? '';
            
            // Handle numeric and string comparison
            $cmp = is_numeric($aVal) && is_numeric($bVal)
                ? $aVal <=> $bVal
                : strcasecmp((string)$aVal, (string)$bVal);
            
            return $sortDirection === 'DESC' ? -$cmp : $cmp;
        });
    }

    return $data;
}

// helper used across reports to translate filter array into SQL conditions
function buildWhereClause($filters, &$params = []) {
    $where = [];
    if (!empty($filters['status'])) {
        if (is_array($filters['status'])) {
            $placeholders = implode(',', array_fill(0, count($filters['status']), '?'));
            $where[] = "t.status IN ($placeholders)";
            $params = array_merge($params, $filters['status']);
        } else {
            $where[] = "t.status = ?";
            $params[] = $filters['status'];
        }
    }
    if (!empty($filters['site_id'])) {
        if (is_array($filters['site_id'])) {
            $placeholders = implode(',', array_fill(0, count($filters['site_id']), '?'));
            $where[] = "t.site_id IN ($placeholders)";
            $params = array_merge($params, array_map('intval', $filters['site_id']));
        } elseif ($filters['site_id'] != '0') {
            $where[] = "t.site_id = ?";
            $params[] = (int)$filters['site_id'];
        }
    }
    if (!empty($filters['created_by'])) {
        if (is_array($filters['created_by'])) {
            $placeholders = implode(',', array_fill(0, count($filters['created_by']), '?'));
            $where[] = "t.created_by IN ($placeholders)";
            $params = array_merge($params, array_map('intval', $filters['created_by']));
        } elseif ($filters['created_by'] != '0') {
            $where[] = "t.created_by = ?";
            $params[] = (int)$filters['created_by'];
        }
    }
    if (!empty($filters['date_from'])) {
        $where[] = "DATE(t.created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = "DATE(t.created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    if (!empty($filters['isp'])) {
        if (is_array($filters['isp'])) {
            $placeholders = implode(',', array_fill(0, count($filters['isp']), '?'));
            $where[] = "s.isp IN ($placeholders)";
            $params = array_merge($params, $filters['isp']);
        } else {
            $where[] = "s.isp = ?";
            $params[] = $filters['isp'];
        }
    }
    if (!empty($filters['province'])) {
        if (is_array($filters['province'])) {
            $placeholders = implode(',', array_fill(0, count($filters['province']), '?'));
            $where[] = "s.province IN ($placeholders)";
            $params = array_merge($params, $filters['province']);
        } else {
            $where[] = "s.province = ?";
            $params[] = $filters['province'];
        }
    }
    if (!empty($filters['municipality'])) {
        if (is_array($filters['municipality'])) {
            $placeholders = implode(',', array_fill(0, count($filters['municipality']), '?'));
            $where[] = "s.municipality IN ($placeholders)";
            $params = array_merge($params, $filters['municipality']);
        } else {
            $where[] = "s.municipality = ?";
            $params[] = $filters['municipality'];
        }
    }
    if (!empty($filters['project_name'])) {
        if (is_array($filters['project_name'])) {
            $placeholders = implode(',', array_fill(0, count($filters['project_name']), '?'));
            $where[] = "s.project_name IN ($placeholders)";
            $params = array_merge($params, $filters['project_name']);
        } else {
            $where[] = "s.project_name = ?";
            $params[] = $filters['project_name'];
        }
    }

    return ['sql' => $where ? ' AND ' . implode(' AND ', $where) : '', 'params' => $params];
}

// ===== REPORT GENERATOR FUNCTIONS =====

function getSiteSummaryReport($pdo, $filters = []) {
    $whereData = buildWhereClause($filters);
    $query = "
        SELECT 
            COALESCE(s.ap_site_code, s.id) as ap_site_code,
            s.site_name,
            s.province,
            s.municipality,
            s.isp,
            COUNT(t.id) as total_tickets,
            SUM(CASE WHEN t.status = 'OPEN' THEN 1 ELSE 0 END) as open_tickets,
            SUM(CASE WHEN t.status = 'IN_PROGRESS' THEN 1 ELSE 0 END) as in_progress_tickets,
            SUM(CASE WHEN t.status = 'RESOLVED' THEN 1 ELSE 0 END) as resolved_tickets,
            SUM(CASE WHEN t.status = 'CLOSED' THEN 1 ELSE 0 END) as closed_tickets,
            ROUND((SUM(CASE WHEN t.duration IS NOT NULL THEN t.duration ELSE 0 END) / COUNT(t.id)) / 1440, 2) as avg_resolution_days,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) as resolved_closed_aging_tickets,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('OPEN', 'IN_PROGRESS') THEN 1 ELSE 0 END) as unresolved_aging_tickets
        FROM sites s
        LEFT JOIN tickets t ON s.id = t.site_id
        WHERE 1=1{$whereData['sql']}
        GROUP BY s.ap_site_code
        ORDER BY s.site_name ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($whereData['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getISPPerformanceReport($pdo, $filters = []) {
    $whereData = buildWhereClause($filters);
    // ensure we still restrict to non-null ISP
    $query = "
        SELECT 
            s.isp,
            COUNT(DISTINCT s.id) as num_sites,
            COUNT(t.id) as total_tickets,
            ROUND(COUNT(t.id) / COUNT(DISTINCT s.id), 2) as avg_tickets_per_site,
            SUM(CASE WHEN t.status = 'OPEN' THEN 1 ELSE 0 END) as open_tickets,
            SUM(CASE WHEN t.status = 'IN_PROGRESS' THEN 1 ELSE 0 END) as in_progress_tickets,
            SUM(CASE WHEN t.status = 'RESOLVED' THEN 1 ELSE 0 END) as resolved_tickets,
            SUM(CASE WHEN t.status = 'CLOSED' THEN 1 ELSE 0 END) as closed_tickets,
            ROUND(AVG(DATEDIFF(CURDATE(), t.created_at)), 2) as avg_resolution_days,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) as resolved_closed_aging_tickets,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('OPEN', 'IN_PROGRESS') THEN 1 ELSE 0 END) as unresolved_aging_tickets,
            ROUND((SUM(CASE WHEN t.status IN ('OPEN', 'IN_PROGRESS') THEN 1 ELSE 0 END) / COUNT(DISTINCT s.id)) * 100, 2) as open_ticket_site_rate
        FROM sites s
        LEFT JOIN tickets t ON s.id = t.site_id
        WHERE s.isp IS NOT NULL{$whereData['sql']}
        GROUP BY s.isp
        ORDER BY total_tickets DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($whereData['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProjectReport($pdo, $filters = []) {
    $whereData = buildWhereClause($filters);
    $query = "
        SELECT 
            s.project_name,
            COUNT(DISTINCT s.id) as num_sites,
            COUNT(t.id) as total_tickets,
            SUM(CASE WHEN t.status = 'NEW' THEN 1 ELSE 0 END) as new_tickets,
            SUM(CASE WHEN t.status = 'OPEN' THEN 1 ELSE 0 END) as open_tickets,
            SUM(CASE WHEN t.status = 'RESOLVED' THEN 1 ELSE 0 END) as resolved_tickets,
            ROUND(AVG(DATEDIFF(CURDATE(), t.created_at)), 2) as avg_resolution_days,
            SUM(CASE WHEN DATEDIFF(NOW(), t.created_at) > 30 AND t.status NOT IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) as aging_tickets,
            ROUND((SUM(CASE WHEN t.status = 'RESOLVED' THEN 1 ELSE 0 END) / COUNT(t.id)) * 100, 2) as success_rate_percent
        FROM sites s
        LEFT JOIN tickets t ON s.id = t.site_id
        WHERE s.project_name IS NOT NULL AND s.project_name != ''{$whereData['sql']}
        GROUP BY s.project_name
        ORDER BY total_tickets DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($whereData['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAgingTicketsReport($pdo, $filters = []) {
    $whereData = buildWhereClause($filters);
    $query = "
        SELECT
            COALESCE(s.ap_site_code, s.id) as ap_site_code,
            s.site_name,
            s.province,
            s.municipality,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) as resolved_closed_aging_tickets,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('OPEN', 'IN_PROGRESS') THEN 1 ELSE 0 END) as unresolved_aging_tickets
        FROM sites s
        LEFT JOIN tickets t ON s.id = t.site_id
        WHERE 1=1{$whereData['sql']}
        GROUP BY s.ap_site_code
        ORDER BY s.site_name ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($whereData['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMonthlyActivityReport($pdo, $filters = []) {
    $whereData = buildWhereClause($filters);
    $query = "
        SELECT 
            DATE_FORMAT(t.created_at, '%Y-%m') as month,
            COUNT(t.id) as new_tickets,
            SUM(CASE WHEN t.status IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) as resolved_tickets,
            SUM(CASE WHEN t.status NOT IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) as pending_tickets,
            ROUND(AVG(DATEDIFF(CURDATE(), t.created_at)), 2) as avg_resolution_days,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) as resolved_closed_aging_tickets,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('OPEN', 'IN_PROGRESS') THEN 1 ELSE 0 END) as unresolved_aging_tickets,
            COUNT(DISTINCT t.site_id) as sites_affected
        FROM tickets t
        LEFT JOIN sites s ON t.site_id = s.id
        WHERE 1=1{$whereData['sql']}
        GROUP BY DATE_FORMAT(t.created_at, '%Y-%m')
        ORDER BY month DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($whereData['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== EXPORT FUNCTIONS =====

function exportCSV($data, $reportType) {
    if (empty($data)) {
        echo "No data available for export.";
        return;
    }

    $reportName = getReportName($reportType);
    $filename = str_replace(' ', '_', $reportName) . '_' . date('Y-m-d_H-i-s') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Add headers
    $headers = array_keys($data[0]);
    fputcsv($output, $headers);

    // Add data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
}

function exportPDF($data, $reportType) {
    // For now, we'll create a simple HTML format that can be printed as PDF
    $reportName = getReportName($reportType);
    $filename = str_replace(' ', '_', $reportName) . '_' . date('Y-m-d_H-i-s') . '.html';

    $html = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h2 { border-bottom: 2px solid #333; padding-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background-color: #333; color: white; padding: 10px; text-align: left; }
            td { padding: 8px; border-bottom: 1px solid #ddd; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .footer { margin-top: 30px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <h2>" . htmlspecialchars($reportName) . "</h2>
        <p><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</p>
    ";

    if (!empty($data)) {
        $html .= "<table>";
        $html .= "<tr style='background-color: #333;'>";
        foreach (array_keys($data[0]) as $header) {
            $html .= "<th>" . htmlspecialchars(str_replace('_', ' ', $header)) . "</th>";
        }
        $html .= "</tr>";
        
        foreach ($data as $row) {
            $html .= "<tr>";
            foreach ($row as $cell) {
                $html .= "<td>" . htmlspecialchars($cell) . "</td>";
            }
            $html .= "</tr>";
        }
        $html .= "</table>";
    }

    $html .= "
        <div class='footer'>
            <p>This report was automatically generated by FPIAP-SMARTs System</p>
        </div>
    </body>
    </html>
    ";

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $html;
}

function getReportName($type) {
    $names = [
        '1_site_summary' => 'Site Summary Report',
        '3_isp_performance' => 'ISP Performance Report',
        '5_project_report' => 'Project Report',
        '7_aging_tickets' => 'Aging Tickets Report',
        '8_monthly_activity' => 'Monthly Activity Report'
    ];
    return $names[$type] ?? 'Report';
}
?>
<?php $activePage = 'reports'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>

<div class="container-fluid mt-4 mb-4">
  <!-- Report Selection -->
  <div class="row mb-4">
    <div class="col-12">
      <h3><i class="bi bi-file-earmark-pdf"></i> Report Generator</h3>
      <p class="text-muted">Select a report type to generate and export data</p>
    </div>
  </div>

  <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-3 mb-5" id="reportGrid">
    <!-- Reports will be dynamically loaded here -->
  </div>

  


  <!-- Filters Section (repurposed dashboard advanced filters) -->
  <div id="filterSection" class="card filter-card mb-4 shadow-sm border-0">
    <div class="card-header bg-gradient" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
      <h5 class="mb-0">
        <button class="btn btn-link p-0 text-decoration-none fw-bold text-white" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="true" aria-controls="filterCollapse">
          <i class="bi bi-funnel-fill me-2"></i>Advanced Filters
          <i class="bi bi-chevron-down float-end"></i>
        </button>
      </h5>
    </div>
    <div id="filterCollapse" class="collapse show">
      <div class="card-body" style="background-color: #f8f9ff;">
        <!-- Active Filter Indicators -->
        <div id="activeFilters" class="d-flex flex-wrap gap-2 mb-3" style="display: none;"></div>

        <div class="row g-3">
          <!-- Status -->
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-circle-fill text-danger me-1"></i>Status
            </label>
            <div class="dropdown">
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                <span id="selectedStatus">All Status</span>
                <span class="badge bg-primary ms-2" id="statusCount" style="display: none;">0</span>
              </button>
              <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
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
                <div id="statusOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
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
          <!-- Site -->
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-geo-alt-fill text-primary me-1"></i>Site
            </label>
            <div class="dropdown">
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                <span id="selectedSite">All Sites</span>
                <span class="badge bg-primary ms-2" id="siteCount" style="display: none;">0</span>
              </button>
              <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
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
                <div id="siteOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
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
          <!-- Created By -->
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-person-fill text-info me-1"></i>Created By
            </label>
            <div class="dropdown">
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                <span id="selectedCreatedBy">All Users</span>
                <span class="badge bg-primary ms-2" id="createdByCount" style="display: none;">0</span>
              </button>
              <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
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
                <div id="createdByOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
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
          <!-- ISP -->
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-wifi text-warning me-1"></i>ISP
            </label>
            <div class="dropdown">
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                <span id="selectedISP">All ISPs</span>
                <span class="badge bg-primary ms-2" id="ispCount" style="display: none;">0</span>
              </button>
              <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
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
                <div id="ispOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center">
                  <small class="text-muted" id="ispSelectionCount">0 selected</small>
                  <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-primary" onclick="applyMultiSelect('isp')">Apply</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('isp')">Cancel</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- Province -->
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-map text-success me-1"></i>Province
            </label>
            <div class="dropdown">
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                <span id="selectedProvince">All Provinces</span>
                <span class="badge bg-primary ms-2" id="provinceCount" style="display: none;">0</span>
              </button>
              <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
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
                <div id="provinceOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center">
                  <small class="text-muted" id="provinceSelectionCount">0 selected</small>
                  <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-primary" onclick="applyMultiSelect('province')">Apply</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('province')">Cancel</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- Municipality -->
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-building text-secondary me-1"></i>Municipality
            </label>
            <div class="dropdown">
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                <span id="selectedMunicipality">All Municipalities</span>
                <span class="badge bg-primary ms-2" id="municipalityCount" style="display: none;">0</span>
              </button>
              <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
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
                <div id="municipalityOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center">
                  <small class="text-muted" id="municipalitySelectionCount">0 selected</small>
                  <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-primary" onclick="applyMultiSelect('municipality')">Apply</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('municipality')">Cancel</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- Project -->
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-folder-fill text-primary me-1"></i>Project
            </label>
            <div class="dropdown">
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                <span id="selectedProject">All Projects</span>
                <span class="badge bg-primary ms-2" id="projectCount" style="display: none;">0</span>
              </button>
              <div class="dropdown-menu p-3" style="min-width: 320px; max-height: auto;">
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
                <div id="projectOptions" style="max-height: 200px; overflow-y: auto; flex: 1;"></div>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center">
                  <small class="text-muted" id="projectSelectionCount">0 selected</small>
                  <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-primary" onclick="applyMultiSelect('project')">Apply</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('project')">Cancel</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="row g-3 mt-2">
          <div class="col-lg-3 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-calendar-date text-primary me-1"></i>Date Range
            </label>
            <div class="input-group">
              <input type="date" class="form-control form-control-sm" id="filterDateFrom">
              <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-calendar3"></i>
              </button>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" onclick="setDateRange('today')">Today</a></li>
                <li><a class="dropdown-item" onclick="setDateRange('yesterday')">Yesterday</a></li>
                <li><a class="dropdown-item" onclick="setDateRange('last7days')">Last 7 Days</a></li>
                <li><a class="dropdown-item" onclick="setDateRange('last30days')">Last 30 Days</a></li>
                <li><a class="dropdown-item" onclick="setDateRange('thisMonth')">This Month</a></li>
                <li><a class="dropdown-item" onclick="setDateRange('lastMonth')">Last Month</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" onclick="clearDateRange()">Clear Dates</a></li>
              </ul>
            </div>
          </div>
          <div class="col-lg-3 col-md-4 col-sm-6 d-flex align-items-end">
            <input type="date" id="filterDateTo" class="form-control form-control-sm">
          </div>
          <div class="col-lg-6 col-md-4 col-sm-12 d-flex align-items-end gap-2">
            <button class="btn btn-sm shadow-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;" onclick="applyFilters()">
              <i class="bi bi-check-circle-fill me-1"></i>Apply Filters
            </button>
            <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="resetFilters()">
              <i class="bi bi-arrow-clockwise me-1"></i>Reset
            </button>
            <div class="ms-auto">
              <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>Filters apply to all report data
              </small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Report Display -->
  <div id="reportSection" class="d-none">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 id="reportTitle">Report</h4>
      <div id="exportButtons"></div>
    </div>

    <div class="spinner-border loading-spinner d-none" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>

    <div class="table-responsive" id="reportTableContainer" style="max-height:600px; overflow-y:auto; display: none;">
      <table class="table table-striped table-hover" id="reportTable">
        <thead class="table-dark"></thead>
        <tbody></tbody>
      </table>
    </div>

    <div id="reportMessage" class="alert alert-info d-none"></div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
  const REPORTS = [
    { id: '1_site_summary', name: 'Site Summary', icon: 'building', description: 'Overview of all sites with key metrics' },
    { id: '3_isp_performance', name: 'ISP Performance', icon: 'speedometer2', description: 'ISP metrics and analysis' },
    { id: '5_project_report', name: 'Project Report', icon: 'folder', description: 'Project-based analysis and metrics' },
    { id: '7_aging_tickets', name: 'Aging Tickets', icon: 'exclamation-circle', description: 'Long-standing unresolved issues' },
    { id: '8_monthly_activity', name: 'Monthly Activity', icon: 'calendar-month', description: 'Time-based trends and analysis' }
  ];

  let currentReport = null;
  let currentReportData = null;
  let currentFilters = {};
  
  // Sort state management
  let currentSortBy = null;
  let currentSortDirection = 'ASC';

  // generic helper for POST requests returning JSON (used by filters)
  function postRequest(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: Object.entries(data).map(([k,v]) => encodeURIComponent(k) + '=' + encodeURIComponent(typeof v === 'object' ? JSON.stringify(v) : v)).join('&')
    })
    .then(resp => {
      if (!resp.ok) throw new Error('Network response was not ok');
      return resp.json();
    });
  }


  // Initialize report grid
  function initReportGrid() {
    const grid = document.getElementById('reportGrid');
    REPORTS.forEach(report => {
      const card = document.createElement('div');
      card.className = 'col';
      card.dataset.reportId = report.id; // store id for later lookup
      card.innerHTML = `
        <div class="card report-card text-white bg-primary border-0" style="cursor: pointer;" onclick="selectReport('${report.id}')">
          <div class="card-body text-center">
            <i class="bi bi-${report.icon}" style="font-size: 2rem; margin-bottom: 10px;"></i>
            <h5 class="card-title">${report.name}</h5>
            <p class="card-text small">${report.description}</p>
          </div>
        </div>
      `;
      grid.appendChild(card);
    });
  }

  // Select and generate report
  async function selectReport(reportId) {
    currentReport = REPORTS.find(r => r.id === reportId);

    // Reset sort state when switching reports
    currentSortBy = null;
    currentSortDirection = 'asc';
    updateSortIndicators();

    // clear previous highlight (active class + border)
    document.querySelectorAll('#reportGrid .report-card.active').forEach(el => {
      el.classList.remove('active');
      el.classList.remove('border', 'border-warning', 'border-2');
      el.classList.add('border-0'); // restore original no-border state
    });

    const newCard = document.querySelector(`#reportGrid .col[data-report-id="${reportId}"] .report-card`);
    if (newCard) {
      // add visible border to indicate selection
      newCard.classList.remove('border-0');
      newCard.classList.add('active', 'border', 'border-warning', 'border-5');
    }
    
    // Show loading
    document.getElementById('reportSection').classList.remove('d-none');
    document.querySelector('.loading-spinner').style.display = 'block';
    document.getElementById('reportTableContainer').style.display = 'none';
    document.getElementById('reportTitle').textContent = currentReport.name + ' Report';

    // Show filter section when user picks any report
    showFiltersForReport(reportId);
    // refresh filter object with any existing selections
    currentFilters = getFilters();

    // Generate report with current filters
    await generateReport(reportId, currentFilters);
  }

  async function generateReport(reportId, filters) {
    try {
      const params = new URLSearchParams({
        action: 'generate_report',
        report_type: reportId,
        filters: JSON.stringify(filters),
        sort_by: currentSortBy || '',
        sort_direction: currentSortDirection || 'ASC',
        csrf_token: '<?php echo htmlspecialchars(generateCSRFToken()); ?>'
      });
      
      const response = await fetch('site_report.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
      });

      const result = await response.json();

      if (result.error) {
        showError(result.error);
      } else {
        currentReportData = result.data;
        displayReport(result.data, currentReport.name);
        updateSortIndicators();
        updateExportButtons(reportId);
      }
    } catch (error) {
      showError('Error generating report: ' + error.message);
    }

    document.querySelector('.loading-spinner').style.display = 'none';
  }

  function displayReport(data, reportName) {
    if (!data || data.length === 0) {
      document.getElementById('reportMessage').textContent = 'No data available for this report.';
      document.getElementById('reportMessage').classList.remove('d-none');
      document.getElementById('reportTableContainer').style.display = 'none';
      return;
    }

    document.getElementById('reportMessage').classList.add('d-none');

    const table = document.getElementById('reportTable');
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');

    thead.innerHTML = '';
    tbody.innerHTML = '';

    // Headers
    const headers = Object.keys(data[0]);
    const headerRow = document.createElement('tr');
    headers.forEach(header => {
      const th = document.createElement('th');
      // Bootstrap utility classes: position-sticky, top-0, bg-dark
      th.classList.add('position-sticky', 'top-0', 'bg-dark');
      
      // Make header clickable
      th.style.cursor = 'pointer';
      th.addEventListener('click', () => {
        handleHeaderSort(header);
      });
      
      // Add icon container for sort indicator
      const headerContent = document.createElement('span');
      headerContent.textContent = formatHeader(header);
      headerContent.className = 'header-content';
      th.appendChild(headerContent);
      
      const sortIcon = document.createElement('span');
      // Using Bootstrap utility classes: ms-2 (margin-start) and d-inline-block
      sortIcon.className = 'sort-icon ms-2 d-inline-block';
      sortIcon.id = `sort-icon-${header}`;
      th.appendChild(sortIcon);
      
      headerRow.appendChild(th);
    });
    thead.appendChild(headerRow);

    // Data rows
    data.forEach(row => {
      const tr = document.createElement('tr');
      headers.forEach(header => {
        const td = document.createElement('td');
        const value = row[header];
        let displayValue = formatValue(value);
        // Add percentage sign for specific columns
        if ((header === 'open_ticket_site_rate' || header === 'success_rate_percent') && displayValue !== 'N/A') {
          displayValue += '%';
        }
        td.textContent = displayValue;
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });

    document.getElementById('reportTableContainer').style.display = 'block';
  }

  function formatHeader(header) {
    if (header === 'ap_site_code') return 'AP Site Code';
    return header
      .replace(/_/g, ' ')
      .replace(/([A-Z])/g, ' $1')
      .trim()
      .split(' ')
      .map(w => w.charAt(0).toUpperCase() + w.slice(1))
      .join(' ');
  }

  function formatValue(value) {
    if (value === null || value === undefined || value === '') return 'N/A';
    if (typeof value === 'number') return value.toFixed(2);
    return String(value);
  }

  // Sort handling functions
  function handleHeaderSort(columnName) {
    // Toggle direction if clicking same column
    if (currentSortBy === columnName) {
      currentSortDirection = currentSortDirection === 'ASC' ? 'DESC' : 'ASC';
    } else {
      currentSortBy = columnName;
      currentSortDirection = 'ASC';
    }
    
    // Re-generate report with new sort params
    generateReport(currentReport.id, currentFilters);
  }

  function updateSortIndicators() {
    const headers = document.querySelectorAll('thead th');
    
    headers.forEach((th, index) => {
      const sortIcon = th.querySelector('.sort-icon');
      
      if (!sortIcon) return;
      
      // Clear all icons
      sortIcon.innerHTML = '';
      // Using Bootstrap utility classes: ms-2 (margin-start) and d-inline-block
      sortIcon.className = 'sort-icon ms-2 d-inline-block';
      
      // Get the header name from the data
      if (currentReportData && currentReportData.length > 0) {
        const headerName = Object.keys(currentReportData[0])[index];
        
        // Only show icon on current sort column
        if (currentSortBy === headerName) {
          const icon = document.createElement('i');
          // Using Bootstrap Icons (bi) with Bootstrap utility classes
          icon.className = currentSortDirection === 'ASC' 
            ? 'bi bi-sort-up text-warning' 
            : 'bi bi-sort-down text-warning';
          sortIcon.appendChild(icon);
          
          // Visual highlight using Bootstrap utility classes
          th.classList.add('fw-bold', 'bg-secondary');
        } else {
          th.classList.remove('fw-bold', 'bg-secondary');
        }
      }
    });
  }

  // utility copied from dashboard.js – maps ticket status to badge CSS classes
  function getStatusBadgeClass(status) {
    const classes = {
      'OPEN': 'bg-danger',
      'IN_PROGRESS': 'bg-warning text-dark',
      'RESOLVED': 'bg-info',
      'CLOSED': 'bg-success'
    };
    return classes[status] || 'bg-secondary';
  }

  function showFiltersForReport(reportId) {
    const filterSection = document.getElementById('filterSection');
    // always show the filter card when a report is selected
    if (reportId) {
      filterSection.classList.remove('d-none');
    } else {
      filterSection.classList.add('d-none');
    }
  }

  // load site list into dropdown
  // old site-specific filter helpers removed - replaced by dashboard-style filtering

  function updateExportButtons(reportId) {
    const container = document.getElementById('exportButtons');
    container.innerHTML = `
      <button class="btn btn-sm btn-success me-2" onclick="exportReport('${reportId}', 'csv')">
        <i class="bi bi-download"></i> Download CSV
      </button>
      <button class="btn btn-sm btn-danger" onclick="exportReport('${reportId}', 'pdf')">
        <i class="bi bi-file-earmark-pdf"></i> Download PDF
      </button>
    `;
  }

  function exportReport(reportId, format) {
    const filters = currentFilters || {};
    const params = new URLSearchParams({
      action: 'export',
      type: reportId,
      format: format,
      filters: JSON.stringify(filters),
      sort_by: currentSortBy || '',
      sort_direction: currentSortDirection || 'ASC'
    });

    window.location.href = 'site_report.php?' + params.toString();
  }

  function showError(message) {
    document.getElementById('reportMessage').textContent = 'Error: ' + message;
    document.getElementById('reportMessage').classList.remove('d-none', 'alert-info');
    document.getElementById('reportMessage').classList.add('alert-danger');
  }

  /* ---------- Filtering utilities (copied from dashboard) ---------- */
  
  function loadFilterOptions() {
    postRequest('site_report.php', { action: 'get_filter_options' })
      .then(data => {
        populateStatusFilter(data.statuses);
        populateSiteFilter(data.sites);
        populateCreatedByFilter(data.personnel);
        populateISPFilter(data.isp);
        populateProvinceFilter(data.provinces);
        populateMunicipalityFilter(data.municipalities);
        populateProjectFilter(data.projects);
        updateSelectionCount('statusOptions');
        updateSelectionCount('siteOptions');
        updateSelectionCount('createdByOptions');
        updateSelectionCount('ispOptions');
        updateSelectionCount('provinceOptions');
        updateSelectionCount('municipalityOptions');
        updateSelectionCount('projectOptions');
      })
      .catch(err => console.error('Failed to load filter options:', err));
  }

  function populateStatusFilter(statuses) {
    const container = document.getElementById('statusOptions');
    container.innerHTML = '';
    statuses.forEach(status => {
      const badgeClass = getStatusBadgeClass(status);
      container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="status_${status}" value="${status}">
                <label class="form-check-label" for="status_${status}">
                    <span class="badge ${badgeClass} me-2">●</span>${status}
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
                    ${site.ap_site_code || site.id} - ${site.site_name}
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
                    ${person.fullname}
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
                    ${isp}
                </label>
            </div>
        `;
    });
    setupSearch('ispSearch', 'ispOptions');
    setupEventDelegation('ispOptions');
  }

  function populateProvinceFilter(provinces) {
    const container = document.getElementById('provinceOptions');
    container.innerHTML = '';
    provinces.forEach(province => {
      container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="province_${province.replace(/\s+/g, '_')}" value="${province}">
                <label class="form-check-label" for="province_${province.replace(/\s+/g, '_')}">
                    ${province}
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
                    ${municipality}
                </label>
            </div>
        `;
    });
    setupSearch('municipalitySearch', 'municipalityOptions');
    setupEventDelegation('municipalityOptions');
  }

  function populateProjectFilter(projects) {
    const container = document.getElementById('projectOptions');
    container.innerHTML = '';
    projects.forEach(project => {
      container.innerHTML += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="project_${project.replace(/\s+/g, '_')}" value="${project}">
                <label class="form-check-label" for="project_${project.replace(/\s+/g, '_')}">
                    ${project}
                </label>
            </div>
        `;
    });
    setupSearch('projectSearch', 'projectOptions');
    setupEventDelegation('projectOptions');
  }

  function setupSearch(searchId, containerId) {
    const searchInput = document.getElementById(searchId);
    const container = document.getElementById(containerId);
    const resultsDivId = containerId.replace('Options', 'SearchResults');
    const resultsDiv = document.getElementById(resultsDivId);
    let searchTimeout;

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
    checks.forEach(ch => {
      const text = ch.textContent.toLowerCase();
      if (text.includes(query)) {
        ch.style.display = '';
        visibleCount++;
      } else {
        ch.style.display = 'none';
      }
    });
    return visibleCount;
  }

  function updateSearchResults(resultsDiv, container, query, count) {
    if (!resultsDiv) return;
    if (query && count === 0) {
      resultsDiv.textContent = 'No results';
      resultsDiv.style.display = 'block';
    } else if (query) {
      resultsDiv.textContent = `${count} found`;
      resultsDiv.style.display = 'block';
    } else {
      resultsDiv.style.display = 'none';
    }
  }

  function setupEventDelegation(containerId) {
    const container = document.getElementById(containerId);
    container.addEventListener('click', function(e) {
      if (e.target.tagName === 'INPUT' && e.target.type === 'checkbox') {
        updateSelectionCount(containerId);
      }
    });
  }

  function updateSelectionCount(containerId) {
    const container = document.getElementById(containerId);
    const countBadge = document.getElementById(containerId.replace('Options', 'Count'));
    if (!container || !countBadge) return;
    const selected = container.querySelectorAll('input[type=checkbox]:checked').length;
    countBadge.textContent = selected;
    countBadge.style.display = selected > 0 ? 'inline-block' : 'none';

    const labelMap = {
      statusOptions: 'selectedStatus',
      siteOptions: 'selectedSite',
      createdByOptions: 'selectedCreatedBy',
      ispOptions: 'selectedISP',
      provinceOptions: 'selectedProvince',
      municipalityOptions: 'selectedMunicipality',
      projectOptions: 'selectedProject'
    };
    const defaultLabels = {
      statusOptions: 'All Status',
      siteOptions: 'All Sites',
      createdByOptions: 'All Users',
      ispOptions: 'All ISPs',
      provinceOptions: 'All Provinces',
      municipalityOptions: 'All Municipalities',
      projectOptions: 'All Projects'
    };
    const labelId = labelMap[containerId];
    const label = labelId ? document.getElementById(labelId) : null;
    if (label) {
      label.textContent = selected === 0 ? defaultLabels[containerId] : `${selected} selected`;
    }
  }

  function applyMultiSelect(type) {
    const dropdown = event.target.closest('.dropdown');
    const bs = bootstrap.Dropdown.getInstance(dropdown.querySelector('.dropdown-toggle'));
    if (bs) bs.hide();
    updateActiveFilters();
    applyFilters();
  }

  function clearMultiSelect(type) {
    const options = document.getElementById(`${type}Options`);
    if (options) {
      options.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
    }
    updateSelectionCount(`${type}Options`);
    updateActiveFilters();
  }

  function invertSelection(containerId) {
    const opts = document.getElementById(containerId);
    opts.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = !cb.checked);
    updateSelectionCount(containerId);
    updateActiveFilters();
  }

  function selectAllVisible(containerId) {
    const opts = document.getElementById(containerId);
    opts.querySelectorAll('.form-check:not([style*="display: none"]) input[type=checkbox]').forEach(cb => cb.checked = true);
    updateSelectionCount(containerId);
  }

  function selectNone(containerId) {
    const opts = document.getElementById(containerId);
    opts.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
    updateSelectionCount(containerId);
  }

  function getFilters() {
    const filters = {};
    const statusSelected = getSelectedValues('statusOptions');
    if (statusSelected.length > 0) filters.status = statusSelected;
    const siteSelected = getSelectedValues('siteOptions');
    if (siteSelected.length > 0) filters.site_id = siteSelected;
    const createdBySelected = getSelectedValues('createdByOptions');
    if (createdBySelected.length > 0) filters.created_by = createdBySelected;
    const ispSelected = getSelectedValues('ispOptions');
    if (ispSelected.length > 0) filters.isp = ispSelected;
    const provinceSelected = getSelectedValues('provinceOptions');
    if (provinceSelected.length > 0) filters.province = provinceSelected;
    const municipalitySelected = getSelectedValues('municipalityOptions');
    if (municipalitySelected.length > 0) filters.municipality = municipalitySelected;
    const projectSelected = getSelectedValues('projectOptions');
    if (projectSelected.length > 0) filters.project_name = projectSelected;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    if (dateFrom) filters.date_from = dateFrom;
    if (dateTo) filters.date_to = dateTo;
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
    if (filters.status && filters.status.length > 0) {
      activeContainer.innerHTML += `
            <span class="badge bg-primary d-flex align-items-center gap-1">
                Status: ${filters.status.join(', ')}
                <button class="btn-close btn-close-white" onclick="removeFilter('status')" style="font-size: 0.6rem;"></button>
            </span>
        `;
      hasFilters = true;
    }
    if (filters.site_id && filters.site_id.length > 0) {
      activeContainer.innerHTML += `
            <span class="badge bg-info d-flex align-items-center gap-1">
                Sites: ${filters.site_id.length} selected
                <button class="btn-close btn-close-white" onclick="removeFilter('site_id')" style="font-size: 0.6rem;"></button>
            </span>
        `;
      hasFilters = true;
    }
    if (filters.created_by && filters.created_by.length > 0) {
      activeContainer.innerHTML += `
            <span class="badge bg-success d-flex align-items-center gap-1">
                Users: ${filters.created_by.length} selected
                <button class="btn-close btn-close-white" onclick="removeFilter('created_by')" style="font-size: 0.6rem;"></button>
            </span>
        `;
      hasFilters = true;
    }
    if (filters.isp && filters.isp.length > 0) {
      activeContainer.innerHTML += `
            <span class="badge bg-warning d-flex align-items-center gap-1">
                ISP: ${filters.isp.join(', ')}
                <button class="btn-close btn-close-white" onclick="removeFilter('isp')" style="font-size: 0.6rem;"></button>
            </span>
        `;
      hasFilters = true;
    }
    if (filters.province && filters.province.length > 0) {
      activeContainer.innerHTML += `
            <span class="badge bg-success d-flex align-items-center gap-1">
                Province: ${filters.province.join(', ')}
                <button class="btn-close btn-close-white" onclick="removeFilter('province')" style="font-size: 0.6rem;"></button>
            </span>
        `;
      hasFilters = true;
    }
    if (filters.municipality && filters.municipality.length > 0) {
      activeContainer.innerHTML += `
            <span class="badge bg-secondary d-flex align-items-center gap-1">
                Municipality: ${filters.municipality.join(', ')}
                <button class="btn-close btn-close-white" onclick="removeFilter('municipality')" style="font-size: 0.6rem;"></button>
            </span>
        `;
      hasFilters = true;
    }
    if (filters.project_name && filters.project_name.length > 0) {
      activeContainer.innerHTML += `
            <span class="badge bg-info d-flex align-items-center gap-1">
                Project: ${filters.project_name.join(', ')}
                <button class="btn-close btn-close-white" onclick="removeFilter('project_name')" style="font-size: 0.6rem;"></button>
            </span>
        `;
      hasFilters = true;
    }
    if (filters.date_from || filters.date_to) {
      activeContainer.innerHTML += `
            <span class="badge bg-dark d-flex align-items-center gap-1">
                Date: ${filters.date_from || '...'} - ${filters.date_to || '...'}
                <button class="btn-close btn-close-white" onclick="removeFilter('date')" style="font-size: 0.6rem;"></button>
            </span>
        `;
      hasFilters = true;
    }
    activeContainer.style.display = hasFilters ? 'flex' : 'none';
  }

  function removeFilter(type) {
    switch (type) {
      case 'status': clearMultiSelect('status'); break;
      case 'site_id': clearMultiSelect('site'); break;
      case 'created_by': clearMultiSelect('createdBy'); break;
      case 'isp': clearMultiSelect('isp'); break;
      case 'province': clearMultiSelect('province'); break;
      case 'municipality': clearMultiSelect('municipality'); break;
      case 'project_name': clearMultiSelect('project'); break;
      case 'date':
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        break;
    }
    updateActiveFilters();
    applyFilters();
  }

  function applyFilters() {
    currentFilters = getFilters();
    if (currentReport) {
      generateReport(currentReport.id, currentFilters);
    }
  }

  function resetFilters() {
    ['status', 'site', 'createdBy', 'isp', 'province', 'municipality', 'project'].forEach(type => clearMultiSelect(type));
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    currentFilters = {};
    updateActiveFilters();
    applyFilters();
  }

  function setDateRange(range) {
    const now = new Date();
    const to = document.getElementById('filterDateTo');
    const from = document.getElementById('filterDateFrom');
    switch (range) {
      case 'today':
        from.value = to.value = now.toISOString().split('T')[0];
        break;
      case 'yesterday':
        const y = new Date(now);
        y.setDate(y.getDate() - 1);
        from.value = to.value = y.toISOString().split('T')[0];
        break;
      case 'last7days':
        const l7 = new Date(now);
        l7.setDate(l7.getDate() - 6);
        from.value = l7.toISOString().split('T')[0];
        to.value = now.toISOString().split('T')[0];
        break;
      case 'last30days':
        const l30 = new Date(now);
        l30.setDate(l30.getDate() - 29);
        from.value = l30.toISOString().split('T')[0];
        to.value = now.toISOString().split('T')[0];
        break;
      case 'thisMonth':
        from.value = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-01';
        to.value = now.toISOString().split('T')[0];
        break;
      case 'lastMonth':
        const lm = new Date(now.getFullYear(), now.getMonth()-1, 1);
        const lmEnd = new Date(now.getFullYear(), now.getMonth(), 0);
        from.value = lm.toISOString().split('T')[0];
        to.value = lmEnd.toISOString().split('T')[0];
        break;
    }
  }

  function clearDateRange() {
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
  }

  /* end filtering utilities */

  // toggle/filter menu helper (same as dashboard)
  function toggleFilterMenu(e, col) {
    e.stopPropagation();
    const menu = document.getElementById('filterMenu_' + col);
    // close others
    document.querySelectorAll('[id^="filterMenu_"]').forEach(m => {
      if (m.id !== 'filterMenu_' + col) {
        m.style.display = 'none';
      }
    });
    // toggle current
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
  }

  // click anywhere hides menus
  document.addEventListener('click', function() {
    document.querySelectorAll('[id^="filterMenu_"]').forEach(m => m.style.display = 'none');
  });

  // Initialize on page load
  document.addEventListener('DOMContentLoaded', function() {
    initReportGrid();
    loadFilterOptions();
    // apply any filters (empty at start)
    applyFilters();
  });
</script>

</body>
</html>