<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require '../lib/Logger.php';
require '../lib/AutoClose.php';

requireAdmin();

// Auto-close resolved tickets older than 7 days
$logger = new Logger($pdo);
$autoClosed = autoCloseResolvedTickets($pdo, $logger);
if ($autoClosed > 0) {
    // Optional: could store in session for display, but for now just run silently
}

$action = $_POST['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    
    try {
        switch ($action) {
            case 'get_stats':
                echo json_encode(getStats($_POST));
                break;
            case 'get_chart_status':
                echo json_encode(getChartStatus($_POST));
                break;
            case 'get_chart_aging':
                echo json_encode(getChartAging($_POST));
                break;
            case 'get_chart_created_by':
                echo json_encode(getChartCreatedBy($_POST));
                break;
            case 'get_recent_tickets':
                echo json_encode(getRecentTickets($_POST));
                break;
            case 'get_aging_tickets':
                echo json_encode(getAgingTickets($_POST));
                break;
            case 'get_filter_options':
                echo json_encode(getFilterOptions());
                break;
            case 'get_completion_rate':
                echo json_encode(getCompletionRate($_POST));
                break;
            case 'get_avg_resolution_time':
                echo json_encode(getAvgResolutionTime($_POST));
                break;
            case 'get_today_intake':
                echo json_encode(getTodayIntake($_POST));
                break;
            case 'get_weekly_trend':
                echo json_encode(getWeeklyTrend($_POST));
                break;
            case 'get_problematic_site':
                echo json_encode(getProblematicSite($_POST));
                break;
            case 'get_problematic_isp':
                echo json_encode(getProblematicISP($_POST));
                break;
            default:
                echo json_encode(['error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

function getStats($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    // join with sites for ISP/province/municipality filters
    // aging tickets are OPEN or IN_PROGRESS tickets with duration >= 3 days (4320 minutes)
    // or if duration is NULL, check if created_at is >= 3 days ago
    $sql = "SELECT
        COUNT(*) as total_tickets,
        SUM(CASE WHEN t.status='OPEN' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN t.status='IN_PROGRESS' THEN 1 ELSE 0 END) as in_progress_count,
        SUM(CASE WHEN t.status='RESOLVED' THEN 1 ELSE 0 END) as resolved_count,
        SUM(CASE WHEN t.status='CLOSED' THEN 1 ELSE 0 END) as closed_count,
        SUM(CASE WHEN (t.status='OPEN' OR t.status='IN_PROGRESS')
             AND (t.duration >= 4320 OR (t.duration IS NULL AND DATEDIFF(NOW(), t.created_at) >= 3)) THEN 1 ELSE 0 END) as aging_count,
        AVG(CASE WHEN t.status='CLOSED' THEN t.duration ELSE NULL END) as avg_resolution_time
    FROM tickets t
    LEFT JOIN sites s ON t.site_id = s.id
    WHERE 1=1{$whereData['sql']}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_tickets' => (int)$result['total_tickets'],
        'open_count' => (int)$result['open_count'],
        'in_progress_count' => (int)$result['in_progress_count'],
        'resolved_count' => (int)$result['resolved_count'],
        'closed_count' => (int)$result['closed_count'],
        'aging_count' => (int)$result['aging_count'],
        'avg_time' => $result['avg_resolution_time'] ? round($result['avg_resolution_time'], 1) : 0
    ];
}

function getChartStatus($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    // include site join for location-based filters
    $sql = "SELECT t.status, COUNT(*) as count
            FROM tickets t
            LEFT JOIN sites s ON t.site_id = s.id
            WHERE 1=1{$whereData['sql']}
            GROUP BY t.status
            ORDER BY FIELD(t.status, 'OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED')";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getChartAging($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    // Aging tickets: OPEN or IN_PROGRESS with duration >= 3 days (4320 minutes)
    // or if duration is NULL, check if created_at is >= 3 days ago
    // Filter out NULL creators to avoid empty entries in chart
    $sql = "SELECT COALESCE(p.fullname, 'Unknown') as creator, COUNT(*) as aging_count
            FROM tickets t
            LEFT JOIN personnels p ON t.created_by = p.id
            LEFT JOIN sites s ON t.site_id = s.id
            WHERE (t.status = 'OPEN' OR t.status = 'IN_PROGRESS')
              AND (t.duration >= 4320 OR (t.duration IS NULL AND DATEDIFF(NOW(), t.created_at) >= 3))
              AND 1=1{$whereData['sql']}
            GROUP BY p.fullname
            HAVING creator IS NOT NULL
            ORDER BY aging_count DESC
            LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getChartCreatedBy($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    // Calculate average tickets per weekday (Mon-Fri) for each creator per day
    $sql = "SELECT DAYOFWEEK(t.created_at) as day_of_week,
                   p.fullname as creator,
                   ROUND(COUNT(*) / GREATEST(1, (SELECT COUNT(DISTINCT DATE(t2.created_at)) FROM tickets t2 LEFT JOIN sites s2 ON t2.site_id = s2.id WHERE DAYOFWEEK(t2.created_at) = DAYOFWEEK(t.created_at) AND 1=1{$whereData['sql']})), 2) as avg_tickets
            FROM tickets t
            LEFT JOIN personnels p ON t.created_by = p.id
            LEFT JOIN sites s ON t.site_id = s.id
            WHERE 1=1{$whereData['sql']}
              AND DAYOFWEEK(t.created_at) BETWEEN 2 AND 6
            GROUP BY day_of_week, p.fullname
            ORDER BY day_of_week, avg_tickets DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($whereData['params'], $whereData['params']));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRecentTickets($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);
    $limit = (int)($filters['limit'] ?? 10);

    $sql = "SELECT
        t.id,
        t.ticket_number,
        t.subject,
        t.status,
        t.created_at,
        s.site_name,
        s.isp,
        p.fullname as created_by_name
    FROM tickets t
    LEFT JOIN sites s ON t.site_id = s.id
    LEFT JOIN personnels p ON t.created_by = p.id
    WHERE 1=1{$whereData['sql']}
    ORDER BY t.created_at DESC
    LIMIT $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAgingTickets($filters = [], $limit = 20, $offset = 0) {
    global $pdo;

    // Extract pagination parameters from filters if provided
    $limit = isset($filters['limit']) ? (int)$filters['limit'] : $limit;
    $offset = isset($filters['offset']) ? (int)$filters['offset'] : $offset;

    // Remove pagination params from filters to avoid issues in buildWhereClause
    $filters = array_diff_key($filters, array_flip(['limit', 'offset', 'action']));

    $whereData = buildWhereClause($filters);
    $limit = (int)$limit;
    $offset = (int)$offset;

    $sql = "SELECT
        t.id,
        t.ticket_number,
        t.subject,
        t.status,
        t.created_at,
        t.duration,
        DATEDIFF(NOW(), t.created_at) as days_old,
        s.site_name,
        s.isp,
        p.fullname as created_by_name,
        CASE
            WHEN t.duration IS NOT NULL THEN FLOOR(t.duration / 1440)
            ELSE DATEDIFF(NOW(), t.created_at)
        END as aging_days
    FROM tickets t
    LEFT JOIN sites s ON t.site_id = s.id
    LEFT JOIN personnels p ON t.created_by = p.id
    WHERE (t.status = 'OPEN' OR t.status = 'IN_PROGRESS')
      AND (t.duration >= 4320 OR (t.duration IS NULL AND DATEDIFF(NOW(), t.created_at) >= 3))
      AND 1=1{$whereData['sql']}
    ORDER BY
        CASE
            WHEN t.duration IS NOT NULL THEN t.duration
            ELSE (DATEDIFF(NOW(), t.created_at) * 1440)
        END DESC,
        t.created_at ASC
    LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total
                 FROM tickets t
                 LEFT JOIN sites s ON t.site_id = s.id
                 WHERE (t.status = 'OPEN' OR t.status = 'IN_PROGRESS')
                   AND (t.duration >= 4320 OR (t.duration IS NULL AND DATEDIFF(NOW(), t.created_at) >= 3))
                   AND 1=1{$whereData['sql']}";

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($whereData['params']);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    return [
        'tickets' => $tickets,
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset
    ];
}

function getFilterOptions() {
    global $pdo;
    
    // Status options
    $statuses = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
    
    // Sites
    $sites = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll(PDO::FETCH_ASSOC);
    
    // Personnel
    $personnel = $pdo->query("SELECT id, fullname FROM personnels ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);
    
    // ISP options
    $isp = $pdo->query("SELECT DISTINCT isp FROM sites WHERE isp IS NOT NULL AND isp != '' ORDER BY isp")->fetchAll(PDO::FETCH_COLUMN);
    
    // Province options
    $provinces = $pdo->query("SELECT DISTINCT province FROM sites WHERE province IS NOT NULL AND province != '' ORDER BY province")->fetchAll(PDO::FETCH_COLUMN);
    
    // Municipality options
    $municipalities = $pdo->query("SELECT DISTINCT municipality FROM sites WHERE municipality IS NOT NULL AND municipality != '' ORDER BY municipality")->fetchAll(PDO::FETCH_COLUMN);
    
    // Project Name options (from sites table)
    $projects = $pdo->query("SELECT DISTINCT project_name FROM sites WHERE project_name IS NOT NULL AND project_name != '' ORDER BY project_name")->fetchAll(PDO::FETCH_COLUMN);
    
    return [
        'statuses' => $statuses,
        'sites' => $sites,
        'personnel' => $personnel,
        'isp' => $isp,
        'provinces' => $provinces,
        'municipalities' => $municipalities,
        'projects' => $projects
    ];
}

function getCompletionRate($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    $sql = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN t.status IN ('CLOSED', 'RESOLVED') THEN 1 ELSE 0 END) as completed
    FROM tickets t
    LEFT JOIN sites s ON t.site_id = s.id
    WHERE 1=1{$whereData['sql']}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $total = (int)$result['total'];
    $completed = (int)$result['completed'];
    $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

    return [
        'rate' => $rate,
        'completed' => $completed,
        'total' => $total
    ];
}

function getAvgResolutionTime($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    $sql = "SELECT
        AVG(t.duration) as avg_duration
    FROM tickets t
    LEFT JOIN sites s ON t.site_id = s.id
    WHERE t.status IN ('CLOSED', 'RESOLVED')
    AND 1=1{$whereData['sql']}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $avg_minutes = $result['avg_duration'] ?: 0;
    $avg_days = round($avg_minutes / 1440, 1);

    return [
        'avg_days' => $avg_days,
        'avg_hours' => round($avg_minutes / 60, 1),
        'avg_minutes' => round($avg_minutes, 0)
    ];
}

function getTodayIntake($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    $sql = "SELECT
        COUNT(*) as today_count,
        SUM(CASE WHEN DATE(t.created_at) = CURDATE() - INTERVAL 1 DAY THEN 1 ELSE 0 END) as yesterday_count
    FROM tickets t
    LEFT JOIN sites s ON t.site_id = s.id
    WHERE DATE(t.created_at) = CURDATE()
    AND 1=1{$whereData['sql']}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $today = (int)$result['today_count'];
    $yesterday = (int)$result['yesterday_count'];
    $trend = $yesterday > 0 ? round((($today - $yesterday) / $yesterday) * 100, 1) : 0;

    return [
        'today' => $today,
        'yesterday' => $yesterday,
        'trend' => $trend,
        'trend_direction' => $trend >= 0 ? 'up' : 'down'
    ];
}

function getWeeklyTrend($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    $sql = "SELECT
        (SELECT COUNT(*) FROM tickets t
         LEFT JOIN sites s ON t.site_id = s.id
         WHERE DATE(t.created_at) BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE()
         AND 1=1{$whereData['sql']}) as current_week,
        (SELECT COUNT(*) FROM tickets t
         LEFT JOIN sites s ON t.site_id = s.id
         WHERE DATE(t.created_at) BETWEEN CURDATE() - INTERVAL 14 DAY AND CURDATE() - INTERVAL 7 DAY
         AND 1=1{$whereData['sql']}) as last_week";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($whereData['params'], $whereData['params']));
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $current = (int)$result['current_week'];
    $last = (int)$result['last_week'];
    $trend = $last > 0 ? round((($current - $last) / $last) * 100, 1) : 0;

    return [
        'current' => $current,
        'last' => $last,
        'trend' => $trend,
        'trend_direction' => $trend >= 0 ? 'up' : 'down'
    ];
}

function getProblematicSite($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    $sql = "SELECT
        CONCAT(s.site_name, ' - ', COALESCE(s.isp, 'N/A')) as location,
        COUNT(t.id) as ticket_count
    FROM tickets t
    LEFT JOIN sites s ON t.site_id = s.id
    WHERE (t.status = 'OPEN' OR t.status = 'IN_PROGRESS')
    AND 1=1{$whereData['sql']}
    GROUP BY s.site_name, s.isp
    ORDER BY ticket_count DESC
    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        return [
            'location' => 'No Issues',
            'ticket_count' => 0
        ];
    }

    return [
        'location' => $result['location'],
        'ticket_count' => (int)$result['ticket_count']
    ];
}

function getProblematicISP($filters = []) {
    global $pdo;

    $whereData = buildWhereClause($filters);

    $sql = "SELECT
        COALESCE(s.isp, 'Unknown ISP') as isp_name,
        COUNT(t.id) as ticket_count
    FROM tickets t
    LEFT JOIN sites s ON t.site_id = s.id
    WHERE (t.status = 'OPEN' OR t.status = 'IN_PROGRESS')
    AND 1=1{$whereData['sql']}
    GROUP BY s.isp
    ORDER BY ticket_count DESC
    LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereData['params']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        return [
            'isp_name' => 'No Issues',
            'ticket_count' => 0
        ];
    }

    return [
        'isp_name' => $result['isp_name'],
        'ticket_count' => (int)$result['ticket_count']
    ];
}

function buildWhereClause($filters) {
    $conditions = [];
    $params = [];

    if (!empty($filters['status'])) {
        if (is_array($filters['status'])) {
            $placeholders = implode(',', array_fill(0, count($filters['status']), '?'));
            $conditions[] = "t.status IN ($placeholders)";
            $params = array_merge($params, $filters['status']);
        } else {
            $conditions[] = "t.status = ?";
            $params[] = $filters['status'];
        }
    }

    if (!empty($filters['site_id'])) {
        if (is_array($filters['site_id'])) {
            $placeholders = implode(',', array_fill(0, count($filters['site_id']), '?'));
            $conditions[] = "t.site_id IN ($placeholders)";
            $params = array_merge($params, array_map('intval', $filters['site_id']));
        } elseif ($filters['site_id'] != '0') {
            $conditions[] = "t.site_id = ?";
            $params[] = (int)$filters['site_id'];
        }
    }

    if (!empty($filters['created_by'])) {
        if (is_array($filters['created_by'])) {
            $placeholders = implode(',', array_fill(0, count($filters['created_by']), '?'));
            $conditions[] = "t.created_by IN ($placeholders)";
            $params = array_merge($params, array_map('intval', $filters['created_by']));
        } elseif ($filters['created_by'] != '0') {
            $conditions[] = "t.created_by = ?";
            $params[] = (int)$filters['created_by'];
        }
    }

    if (!empty($filters['date_from'])) {
        $conditions[] = "DATE(t.created_at) >= ?";
        $params[] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $conditions[] = "DATE(t.created_at) <= ?";
        $params[] = $filters['date_to'];
    }

    if (!empty($filters['isp'])) {
        if (is_array($filters['isp'])) {
            $placeholders = implode(',', array_fill(0, count($filters['isp']), '?'));
            $conditions[] = "s.isp IN ($placeholders)";
            $params = array_merge($params, $filters['isp']);
        } else {
            $conditions[] = "s.isp = ?";
            $params[] = $filters['isp'];
        }
    }

    if (!empty($filters['province'])) {
        if (is_array($filters['province'])) {
            $placeholders = implode(',', array_fill(0, count($filters['province']), '?'));
            $conditions[] = "s.province IN ($placeholders)";
            $params = array_merge($params, $filters['province']);
        } else {
            $conditions[] = "s.province = ?";
            $params[] = $filters['province'];
        }
    }

    if (!empty($filters['municipality'])) {
        if (is_array($filters['municipality'])) {
            $placeholders = implode(',', array_fill(0, count($filters['municipality']), '?'));
            $conditions[] = "s.municipality IN ($placeholders)";
            $params = array_merge($params, $filters['municipality']);
        } else {
            $conditions[] = "s.municipality = ?";
            $params[] = $filters['municipality'];
        }
    }

    if (!empty($filters['project'])) {
        if (is_array($filters['project'])) {
            $placeholders = implode(',', array_fill(0, count($filters['project']), '?'));
            $conditions[] = "s.project_name IN ($placeholders)";
            $params = array_merge($params, $filters['project']);
        } else {
            $conditions[] = "s.project_name = ?";
            $params[] = $filters['project'];
        }
    }

    return [
        'sql' => $conditions ? ' AND ' . implode(' AND ', $conditions) : '',
        'params' => $params
    ];
}

function getStatusBadgeClass($status) {
    return match($status) {
        'OPEN' => 'bg-danger',
        'IN_PROGRESS' => 'bg-warning text-dark',
        'RESOLVED' => 'bg-info',
        'CLOSED' => 'bg-success',
        default => 'bg-secondary'
    };
}

function countWeekdays($start, $end) {
    $startDate = new DateTime($start);
    $endDate = new DateTime($end);
    $endDate = $endDate->modify('+1 day'); // Include the end date
    $weekdays = 0;
    $period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate);
    foreach ($period as $date) {
        if ($date->format('N') < 6) { // Mon-Fri (1=Monday, 5=Friday)
            $weekdays++;
        }
    }
    return $weekdays;
}
?>
<?php $activePage = 'dashboard'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>

<!-- Dashboard Content -->
<div class="container-fluid mt-4">
  <!-- Header Section -->
  <div class="row mb-4">
    <div class="col-12">
      <h1 class="h3 mb-0">Dashboard</h1>
      <p class="text-muted">Overview of ticket system performance and status</p>
    </div>
  </div>

  <!-- Filter Section (Advanced Filters) -->
  <div class="card filter-card mb-4 shadow-sm border-0">
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
        <div id="activeFilters" class="d-flex flex-wrap gap-2 mb-3" style="display: none;">
          <!-- Active filter badges will be inserted here -->
        </div>

        <div class="row g-3">
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-circle-fill text-danger me-1"></i>Status
            </label>
            <!-- Multi-select with search -->
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
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-geo-alt-fill text-primary me-1"></i>Site
            </label>
            <!-- Multi-select with search -->
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
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-person-fill text-info me-1"></i>Created By
            </label>
            <!-- Multi-select with search -->
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
                <div id="createdByOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                  <!-- User checkboxes will be populated here -->
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
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-wifi text-warning me-1"></i>ISP
            </label>
            <!-- Multi-select with search -->
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
                <div id="ispOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                  <!-- ISP checkboxes will be populated here -->
                </div>
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
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-map text-success me-1"></i>Province
            </label>
            <!-- Multi-select with search -->
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
                <div id="provinceOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                  <!-- Province checkboxes will be populated here -->
                </div>
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
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-building text-secondary me-1"></i>Municipality
            </label>
            <!-- Multi-select with search -->
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
                <div id="municipalityOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                  <!-- Municipality checkboxes will be populated here -->
                </div>
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
          <div class="col-lg-2 col-md-4 col-sm-6">
            <label class="form-label fw-semibold small">
              <i class="bi bi-folder-fill text-muted me-1"></i>Project
            </label>
            <!-- Multi-select with search -->
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
                <div id="projectOptions" style="max-height: 200px; overflow-y: auto; flex: 1;">
                  <!-- Project checkboxes will be populated here -->
                </div>
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
            <!-- Advanced Date Range Options -->
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
                <i class="bi bi-info-circle me-1"></i>Filters apply to all dashboard data
              </small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Stats Cards -->
  <div class="row mb-4" id="statsCards">
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="display-6 fw-bold text-primary" id="totalTickets">0</div>
          <div class="text-muted small">Total Tickets</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="display-6 fw-bold text-danger" id="openTickets">0</div>
          <div class="text-muted small">Open Tickets</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="display-6 fw-bold text-warning" id="inProgressTickets">0</div>
          <div class="text-muted small">In Progress</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="display-6 fw-bold text-info" id="resolvedTickets">0</div>
          <div class="text-muted small">Resolved</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
      <div class="card text-center h-100" id="agingCard">
        <div class="card-body">
          <div class="display-6 fw-bold text-success" id="closedTickets">0</div>
          <div class="text-muted small">Closed</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
      <div class="card text-center h-100">
        <div class="card-body">
          <div class="display-6 fw-bold text-secondary" id="agingTickets">0</div>
          <div class="text-muted small">Aging Tickets</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Enhanced Stat Cards Row 1 (KPIs) -->
  <div class="row mb-4" id="enhancedStatsRow1">
    <!-- Completion Rate Card -->
    <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12 mb-3">
      <div class="card h-100 border-0 shadow-sm overflow-hidden" style="transition: all 0.3s ease;">
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); height: 4px;"></div>
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <div class="text-muted small fw-semibold mb-2">Completion Rate</div>
              <div class="display-6 fw-bold text-success" id="completionRate" style="font-size: 2.5rem;">0%</div>
            </div>
            <div style="background: rgba(16, 185, 129, 0.1); border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
              <i class="bi bi-percent text-success fs-5"></i>
            </div>
          </div>
          <div class="text-muted small" style="font-size: 0.9rem;">
            <i class="bi bi-info-circle me-1"></i>
            <span id="completionStats">0 / 0 tickets</span>
          </div>
        </div>
      </div>
    </div>
    <!-- Avg Resolution Time Card -->
    <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12 mb-3">
      <div class="card h-100 border-0 shadow-sm overflow-hidden" style="transition: all 0.3s ease;">
        <div style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); height: 4px;"></div>
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <div class="text-muted small fw-semibold mb-2">Avg Resolution</div>
              <div class="display-6 fw-bold text-info" id="avgResolutionTime" style="font-size: 2.5rem;">0 days</div>
            </div>
            <div style="background: rgba(59, 130, 246, 0.1); border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
              <i class="bi bi-hourglass-end text-info fs-5"></i>
            </div>
          </div>
          <div class="text-muted small" style="font-size: 0.9rem;">
            <i class="bi bi-info-circle me-1"></i>
            <span id="avgResolutionStats">0 hours avg</span>
          </div>
        </div>
      </div>
    </div>
    <!-- Today's Intake Card -->
    <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12 mb-3">
      <div class="card h-100 border-0 shadow-sm overflow-hidden" style="transition: all 0.3s ease;">
        <div style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); height: 4px;"></div>
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <div class="text-muted small fw-semibold mb-2">Today's Intake</div>
              <div class="display-6 fw-bold" id="todayIntake" style="font-size: 2.5rem; color: #8b5cf6;">0</div>
            </div>
            <div style="background: rgba(139, 92, 246, 0.1); border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
              <i class="bi bi-inbox" style="color: #8b5cf6;" class="fs-5"></i>
            </div>
          </div>
          <div class="text-muted small" style="font-size: 0.9rem;">
            <span id="todayTrend"><i class="bi bi-dash"></i> 0%</span>
            <span class="ms-2">vs yesterday</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Enhanced Stat Cards Row 2 (Trends & Insights) -->
  <div class="row mb-4" id="enhancedStatsRow2">
    <!-- Weekly Trend Card -->
    <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12 mb-3">
      <div class="card h-100 border-0 shadow-sm overflow-hidden" style="transition: all 0.3s ease;">
        <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); height: 4px;"></div>
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <div class="text-muted small fw-semibold mb-2">Weekly Trend</div>
              <div class="display-6 fw-bold" id="weeklyTrend" style="font-size: 2.5rem; color: #f59e0b;"><i class="bi bi-dash"></i> 0%</div>
            </div>
            <div style="background: rgba(245, 158, 11, 0.1); border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
              <i class="bi bi-graph-up-arrow" style="color: #f59e0b;" class="fs-5"></i>
            </div>
          </div>
          <div class="text-muted small" style="font-size: 0.9rem;">
            <i class="bi bi-info-circle me-1"></i>
            <span id="weeklyStats">0 vs 0 last week</span>
          </div>
        </div>
      </div>
    </div>
    <!-- Most Problematic ISP Card -->
    <div class="col-xl-8 col-lg-6 col-md-6 col-sm-12 mb-3">
      <div class="card h-100 border-0 shadow-sm overflow-hidden" style="transition: all 0.3s ease;">
        <div style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); height: 4px;"></div>
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="flex-grow-1">
              <div class="d-flex align-items-center mb-2">
                <i class="bi bi-exclamation-triangle" style="color: #ef4444;" class="me-2 fs-5"></i>
                <div class="text-muted small fw-semibold">Most Problematic ISP</div>
              </div>
              <div class="fw-bold" id="problematicSite" style="font-size: 1.3rem; color: #1f2937;">-</div>
            </div>
            <div style="background: rgba(239, 68, 68, 0.1); border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
              <div class="badge bg-danger" id="problematicSiteCount" style="font-size: 0.9rem;">0 Issues</div>
            </div>
          </div>
          <div class="text-muted small" style="font-size: 0.85rem;">
            <i class="bi bi-wifi me-1"></i>Active open/in-progress tickets
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Charts & Analytics -->
  <div class="row mb-4">
    <div class="col-lg-4 col-md-7 mb-5">
      <div class="card">
        <div class="card-header">
          <h5><i class="bi bi-bar-chart"></i> Tickets by Status</h5>
        </div>
        <div class="card-body">
          <canvas id="statusChart" height="100"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-8 col-md-6 mb-4">
      <div class="card">  
        <div class="card-header">
          <h5><i class="bi bi-clock"></i> Aging Tickets</h5>
        </div>
        <div class="card-body">
          <canvas id="agingChart" height="150"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Creator Chart -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header">
          <h5><i class="bi bi-person-lines-fill"></i> Avg Tickets per Weekday</h5>
        </div>
        <div class="card-body">
          <canvas id="createdByChart" height="100"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- AGING TICKETS SECTION -->
  <!-- Aging Tickets Section -->
  <div class="card mb-4 border-warning">
      <div class="card-header bg-warning text-dark">
          <div class="d-flex justify-content-between align-items-center">
              <h5 class="mb-0">
                  <i class="bi bi-exclamation-triangle-fill me-2"></i>
                  Aging Tickets Requiring Attention
                  <span class="badge bg-danger ms-2" id="agingCountBadge">0</span>
              </h5>
              <button class="btn btn-sm btn-outline-dark" onclick="refreshAgingTickets()">
                  <i class="bi bi-arrow-clockwise"></i> Refresh
              </button>
          </div>
      </div>
      <div class="card-body">
          <div id="agingTicketsContainer">
              <!-- Loading State -->
              <div id="agingTicketsLoading" class="text-center py-4">
                  <div class="spinner-border text-warning" role="status">
                      <span class="visually-hidden">Loading...</span>
                  </div>
                  <p class="text-muted mt-2">Loading aging tickets...</p>
              </div>

              <!-- Empty State -->
              <div id="agingTicketsEmpty" class="text-center py-4" style="display: none;">
                  <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                  <h5 class="text-success mt-3">No Aging Tickets!</h5>
                  <p class="text-muted">All tickets are being handled promptly.</p>
              </div>

              <!-- Error State -->
              <div id="agingTicketsError" class="alert alert-danger" style="display: none;">
                  <i class="bi bi-exclamation-triangle-fill me-2"></i>
                  Failed to load aging tickets. Please try again.
              </div>

              <!-- Aging Tickets Table -->
              <div id="agingTicketsTable" class="table-responsive" style="display: none;">
                  <table class="table table-hover">
                      <thead class="table-warning">
                          <tr>
                              <th>Ticket #</th>
                              <th>Site</th>
                              <th>ISP</th>
                              <th>Subject</th>
                              <th>Status</th>
                              <th>Created</th>
                              <th>Creator</th>
                              <th>Days Old</th>
                              <th>Actions</th>
                          </tr>
                      </thead>
                      <tbody id="agingTicketsTableBody">
                          <!-- Dynamic content -->
                      </tbody>
                  </table>

                  <!-- Pagination -->
                  <nav id="agingPagination" aria-label="Aging tickets pagination" style="display: none;">
                      <ul class="pagination justify-content-center" id="agingPaginationList">
                          <!-- Dynamic pagination -->
                      </ul>
                  </nav>
              </div>
          </div>
      </div>
  </div>

  <!-- Recent Tickets Table -->
  <div class="card mb-4">
    <div class="card-header">
      <h5><i class="bi bi-list-ul"></i> Recent Tickets</h5>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover" id="recentTicketsTable">
          <thead>
            <tr>
              <th>Ticket #</th>
              <th>Site</th>
              <th>ISP</th>
              <th>Subject</th>
              <th>Status</th>
              <th>Creator</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colspan="8" class="text-center text-muted">Loading...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
