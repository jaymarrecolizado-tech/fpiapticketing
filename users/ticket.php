<?php
ob_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require '../config/db.php';
require '../config/auth.php';
require '../lib/Validator.php';
require '../lib/Sanitizer.php';
require '../notif/notification.php';
require '../lib/Logger.php';
require '../lib/auto_close.php';
require_once '../lib/TicketHistory.php';

requireLogin();

// Get current user's personnel_id
$stmt = $pdo->prepare("SELECT personnel_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: ../index.php');
    exit;
}
$personnelId = $user['personnel_id'];

$action = $_POST['action'] ?? '';

// AJAX: Get filter options
if ($action == 'get_filter_options') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $projects = $pdo->prepare("SELECT DISTINCT project_name FROM sites WHERE created_by = ? AND project_name IS NOT NULL AND project_name != '' ORDER BY project_name");
        $projects->execute([$personnelId]);
        $projects = $projects->fetchAll(PDO::FETCH_COLUMN);

        $statuses = $pdo->prepare("SELECT DISTINCT status FROM sites WHERE created_by = ? AND status IS NOT NULL AND status != '' ORDER BY status");
        $statuses->execute([$personnelId]);
        $statuses = $statuses->fetchAll(PDO::FETCH_COLUMN);

        $provinces = $pdo->prepare("SELECT DISTINCT province FROM sites WHERE created_by = ? AND province IS NOT NULL AND province != '' ORDER BY province");
        $provinces->execute([$personnelId]);
        $provinces = $provinces->fetchAll(PDO::FETCH_COLUMN);

        $municipalities = $pdo->prepare("SELECT DISTINCT municipality FROM sites WHERE created_by = ? AND municipality IS NOT NULL AND municipality != '' ORDER BY municipality");
        $municipalities->execute([$personnelId]);
        $municipalities = $municipalities->fetchAll(PDO::FETCH_COLUMN);

        $isps = $pdo->prepare("SELECT DISTINCT isp FROM sites WHERE created_by = ? AND isp IS NOT NULL AND isp != '' ORDER BY isp");
        $isps->execute([$personnelId]);
        $isps = $isps->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode([
            'projects' => $projects,
            'statuses' => $statuses,
            'provinces' => $provinces,
            'municipalities' => $municipalities,
            'isps' => $isps
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: Search sites
if ($action == 'search_sites') {
    ob_clean();
    header('Content-Type: application/json');
    $query = $_POST['query'] ?? '';
    $projectFilter = $_POST['project_filter'] ?? '';
    $statusFilter = $_POST['status_filter'] ?? '';
    $provinceFilter = $_POST['province_filter'] ?? '';
    $municipalityFilter = $_POST['municipality_filter'] ?? '';
    $ispFilter = $_POST['isp_filter'] ?? '';

    if (strlen($query) > 100) $query = substr($query, 0, 100);
    if (!empty($query)) $query = Sanitizer::normalize($query);

    $page = max(1, intval($_POST['page'] ?? 1));
    $limit = 5;
    $offset = ($page - 1) * $limit;

    try {
        $conditions = ["s.created_by = ?"];
        $params = [$personnelId];

        if (!empty($query)) {
            $conditions[] = "(s.site_name LIKE ? OR s.project_name LIKE ? OR s.location_name LIKE ?)";
            $searchTerm = '%' . $query . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        if (!empty($projectFilter)) { $conditions[] = "s.project_name = ?"; $params[] = $projectFilter; }
        if (!empty($statusFilter)) { $conditions[] = "s.status = ?"; $params[] = $statusFilter; }
        if (!empty($provinceFilter)) { $conditions[] = "s.province = ?"; $params[] = $provinceFilter; }
        if (!empty($municipalityFilter)) { $conditions[] = "s.municipality = ?"; $params[] = $municipalityFilter; }
        if (!empty($ispFilter)) { $conditions[] = "s.isp = ?"; $params[] = $ispFilter; }

        $where = implode(" AND ", $conditions);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sites s WHERE $where");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        $sql = "SELECT s.id, s.site_name, s.project_name, s.location_name FROM sites s WHERE $where ORDER BY s.site_name ASC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as &$row) {
            $row['display_text'] = $row['site_name'] . ' (' . $row['project_name'] . ')';
        }

        echo json_encode([
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: Create ticket
if ($action == 'create_tickets') {
    ob_clean();
    header('Content-Type: application/json');

    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    try {
        $siteIds = $_POST['site_ids'] ?? '';
        $subject = $_POST['subject'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $priority = $_POST['priority'] ?? 'medium';

        $subject = Sanitizer::normalize($subject);
        $notes = Sanitizer::remarks($notes);
        $priority = Sanitizer::normalize($priority);

        if (empty($siteIds) || !Validator::subject($subject)) {
            echo json_encode(['success' => false, 'message' => 'Invalid subject (5-255 characters required)']);
            exit;
        }
        if (!empty($notes) && !Validator::remarks($notes)) {
            echo json_encode(['success' => false, 'message' => 'Notes exceed maximum length']);
            exit;
        }
        $validStatuses = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
        $validPriorities = ['low', 'medium', 'high', 'critical'];
        if (!Validator::inList($priority, $validPriorities)) {
            $priority = 'medium';
        }

        $siteIdArray = array_filter(array_map('intval', explode(',', $siteIds)));
        if (empty($siteIdArray)) {
            echo json_encode(['success' => false, 'message' => 'No valid sites selected']);
            exit;
        }

        $successCount = 0;
        $skippedCount = 0;
        $createdTickets = [];

        foreach ($siteIdArray as $siteId) {
            // Verify site ownership
            $chk = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND created_by = ?");
            $chk->execute([$siteId, $personnelId]);
            if (!$chk->fetch()) {
                $skippedCount++;
                continue;
            }

            // Check for unresolved tickets
            $checkStmt = $pdo->prepare("SELECT id FROM tickets WHERE site_id = ? AND status NOT IN ('CLOSED', 'RESOLVED')");
            $checkStmt->execute([$siteId]);
            if ($checkStmt->rowCount() > 0) {
                $skippedCount++;
                continue;
            }

            $year = date('Y');
            $counterStmt = $pdo->prepare("INSERT INTO ticket_counter (year, counter, updated_at) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE counter = counter + 1");
            $counterStmt->execute([$year]);

            $getCounterStmt = $pdo->prepare("SELECT counter FROM ticket_counter WHERE year = ?");
            $getCounterStmt->execute([$year]);
            $counter = $getCounterStmt->fetchColumn();
            $ticketNumber = sprintf('F-SMART-%d-%04d', $year, $counter);

            $insertStmt = $pdo->prepare("INSERT INTO tickets (ticket_number, site_id, subject, status, priority, notes, created_by, created_at, updated_at, solved_date) VALUES (?, ?, ?, 'OPEN', ?, ?, ?, NOW(), NOW(), NULL)");
            $insertStmt->execute([$ticketNumber, $siteId, $subject, $priority, $notes, $personnelId]);

            $ticketId = $pdo->lastInsertId();
            $history = new TicketHistory($pdo);
            $history->logChange($ticketId, $personnelId, 'created', null, null, 'Ticket created');

            $successCount++;
            $createdTickets[] = ['id' => $ticketId, 'number' => $ticketNumber];

            $logger = new Logger($pdo);
            $logger->logEntityAction('ticket_created', 'ticket', $ticketId, [
                'ticket_number' => $ticketNumber,
                'site_id' => $siteId,
                'subject' => $subject,
                'status' => 'OPEN',
                'priority' => $priority,
                'created_by' => $personnelId
            ], 'low');
        }

        // Send notifications
        if (!empty($createdTickets)) {
            try {
                $creatorStmt = $pdo->prepare("SELECT id, fullname FROM personnels WHERE id = ?");
                $creatorStmt->execute([$personnelId]);
                $creator = $creatorStmt->fetch(PDO::FETCH_ASSOC);
                if ($creator) {
                    $notifier = new NotificationManager($pdo);
                    $notifier->notifyBulkTicketsCreated($createdTickets, $creator);
                }
            } catch (Exception $e) {
                error_log("Error sending notifications: " . $e->getMessage());
            }
        }

        $message = "Created $successCount ticket(s)";
        if ($skippedCount > 0) $message .= ", Skipped $skippedCount site(s)";

        echo json_encode([
            'success' => $successCount > 0,
            'message' => $message,
            'created_count' => $successCount,
            'skipped_count' => $skippedCount,
            'ticket_numbers' => array_column($createdTickets, 'number')
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

requireLogin();
$activePage = 'tickets';
?>
<?php require __DIR__ . '/../includes/user_header.php'; ?>

<main class="flex-grow-1 overflow-auto">
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-lg-8 col-12 pe-3">
            <div id="alertContainer"></div>
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Create New Ticket</h5>
                </div>
                <div class="card-body">
                    <form id="ticketForm" onsubmit="saveTicket(event)">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Filter Sites</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <select id="projectFilter" class="form-select form-select-sm" onchange="onFilterChange()">
                                        <option value="">All Projects</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <select id="statusFilter" class="form-select form-select-sm" onchange="onFilterChange()">
                                        <option value="">All Statuses</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <select id="provinceFilter" class="form-select form-select-sm" onchange="onFilterChange()">
                                        <option value="">All Provinces</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <select id="municipalityFilter" class="form-select form-select-sm" onchange="onFilterChange()">
                                        <option value="">All Municipalities</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <select id="ispFilter" class="form-select form-select-sm" onchange="onFilterChange()">
                                        <option value="">ISP (any)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Search & Select Site(s) <span class="text-danger">*</span></label>
                            <div style="position: relative;">
                                <input type="text" id="siteSearch" class="form-control" placeholder="Type site name or location..." autocomplete="off">
                                <div id="siteAutocompleteList" style="position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-top: none; display: none; max-height: 220px; overflow-y: auto; z-index: 1000;"></div>
                            </div>
                            <div id="selectedSitesDisplay" class="mt-2" style="display: flex; flex-wrap: wrap; gap: 8px;"></div>
                            <input type="hidden" id="siteIds" name="site_ids">
                        </div>

                        <hr>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Priority</label>
                            <select id="priority" name="priority" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Subject <span class="text-danger">*</span></label>
                            <input type="text" id="subject" name="subject" class="form-control" placeholder="Ticket subject/title" required maxlength="200">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Optional notes..."></textarea>
                        </div>

                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-check-circle"></i> Create Ticket(s)
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-12 ps-3">
            <div class="card shadow-sm" style="position: sticky; top: 20px;">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Ticket Summary</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <h6 class="text-muted">Selected Sites:</h6>
                        <div id="summarySites" style="padding: 10px; background: #f8f9fa; border-radius: 4px; min-height: 40px;">
                            <span class="text-muted small">No sites selected</span>
                        </div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <h6 class="text-muted">Subject:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small id="summarySubject" class="text-muted">Not set</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <h6 class="text-muted">Notes:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px; min-height: 60px; word-wrap: break-word; white-space: pre-wrap;">
                            <small id="summaryNotes" class="text-muted">No notes</small>
                        </div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <h6 class="text-muted">Tickets to Create:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small>
                                <strong>Count:</strong> <span id="summaryCount" class="badge bg-primary">0</span><br>
                                <strong>Status:</strong> <span class="badge bg-info">OPEN</span>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<script>
let selectedSites = [];
let currentPage = 1;
let currentQuery = '';

document.addEventListener('DOMContentLoaded', function() {
    loadFilterOptions();
    initSiteAutocomplete();
});

function initSiteAutocomplete() {
    const input = document.getElementById('siteSearch');
    const list = document.getElementById('siteAutocompleteList');
    if (!input) return;

    input.addEventListener('focus', function() {
        if (list.style.display === 'none') {
            currentQuery = this.value;
            currentPage = 1;
            performSiteSearch();
        }
    });
    input.addEventListener('input', function() {
        currentQuery = this.value;
        currentPage = 1;
        performSiteSearch();
    });
    document.addEventListener('click', function(e) {
        if (e.target !== input && !list.contains(e.target)) {
            list.style.display = 'none';
        }
    });
}

function performSiteSearch() {
    const list = document.getElementById('siteAutocompleteList');
    const projectFilter = document.getElementById('projectFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const provinceFilter = document.getElementById('provinceFilter').value;
    const municipalityFilter = document.getElementById('municipalityFilter').value;
    const ispFilter = document.getElementById('ispFilter').value;

    fetch('ticket.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=search_sites&query=${encodeURIComponent(currentQuery)}&page=${currentPage}&project_filter=${encodeURIComponent(projectFilter)}&status_filter=${encodeURIComponent(statusFilter)}&province_filter=${encodeURIComponent(provinceFilter)}&municipality_filter=${encodeURIComponent(municipalityFilter)}&isp_filter=${encodeURIComponent(ispFilter)}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) { console.error(data.error); return; }
        let html = '';
        data.results.forEach(site => {
            const isSelected = selectedSites.some(s => s.id === site.id);
            if (!isSelected) {
                html += `<div class="p-2 border-bottom site-option" style="cursor:pointer;" onclick="selectSite(${site.id}, '${site.site_name.replace(/'/g, "\\'")}', '${site.project_name.replace(/'/g, "\\'")}')">${site.display_text}</div>`;
            }
        });
        if (data.pages > currentPage) {
            html += `<div class="p-2 text-center text-primary" style="cursor:pointer;" onclick="currentPage++; performSiteSearch();">Load more...</div>`;
        }
        if (!html) html = '<div class="p-2 text-muted">No sites found</div>';
        list.innerHTML = html;
        list.style.display = 'block';
    });
}

function selectSite(id, name, project) {
    if (selectedSites.some(s => s.id === id)) return;
    selectedSites.push({id, name, project});
    updateSelectedSitesDisplay();
    document.getElementById('siteSearch').value = '';
    document.getElementById('siteAutocompleteList').style.display = 'none';
}

function removeSite(id) {
    selectedSites = selectedSites.filter(s => s.id !== id);
    updateSelectedSitesDisplay();
}

function updateSelectedSitesDisplay() {
    const container = document.getElementById('selectedSitesDisplay');
    const hidden = document.getElementById('siteIds');
    const summary = document.getElementById('summarySites');
    const count = document.getElementById('summaryCount');

    if (selectedSites.length === 0) {
        container.innerHTML = '';
        hidden.value = '';
        summary.innerHTML = '<span class="text-muted small">No sites selected</span>';
        count.textContent = '0';
        return;
    }

    let html = '';
    let summaryHtml = '';
    let ids = [];
    selectedSites.forEach(s => {
        html += `<span class="badge bg-primary d-flex align-items-center gap-1">${s.name} <i class="bi bi-x-circle" style="cursor:pointer;" onclick="removeSite(${s.id})"></i></span>`;
        summaryHtml += `<div class="small mb-1">${s.name} (${s.project})</div>`;
        ids.push(s.id);
    });
    container.innerHTML = html;
    hidden.value = ids.join(',');
    summary.innerHTML = summaryHtml;
    count.textContent = selectedSites.length;
}

function loadFilterOptions() {
    fetch('ticket.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_filter_options'
    })
    .then(res => res.json())
    .then(data => {
        if (data.projects) fillSelect('projectFilter', data.projects);
        if (data.statuses) fillSelect('statusFilter', data.statuses);
        if (data.provinces) fillSelect('provinceFilter', data.provinces);
        if (data.municipalities) fillSelect('municipalityFilter', data.municipalities);
        if (data.isps) fillSelect('ispFilter', data.isps);
    });
}

function fillSelect(id, options) {
    const select = document.getElementById(id);
    options.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt;
        option.textContent = opt;
        select.appendChild(option);
    });
}

function onFilterChange() {
    currentPage = 1;
    currentQuery = document.getElementById('siteSearch').value;
    performSiteSearch();
}

function saveTicket(e) {
    e.preventDefault();
    const siteIds = document.getElementById('siteIds').value;
    if (!siteIds) {
        showAlert('Please select at least one site', 'danger');
        return;
    }

    const formData = new FormData(document.getElementById('ticketForm'));
    formData.append('action', 'create_tickets');
    formData.append('site_ids', siteIds);

    fetch('ticket.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            selectedSites = [];
            updateSelectedSitesDisplay();
            document.getElementById('ticketForm').reset();
        } else {
            showAlert(data.message, 'danger');
        }
    })
    .catch(err => showAlert('Error: ' + err.message, 'danger'));
}

function showAlert(message, type) {
    const container = document.getElementById('alertContainer');
    container.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
    setTimeout(() => { container.innerHTML = ''; }, 5000);
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
