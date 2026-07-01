<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require_once '../lib/duration.php';

requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo "<p>Invalid ticket id</p>";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT t.*, s.site_name, p.fullname AS created_by_name
                           FROM tickets t
                           LEFT JOIN sites s ON t.site_id = s.id
                           LEFT JOIN personnels p ON t.created_by = p.id
                           WHERE t.id = ?");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        echo "<p>Ticket not found</p>";
        exit;
    }
} catch (PDOException $e) {
    echo "<p>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

function escapeHtml($s) {
    if ($s === null) return '';
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<?php $activePage = 'tickets'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>

<main class="flex-grow-1 overflow-auto">
<div class="container-fluid mt-4">
    <div class="row">
        <!-- Left Column: Ticket Details -->
        <div class="col-lg-8 col-12 pe-3" style="transition: all 0.3s ease;">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Ticket #<?php echo escapeHtml($ticket['ticket_number']); ?></h5>
                </div>
                <div class="card-body">
                    <!-- Subject -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold">Subject</h6>
                        <p class="mb-0"><?php echo escapeHtml($ticket['subject']); ?></p>
                    </div>

                    <hr>

                    <!-- Site Information -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold">Site</h6>
                        <p class="mb-0"><?php echo escapeHtml($ticket['site_name']); ?></p>
                    </div>

                    <hr>


                    <!-- Duration -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold">Duration</h6>
                        <p class="mb-0"><span class="badge bg-info"><?php echo formatDurationDisplay(calculateDurationMinutes($ticket['created_at'], $ticket['duration'], $ticket['status'])); ?></span></p>
                    </div>

                    <hr>

                    <!-- Notes -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold">Notes</h6>
                        <div style="padding: 12px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #05b6f1; min-height: 60px;">
                            <p class="mb-0" style="white-space: pre-wrap; word-wrap: break-word;">
                                <?php echo escapeHtml($ticket['notes']) ?: '<span class="text-muted">No notes</span>'; ?>
                            </p>
                        </div>
                    </div>

                    <hr>

                    <!-- Action Buttons -->
                    <div class="d-flex gap-2">
                        <?php if ($ticket['status'] !== 'CLOSED'): ?>
                        <a href="edit_ticket.php?id=<?php echo escapeHtml($ticket['id']); ?>" class="btn btn-warning flex-fill">
                            <i class="bi bi-pencil-square"></i> Edit Ticket
                        </a>
                        <?php endif; ?>
                        <a href="viewtickets.php" class="btn btn-secondary flex-fill">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Summary Panel -->
        <div class="col-lg-4 col-12 ps-3">
            <div class="card shadow-sm" style="position: sticky; top: 20px;">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Ticket Information</h5>
                </div>
                <div class="card-body">
                    <!-- Ticket Number -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Ticket Number</h6>
                        <small class="fw-bold d-block text-primary"><?php echo escapeHtml($ticket['ticket_number']); ?></small>
                    </div>

                    <hr>

                    <!-- Status -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Status</h6>
                        <div>
                            <?php 
                            $statusClass = match($ticket['status']) {
                                'OPEN' => 'bg-danger',
                                'IN_PROGRESS' => 'bg-warning',
                                'RESOLVED' => 'bg-info',
                                'CLOSED' => 'bg-success',
                                default => 'bg-secondary'
                            };
                            ?>
                            <span class="badge <?php echo $statusClass; ?> fs-6"><?php echo escapeHtml($ticket['status']); ?></span>
                        </div>
                    </div>

                    <hr>

                    <!-- Solved Date (only for RESOLVED/CLOSED tickets) -->
                    <?php if (($ticket['status'] === 'CLOSED' || $ticket['status'] === 'RESOLVED') && $ticket['solved_date']): ?>
                    <div class="mb-3">
                        <h6 class="text-muted small">Solved Date</h6>
                        <small><?php echo escapeHtml($ticket['solved_date']); ?></small>
                    </div>

                    <hr>
                    <?php endif; ?>

                    <!-- Created Information -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Created By</h6>
                        <small><?php echo escapeHtml($ticket['created_by_name'] ?? 'Unknown'); ?></small>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <h6 class="text-muted small">Created At</h6>
                        <small><?php echo escapeHtml($ticket['created_at']); ?></small>
                    </div>

                    <hr>

                    <!-- Updated At -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Updated At</h6>
                        <small><?php echo escapeHtml($ticket['updated_at'] ?? 'N/A'); ?></small>
                    </div>

                    <hr>

                    <!-- Quick Stats -->
                    <div class="mt-4">
                        <h6 class="text-muted small fw-bold">Quick Stats</h6>
                        <div class="row g-2">
                            <div class="col-6">
                                <div style="padding: 12px; background: #e7f3ff; border-radius: 4px; text-align: center;">
                                    <small class="text-muted d-block">Duration</small>
                                    <small class="fw-bold text-primary"><?php echo formatDurationDisplay(calculateDurationMinutes($ticket['created_at'], $ticket['duration'], $ticket['status'])); ?></small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div style="padding: 12px; background: #f0f8ff; border-radius: 4px; text-align: center;">
                                    <small class="text-muted d-block">Site</small>
                                    <small class="fw-bold text-primary" style="overflow: hidden; text-overflow: ellipsis; display: block; white-space: nowrap;"><?php echo escapeHtml(substr($ticket['site_name'], 0, 15)); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<footer class="bg-dark text-light text-center py-3 mt-auto">
  <div class="container">
    <?php echo date('Y'); ?> &copy; FREE PUBLIC INTERNET ACCESS PROGRAM - SERVICE MANAGEMENT AND RESPONSE TICKETING SYSTEM (FPIAP-SMARTs). All Rights Reserved.
  </div>
</footer>

<?php require __DIR__ . '/../includes/footer.php'; ?>