// Dashboard JavaScript Functions
let statusChart, agingChart, createdByChart;

document.addEventListener('DOMContentLoaded', function() {
    initDashboard();
});

function initDashboard() {
    loadFilterOptions();
    setupCharts();
    applyFilters();
    loadAgingTickets();
    setupDropdownBehavior();
    
    // Ensure all dropdowns are closed on page load
    closeAllDropdowns();
    
    // Add event listeners for date inputs
    document.getElementById('filterDateFrom').addEventListener('change', updateActiveFilters);
    document.getElementById('filterDateTo').addEventListener('change', updateActiveFilters);
    
    // Auto-refresh enhanced stats every 30 seconds
    setInterval(() => {
        if (document.hidden) return;
        const filters = getFilters();
        loadCompletionRate(filters);
        loadAvgResolutionTime(filters);
        loadTodayIntake(filters);
        loadWeeklyTrend(filters);
        loadProblematicISP(filters);
    }, 30000);
}

function closeDropdownByType(type) {
    // Find the dropdown container and close it
    const optionsContainer = document.getElementById(type + 'Options');
    if (!optionsContainer) return;

    const dropdownDiv = optionsContainer.closest('.dropdown');
    if (!dropdownDiv) return;

    const toggleButton = dropdownDiv.querySelector('.dropdown-toggle');
    const dropdownMenu = dropdownDiv.querySelector('.dropdown-menu');

    // Method 1: Try Bootstrap API first
    try {
        const bsDropdown = bootstrap.Dropdown.getInstance(toggleButton);
        if (bsDropdown) {
            bsDropdown.hide();
        }
    } catch (e) {
        // Fallback if Bootstrap API fails
    }

    // Method 2: Force close by removing classes and updating attributes
    if (dropdownMenu) {
        dropdownMenu.classList.remove('show');
    }
    if (toggleButton) {
        toggleButton.setAttribute('aria-expanded', 'false');
        toggleButton.classList.remove('show');
    }

    // Method 3: Additional cleanup - remove any lingering Bootstrap state
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

function setupDropdownBehavior() {
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(event) {
        const clickedElement = event.target;

        // Check if click is inside a dropdown
        const dropdownContainer = clickedElement.closest('.dropdown');

        if (!dropdownContainer) {
            // Click is outside all dropdowns - close all open dropdowns
            document.querySelectorAll('.dropdown').forEach(dropdown => {
                const menu = dropdown.querySelector('.dropdown-menu');
                const toggle = dropdown.querySelector('.dropdown-toggle');

                if (menu && menu.classList.contains('show')) {
                    menu.classList.remove('show');
                    if (toggle) toggle.setAttribute('aria-expanded', 'false');

                    // Also try Bootstrap API
                    try {
                        const bsDropdown = bootstrap.Dropdown.getInstance(toggle);
                        if (bsDropdown) bsDropdown.hide();
                    } catch (e) {}
                }
            });
        }
    });

}

