<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require '../lib/duration.php';
require '../notif/notification.php';

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

// AJAX: Get tickets
if ($action === 'get_tickets') {
    header('Content-Type: application/json');
    $page = max(1, intval($_POST['page'] ?? 1));
    $perPage = intval($_POST['per_page'] ?? 25);
    $search = trim($_POST['search'] ?? '');
    $statusArray = $_POST['status'] ?? [];
    if (!is_array($statusArray)) $statusArray = $statusArray !== '' ? [$statusArray] : [];
    $sortBy = trim($_POST['sort_by'] ?? 'created_at');
    $sortOrder = strtoupper(trim($_POST['sort_order'] ?? 'DESC'));

    $validSortColumns = ['ticket_number', 'site_name', 'subject', 'status', 'priority', 'created_at', 'duration'];
    if (!in_array($sortBy, $validSortColumns)) $sortBy = 'created_at';
    if (!in_array($sortOrder, ['ASC', 'DESC'])) $sortOrder = 'DESC';

    try {
        $conditions = ["t.created_by = ?"];
        $params = [$personnelId];

        if (!empty($search)) {
            $conditions[] = "(t.ticket_number LIKE ? OR t.subject LIKE ? OR s.site_name LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        if (!empty($statusArray)) {
            $placeholders = implode(',', array_fill(0, count($statusArray), '?'));
            $conditions[] = "t.status IN ($placeholders)";
            $params = array_merge($params, $statusArray);
        }

        $where = implode(" AND ", $conditions);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t LEFT JOIN sites s ON t.site_id = s.id WHERE $where");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        $sql = "SELECT t.id, t.ticket_number, t.subject, t.status, t.priority, t.notes, t.created_at, t.duration, t.solved_date,
                       s.site_name, s.isp, s.province, s.municipality
                FROM tickets t
                LEFT JOIN sites s ON t.site_id = s.id
                WHERE $where
                ORDER BY t.$sortBy $sortOrder
                LIMIT $perPage OFFSET " . ($page - 1) * $perPage;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'tickets' => $tickets,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $perPage)
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: Get filter options
if ($action === 'get_filters') {
    header('Content-Type: application/json');
    try {
        $statuses = $pdo->prepare("SELECT DISTINCT status FROM tickets WHERE created_by = ? AND status IS NOT NULL ORDER BY status");
        $statuses->execute([$personnelId]);
        $statuses = $statuses->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode(['statuses' => $statuses]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$activePage = 'tickets';
?>
<?php require __DIR__ . '/../includes/user_header.php'; ?>

<div class="container-fluid mt-4">
    <div id="alertContainer"></div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>MY TICKETS</h3>
    </div>

    <div class="mb-3">
        <input type="text" id="searchInput" class="form-control form-control-lg" placeholder="Search by ticket number, subject, or site name...">
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar bg-light p-3 mb-3 border rounded">
        <div class="row g-2">
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="dropdown">
                    <button class="btn btn-outline-secondary w-100" type="button" onclick="toggleFilter('status')">
                        Status <span class="badge bg-primary" id="status_count">0</span>
                    </button>
                    <div id="filterMenu_status" class="dropdown-menu p-2 w-100" style="max-height: 200px; overflow-y: auto; display: none;">
                        <div id="statusOptions"></div>
                        <div class="mt-2 text-end"><button class="btn btn-sm btn-link" onclick="clearFilter('status')">Clear</button></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover" id="ticketsTable">
            <thead class="table-light">
                <tr>
                    <th style="cursor:pointer;" onclick="sortTable('ticket_number')">Ticket # <i class="bi bi-arrow-down-up"></i></th>
                    <th style="cursor:pointer;" onclick="sortTable('subject')">Subject <i class="bi bi-arrow-down-up"></i></th>
                    <th style="cursor:pointer;" onclick="sortTable('site_name')">Site <i class="bi bi-arrow-down-up"></i></th>
                    <th>ISP</th>
                    <th>Location</th>
                    <th style="cursor:pointer;" onclick="sortTable('status')">Status <i class="bi bi-arrow-down-up"></i></th>
                    <th style="cursor:pointer;" onclick="sortTable('priority')">Priority <i class="bi bi-arrow-down-up"></i></th>
                    <th style="cursor:pointer;" onclick="sortTable('duration')">Duration <i class="bi bi-arrow-down-up"></i></th>
                    <th style="cursor:pointer;" onclick="sortTable('created_at')">Created <i class="bi bi-arrow-down-up"></i></th>
                </tr>
            </thead>
            <tbody id="ticketsBody">
                <tr><td colspan="9" class="text-center text-muted py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-muted small" id="paginationInfo">Showing 0 of 0 tickets</div>
        <nav>
            <ul class="pagination mb-0" id="pagination"></ul>
        </nav>
    </div>
</div>

<script>
let currentPage = 1;
let currentSort = 'created_at';
let currentOrder = 'DESC';
let currentFilters = { status: [] };

document.addEventListener('DOMContentLoaded', function() {
    loadFilters();
    loadTickets();

    document.getElementById('searchInput').addEventListener('keyup', function(e) {
        if (e.key === 'Enter') { currentPage = 1; loadTickets(); }
    });
});

function loadFilters() {
    fetch('view_tickets.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_filters'
    })
    .then(res => res.json())
    .then(data => {
        if (data.statuses) {
            const container = document.getElementById('statusOptions');
            data.statuses.forEach(s => {
                const checked = currentFilters.status.includes(s) ? 'checked' : '';
                container.innerHTML += `<div class="form-check"><input class="form-check-input filter-check" type="checkbox" value="${s}" data-filter="status" ${checked}><label class="form-check-label">${s}</label></div>`;
            });
        }
    });
}

function loadTickets() {
    const search = document.getElementById('searchInput').value;
    const formData = new URLSearchParams();
    formData.append('action', 'get_tickets');
    formData.append('page', currentPage);
    formData.append('per_page', 25);
    formData.append('search', search);
    formData.append('sort_by', currentSort);
    formData.append('sort_order', currentOrder);
    currentFilters.status.forEach(s => formData.append('status[]', s));

    fetch('view_tickets.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) { console.error(data.error); return; }
        renderTickets(data.tickets);
        renderPagination(data.page, data.pages, data.total);
    });
}

function renderTickets(tickets) {
    const tbody = document.getElementById('ticketsBody');
    if (!tickets || tickets.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No tickets found</td></tr>';
        return;
    }
    let html = '';
    tickets.forEach(t => {
        const statusBadge = getStatusBadge(t.status);
        const priorityBadge = getPriorityBadge(t.priority);
        const duration = formatDuration(calculateDurationMinutes(t.created_at, t.duration, t.status));
        const location = [t.province, t.municipality].filter(Boolean).join(', ');
        html += `<tr>
            <td><a href="detail_ticket.php?id=${t.id}">${t.ticket_number}</a></td>
            <td>${escapeHtml(t.subject)}</td>
            <td>${escapeHtml(t.site_name || 'N/A')}</td>
            <td>${escapeHtml(t.isp || 'N/A')}</td>
            <td>${escapeHtml(location || 'N/A')}</td>
            <td>${statusBadge}</td>
            <td>${priorityBadge}</td>
            <td>${duration}</td>
            <td>${new Date(t.created_at).toLocaleDateString()}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function getStatusBadge(status) {
    const classes = { OPEN: 'bg-danger', IN_PROGRESS: 'bg-warning text-dark', RESOLVED: 'bg-info', CLOSED: 'bg-success' };
    return `<span class="badge ${classes[status] || 'bg-secondary'}">${status}</span>`;
}

function getPriorityBadge(priority) {
    const classes = { critical: 'bg-danger', high: 'bg-warning text-dark', medium: 'bg-info', low: 'bg-secondary' };
    return `<span class="badge ${classes[priority] || 'bg-secondary'}">${(priority || 'medium').charAt(0).toUpperCase() + (priority || 'medium').slice(1)}</span>`;
}

function calculateDurationMinutes(created, stored, status) {
    if (status === 'CLOSED' || status === 'RESOLVED') return parseInt(stored) || 0;
    const createdDate = new Date(created);
    const now = new Date();
    return Math.floor((now - createdDate) / 60000);
}

function formatDuration(minutes) {
    minutes = Math.max(0, minutes);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const mins = minutes % 60;
    const parts = [];
    if (days > 0) parts.push(days + 'd');
    if (hours > 0) parts.push(hours + 'h');
    if (mins > 0 || parts.length === 0) parts.push(mins + 'm');
    return parts.join(' ');
}

function escapeHtml(s) {
    if (!s) return '';
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function renderPagination(page, pages, total) {
    document.getElementById('paginationInfo').innerHTML = `Showing ${(page-1)*25+1}-${Math.min(page*25, total)} of ${total} tickets`;
    const ul = document.getElementById('pagination');
    let html = '';
    if (page > 1) html += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${page-1})">Prev</a></li>`;
    for (let i = Math.max(1, page-2); i <= Math.min(pages, page+2); i++) {
        html += `<li class="page-item ${i===page?'active':''}"><a class="page-link" href="#" onclick="goToPage(${i})">${i}</a></li>`;
    }
    if (page < pages) html += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${page+1})">Next</a></li>`;
    ul.innerHTML = html;
}

function goToPage(page) { currentPage = page; loadTickets(); }

function sortTable(col) {
    if (currentSort === col) { currentOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC'; }
    else { currentSort = col; currentOrder = 'ASC'; }
    currentPage = 1;
    loadTickets();
}

function toggleFilter(type) {
    const menu = document.getElementById('filterMenu_' + type);
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

function clearFilter(type) {
    currentFilters[type] = [];
    document.querySelectorAll(`.filter-check[data-filter="${type}"]`).forEach(cb => cb.checked = false);
    document.getElementById(type + '_count').textContent = '0';
    currentPage = 1;
    loadTickets();
}

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('filter-check')) {
        const type = e.target.dataset.filter;
        const val = e.target.value;
        if (e.target.checked) {
            if (!currentFilters[type].includes(val)) currentFilters[type].push(val);
        } else {
            currentFilters[type] = currentFilters[type].filter(v => v !== val);
        }
        document.getElementById(type + '_count').textContent = currentFilters[type].length;
        currentPage = 1;
        loadTickets();
    }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
