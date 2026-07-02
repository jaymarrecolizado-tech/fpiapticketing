<?php
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/security_headers.php';

requireAdmin();

function buildWhereClause($filters, &$params = []) {
    $clauses = [];
    if (!empty($filters['status'])) {
        if (is_array($filters['status'])) {
            $placeholders = implode(',', array_fill(0, count($filters['status']), '?'));
            $clauses[] = "t.status IN ($placeholders)";
            $params = array_merge($params, $filters['status']);
        } else {
            $clauses[] = "t.status = ?";
            $params[] = $filters['status'];
        }
    }
    if (!empty($filters['date_from'])) {
        $clauses[] = "DATE(t.created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $clauses[] = "DATE(t.created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    if (!empty($filters['site_id'])) {
        if (is_array($filters['site_id'])) {
            $placeholders = implode(',', array_fill(0, count($filters['site_id']), '?'));
            $clauses[] = "t.site_id IN ($placeholders)";
            $params = array_merge($params, array_map('intval', $filters['site_id']));
        } else {
            $clauses[] = "t.site_id = ?";
            $params[] = intval($filters['site_id']);
        }
    }
    if (!empty($filters['isp'])) {
        if (is_array($filters['isp'])) {
            $placeholders = implode(',', array_fill(0, count($filters['isp']), '?'));
            $clauses[] = "s.isp IN ($placeholders)";
            $params = array_merge($params, $filters['isp']);
        } else {
            $clauses[] = "s.isp = ?";
            $params[] = $filters['isp'];
        }
    }
    if (!empty($filters['province'])) {
        if (is_array($filters['province'])) {
            $placeholders = implode(',', array_fill(0, count($filters['province']), '?'));
            $clauses[] = "s.province IN ($placeholders)";
            $params = array_merge($params, $filters['province']);
        } else {
            $clauses[] = "s.province = ?";
            $params[] = $filters['province'];
        }
    }
    if (!empty($filters['municipality'])) {
        if (is_array($filters['municipality'])) {
            $placeholders = implode(',', array_fill(0, count($filters['municipality']), '?'));
            $clauses[] = "s.municipality IN ($placeholders)";
            $params = array_merge($params, $filters['municipality']);
        } else {
            $clauses[] = "s.municipality = ?";
            $params[] = $filters['municipality'];
        }
    }
    if (!empty($filters['created_by'])) {
        if (is_array($filters['created_by'])) {
            $placeholders = implode(',', array_fill(0, count($filters['created_by']), '?'));
            $clauses[] = "p.fullname IN ($placeholders)";
            $params = array_merge($params, $filters['created_by']);
        } else {
            $clauses[] = "p.fullname = ?";
            $params[] = $filters['created_by'];
        }
    }
    if (!empty($filters['project_name'])) {
        if (is_array($filters['project_name'])) {
            $placeholders = implode(',', array_fill(0, count($filters['project_name']), '?'));
            $clauses[] = "s.project_name IN ($placeholders)";
            $params = array_merge($params, $filters['project_name']);
        } else {
            $clauses[] = "s.project_name = ?";
            $params[] = $filters['project_name'];
        }
    }

    if (count($clauses) > 0) {
        return ' AND ' . implode(' AND ', $clauses);
    }
    return '';
}

function getCreatedByReport($pdo, $filters = []) {
    $params = [];
    $whereClause = buildWhereClause($filters, $params);

    $query = "
        SELECT
            COALESCE(p.fullname, 'Unknown') AS created_by_name,
            COUNT(t.id) AS total_tickets,
            SUM(CASE WHEN t.status = 'OPEN' THEN 1 ELSE 0 END) AS open_tickets,
            SUM(CASE WHEN t.status = 'IN_PROGRESS' THEN 1 ELSE 0 END) AS in_progress_tickets,
            SUM(CASE WHEN t.status = 'RESOLVED' THEN 1 ELSE 0 END) AS resolved_tickets,
            SUM(CASE WHEN t.status = 'CLOSED' THEN 1 ELSE 0 END) AS closed_tickets,
            ROUND(AVG(DATEDIFF(CURDATE(), t.created_at)), 2) AS avg_resolution_days,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) AS resolved_closed_aging_tickets,
            SUM(CASE WHEN t.duration IS NOT NULL AND t.duration >= 3 * 1440 AND t.status IN ('OPEN', 'IN_PROGRESS') THEN 1 ELSE 0 END) AS unresolved_aging_tickets
        FROM personnels p
        LEFT JOIN tickets t ON p.id = t.created_by
        LEFT JOIN sites s ON t.site_id = s.id
        WHERE 1=1" . $whereClause . "
        GROUP BY p.id
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getResolutionTimeAnalysisReport($pdo, $filters = []) {
    $params = [];
    $whereClause = buildWhereClause($filters, $params);

    $query = "
        SELECT
            CASE
                WHEN t.duration < 1440 THEN 'Less Than 1 Day'
                WHEN t.duration <= 3 * 1440 THEN '1 - 3 Days'
                WHEN t.duration <= 7 * 1440 THEN '3 - 7 Days'
                WHEN t.duration <= 14 * 1440 THEN '1 - 2 Weeks'
                ELSE 'Over 2 Weeks'
            END as resolution_category,
            COUNT(*) as ticket_count,
            ROUND(AVG(t.duration), 2) as avg_duration_minutes,
            ROUND(AVG(t.duration) / 1440, 2) as avg_duration_days,
            MIN(t.duration) as min_duration,
            MAX(t.duration) as max_duration
        FROM tickets t
        LEFT JOIN sites s ON t.site_id = s.id
        WHERE t.status IN ('RESOLVED', 'CLOSED')
        AND t.duration IS NOT NULL" . $whereClause . "
        GROUP BY
            CASE
                WHEN t.duration < 1440 THEN 'Less Than 1 Day'
                WHEN t.duration <= 3 * 1440 THEN '1 - 3 Days'
                WHEN t.duration <= 7 * 1440 THEN '3 - 7 Days'
                WHEN t.duration <= 14 * 1440 THEN '1 - 2 Weeks'
                ELSE 'Over 2 Weeks'
            END
        ORDER BY avg_duration_minutes
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getGeographicDistributionReport($pdo, $filters = []) {
    $params = [];
    $whereClause = buildWhereClause($filters, $params);

    $query = "
        SELECT
            s.province,
            s.municipality,
            COUNT(t.id) as total_tickets,
            COUNT(DISTINCT s.id) as sites_count,
            SUM(CASE WHEN t.status IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) as resolved_tickets,
            ROUND(
                CASE
                    WHEN SUM(CASE WHEN t.status IN ('RESOLVED', 'CLOSED') AND t.duration IS NOT NULL THEN 1 END) > 0
                    THEN SUM(CASE WHEN t.status IN ('RESOLVED', 'CLOSED') AND t.duration IS NOT NULL THEN t.duration END) / SUM(CASE WHEN t.status IN ('RESOLVED', 'CLOSED') AND t.duration IS NOT NULL THEN 1 END) / 1440
                    ELSE NULL
                END,
                2
            ) as avg_resolution_time_days
        FROM sites s
        LEFT JOIN tickets t ON s.id = t.site_id
        WHERE s.province IS NOT NULL AND s.province != ''" . $whereClause . "
        GROUP BY s.province, s.municipality
        ORDER BY s.province, s.municipality
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCompletionRateReport($pdo, $filters = []) {
    $params = [];
    $whereClause = buildWhereClause($filters, $params);

    $query = "
        SELECT
            DATE_FORMAT(t.created_at, '%Y-%m') as month_year,
            COUNT(*) as total_created,
            SUM(CASE WHEN t.status IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) as total_resolved,
            ROUND(
                SUM(CASE WHEN t.status IN ('RESOLVED', 'CLOSED') THEN 1 ELSE 0 END) * 100.0 / COUNT(*),
                2
            ) as completion_rate,
            ROUND(AVG(CASE WHEN t.status IN ('RESOLVED', 'CLOSED') THEN t.duration END), 2) as avg_resolution_time
        FROM tickets t
        LEFT JOIN sites s ON t.site_id = s.id
        WHERE 1=1" . $whereClause . "
        GROUP BY DATE_FORMAT(t.created_at, '%Y-%m')
        ORDER BY month_year DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($action === 'generate_report') {
    header('Content-Type: application/json');
    try {
        $reportType = $_POST['report_type'] ?? '2_created_by';
        $filters = json_decode($_POST['filters'] ?? '{}', true);
        $sortBy = $_POST['sort_by'] ?? '';
        $sortDirection = strtoupper($_POST['sort_direction'] ?? 'ASC');

        if ($reportType === '8_resolution_time_analysis') {
            $data = getResolutionTimeAnalysisReport($pdo, $filters);
            $allowedColumns = ['resolution_category', 'ticket_count', 'avg_duration_minutes', 'avg_duration_days', 'min_duration', 'max_duration'];
        } elseif ($reportType === '9_geographic_distribution') {
            $data = getGeographicDistributionReport($pdo, $filters);
            $allowedColumns = ['province', 'municipality', 'total_tickets', 'sites_count', 'resolved_tickets', 'avg_resolution_time_days'];
        } elseif ($reportType === '11_completion_rate') {
            $data = getCompletionRateReport($pdo, $filters);
            $allowedColumns = ['month_year', 'total_created', 'total_resolved', 'completion_rate', 'avg_resolution_time'];
        } else {
            $data = getCreatedByReport($pdo, $filters);
            $allowedColumns = ['created_by_name', 'total_tickets', 'open_tickets', 'in_progress_tickets', 'resolved_tickets', 'closed_tickets', 'avg_resolution_days', 'resolved_closed_aging_tickets', 'unresolved_aging_tickets'];
        }

        if (!empty($sortBy) && in_array($sortBy, $allowedColumns)) {
            usort($data, function($a, $b) use ($sortBy, $sortDirection) {
                $aVal = $a[$sortBy] ?? '';
                $bVal = $b[$sortBy] ?? '';
                if (is_numeric($aVal) && is_numeric($bVal)) {
                    $cmp = $aVal <=> $bVal;
                } else {
                    $cmp = strcasecmp((string)$aVal, (string)$bVal);
                }
                return $sortDirection === 'DESC' ? -$cmp : $cmp;
            });
        }

        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'export_csv') {
    $filters = json_decode($_GET['filters'] ?? '{}', true);
    $sortBy = $_GET['sort_by'] ?? '';
    $sortDirection = strtoupper($_GET['sort_direction'] ?? 'ASC');
    $reportType = $_GET['report_type'] ?? '2_created_by';
    $format = $_GET['format'] ?? 'csv'; // csv or pdf

    if ($reportType === '8_resolution_time_analysis') {
        $data = getResolutionTimeAnalysisReport($pdo, $filters);
        $allowedColumns = ['resolution_category', 'ticket_count', 'avg_duration_minutes', 'avg_duration_days', 'min_duration', 'max_duration'];
        $filename = 'resolution_time_analysis_report';
    } elseif ($reportType === '9_geographic_distribution') {
        $data = getGeographicDistributionReport($pdo, $filters);
        $allowedColumns = ['province', 'municipality', 'total_tickets', 'sites_count', 'resolved_tickets', 'avg_resolution_time_days'];
        $filename = 'geographic_distribution_report';
    } elseif ($reportType === '11_completion_rate') {
        $data = getCompletionRateReport($pdo, $filters);
        $allowedColumns = ['month_year', 'total_created', 'total_resolved', 'completion_rate', 'avg_resolution_time'];
        $filename = 'completion_rate_report';
    } else {
        $data = getCreatedByReport($pdo, $filters);
        $allowedColumns = ['created_by_name', 'total_tickets', 'open_tickets', 'in_progress_tickets', 'resolved_tickets', 'closed_tickets', 'avg_resolution_days', 'resolved_closed_aging_tickets', 'unresolved_aging_tickets'];
        $filename = 'created_by_report';
    }

    if (!empty($sortBy) && in_array($sortBy, $allowedColumns)) {
        usort($data, function($a, $b) use ($sortBy, $sortDirection) {
            $aVal = $a[$sortBy] ?? '';
            $bVal = $b[$sortBy] ?? '';
            if (is_numeric($aVal) && is_numeric($bVal)) {
                $cmp = $aVal <=> $bVal;
            } else {
                $cmp = strcasecmp((string)$aVal, (string)$bVal);
            }
            return $sortDirection === 'DESC' ? -$cmp : $cmp;
        });
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d_H-i-s') . '.csv"');

        $output = fopen('php://output', 'w');
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
    } elseif ($format === 'pdf') {
        exportPDF($data, $reportType);
    }
    exit;
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
        '2_created_by' => 'Created By Report',
        '8_resolution_time_analysis' => 'Resolution Time Analysis Report',
        '9_geographic_distribution' => 'Geographic Distribution Report',
        '11_completion_rate' => 'Completion Rate Report'
    ];
    return $names[$type] ?? 'Report';
}

if ($action === 'get_filter_options') {
    header('Content-Type: application/json');
    try {
        $statuses = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];

        $stmt = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name ASC");
        $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT DISTINCT fullname AS value FROM personnels ORDER BY fullname ASC");
        $createdBy = array_map(function($row) { return $row['value']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $pdo->query("SELECT DISTINCT isp AS value FROM sites WHERE isp IS NOT NULL AND isp != '' ORDER BY isp ASC");
        $isps = array_map(function($row) { return $row['value']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $pdo->query("SELECT DISTINCT province AS value FROM sites WHERE province IS NOT NULL AND province != '' ORDER BY province ASC");
        $provinces = array_map(function($row) { return $row['value']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $pdo->query("SELECT DISTINCT municipality AS value FROM sites WHERE municipality IS NOT NULL AND municipality != '' ORDER BY municipality ASC");
        $municipalities = array_map(function($row) { return $row['value']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stmt = $pdo->query("SELECT DISTINCT project_name AS value FROM sites WHERE project_name IS NOT NULL AND project_name != '' ORDER BY project_name ASC");
        $projects = array_map(function($row) { return $row['value']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode([ 'success' => true, 'status' => $statuses, 'sites' => $sites, 'created_by' => $createdBy, 'isp' => $isps, 'province' => $provinces, 'municipality' => $municipalities, 'project_name' => $projects ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

?>
<?php $activePage = 'reports'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>

<div class="container-fluid my-4">
  <div class="row" id="reportGrid"></div>

  <div id="filterCard" class="card mb-4 shadow-sm border-0">
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
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="statusDropdown" data-bs-toggle="dropdown">
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
          <!-- Project -->
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-folder-fill text-primary me-1"></i>Project
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
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('project')">Apply</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('project')">Cancel</button>
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
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="createdByDropdown" data-bs-toggle="dropdown">
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
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="ispDropdown" data-bs-toggle="dropdown">
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
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('isp')">Apply</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('isp')">Cancel</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- Province -->
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-geo-alt text-success me-1"></i>Province
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
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('province')">Apply</button>
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
              <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="municipalityDropdown" data-bs-toggle="dropdown">
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
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); applyMultiSelect('municipality')">Apply</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="clearMultiSelect('municipality')">Cancel</button>
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

  <div id="reportSection" class="d-none">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 id="reportTitle">Created By Report</h4>
      <div id="exportButtons" class="d-flex gap-2"></div>
    </div>

    <div class="spinner-border loading-spinner d-none" role="status"><span class="visually-hidden">Loading...</span></div>

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
<script>

  const REPORTS = [
    { id: '2_created_by', name: 'Created By', icon: 'person-check', description: 'Ticket creation metrics by personnel' },
    { id: '8_resolution_time_analysis', name: 'Resolution Time Analysis', icon: 'clock-history', description: 'Analysis of ticket resolution times' },
    { id: '9_geographic_distribution', name: 'Geographic Distribution', icon: 'geo-alt', description: 'Ticket distribution by province and municipality' },
    { id: '11_completion_rate', name: 'Completion Rate', icon: 'check-circle', description: 'Completion rates over time' }
  ];

  let currentReport = null;
  let currentReportData = null;
  let currentFilters = {};
  let currentSortBy = 'total_tickets';
  let currentSortDirection = 'DESC';

  function postRequest(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: Object.entries(data).map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(typeof v === 'object' ? JSON.stringify(v) : v)).join('&')
    }).then(async resp => {
      const text = await resp.text();
      if (!resp.ok) {
        throw new Error(`HTTP ${resp.status}: ${text}`);
      }
      try {
        return JSON.parse(text);
      } catch (e) {
        throw new Error(`Invalid JSON response: ${text}`);
      }
    });
  }

  function initReportGrid() {
    const grid = document.getElementById('reportGrid');
    REPORTS.forEach(report => {
      const card = document.createElement('div');
      card.className = 'col-lg-3 col-md-6 col-sm-12 mb-3';
      card.innerHTML = `
        <div class="card report-card text-white bg-primary border-0" style="cursor:pointer;" onclick="selectReport('${report.id}')">
          <div class="card-body text-center">
            <i class="bi bi-${report.icon}" style="font-size:2rem;"></i>
            <h5 class="card-title mt-2">${report.name}</h5>
            <p class="card-text small">${report.description}</p>
          </div>
        </div>
      `;
      grid.appendChild(card);
    });
  }

  function showFiltersForReport(reportId) {
    // Filters always shown for all reports
    document.getElementById('filterCard').classList.remove('d-none');
  }

  async function selectReport(reportId) {
    currentReport = REPORTS.find(r => r.id === reportId);
    // Set default sort column based on report type
    if (reportId === '8_resolution_time_analysis') {
      currentSortBy = 'avg_duration_minutes';
    } else if (reportId === '9_geographic_distribution') {
      currentSortBy = 'total_tickets';
    } else if (reportId === '11_completion_rate') {
      currentSortBy = 'month_year';
    } else {
      currentSortBy = 'total_tickets';
    }
    currentSortDirection = 'DESC';
    currentFilters = getFilters();

    document.querySelectorAll('#reportGrid .report-card').forEach(card => {
      card.classList.remove('active', 'border', 'border-warning', 'border-3', 'border-5');
      card.classList.add('border-0');
    });
    const selectedCard = document.querySelector(`#reportGrid .report-card[onclick*="${reportId}"]`);
    if (selectedCard) {
      selectedCard.classList.remove('border-0');
      selectedCard.classList.add('active', 'border', 'border-warning', 'border-5');
    }

    document.getElementById('reportSection').classList.remove('d-none');
    document.querySelector('.loading-spinner').classList.remove('d-none');
    document.getElementById('reportTableContainer').style.display = 'none';
    document.getElementById('reportTitle').textContent = currentReport.name + ' Report';

    showFiltersForReport(reportId);
    await generateReport(reportId, currentFilters);
  }

  async function generateReport(reportId, filters) {
    try {
      const response = await postRequest('ticket_report.php', {
        action: 'generate_report',
        report_type: reportId,
        filters: filters,
        sort_by: currentSortBy,
        sort_direction: currentSortDirection
      });

      if (response.error) {
        document.getElementById('reportMessage').textContent = 'Error: ' + response.error;
        document.getElementById('reportMessage').classList.remove('d-none', 'alert-info');
        document.getElementById('reportMessage').classList.add('alert-danger');
        return;
      }

      currentReportData = response.data;
      displayReport(response.data);
      updateExportButtons(reportId);
    } catch (err) {
      document.getElementById('reportMessage').textContent = 'Error generating report: ' + err.message;
      document.getElementById('reportMessage').classList.remove('d-none', 'alert-info');
      document.getElementById('reportMessage').classList.add('alert-danger');
    } finally {
      document.querySelector('.loading-spinner').classList.add('d-none');
    }
  }

  function displayReport(data) {
    const reportMessage = document.getElementById('reportMessage');
    if (!data || data.length === 0) {
      reportMessage.textContent = 'No data available for this report.';
      reportMessage.classList.remove('d-none', 'alert-info');
      reportMessage.classList.add('alert-warning');
      document.getElementById('reportTableContainer').style.display = 'none';
      return;
    }

    reportMessage.classList.add('d-none');

    const table = document.getElementById('reportTable');
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    thead.innerHTML = '';
    tbody.innerHTML = '';

    const headers = Object.keys(data[0]);
    const headerRow = document.createElement('tr');

    headers.forEach(header => {
      const th = document.createElement('th');
      th.classList.add('position-sticky', 'top-0', 'bg-dark', 'text-white');
      th.style.cursor = 'pointer';
      th.textContent = header.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
      th.addEventListener('click', () => {
        if (currentSortBy === header) {
          currentSortDirection = currentSortDirection === 'ASC' ? 'DESC' : 'ASC';
        } else {
          currentSortBy = header;
          currentSortDirection = 'ASC';
        }
        generateReport(currentReport.id, getFilters());
      });
      if (currentSortBy === header) {
        th.textContent += currentSortDirection === 'ASC' ? ' ▲' : ' ▼';
      }
      headerRow.appendChild(th);
    });

    thead.appendChild(headerRow);

    data.forEach(row => {
      const tr = document.createElement('tr');
      headers.forEach(header => {
        const td = document.createElement('td');
        td.textContent = row[header] !== null ? row[header] : 'N/A';
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });

    document.getElementById('reportTableContainer').style.display = 'block';
  }

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
    const filters = getFilters();
    const params = new URLSearchParams({
      action: 'export_csv',
      report_type: reportId,
      format: format,
      filters: JSON.stringify(filters),
      sort_by: currentSortBy || '',
      sort_direction: currentSortDirection || 'ASC'
    });

    window.location.href = 'ticket_report.php?' + params.toString();
  }

  function getSelectedValues(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return [];
    return Array.from(container.querySelectorAll('input[type="checkbox"]:checked')).map(checkbox => checkbox.value);
  }

  function updateSelectedDisplay(type, selected) {
    const displayId = {
      status: 'selectedStatus',
      site: 'selectedSite',
      createdBy: 'selectedCreatedBy',
      project: 'selectedProject',
      isp: 'selectedISP',
      province: 'selectedProvince',
      municipality: 'selectedMunicipality'
    }[type];
    const countId = {
      status: 'statusCount',
      site: 'siteCount',
      createdBy: 'createdByCount',
      project: 'projectCount',
      isp: 'ispCount',
      province: 'provinceCount',
      municipality: 'municipalityCount'
    }[type];

    const display = document.getElementById(displayId);
    const count = document.getElementById(countId);
    if (!display || !count) return;

    if (selected.length === 0) {
      const defaultText = {
        status: 'All Status',
        site: 'All Sites',
        createdBy: 'All Users',
        project: 'All Projects',
        isp: 'All ISPs',
        province: 'All Provinces',
        municipality: 'All Municipalities'
      }[type];
      display.textContent = defaultText;
      count.style.display = 'none';
      count.textContent = '';
    } else {
      display.textContent = selected.length === 1 ? selected[0] : `${selected.length} selected`;
      count.style.display = 'inline-block';
      count.textContent = selected.length;
    }
  }

  function updateActiveFilters() {
    const activeFilters = [];
    const statuses = getSelectedValues('statusOptions');
    const sites = getSelectedValues('siteOptions');
    const createdBy = getSelectedValues('createdByOptions');
    const projects = getSelectedValues('projectOptions');
    const isps = getSelectedValues('ispOptions');
    const provinces = getSelectedValues('provinceOptions');
    const municipalities = getSelectedValues('municipalityOptions');

    if (statuses.length > 0 && statuses.length < document.getElementById('statusOptions').querySelectorAll('input[type="checkbox"]').length) {
      activeFilters.push({ label: `Status: ${statuses.join(', ')}`, type: 'status' });
    }
    if (sites.length > 0 && sites.length < document.getElementById('siteOptions').querySelectorAll('input[type="checkbox"]').length) {
      activeFilters.push({ label: `Site: ${sites.join(', ')}`, type: 'site' });
    }
    if (createdBy.length > 0 && createdBy.length < document.getElementById('createdByOptions').querySelectorAll('input[type="checkbox"]').length) {
      activeFilters.push({ label: `Created By: ${createdBy.join(', ')}`, type: 'createdBy' });
    }
    if (projects.length > 0 && projects.length < document.getElementById('projectOptions').querySelectorAll('input[type="checkbox"]').length) {
      activeFilters.push({ label: `Project: ${projects.join(', ')}`, type: 'project' });
    }
    if (isps.length > 0 && isps.length < document.getElementById('ispOptions').querySelectorAll('input[type="checkbox"]').length) {
      activeFilters.push({ label: `ISP: ${isps.join(', ')}`, type: 'isp' });
    }
    if (provinces.length > 0 && provinces.length < document.getElementById('provinceOptions').querySelectorAll('input[type="checkbox"]').length) {
      activeFilters.push({ label: `Province: ${provinces.join(', ')}`, type: 'province' });
    }
    if (municipalities.length > 0 && municipalities.length < document.getElementById('municipalityOptions').querySelectorAll('input[type="checkbox"]').length) {
      activeFilters.push({ label: `Municipality: ${municipalities.join(', ')}`, type: 'municipality' });
    }

    const activeFiltersContainer = document.getElementById('activeFilters');
    if (activeFilters.length === 0) {
      activeFiltersContainer.style.display = 'none';
      activeFiltersContainer.innerHTML = '';
      return;
    }

    activeFiltersContainer.style.display = 'block';
    activeFiltersContainer.innerHTML = activeFilters.map(f => `
      <span class="badge rounded-pill bg-info text-dark me-2">
        ${f.label} <i class="bi bi-x-circle-fill" style="cursor:pointer;" onclick="removeFilter('${f.type}')"></i>
      </span>
    `).join('');
  }

  function getFilters() {
    const dateFromEl = document.getElementById('filterDateFrom');
    const dateToEl = document.getElementById('filterDateTo');

    const filters = {
      status: getSelectedValues('statusOptions'),
      site_id: getSelectedValues('siteOptions'),
      created_by: getSelectedValues('createdByOptions'),
      project_name: getSelectedValues('projectOptions'),
      isp: getSelectedValues('ispOptions'),
      province: getSelectedValues('provinceOptions'),
      municipality: getSelectedValues('municipalityOptions'),
      date_from: dateFromEl ? dateFromEl.value : '',
      date_to: dateToEl ? dateToEl.value : ''
    };

    const statusContainer = document.getElementById('statusOptions');
    if (filters.status.length === statusContainer?.querySelectorAll('input[type="checkbox"]').length) {
      delete filters.status;
    }

    const siteContainer = document.getElementById('siteOptions');
    if (filters.site_id.length === siteContainer?.querySelectorAll('input[type="checkbox"]').length) {
      delete filters.site_id;
    }

    const createdByContainer = document.getElementById('createdByOptions');
    if (filters.created_by.length === createdByContainer?.querySelectorAll('input[type="checkbox"]').length) {
      delete filters.created_by;
    }

    const projectContainer = document.getElementById('projectOptions');
    if (filters.project_name.length === projectContainer?.querySelectorAll('input[type="checkbox"]').length) {
      delete filters.project_name;
    }

    const ispContainer = document.getElementById('ispOptions');
    if (filters.isp.length === ispContainer?.querySelectorAll('input[type="checkbox"]').length) {
      delete filters.isp;
    }

    const provinceContainer = document.getElementById('provinceOptions');
    if (filters.province.length === provinceContainer?.querySelectorAll('input[type="checkbox"]').length) {
      delete filters.province;
    }

    const municipalityContainer = document.getElementById('municipalityOptions');
    if (filters.municipality.length === municipalityContainer?.querySelectorAll('input[type="checkbox"]').length) {
      delete filters.municipality;
    }

    return filters;
  }

  function setupSearch(searchId, containerId) {
    const input = document.getElementById(searchId);
    if (!input) return;
    let timeout;
    input.addEventListener('input', function() {
      clearTimeout(timeout);
      timeout = setTimeout(() => {
        filterOptions(containerId, this.value);
      }, 100);
    });
  }

  function filterOptions(containerId, query) {
    const container = document.getElementById(containerId);
    const results = document.getElementById(containerId.replace('Options', 'SearchResults'));
    if (!container || !results) return;
    const options = container.querySelectorAll('.form-check');
    const q = query.trim().toLowerCase();
    let visibleCount = 0;
    options.forEach(option => {
      const label = option.querySelector('label').textContent.toLowerCase();
      if (!q || label.includes(q)) {
        option.style.display = '';
        visibleCount++;
      } else {
        option.style.display = 'none';
      }
    });
    results.style.display = 'block';
    results.textContent = `Showing ${visibleCount} of ${options.length} options`;
  }

  function selectAllVisible(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(checkbox => {
      if (checkbox.closest('.form-check').style.display !== 'none') {
        checkbox.checked = true;
      }
    });
    updateSelectionCount(containerId);
  }

  function selectNone(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(checkbox => checkbox.checked = false);
    updateSelectionCount(containerId);
  }

  function invertSelection(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(checkbox => {
      if (checkbox.closest('.form-check').style.display !== 'none') {
        checkbox.checked = !checkbox.checked;
      }
    });
    updateSelectionCount(containerId);
  }

  function updateSelectionCount(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const total = container.querySelectorAll('input[type="checkbox"]:not(:disabled)').length;
    const selected = container.querySelectorAll('input[type="checkbox"]:checked').length;

    document.getElementById(containerId.replace('Options', 'SelectionCount')).textContent = `${selected} selected`;
    const typeName = containerId.replace('Options', '');
    updateSelectedDisplay(typeName, Array.from(container.querySelectorAll('input[type="checkbox"]:checked')).map(i => i.value));
    updateActiveFilters();
  }

  function applyMultiSelect(type) {
    const selected = getSelectedValues(`${type}Options`);
    updateSelectedDisplay(type, selected);
    updateActiveFilters();
    const dropBtn = document.getElementById(`${type}Dropdown`);
    if (dropBtn) {
      const drop = new bootstrap.Dropdown(dropBtn);
      drop.hide();
    }
    applyFilters();
  }

  function clearMultiSelect(type) {
    const options = document.getElementById(`${type}Options`);
    if (!options) return;
    options.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
    updateSelectedDisplay(type, []);
    updateActiveFilters();
    const dropBtn = document.getElementById(`${type}Dropdown`);
    if (dropBtn) {
      const drop = new bootstrap.Dropdown(dropBtn);
      drop.hide();
    }
  }

  function removeFilter(type) {
    clearMultiSelect(type);
    applyFilters();
  }

  function setDateRange(range) {
    const today = new Date();
    let fromDate = new Date();
    let toDate = new Date();

    switch (range) {
      case 'today':
        break;
      case 'yesterday':
        fromDate.setDate(today.getDate() - 1);
        toDate.setDate(today.getDate() - 1);
        break;
      case 'last7days':
        fromDate.setDate(today.getDate() - 6);
        break;
      case 'last30days':
        fromDate.setDate(today.getDate() - 29);
        break;
      case 'thisMonth':
        fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
        break;
      case 'lastMonth':
        fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        toDate = new Date(today.getFullYear(), today.getMonth(), 0);
        break;
      default:
        return;
    }

    document.getElementById('filterDateFrom').value = fromDate.toISOString().split('T')[0];
    document.getElementById('filterDateTo').value = toDate.toISOString().split('T')[0];
    applyFilters();
  }

  function clearDateRange() {
    const dateFromEl = document.getElementById('filterDateFrom');
    const dateToEl = document.getElementById('filterDateTo');
    if (dateFromEl) dateFromEl.value = '';
    if (dateToEl) dateToEl.value = '';
    applyFilters();
  }

  function applyFilters() {
    currentFilters = getFilters();
    generateReport(currentReport.id, currentFilters);
  }

  function resetFilters() {
    clearMultiSelect('status');
    clearMultiSelect('site');
    clearMultiSelect('createdBy');
    clearMultiSelect('project');
    clearMultiSelect('isp');
    clearMultiSelect('province');
    clearMultiSelect('municipality');
    clearDateRange();
    currentSortBy = 'total_tickets';
    currentSortDirection = 'DESC';
    currentFilters = getFilters();
    generateReport(currentReport.id, currentFilters);
  }

  async function populateFilterOptions() {
    try {
      const resp = await postRequest('ticket_report.php', { action: 'get_filter_options' });
      if (!resp.success) {
        throw new Error(resp.error || 'Failed to load filter options');
      }

      populateMultiSelectOptions('status', resp.status, 'statusOptions', false);
      populateMultiSelectOptions('site', resp.sites.map(s => ({ value: s.id, text: s.site_name })), 'siteOptions', false);
      populateMultiSelectOptions('createdBy', resp.created_by, 'createdByOptions', false);
      populateMultiSelectOptions('project', resp.project_name, 'projectOptions', false);
      populateMultiSelectOptions('isp', resp.isp, 'ispOptions', false);
      populateMultiSelectOptions('province', resp.province, 'provinceOptions', false);
      populateMultiSelectOptions('municipality', resp.municipality, 'municipalityOptions', false);

      setupSearch('statusSearch', 'statusOptions');
      setupSearch('siteSearch', 'siteOptions');
      setupSearch('createdBySearch', 'createdByOptions');
      setupSearch('projectSearch', 'projectOptions');
      setupSearch('ispSearch', 'ispOptions');
      setupSearch('provinceSearch', 'provinceOptions');
      setupSearch('municipalitySearch', 'municipalityOptions');

      updateSelectionCount('statusOptions');
      updateSelectionCount('siteOptions');
      updateSelectionCount('createdByOptions');
      updateSelectionCount('projectOptions');
      updateSelectionCount('ispOptions');
      updateSelectionCount('provinceOptions');
      updateSelectionCount('municipalityOptions');

      updateActiveFilters();
    } catch (err) {
      console.error('Filter init error:', err);
    }
  }

  function populateMultiSelectOptions(type, options, containerId, allSelected = false) {
    const container = document.getElementById(containerId);
    if (!container) return;

    let content = '';
    options.forEach(item => {
      const value = item && typeof item === 'object' ? item.value : item;
      const text = item && typeof item === 'object' ? item.text : item;
      const checked = allSelected ? 'checked' : '';
      const id = `${containerId}_${value}`.replace(/[^a-zA-Z0-9_-]/g, '_');
      content += `
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="${value}" id="${id}" ${checked} onchange="updateSelectionCount('${containerId}')">
          <label class="form-check-label" for="${id}">${text}</label>
        </div>
      `;
    });
    container.innerHTML = content;
  }

  initReportGrid();
  populateFilterOptions();
</script>

</body>
</html>