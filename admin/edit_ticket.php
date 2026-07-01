<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require '../lib/Validator.php';
require '../notif/notification.php';
require '../lib/Logger.php';
require_once '../lib/duration.php';
require_once '../lib/TicketHistory.php';

requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo "<p>Invalid ticket id</p>";
    exit;
}
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ===== CSRF Validation =====
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        // handle update
        $status = Sanitizer::normalize($_POST['status'] ?? 'OPEN');
        $notes = Sanitizer::normalize($_POST['notes'] ?? '');

        // Validate status
        $validStatuses = ['IN_PROGRESS', 'RESOLVED'];
        if (!Validator::inList($status, $validStatuses)) {
            $error = 'Invalid ticket status';
        } elseif (!Validator::string($notes, 0, 2000)) {
            $error = 'Notes exceed maximum length (2000 characters)';
        } else {
            try {
                // Get current ticket status before update
                $currentStmt = $pdo->prepare("SELECT status, ticket_number FROM tickets WHERE id = ?");
                $currentStmt->execute([$id]);
                $currentTicket = $currentStmt->fetch(PDO::FETCH_ASSOC);
                $oldStatus = $currentTicket['status'];
                $ticketNumber = $currentTicket['ticket_number'];

                // Set solved_date and duration based on status
                if ($status === 'CLOSED' || $status === 'RESOLVED') {
                    $solved_date = 'NOW()';
                    $duration_sql = ', duration = TIMESTAMPDIFF(MINUTE, created_at, NOW())';
                } else {
                    $solved_date = 'NULL';
                    $duration_sql = '';
                }
                $updateSql = "UPDATE tickets SET status = ?, notes = ?, solved_date = $solved_date, updated_at = NOW()$duration_sql WHERE id = ?";
                $stmt2 = $pdo->prepare($updateSql);
                $stmt2->execute([$status, $notes, $id]);
                
                // Log ticket history
                $history = new TicketHistory($pdo);
                if ($oldStatus !== $status) {
                    $history->logChange($id, $_SESSION['user_id'], 'status_changed', 'status', $oldStatus, $status);
                }
                if (!empty($notes)) {
                    $history->logChange($id, $_SESSION['user_id'], 'updated', 'notes', null, $notes);
                }
                
                // Log ticket status change
                $logger = new Logger($pdo);
                $logger->logEntityAction('ticket_status_changed', 'ticket', $id, [
                    'ticket_number' => $ticketNumber,
                    'old_status' => $oldStatus,
                    'new_status' => $status,
                    'notes' => $notes
                ], 'low');
                
                // Send notification if status changed to RESOLVED or CLOSED
                if ($status === 'RESOLVED' || $status === 'CLOSED') {
                    $notificationManager = new NotificationManager($pdo);
                    $notificationManager->notifyTicketStatusUpdate($id, $status);
                }
                
                // Redirect to viewtickets.php with success message
                header("Location: viewtickets.php?updated=" . $id . "&ticket_number=" . urlencode($ticketNumber) . "&success=1");
                exit;
            } catch (PDOException $e) {
                $error = 'Database error: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// fetch current ticket data for display
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

    // Check if ticket is already CLOSED - prevent editing
    if ($ticket['status'] === 'CLOSED') {
        echo "<div class='alert alert-warning' role='alert'>
                <h4 class='alert-heading'>Ticket Cannot Be Edited</h4>
                <p>This ticket has been closed and cannot be modified.</p>
                <hr>
                <p class='mb-0'>If you need to make changes, please create a new ticket or contact system administrator.</p>
              </div>";
        exit;
    }
} catch (PDOException $e) {
    echo "<p>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}


?>
<?php $activePage = 'tickets'; ?>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>

<main class="flex-grow-1 overflow-auto">
<div class="container-fluid mt-4">
    <div class="row">
        <!-- Left Column: Edit Form -->
        <div class="col-lg-8 col-12 pe-3" style="transition: all 0.3s ease;">
            <div id="alertContainer">
              <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo Sanitizer::escapeHtml($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
              <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo Sanitizer::escapeHtml($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
            </div>
            
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Edit Ticket #<?php echo Sanitizer::escapeHtml($ticket['ticket_number']); ?></h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        
                        <!-- Status -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select" onchange="updateSummary()">
                                <option value="IN_PROGRESS"<?php if($ticket['status']=='IN_PROGRESS') echo ' selected'; ?>>IN PROGRESS</option>
                                <option value="RESOLVED"<?php if($ticket['status']=='RESOLVED') echo ' selected'; ?>>RESOLVED</option>
                            </select>
                        </div>

                        <!-- Notes -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea name="notes" class="form-control" rows="5" placeholder="Optional notes..." onchange="updateSummary()"><?php echo Sanitizer::escapeHtml($ticket['notes']); ?></textarea>
                        </div>

                        <hr>

                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <!-- Action Buttons -->
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success flex-fill">
                                <i class="bi bi-check-circle"></i> Save Changes
                            </button>
                            <a href="viewtickets.php" class="btn btn-secondary flex-fill">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Ticket Summary -->
        <div class="col-lg-4 col-12 ps-3">
            <div class="card shadow-sm" style="position: sticky; top: 20px;">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Ticket Details</h5>
                </div>
                <div class="card-body">
                    <!-- Ticket Number -->
                    <div class="mb-3">
                        <h6 class="text-muted">Ticket Number:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small><strong><?php echo Sanitizer::escapeHtml($ticket['ticket_number']); ?></strong></small>
                        </div>
                    </div>

                    <hr>

                    <!-- Site -->
                    <div class="mb-3">
                        <h6 class="text-muted">Site:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small><?php echo Sanitizer::escapeHtml($ticket['site_name'] ?? 'N/A'); ?></small>
                        </div>
                    </div>

                    <hr>

                    <!-- Subject -->
                    <div class="mb-3">
                        <h6 class="text-muted">Subject:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small><?php echo Sanitizer::escapeHtml($ticket['subject'] ?? 'N/A'); ?></small>
                        </div>
                    </div>

                    <hr>


                    <!-- Duration -->
                    <div class="mb-3">
                        <h6 class="text-muted">Duration:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small id="summaryDuration"><?php echo formatDurationDisplay(calculateDurationMinutes($ticket['created_at'], $ticket['duration'], $ticket['status'])); ?></small>
                        </div>
                    </div>

                    <hr>

                    <!-- Status (Live Update) -->
                    <div class="mb-3">
                        <h6 class="text-muted">Status:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small id="summaryStatus">
                                <?php 
                                $statusClass = match($ticket['status']) {
                                    'OPEN' => 'bg-danger',
                                    'IN_PROGRESS' => 'bg-warning',
                                    'RESOLVED' => 'bg-info',
                                    'CLOSED' => 'bg-success',
                                    default => 'bg-secondary'
                                };
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo Sanitizer::escapeHtml($ticket['status']); ?></span>
                            </small>
                        </div>
                    </div>

                    <hr>

                    <!-- Created By -->
                    <div class="mb-3">
                        <h6 class="text-muted">Created By:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small><?php echo Sanitizer::escapeHtml($ticket['created_by_name'] ?? 'N/A'); ?></small>
                        </div>
                    </div>

                    <hr>

                    <!-- Created At -->
                    <div class="mb-3">
                        <h6 class="text-muted">Created At:</h6>
                        <div style="padding: 10px; background: #f8f9fa; border-radius: 4px;">
                            <small><?php echo Sanitizer::escapeHtml($ticket['created_at'] ?? 'N/A'); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
    // Update summary panel with live data
    function updateSummary() {
        const status = document.querySelector('select[name="status"]').value;
        const notes = document.querySelector('textarea[name="notes"]').value;

        // Update Status with badge color
        const statusClass = status === 'OPEN' ? 'bg-danger' : 
                           (status === 'IN_PROGRESS' ? 'bg-warning' : 
                           (status === 'RESOLVED' ? 'bg-info' : 'bg-success'));
        document.getElementById('summaryStatus').innerHTML = `<span class="badge ${statusClass}">${status}</span>`;

        // Calculate real-time duration for OPEN/IN_PROGRESS tickets
        if (status === 'OPEN' || status === 'IN_PROGRESS') {
            const createdAt = new Date('<?php echo $ticket['created_at']; ?>');
            const now = new Date();
            const diffMs = now - createdAt;
            const diffMinutes = Math.floor(diffMs / (1000 * 60));
            
            // Format duration as "X Day(s) Y Hr(s) Z Min(s)"
            const days = Math.floor(diffMinutes / 1440);
            const hours = Math.floor((diffMinutes % 1440) / 60);
            const minutes = diffMinutes % 60;
            
            let durationText = '';
            if (days > 0) durationText += days + ' Day' + (days > 1 ? 's' : '') + ' ';
            if (hours > 0) durationText += hours + ' Hr' + (hours > 1 ? 's' : '') + ' ';
            if (minutes > 0 || durationText === '') durationText += minutes + ' Min' + (minutes > 1 ? 's' : '');
            
            document.getElementById('summaryDuration').textContent = durationText.trim();
        } else {
            // For CLOSED tickets, use stored duration from PHP
            document.getElementById('summaryDuration').textContent = '<?php echo formatDurationDisplay($ticket['duration']); ?>';
        }

        // Notes are not shown in summary panel but could be logged if needed
    }

    // Initialize summary on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateSummary();
        // Update summary every minute for real-time duration
        setInterval(updateSummary, 60000);

        // Update summary when status changes
        const statusSelect = document.querySelector('select[name="status"]');
        if (statusSelect) {
            statusSelect.addEventListener('change', updateSummary);
        }
    });

</script>

</body>
</html>