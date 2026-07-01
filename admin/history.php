<?php
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../lib/TicketHistory.php';
require_once '../lib/HistoryExport.php';

// Authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$history = new TicketHistory($pdo);
$filters = [
    'ticket_number' => $_GET['ticket_number'] ?? null,
    'editor_name' => $_GET['editor_name'] ?? null,
    'action' => $_GET['action'] ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to' => $_GET['date_to'] ?? null
];

$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$historyData = $history->getGroupedHistory($filters, $limit, $offset);
$totalTickets = $history->getGroupedHistoryCount($filters);
$stats = $history->getHistoryStats();

// Handle exports
if (isset($_GET['export'])) {
    if ($_GET['export'] === 'csv') {
        HistoryExport::downloadCSV($historyData);
    } elseif ($_GET['export'] === 'pdf') {
        HistoryExport::downloadPDF($historyData);
    }
}
?>
<?php $activePage = 'settings'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>



<!-- Main Content -->
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-clock-history me-2"></i>Ticket History</h2>
                <div class="btn-group" role="group">
                    <a href="?<?php echo http_build_query(array_merge($filters, ['export' => 'csv'])); ?>" class="btn btn-success">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($filters, ['export' => 'pdf'])); ?>" class="btn btn-danger">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label for="ticket_number" class="form-label">Ticket Number</label>
                            <input type="text" class="form-control" id="ticket_number" name="ticket_number"
                                   placeholder="Enter ticket number" value="<?php echo htmlspecialchars($filters['ticket_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="editor_name" class="form-label">Last Updated By</label>
                            <input type="text" class="form-control" id="editor_name" name="editor_name"
                                   placeholder="Enter editor full name" value="<?php echo htmlspecialchars($filters['editor_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="action" class="form-label">Action</label>
                            <select class="form-control" id="action" name="action">
                                <option value="">All Actions</option>
                                <option value="created" <?php echo ($filters['action'] ?? '') === 'created' ? 'selected' : ''; ?>>Created</option>
                                <option value="updated" <?php echo ($filters['action'] ?? '') === 'updated' ? 'selected' : ''; ?>>Updated</option>
                                <option value="status_changed" <?php echo ($filters['action'] ?? '') === 'status_changed' ? 'selected' : ''; ?>>Status Changed</option>
                                <option value="assigned" <?php echo ($filters['action'] ?? '') === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                                <option value="comment_added" <?php echo ($filters['action'] ?? '') === 'comment_added' ? 'selected' : ''; ?>>Comment Added</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from"
                                   value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to"
                                   value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search me-1"></i>Filter
                            </button>
                            <a href="history.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle me-1"></i>Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- History Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-table me-2"></i>History Records (<?php echo count($historyData); ?> shown)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($historyData)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>No history records found matching the current filters.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th><i class="bi bi-hash me-1"></i>Ticket Number</th>
                                        <th><i class="bi bi-ticket-detailed me-1"></i>Title</th>
                                        <th><i class="bi bi-person-circle me-1"></i>Last Updated By</th>
                                        <th><i class="bi bi-bar-chart-line me-1"></i>Changes</th>
                                        <th><i class="bi bi-calendar-event me-1"></i>Last Updated</th>
                                        <th><i class="bi bi-card-text me-1"></i>History Summary</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historyData as $entry): ?>
                                    <tr>
                                        <td>
                                            <a href="detail_ticket.php?id=<?php echo $entry['ticket_id']; ?>" class="text-decoration-none">
                                                <strong><?php echo htmlspecialchars($entry['ticket_number'] ?? ('#' . $entry['ticket_id'])); ?></strong>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($entry['ticket_title'] ?? 'No title'); ?></td>
                                        <td><?php echo htmlspecialchars($entry['last_updated_by'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($entry['changes_count'] ?? 0); ?></td>
                                        <td><?php echo $entry['last_updated'] ? date('M d, Y H:i:s', strtotime($entry['last_updated'])) : '-'; ?></td>
                                        <td style="white-space: pre-line;">
                                            <?php echo nl2br(htmlspecialchars(str_replace(' | ', "\n", $entry['history_summary'] ?? ''))); ?>
                                            <div class="mt-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary view-history-details" data-bs-toggle="modal" data-bs-target="#historyDetailsModal" data-history-summary="<?php echo htmlspecialchars($entry['history_summary'] ?? ''); ?>" data-ticket-number="<?php echo htmlspecialchars($entry['ticket_number'] ?? ('#' . $entry['ticket_id'])); ?>" data-last-updated-by="<?php echo htmlspecialchars($entry['last_updated_by'] ?? 'N/A'); ?>">
                                                    View Full Details
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <nav aria-label="History pagination" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query($filters); ?>">
                                        <i class="bi bi-chevron-left me-1"></i>Previous
                                    </a>
                                </li>
                                <?php endif; ?>

                                <?php
                                $totalPages = $totalTickets > 0 ? ceil($totalTickets / $limit) : 1;
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);

                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages && count($historyData) === $limit): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query($filters); ?>">
                                        Next<i class="bi bi-chevron-right ms-1"></i>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Full History Details Modal -->
<div class="modal fade" id="historyDetailsModal" tabindex="-1" aria-labelledby="historyDetailsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="historyDetailsModalLabel">Full History Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <strong>Ticket:</strong> <span id="historyDetailsTicket"></span>
        </div>
        <div class="mb-3">
          <strong>Last Edited By:</strong> <span id="historyDetailsEditor"></span>
        </div>
        <pre id="historyDetailsContent" class="bg-light p-3 rounded" style="white-space: pre-wrap; word-break: break-word;">No details available.</pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
    // Full history details modal listener
    document.addEventListener('click', function(event) {
        const button = event.target.closest('.view-history-details');
        if (!button) return;

        const ticketNumber = button.getAttribute('data-ticket-number') || 'Unknown Ticket';
        const summary = button.getAttribute('data-history-summary') || '';
        const editorName = button.getAttribute('data-last-updated-by') || 'N/A';
        const ticketLabel = document.getElementById('historyDetailsTicket');
        const editorLabel = document.getElementById('historyDetailsEditor');
        const detailsContent = document.getElementById('historyDetailsContent');

        if (ticketLabel) {
            ticketLabel.textContent = ticketNumber;
        }
        if (editorLabel) {
            editorLabel.textContent = editorName;
        }
        if (detailsContent) {
            const formatted = summary.replace(/ \| /g, '\n');
            detailsContent.textContent = formatted || 'No details available.';
        }
    });
</script>

</body>
</html>