function closeAllDropdowns() {
    // Close all dropdown menus on page load
    document.querySelectorAll('.dropdown-menu').forEach(menu => {
        menu.classList.remove('show');
    });
    
    // Reset all dropdown toggle buttons
    document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
        toggle.setAttribute('aria-expanded', 'false');
        toggle.classList.remove('show');
    });
    
    // Also try to close using Bootstrap API
    document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
        try {
            const bsDropdown = bootstrap.Dropdown.getInstance(toggle);
            if (bsDropdown) bsDropdown.hide();
        } catch (e) {}
    });
}

function setupCharts() {
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: [],
            datasets: [{
                data: [],
                backgroundColor: ['#dc3545', '#ffc107', '#0dcaf0', '#198754']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    const agingCtx = document.getElementById('agingChart').getContext('2d');
    agingChart = new Chart(agingCtx, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Aging Tickets',
                data: [],
                backgroundColor: ['#ffc107', '#fd7e14', '#dc3545']
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                    }
                },
                x: {
                    title: {
                        display: true
                    }
                }
            }
        }
    });

    // creator chart
    const creatorCtx = document.getElementById('createdByChart').getContext('2d');
    createdByChart = new Chart(creatorCtx, {
        type: 'bar',
        data: {
            labels: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            datasets: []
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            },
            plugins: {
                legend: {
                    position: 'top'
                }
            }
        }
    });
}

