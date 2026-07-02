<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Duration.php';
require '../lib/AutoClose.php';
require '../lib/Logger.php';

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

// Auto-close resolved tickets
$logger = new Logger($pdo);
autoCloseResolvedTickets($pdo, $logger);

// Fetch personal ticket stats
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status='OPEN' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN status='IN_PROGRESS' THEN 1 ELSE 0 END) as in_progress_count,
        SUM(CASE WHEN status='RESOLVED' THEN 1 ELSE 0 END) as resolved_count,
        SUM(CASE WHEN status='CLOSED' THEN 1 ELSE 0 END) as closed_count,
        SUM(CASE WHEN (status='OPEN' OR status='IN_PROGRESS')
             AND (duration >= 4320 OR (duration IS NULL AND DATEDIFF(NOW(), created_at) >= 3)) THEN 1 ELSE 0 END) as aging_count
    FROM tickets WHERE created_by = ?
");
$stmt->execute([$personnelId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent tickets (last 5)
$stmt = $pdo->prepare("
    SELECT t.id, t.ticket_number, t.subject, t.status, t.priority, t.created_at, t.duration, t.solved_date,
           s.site_name
    FROM tickets t
    LEFT JOIN sites s ON t.site_id = s.id
    WHERE t.created_by = ?
    ORDER BY t.created_at DESC
    LIMIT 5
");
$stmt->execute([$personnelId]);
$recentTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'dashboard';
?>
<?php require __DIR__ . '/../includes/user_header.php'; ?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-0">My Dashboard</h1>
            <p class="text-muted">Overview of your tickets</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center shadow-sm border-0">
                <div class="card-body">
                    <h3 class="mb-0"><?php echo (int)$stats['total']; ?></h3>
                    <small class="text-muted">Total</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center shadow-sm border-0" style="border-left: 4px solid #dc3545;">
                <div class="card-body">
                    <h3 class="mb-0 text-danger"><?php echo (int)$stats['open_count']; ?></h3>
                    <small class="text-muted">Open</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center shadow-sm border-0" style="border-left: 4px solid #ffc107;">
                <div class="card-body">
                    <h3 class="mb-0 text-warning"><?php echo (int)$stats['in_progress_count']; ?></h3>
                    <small class="text-muted">In Progress</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center shadow-sm border-0" style="border-left: 4px solid #0dcaf0;">
                <div class="card-body">
                    <h3 class="mb-0 text-info"><?php echo (int)$stats['resolved_count']; ?></h3>
                    <small class="text-muted">Resolved</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center shadow-sm border-0" style="border-left: 4px solid #198754;">
                <div class="card-body">
                    <h3 class="mb-0 text-success"><?php echo (int)$stats['closed_count']; ?></h3>
                    <small class="text-muted">Closed</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6">
            <div class="card text-center shadow-sm border-0" style="border-left: 4px solid #6f42c1;">
                <div class="card-body">
                    <h3 class="mb-0 text-purple"><?php echo (int)$stats['aging_count']; ?></h3>
                    <small class="text-muted">Aging</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Tickets -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Tickets</h5>
            <a href="view_tickets.php" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($recentTickets)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-1"></i>
                    <p class="mt-2">No tickets yet. <a href="ticket.php">Create your first ticket</a></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject</th>
                                <th>Site</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Duration</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTickets as $t): ?>
                                <tr>
                                    <td><a href="detail_ticket.php?id=<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['ticket_number']); ?></a></td>
                                    <td><?php echo htmlspecialchars($t['subject']); ?></td>
                                    <td><?php echo htmlspecialchars($t['site_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = match($t['status']) {
                                            'OPEN' => 'bg-danger',
                                            'IN_PROGRESS' => 'bg-warning text-dark',
                                            'RESOLVED' => 'bg-info',
                                            'CLOSED' => 'bg-success',
                                            default => 'bg-secondary'
                                        };
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>"><?php echo $t['status']; ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $priorityClass = match($t['priority'] ?? 'medium') {
                                            'critical' => 'bg-danger',
                                            'high' => 'bg-warning text-dark',
                                            'medium' => 'bg-info',
                                            'low' => 'bg-secondary',
                                            default => 'bg-secondary'
                                        };
                                        ?>
                                        <span class="badge <?php echo $priorityClass; ?>"><?php echo ucfirst($t['priority'] ?? 'medium'); ?></span>
                                    </td>
                                    <td><?php echo formatDurationDisplay(calculateDurationMinutes($t['created_at'], $t['duration'], $t['status'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($t['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
