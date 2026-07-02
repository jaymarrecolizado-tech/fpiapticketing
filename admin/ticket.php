<?php
// Start output buffering to prevent stray HTML from breaking JSON responses
ob_start();

// Prevent PHP warnings/notices from corrupting AJAX JSON replies
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require '../config/db.php';
require '../config/auth.php';
require '../lib/Validator.php';
require '../lib/Sanitizer.php';
require '../notif/notification.php';
require '../lib/Logger.php';
require '../lib/AutoClose.php';
require_once '../lib/TicketHistory.php';

$action = $_POST['action'] ?? '';

// Fetch filter options for dropdowns
if ($action == 'get_filter_options') {
    // Clear any stray output from includes
    ob_clean();
    header('Content-Type: application/json');
    try {
        $projects = $pdo->query("SELECT DISTINCT project_name FROM sites WHERE project_name IS NOT NULL AND project_name != '' ORDER BY project_name")->fetchAll(PDO::FETCH_COLUMN);
        $statuses = $pdo->query("SELECT DISTINCT status FROM sites WHERE status IS NOT NULL AND status != '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
        $provinces = $pdo->query("SELECT DISTINCT province FROM sites WHERE province IS NOT NULL AND province != '' ORDER BY province")->fetchAll(PDO::FETCH_COLUMN);
        $municipalities = $pdo->query("SELECT DISTINCT municipality FROM sites WHERE municipality IS NOT NULL AND municipality != '' ORDER BY municipality")->fetchAll(PDO::FETCH_COLUMN);
        $isps = $pdo->query("SELECT DISTINCT isp FROM sites WHERE isp IS NOT NULL AND isp != '' ORDER BY isp")->fetchAll(PDO::FETCH_COLUMN);

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

// Create Tickets
if ($action == 'create_tickets') {
    // Clear any stray output from includes
    ob_clean();
    header('Content-Type: application/json');
    // ===== CSRF Validation =====
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
        exit;
    }

    try {
        // Validate inputs
        $siteIds = $_POST['site_ids'] ?? '';
        $subject = $_POST['subject'] ?? '';
    $status = $_POST['status'] ?? 'OPEN';
    $notes = $_POST['notes'] ?? '';
    $priority = $_POST['priority'] ?? 'medium';
    $category = $_POST['category'] ?? '';
    $dueDate = $_POST['due_date'] ?? '';
    // use the personnel ID stored in session for ownership (matches tickets.created_by FK)
    $createdBy = $_SESSION['personnel_id'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 1;

    // Sanitize inputs
    $subject = Sanitizer::normalize($subject);
    $status = Sanitizer::normalize($status);
    $notes = Sanitizer::remarks($notes);
    $priority = Sanitizer::normalize($priority);
    $category = Sanitizer::normalize($category);
    $dueDate = !empty($dueDate) ? $dueDate : null;

    // Validate required fields
    if (empty($siteIds) || !Validator::subject($subject)) {
        echo json_encode(['success' => false, 'message' => 'Invalid subject (5-255 characters required)']);
        exit;
    }

    // Validate notes if provided
    if (!empty($notes) && !Validator::remarks($notes)) {
        echo json_encode(['success' => false, 'message' => 'Notes exceed maximum length (2000 characters)']);
        exit;
    }

    // Validate priority
    $validPriorities = ['low', 'medium', 'high', 'critical'];
    if (!Validator::inList($priority, $validPriorities)) {
        $priority = 'medium';
    }

    // Validate status
    $validStatuses = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
    if (!Validator::inList($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    // Parse and validate site IDs
    $siteIdArray = array_filter(array_map('intval', explode(',', $siteIds)));
    if (empty($siteIdArray) || !Validator::integerArray($siteIdArray, 1, 100)) {
        echo json_encode(['success' => false, 'message' => 'No valid sites selected']);
        exit;
    }

        $successCount = 0;
        $skippedCount = 0;
        $createdTickets = [];
        $errors = [];

        foreach ($siteIdArray as $siteId) {
            try {
                // Check for unresolved tickets on this site
                $checkStmt = $pdo->prepare("SELECT id FROM tickets WHERE site_id = ? AND status NOT IN ('CLOSED', 'RESOLVED')");
                $checkStmt->execute([$siteId]);
                if ($checkStmt->rowCount() > 0) {
                    $skippedCount++;
                    continue;
                }

                // Generate ticket number: F-SMART-YYYY-NNNN
                $year = date('Y');
                $counterStmt = $pdo->prepare("INSERT INTO ticket_counter (year, counter, updated_at) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE counter = counter + 1");
                $counterStmt->execute([$year]);

                // Get current counter value
                $getCounterStmt = $pdo->prepare("SELECT counter FROM ticket_counter WHERE year = ?");
                $getCounterStmt->execute([$year]);
                $counter = $getCounterStmt->fetchColumn();
                $ticketNumber = sprintf('F-SMART-%d-%04d', $year, $counter);

                // Insert ticket
                $insertStmt = $pdo->prepare("INSERT INTO tickets (ticket_number, site_id, subject, status, priority, category, notes, created_by, created_at, updated_at, solved_date, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NULL, ?)");
                $insertStmt->execute([
                    $ticketNumber,
                    $siteId,
                    $subject,
                    $status,
                    $priority,
                    $category ?: null,
                    $notes,
                    $createdBy,
                    $dueDate
                ]);

                $ticketId = $pdo->lastInsertId();
                
                // Log ticket creation in history
                $history = new TicketHistory($pdo);
                $history->logChange($ticketId, $createdBy, 'created', null, null, 'Ticket created');
                
                $successCount++;
                $createdTickets[] = [
                    'id' => $ticketId,
                    'number' => $ticketNumber
                ];

                // Log ticket creation
                $logger = new Logger($pdo);
                $logger->logEntityAction('ticket_created', 'ticket', $ticketId, [
                    'ticket_number' => $ticketNumber,
                    'site_id' => $siteId,
                    'subject' => $subject,
                    'status' => $status,
                    'created_by' => $createdBy
                ], 'low');

            } catch (Exception $e) {
                $errors[] = "Site ID $siteId: " . $e->getMessage();
            }
        }

        // Send notifications for created tickets
        if (!empty($createdTickets)) {
            try {
                // Get creator details for notifications (createdBy is now a personnels.id)
                $creatorStmt = $pdo->prepare("SELECT id, fullname FROM personnels WHERE id = ?");
                $creatorStmt->execute([$createdBy]);
                $creator = $creatorStmt->fetch(PDO::FETCH_ASSOC);

                if ($creator) {
                    $notifier = new NotificationManager($pdo);
                    $result = $notifier->notifyBulkTicketsCreated($createdTickets, $creator);
                    if (!$result) {
                        error_log("Failed to create bulk ticket notification for creator personnel ID: {$createdBy}");
                    }
                } else {
                    error_log("Creator not found for user ID: $createdBy. Personnel ID may not exist.");
                }
            } catch (Exception $e) {
                error_log("Error sending notifications: " . $e->getMessage());
            }
        }

        $message = "Created $successCount ticket(s)";
        if ($skippedCount > 0) {
            $message .= ", Skipped $skippedCount site(s) with unresolved tickets";
        }

        // Extract ticket numbers for response
        $ticketNumbers = array_map(function($ticket) {
            return $ticket['number'];
        }, $createdTickets);

        echo json_encode([
            'success' => $successCount > 0,
            'message' => $message,
            'created_count' => $successCount,
            'skipped_count' => $skippedCount,
            'ticket_numbers' => $ticketNumbers,
            'errors' => $errors
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Autocomplete Search for Sites (supports filters)
if ($action == 'search_sites') {
    header('Content-Type: application/json');
    $query = $_POST['query'] ?? '';
    $projectFilter = $_POST['project_filter'] ?? '';
    $statusFilter = $_POST['status_filter'] ?? '';
    $provinceFilter = $_POST['province_filter'] ?? '';
    $municipalityFilter = $_POST['municipality_filter'] ?? '';
    $ispFilter = $_POST['isp_filter'] ?? '';

    // Validate and sanitize inputs
    if (strlen($query) > 100) {
        $query = substr($query, 0, 100);
    }
    if (!empty($query)) {
        $query = Sanitizer::normalize($query);
    }
    $projectFilter = !empty($projectFilter) ? Sanitizer::normalize($projectFilter) : '';
    $statusFilter = !empty($statusFilter) ? Sanitizer::normalize($statusFilter) : '';
    $provinceFilter = !empty($provinceFilter) ? Sanitizer::normalize($provinceFilter) : '';
    $municipalityFilter = !empty($municipalityFilter) ? Sanitizer::normalize($municipalityFilter) : '';
    $ispFilter = !empty($ispFilter) ? Sanitizer::normalize($ispFilter) : '';

    $page = (int)($_POST['page'] ?? 1);
    $limit = 5;
    $offset = ($page - 1) * $limit;

    try {
        // Build WHERE clause with optional filters
        $conditions = [];
        $params = [];

        if (!empty($query)) {
            $conditions[] = "(site_name LIKE ? OR project_name LIKE ? OR location_name LIKE ?)";
            $searchTerm = '%' . $query . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($projectFilter)) {
            $conditions[] = "project_name = ?";
            $params[] = $projectFilter;
        }

        if (!empty($statusFilter)) {
            $conditions[] = "status = ?";
            $params[] = $statusFilter;
        }

        if (!empty($provinceFilter)) {
            $conditions[] = "province = ?";
            $params[] = $provinceFilter;
        }

        if (!empty($municipalityFilter)) {
            $conditions[] = "municipality = ?";
            $params[] = $municipalityFilter;
        }

        if (!empty($ispFilter)) {
            $conditions[] = "isp = ?";
            $params[] = $ispFilter;
        }

        // Count total results
        $sqlCount = "SELECT COUNT(*) FROM sites";
        if (!empty($conditions)) {
            $sqlCount .= " WHERE " . implode(" AND ", $conditions);
        }

        $stmtCount = $pdo->prepare($sqlCount);
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();

        // Fetch paginated results
        $sql = "SELECT id, site_name, project_name, location_name FROM sites";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        $sql .= " ORDER BY site_name ASC LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format display name
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

// Manual trigger for auto-closing resolved tickets (optional endpoint)
if ($action == 'auto_close_resolved') {
    header('Content-Type: application/json');
    try {
        $autoClosed = autoCloseResolvedTickets($pdo, $logger);
        echo json_encode([
            'success' => true,
            'message' => "$autoClosed tickets auto-closed",
            'count' => $autoClosed
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ===== SECURITY: Enforce admin authentication for page access (not AJAX) =====
requireAdmin();

?>
<?php $activePage = 'tickets'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>

<main class="flex-grow-1 overflow-auto">
<div class="container-fluid mt-4">
    <div class="row">
        <!-- Left Column: Ticket Creation Form -->
        <div class="col-lg-8 col-12 pe-3" style="transition: all 0.3s ease;">
            <div id="alertContainer"></div>
            
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Create New Ticket</h5>
                </div>
                <div class="card-body">
                    <form id="ticketForm" onsubmit="saveTicket(event)">
                        <!-- Site Selection Filters -->
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

                        <!-- Site Search -->
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

                        <!-- Priority -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Priority</label>
                            <select id="priority" name="priority" class="form-select">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>

                        <!-- Category -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Category</label>
                            <select id="category" name="category" class="form-select">
                                <option value="">None</option>
                                <option value="connectivity">Connectivity</option>
                                <option value="hardware">Hardware</option>
                                <option value="software">Software</option>
                                <option value="power">Power</option>
                                <option value="security">Security</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <!-- Due Date -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Due Date</label>
                            <input type="datetime-local" id="due_date" name="due_date" class="form-control">
                        </div>

                        <!-- Subject -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Subject <span class="text-danger">*</span></label>
                            <input type="text" id="subject" name="subject" class="form-control" placeholder="Ticket subject/title" required maxlength="200" oninput="updateSummary()">
                        </div>
                        
                        
                        <!-- Notes -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Optional notes..." oninput="updateSummary()"></textarea>
                        </div>
                        
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-check-circle"></i> Create Ticket(s)
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Summary Panel -->
        <div class="col-lg-4 col-12 ps-3">
            <div class="card shadow-sm" style="position: sticky; top: 20px;">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Ticket Summary</h5>
                </div>
                <div class="card-body">
                    <!-- Selected Sites -->
                    <div class="mb-3">
                        <h6 class="text-muted">Selected Sites:</h6>
                        <div id="summarySites" style="padding: 10px; background: #f8f9fa; border-radius: 4px; min-height: 40px;">
                            <span class="text-muted small">No sites selected</span>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Subject -->
                    <div class="mb-3">
                        <h6 class="text-muted">Subject:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small id="summarySubject" class="text-muted">Not set</small>
                        </div>
                    </div>
                    
                    
                    <!-- Notes -->
                    <div class="mb-3">
                        <h6 class="text-muted">Notes:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px; min-height: 60px; word-wrap: break-word; white-space: pre-wrap;">
                            <small id="summaryNotes" class="text-muted">No notes</small>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Tickets to Create -->
                    <div class="mb-3">
                        <h6 class="text-muted">Tickets to Create:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small>
                                <strong>Count:</strong> <span id="summaryCount" class="badge bg-primary">0</span><br>
                                <strong>Status:</strong> <span class="badge bg-info">OPEN</span>
                            </small>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Creator & Timestamp -->
                    <div class="mb-3">
                        <h6 class="text-muted">Creator:</h6>
                        <small class="text-muted" id="summaryCreator">
                            <?php 
                            // Get current user info from session
                            $creator = $_SESSION['name'] ?? $_SESSION['email'] ?? 'Current User';
                            echo htmlspecialchars($creator);
                            ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
    // Multi-site selection array
    let selectedSites = [];
    let currentPage = 1;
    let currentQuery = '';

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadFilterOptions();
        initSiteAutocomplete();
    });

    // Initialize site autocomplete
    function initSiteAutocomplete() {
        const input = document.getElementById('siteSearch');
        const list = document.getElementById('siteAutocompleteList');
        
        if (!input) return;

        // Show on focus
        input.addEventListener('focus', function() {
            if (list.style.display === 'none') {
                currentQuery = this.value;
                currentPage = 1;
                performSiteSearch();
            }
        });

        // Update on type
        input.addEventListener('input', function() {
            currentQuery = this.value;
            currentPage = 1;
            performSiteSearch();
        });

        // Hide on click outside
        document.addEventListener('click', function(e) {
            if (e.target !== input && !list.contains(e.target)) {
                list.style.display = 'none';
            }
        });
    }

    // Perform site search
    // Load filter options (projects, statuses, provinces, municipalities)
    function loadFilterOptions() {
        fetch('ticket.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_filter_options'
        })
        .then(res => res.json())
        .then(data => {
            if (data.projects) {
                const projectSelect = document.getElementById('projectFilter');
                data.projects.forEach(project => {
                    const option = document.createElement('option');
                    option.value = project;
                    option.textContent = project;
                    projectSelect.appendChild(option);
                });
            }
            if (data.statuses) {
                const statusSelect = document.getElementById('statusFilter');
                data.statuses.forEach(status => {
                    const option = document.createElement('option');
                    option.value = status;
                    option.textContent = status;
                    statusSelect.appendChild(option);
                });
            }
            if (data.provinces) {
                const provinceSelect = document.getElementById('provinceFilter');
                data.provinces.forEach(province => {
                    const option = document.createElement('option');
                    option.value = province;
                    option.textContent = province;
                    provinceSelect.appendChild(option);
                });
            }
            if (data.municipalities) {
                const municipalitySelect = document.getElementById('municipalityFilter');
                data.municipalities.forEach(municipality => {
                    const option = document.createElement('option');
                    option.value = municipality;
                    option.textContent = municipality;
                    municipalitySelect.appendChild(option);
                });
            }
            if (data.isps) {
                const ispSelect = document.getElementById('ispFilter');
                data.isps.forEach(isp => {
                    const option = document.createElement('option');
                    option.value = isp;
                    option.textContent = isp;
                    ispSelect.appendChild(option);
                });
            }
        })
        .catch(err => console.error('Error loading filter options:', err));
    }

    // Handle filter changes
    function onFilterChange() {
        currentPage = 1;
        currentQuery = document.getElementById('siteSearch').value;
        performSiteSearch();
    }

    // Perform site search
    function performSiteSearch() {
        const list = document.getElementById('siteAutocompleteList');

        // Get filter values
        const projectFilter = (document.getElementById('projectFilter') && document.getElementById('projectFilter').value) || '';
        const statusFilter = (document.getElementById('statusFilter') && document.getElementById('statusFilter').value) || '';
        const provinceFilter = (document.getElementById('provinceFilter') && document.getElementById('provinceFilter').value) || '';
        const municipalityFilter = (document.getElementById('municipalityFilter') && document.getElementById('municipalityFilter').value) || '';
        const ispFilter = document.getElementById('ispFilter').value || '';

        const body = 'action=search_sites'
            + '&query=' + encodeURIComponent(currentQuery)
            + '&page=' + currentPage
            + '&project_filter=' + encodeURIComponent(projectFilter)
            + '&status_filter=' + encodeURIComponent(statusFilter)
            + '&province_filter=' + encodeURIComponent(provinceFilter)
            + '&municipality_filter=' + encodeURIComponent(municipalityFilter)
            + '&isp_filter=' + encodeURIComponent(ispFilter);

        fetch('ticket.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body
        })
        .then(res => res.json())
        .then(data => {
            list.innerHTML = '';

            if (data.results && data.results.length > 0) {
                list.style.display = 'block';

                // Filter out already selected sites
                const availableResults = data.results.filter(item => 
                    !selectedSites.find(s => s.id === item.id)
                );

                if (availableResults.length === 0) {
                    // If current page has no available results but there are more pages,
                    // automatically advance to the next page and retry (helps when user
                    // has selected all items on earlier pages).
                    if (data.pages && data.page && data.page < data.pages) {
                        currentPage = data.page + 1;
                        performSiteSearch();
                        return;
                    }

                    list.innerHTML = '<div style="padding: 10px; color: #999; text-align: center;">All matching sites already selected</div>';
                    return;
                }

                // Render items
                availableResults.forEach(item => {
                    const a = document.createElement('a');
                    a.className = 'list-group-item list-group-item-action';
                    a.style.display = 'block';
                    a.style.padding = '10px';
                    a.style.textDecoration = 'none';
                    a.style.color = 'inherit';
                    a.style.borderBottom = '1px solid #eee';
                    a.href = '#';
                    a.textContent = item.display_text;
                    a.onclick = function(e) {
                        e.preventDefault();
                        selectSite(item);
                    };
                    list.appendChild(a);
                });

                // Render Pagination Controls
                if (data.pages > 1) {
                    const paginationDiv = document.createElement('div');
                    paginationDiv.style.display = 'flex';
                    paginationDiv.style.justifyContent = 'space-between';
                    paginationDiv.style.padding = '8px';
                    paginationDiv.style.borderTop = '1px solid #ddd';
                    paginationDiv.style.backgroundColor = '#f9f9f9';
                    
                    const prevBtn = document.createElement('button');
                    prevBtn.type = 'button';
                    prevBtn.className = 'btn btn-sm btn-outline-secondary';
                    prevBtn.innerHTML = '<i class="bi bi-chevron-left"></i> Prev';
                    prevBtn.disabled = data.page <= 1;
                    prevBtn.onmousedown = function(e) { e.preventDefault(); };
                    prevBtn.onclick = function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (currentPage > 1) {
                            currentPage--;
                            performSiteSearch();
                        }
                    };

                    const nextBtn = document.createElement('button');
                    nextBtn.type = 'button';
                    nextBtn.className = 'btn btn-sm btn-outline-secondary';
                    nextBtn.innerHTML = 'Next <i class="bi bi-chevron-right"></i>';
                    nextBtn.disabled = data.page >= data.pages;
                    nextBtn.onmousedown = function(e) { e.preventDefault(); };
                    nextBtn.onclick = function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (currentPage < data.pages) {
                            currentPage++;
                            performSiteSearch();
                        }
                    };

                    const info = document.createElement('span');
                    info.style.fontSize = '12px';
                    info.style.color = '#999';
                    info.style.alignSelf = 'center';
                    info.textContent = `Page ${data.page} of ${data.pages}`;

                    paginationDiv.appendChild(prevBtn);
                    paginationDiv.appendChild(info);
                    paginationDiv.appendChild(nextBtn);
                    list.appendChild(paginationDiv);
                }

            } else {
                if (currentQuery.length > 0) {
                    list.style.display = 'block';
                    list.innerHTML = '<div style="padding: 10px; color: #999; text-align: center;">No sites found</div>';
                }
            }
        })
        .catch(err => console.error(err));
    }

    // Select site and add to list
    function selectSite(item) {
        // Check if already selected
        if (selectedSites.find(s => s.id === item.id)) {
            return; // Already selected
        }
        
        selectedSites.push(item);
        document.getElementById('siteSearch').value = '';
        // keep JS state in sync
        currentQuery = '';
        currentPage = 1;
        document.getElementById('siteAutocompleteList').style.display = 'none';
        renderSelectedSites();
        updateSummary();
    }

    // Render selected sites as tags
    function renderSelectedSites() {
        const container = document.getElementById('selectedSitesDisplay');
        container.innerHTML = '';
        
        selectedSites.forEach(site => {
            const badge = document.createElement('span');
            badge.className = 'badge bg-primary';
            badge.style.padding = '8px 12px';
            badge.style.fontSize = '14px';
            badge.style.display = 'inline-flex';
            badge.style.alignItems = 'center';
            badge.style.gap = '8px';
            
            const text = document.createElement('span');
            text.textContent = site.site_name;
            
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.style.background = 'none';
            removeBtn.style.border = 'none';
            removeBtn.style.color = 'white';
            removeBtn.style.cursor = 'pointer';
            removeBtn.style.padding = '0';
            removeBtn.style.fontSize = '18px';
            removeBtn.textContent = '×';
            removeBtn.onclick = function(e) {
                e.preventDefault();
                removeSite(site.id);
            };
            
            badge.appendChild(text);
            badge.appendChild(removeBtn);
            container.appendChild(badge);
        });
        
        // Update hidden field with site IDs
        document.getElementById('siteIds').value = selectedSites.map(s => s.id).join(',');
    }

    // Remove site from selection
    function removeSite(siteId) {
        selectedSites = selectedSites.filter(s => s.id !== siteId);
        renderSelectedSites();
        updateSummary();
        // Refresh dropdown if open to show removed site again
        if (document.getElementById('siteAutocompleteList').style.display === 'block') {
            performSiteSearch();
        }
    }

    // Update summary panel with live data
    function updateSummary() {
        const subject = document.getElementById('subject').value;
        const notes = document.getElementById('notes').value;

        // Update Selected Sites
        const sitesHtml = selectedSites.length > 0 
            ? selectedSites.map(s => `<li>${s.site_name}</li>`).join('')
            : '<span class="text-muted small">No sites selected</span>';
        
        document.getElementById('summarySites').innerHTML = selectedSites.length > 0 
            ? `<ul style="margin: 0; padding-left: 20px; font-size: 14px;">${sitesHtml}</ul>`
            : sitesHtml;

        // Update Subject
        document.getElementById('summarySubject').textContent = subject || 'Not set';

        // Update Notes
        document.getElementById('summaryNotes').textContent = notes ? notes.trim() : 'No notes';

        // Update Ticket Count
        const count = selectedSites.length;
        document.getElementById('summaryCount').textContent = count;
        document.getElementById('summaryCount').className = count > 0 ? 'badge bg-success' : 'badge bg-secondary';
    }

    // Save ticket(s)
    function saveTicket(event) {
        event.preventDefault();
        
        if (selectedSites.length === 0) {
            alert('Please select at least one site.');
            return;
        }
        
        const formData = new FormData(event.target);
        formData.append('action', 'create_tickets');
        formData.append('duration', 0);

        fetch('ticket.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                showAlert(result.message, 'success');
                // Reset form
                setTimeout(() => {
                    document.getElementById('ticketForm').reset();
                    selectedSites = [];
                    renderSelectedSites();
                    updateSummary();
                }, 1500);
            } else {
                showAlert(result.message || 'Error creating ticket', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showAlert('An error occurred', 'error');
        });
    }

    // Show alert
    function showAlert(message, type = 'success') {
        const container = document.getElementById('alertContainer');
        const alertClass = type === 'error' ? 'alert-danger' : 'alert-success';
        const html = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        container.innerHTML = html;
        setTimeout(() => {
            const alert = document.querySelector('.alert');
            if (alert) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            }
        }, 3000);
    }


</script>

</body>
</html>