function loadFilterOptions() {
    postRequest('dashboard.php', { action: 'get_filter_options' })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load filter options:', data.error);
                return;
            }
            
            populateStatusFilter(data.statuses);
            populateSiteFilter(data.sites);
            populateCreatedByFilter(data.personnel);
            populateISPFilter(data.isp);
            populateProvinceFilter(data.provinces);
            populateMunicipalityFilter(data.municipalities);
            populateProjectFilter(data.projects);
            
            // Initialize selection counts
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
    // Add search functionality
    setupSearch('statusSearch', 'statusOptions');
    // Add event delegation
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
                    ${site.site_name}
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
        }, 100); // 100ms debounce
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
}

function clearMultiSelect(type) {
    // Map filter types to their container IDs and normalized type names
    const typeMap = {
        'created_by': { containerId: 'createdByOptions', displayType: 'createdBy' },
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
            <span class="badge bg-secondary d-flex align-items-center gap-1">
                Project: ${filters.project.join(', ')}
                <button class="btn-close btn-close-white" onclick="removeFilter('project')" style="font-size: 0.6rem;"></button>
            </span>
        `;
        hasFilters = true;
    }
    
    // Date range
    if (filters.date_from || filters.date_to) {
        const dateText = filters.date_from && filters.date_to ? 
            `${filters.date_from} to ${filters.date_to}` : 
            (filters.date_from ? `From ${filters.date_from}` : `To ${filters.date_to}`);
        activeContainer.innerHTML += `
            <span class="badge bg-info d-flex align-items-center gap-1">
                Date: ${dateText}
                <button class="btn-close btn-close-white" onclick="removeFilter('date')" style="font-size: 0.6rem;"></button>
            </span>
        `;
        hasFilters = true;
    }
    
    activeContainer.style.display = hasFilters ? '' : 'none';
}

function removeFilter(type) {
    if (type === 'status') {
        clearMultiSelect('status');
    } else if (type === 'site') {
        clearMultiSelect('site');
    } else if (type === 'created_by') {
        clearMultiSelect('created_by');
    } else if (type === 'isp') {
        clearMultiSelect('isp');
    } else if (type === 'province') {
        clearMultiSelect('province');
    } else if (type === 'municipality') {
        clearMultiSelect('municipality');
    } else if (type === 'project') {
        clearMultiSelect('project');
    } else if (type === 'date') {
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
    }
    updateActiveFilters();
    applyFilters();
}

function setDateRange(range) {
    const today = new Date();
    let fromDate, toDate;
    
    switch(range) {
        case 'today':
            fromDate = toDate = today.toISOString().split('T')[0];
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(today.getDate() - 1);
            fromDate = toDate = yesterday.toISOString().split('T')[0];
            break;
        case 'last7days':
            const last7 = new Date(today);
            last7.setDate(today.getDate() - 7);
            fromDate = last7.toISOString().split('T')[0];
            toDate = today.toISOString().split('T')[0];
            break;
        case 'last30days':
            const last30 = new Date(today);
            last30.setDate(today.getDate() - 30);
            fromDate = last30.toISOString().split('T')[0];
            toDate = today.toISOString().split('T')[0];
            break;
        case 'thisMonth':
            fromDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
            toDate = today.toISOString().split('T')[0];
            break;
        case 'lastMonth':
            const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
            fromDate = lastMonth.toISOString().split('T')[0];
            toDate = lastMonthEnd.toISOString().split('T')[0];
            break;
    }
    
    document.getElementById('filterDateFrom').value = fromDate;
    document.getElementById('filterDateTo').value = toDate;
    updateActiveFilters();
    
    // Close dropdown
    const dropdown = event.target.closest('.dropdown');
    const bsDropdown = bootstrap.Dropdown.getInstance(dropdown.querySelector('.dropdown-toggle'));
    if (bsDropdown) bsDropdown.hide();
}

function clearDateRange() {
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    updateActiveFilters();
    
    // Close dropdown
    const dropdown = event.target.closest('.dropdown');
    const bsDropdown = bootstrap.Dropdown.getInstance(dropdown.querySelector('.dropdown-toggle'));
    if (bsDropdown) bsDropdown.hide();
}

function applyFilters() {
    const filters = getFilters();
    
    loadStats(filters);
    loadCompletionRate(filters);
    loadAvgResolutionTime(filters);
    loadTodayIntake(filters);
    loadWeeklyTrend(filters);
    loadProblematicISP(filters);
    loadChartStatus(filters);
    loadChartAging(filters);
    loadChartCreatedBy(filters);
    loadRecentTickets(filters);
    loadAgingTickets(filters, 1);
}

function resetFilters() {
    // Clear all multi-select checkboxes
    ['status', 'site', 'created_by', 'isp', 'province', 'municipality', 'project'].forEach(type => {
        clearMultiSelect(type);
    });
    
    // Clear date inputs
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    
    // Update displays
    updateActiveFilters();
    applyFilters();
}

function loadCompletionRate(filters) {
    postRequest('dashboard.php', { action: 'get_completion_rate', ...filters })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load completion rate:', data.error);
                return;
            }
            
            let rateColor = 'text-success';
            if (data.rate < 50) rateColor = 'text-danger';
            else if (data.rate < 75) rateColor = 'text-warning';
            
            document.getElementById('completionRate').textContent = data.rate + '%';
            document.getElementById('completionRate').className = `display-5 fw-bold ${rateColor}`;
            document.getElementById('completionStats').textContent = `${data.completed} / ${data.total}`;
        })
        .catch(err => console.error('Failed to load completion rate:', err));
}

function loadAvgResolutionTime(filters) {
    postRequest('dashboard.php', { action: 'get_avg_resolution_time', ...filters })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load avg resolution time:', data.error);
                return;
            }
            
            let timeColor = 'text-success';
            if (data.avg_days > 7) timeColor = 'text-danger';
            else if (data.avg_days > 4) timeColor = 'text-warning';
            
            document.getElementById('avgResolutionTime').textContent = data.avg_days + ' days';
            document.getElementById('avgResolutionTime').className = `display-5 fw-bold ${timeColor}`;
            document.getElementById('avgResolutionStats').textContent = data.avg_hours + ' hours';
        })
        .catch(err => console.error('Failed to load avg resolution time:', err));
}

function loadTodayIntake(filters) {
    postRequest('dashboard.php', { action: 'get_today_intake', ...filters })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load today intake:', data.error);
                return;
            }
            
            const trend = data.trend >= 0 ? `<i class="bi bi-arrow-up text-danger"></i> ${data.trend}%` : `<i class="bi bi-arrow-down text-success"></i> ${Math.abs(data.trend)}%`;
            
            document.getElementById('todayIntake').textContent = data.today;
            document.getElementById('todayTrend').innerHTML = data.yesterday > 0 ? trend : '<i class="bi bi-dash"></i> -';
        })
        .catch(err => console.error('Failed to load today intake:', err));
}

function loadWeeklyTrend(filters) {
    postRequest('dashboard.php', { action: 'get_weekly_trend', ...filters })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load weekly trend:', data.error);
                return;
            }
            
            let trendColor = 'text-warning';
            let trendIcon = data.trend_direction === 'up' ? '<i class="bi bi-arrow-up text-danger"></i>' : '<i class="bi bi-arrow-down text-success"></i>';
            
            if (data.trend > 0) trendColor = 'text-danger';
            else if (data.trend < 0) trendColor = 'text-success';
            
            document.getElementById('weeklyTrend').innerHTML = `${trendIcon} ${Math.abs(data.trend)}%`;
            document.getElementById('weeklyTrend').className = `display-5 fw-bold ${trendColor}`;
            document.getElementById('weeklyStats').textContent = `${data.current} vs ${data.last}`;
        })
        .catch(err => console.error('Failed to load weekly trend:', err));
}

function loadProblematicISP(filters) {
    postRequest('dashboard.php', { action: 'get_problematic_isp', ...filters })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load problematic ISP:', data.error);
                return;
            }
            
            const ispColor = data.ticket_count > 0 ? 'text-danger' : 'text-success';
            
            document.getElementById('problematicSite').textContent = data.isp_name;
            document.getElementById('problematicSite').className = `display-6 fw-bold ${ispColor}`;
            document.getElementById('problematicSiteCount').textContent = data.ticket_count + ' Issues';
        })
        .catch(err => console.error('Failed to load problematic ISP:', err));
}

function loadStats(filters) {
    postRequest('dashboard.php', { action: 'get_stats', ...filters })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load stats:', data.error);
                return;
            }
            
            document.getElementById('totalTickets').textContent = data.total_tickets;
            document.getElementById('openTickets').textContent = data.open_count;
            document.getElementById('inProgressTickets').textContent = data.in_progress_count;
            document.getElementById('resolvedTickets').textContent = data.resolved_count;
            document.getElementById('closedTickets').textContent = data.closed_count;
            document.getElementById('agingTickets').textContent = data.aging_count;
            
            // Highlight aging tickets if any
            const agingCard = document.getElementById('agingTickets').closest('.card');
            if (data.aging_count > 0) {
                agingCard.classList.add('border-danger', 'border-3');
                agingCard.style.backgroundColor = '#fff5f5';
            } else {
                agingCard.classList.remove('border-danger', 'border-3');
                agingCard.style.backgroundColor = '';
            }
        })
        .catch(err => console.error('Failed to load stats:', err));
}

function loadChartStatus(filters) {
    postRequest('dashboard.php', { action: 'get_chart_status', ...filters })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load status chart:', data.error);
                return;
            }
            
            statusChart.data.labels = data.map(item => item.status);
            statusChart.data.datasets[0].data = data.map(item => item.count);
            statusChart.update();
        })
        .catch(err => console.error('Failed to load status chart:', err));
}

function loadChartAging(filters) {
    postRequest('dashboard.php', { action: 'get_chart_aging', ...filters })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load aging chart:', data.error);
                return;
            }
            
            // Filter out NULL creators and convert aging_count to numbers
            const filteredData = data.filter(item => item.creator !== null && item.creator !== '');
            agingChart.data.labels = filteredData.map(item => item.creator);
            agingChart.data.datasets[0].data = filteredData.map(item => parseInt(item.aging_count));
            
            agingChart.update();
        })
        .catch(err => console.error('Failed to load aging chart:', err));
}

function loadChartCreatedBy(filters) {
    postRequest('dashboard.php', { action: 'get_chart_created_by', ...filters })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load created by chart:', data.error);
                return;
            }
            
            const dayMap = {2: 0, 3: 1, 4: 2, 5: 3, 6: 4}; // index for Monday to Friday
            const labels = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            const creators = [...new Set(data.map(item => item.creator))];
            const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#C9CBCF', '#FF6384', '#36A2EB', '#FFCE56'];

            const datasets = creators.slice(0, 10).map((creator, index) => { // limit to 10 creators
                const creatorData = data.filter(item => item.creator === creator);
                const dayData = [0, 0, 0, 0, 0];
                creatorData.forEach(item => {
                    const dayIndex = dayMap[item.day_of_week];
                    dayData[dayIndex] = parseFloat(item.avg_tickets);
                });
                return {
                    label: creator,
                    data: dayData,
                    backgroundColor: colors[index % colors.length]
                };
            });

            createdByChart.data.labels = labels;
            createdByChart.data.datasets = datasets;
            createdByChart.update();
        })
        .catch(err => console.error('Failed to load creator chart:', err));
}

function loadRecentTickets(filters) {
    postRequest('dashboard.php', { action: 'get_recent_tickets', ...filters, limit: 10 })
        .then(data => {
            // Check for error response
            if (data.error) {
                console.error('Failed to load recent tickets:', data.error);
                return;
            }
            
            const tbody = document.querySelector('#recentTicketsTable tbody');
            tbody.innerHTML = '';
            
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No tickets found</td></tr>';
                return;
            }
            
            data.forEach(ticket => {
                const row = `
                    <tr>
                        <td><a href="detail_ticket.php?id=${ticket.id}" class="text-decoration-none">${ticket.ticket_number}</a></td>
                        <td>${ticket.site_name || 'N/A'}</td>
                        <td>${ticket.isp || 'N/A'}</td>
                        <td>${ticket.subject.length > 50 ? ticket.subject.substring(0, 50) + '...' : ticket.subject}</td>
                        <td><span class="badge ${getStatusBadgeClass(ticket.status)}">${ticket.status}</span></td>
                        <td>${ticket.created_by_name || 'N/A'}</td>
                        <td>${new Date(ticket.created_at).toLocaleDateString()}</td>
                        <td>
                            <a href="detail_ticket.php?id=${ticket.id}" class="btn btn-sm btn-outline-primary">View</a>
                        </td>
                    </tr>
                `;
                tbody.insertAdjacentHTML('beforeend', row);
            });
        })
        .catch(err => console.error('Failed to load recent tickets:', err));
}

function loadAgingTickets(filters = null, page = 1) {
    if (!filters) {
        filters = getFilters();
    }

    const limit = 10;
    const offset = (page - 1) * limit;

    // Show loading state, hide others
    document.getElementById('agingTicketsLoading').style.display = 'block';
    document.getElementById('agingTicketsEmpty').style.display = 'none';
    document.getElementById('agingTicketsError').style.display = 'none';
    document.getElementById('agingTicketsTable').style.display = 'none';

    postRequest('dashboard.php', {
        action: 'get_aging_tickets',
        ...filters,
        limit: limit,
        offset: offset
    })
    .then(data => {
        // Check for error response
        if (data.error) {
            console.error('Failed to load aging tickets:', data.error);
            // Show error state
            document.getElementById('agingTicketsLoading').style.display = 'none';
            document.getElementById('agingTicketsError').style.display = 'block';
            document.getElementById('agingTicketsEmpty').style.display = 'none';
            document.getElementById('agingTicketsTable').style.display = 'none';
            return;
        }
        
        displayAgingTickets(data, page, limit);
    })
    .catch(err => {
        console.error('Failed to load aging tickets:', err);
        // Show error state
        document.getElementById('agingTicketsLoading').style.display = 'none';
        document.getElementById('agingTicketsError').style.display = 'block';
        document.getElementById('agingTicketsEmpty').style.display = 'none';
        document.getElementById('agingTicketsTable').style.display = 'none';
    });
}

function displayAgingTickets(data, currentPage, limit) {
    const badge = document.getElementById('agingCountBadge');

    // Update badge
    badge.textContent = data.total;

    if (data.tickets.length === 0) {
        // Show empty state
        document.getElementById('agingTicketsLoading').style.display = 'none';
        document.getElementById('agingTicketsEmpty').style.display = 'block';
        document.getElementById('agingTicketsError').style.display = 'none';
        document.getElementById('agingTicketsTable').style.display = 'none';
        return;
    }

    // Show table
    document.getElementById('agingTicketsLoading').style.display = 'none';
    document.getElementById('agingTicketsEmpty').style.display = 'none';
    document.getElementById('agingTicketsError').style.display = 'none';
    document.getElementById('agingTicketsTable').style.display = 'block';

    const tbody = document.getElementById('agingTicketsTableBody');
    tbody.innerHTML = '';

    data.tickets.forEach(ticket => {
        const row = `
            <tr class="${ticket.aging_days >= 7 ? 'table-danger' : ticket.aging_days >= 5 ? 'table-warning' : ''}">
                <td>
                    <a href="detail_ticket.php?id=${ticket.id}" class="text-decoration-none fw-bold">
                        ${ticket.ticket_number}
                    </a>
                </td>
                <td>${ticket.site_name || 'N/A'}</td>
                <td>${ticket.isp || 'N/A'}</td>
                <td>
                    <div style="max-width: 200px;" class="text-truncate" title="${ticket.subject}">
                        ${ticket.subject}
                    </div>
                </td>
                <td><span class="badge ${getStatusBadgeClass(ticket.status)}">${ticket.status}</span></td>
                <td>${new Date(ticket.created_at).toLocaleDateString()}</td>
                <td>${ticket.created_by_name || 'N/A'}</td>
                <td>
                    <span class="badge ${ticket.aging_days >= 7 ? 'bg-danger' : ticket.aging_days >= 5 ? 'bg-warning' : 'bg-secondary'}">
                        ${ticket.aging_days} days
                    </span>
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <a href="detail_ticket.php?id=${ticket.id}" class="btn btn-outline-primary" title="View Details">
                            <i class="bi bi-eye"></i>
                        </a>
                        ${ticket.status !== 'CLOSED' ? `<a href="edit_ticket.php?id=${ticket.id}" class="btn btn-outline-secondary" title="Edit Ticket">
                            <i class="bi bi-pencil"></i>
                        </a>` : ''}
                    </div>
                </td>
            </tr>
        `;
        tbody.insertAdjacentHTML('beforeend', row);
    });

    // Handle pagination
    const totalPages = Math.ceil(data.total / limit);
    if (totalPages > 1) {
        displayAgingPagination(totalPages, currentPage);
        document.getElementById('agingPagination').style.display = 'block';
    } else {
        document.getElementById('agingPagination').style.display = 'none';
    }
}

function displayAgingPagination(totalPages, currentPage) {
    const paginationList = document.getElementById('agingPaginationList');
    paginationList.innerHTML = '';

    // Previous button
    const prevDisabled = currentPage === 1 ? 'disabled' : '';
    paginationList.innerHTML += `
        <li class="page-item ${prevDisabled}">
            <a class="page-link" href="#" onclick="loadAgingTickets(null, ${currentPage - 1}); return false;">Previous</a>
        </li>
    `;

    // Page numbers
    const startPage = Math.max(1, currentPage - 2);
    const endPage = Math.min(totalPages, currentPage + 2);

    if (startPage > 1) {
        paginationList.innerHTML += `
            <li class="page-item">
                <a class="page-link" href="#" onclick="loadAgingTickets(null, 1); return false;">1</a>
            </li>
        `;
        if (startPage > 2) {
            paginationList.innerHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }

    for (let i = startPage; i <= endPage; i++) {
        const active = i === currentPage ? 'active' : '';
        paginationList.innerHTML += `
            <li class="page-item ${active}">
                <a class="page-link" href="#" onclick="loadAgingTickets(null, ${i}); return false;">${i}</a>
            </li>
        `;
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            paginationList.innerHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        paginationList.innerHTML += `
            <li class="page-item">
                <a class="page-link" href="#" onclick="loadAgingTickets(null, ${totalPages}); return false;">${totalPages}</a>
            </li>
        `;
    }

    // Next button
    const nextDisabled = currentPage === totalPages ? 'disabled' : '';
    paginationList.innerHTML += `
        <li class="page-item ${nextDisabled}">
            <a class="page-link" href="#" onclick="loadAgingTickets(null, ${currentPage + 1}); return false;">Next</a>
        </li>
    `;
}

function refreshAgingTickets() {
    loadAgingTickets(null, 1);
}

function getStatusBadgeClass(status) {
    const classes = {
        'OPEN': 'bg-danger',
        'IN_PROGRESS': 'bg-warning text-dark',
        'RESOLVED': 'bg-info',
        'CLOSED': 'bg-success'
    };
    return classes[status] || 'bg-secondary';
}

function postRequest(url, data) {
    // ensure arrays are serialized with [] so PHP treats them as arrays
    const params = new URLSearchParams();
    for (const key in data) {
        const value = data[key];
        if (Array.isArray(value)) {
            value.forEach(v => {
                params.append(key + '[]', v);
            });
        } else if (value !== undefined && value !== null) {
            params.append(key, value);
        }
    }

    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: params.toString()
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    });
}

// Helper function to add delay for better UX
function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

</script>

</body>
</html>