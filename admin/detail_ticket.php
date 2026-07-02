<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Sanitizer.php';
require_once '../lib/Duration.php';

requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo "<p>Invalid ticket id</p>";
    exit;
}

// Handle AJAX comment actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Handle file upload
if ($action === 'upload_attachment') {
    header('Content-Type: application/json');
    $ticketId = intval($_POST['ticket_id'] ?? 0);
    $userId = $_SESSION['user_id'];

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Upload failed']);
        exit;
    }

    $file = $_FILES['file'];
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        echo json_encode(['success' => false, 'message' => 'File too large (max 10MB)']);
        exit;
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain',
                     'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                     'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'File type not allowed']);
        exit;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../uploads/tickets';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $safeFilename)) {
        $stmt = $pdo->prepare("INSERT INTO ticket_attachments (ticket_id, user_id, filename, original_name, file_size, mime_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$ticketId, $userId, $safeFilename, $file['name'], $file['size'], $mimeType]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    }
    exit;
}

if ($action === 'get_attachments') {
    header('Content-Type: application/json');
    $ticketId = intval($_GET['ticket_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT ta.id, ta.filename, ta.original_name, ta.file_size, ta.mime_type, ta.created_at, p.fullname
        FROM ticket_attachments ta
        LEFT JOIN users u ON ta.user_id = u.id
        LEFT JOIN personnels p ON u.personnel_id = p.id
        WHERE ta.ticket_id = ?
        ORDER BY ta.created_at DESC
    ");
    $stmt->execute([$ticketId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'delete_attachment') {
    header('Content-Type: application/json');
    $attachId = intval($_POST['attachment_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT filename FROM ticket_attachments WHERE id = ?");
    $stmt->execute([$attachId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($file) {
        $path = __DIR__ . '/../uploads/tickets/' . $file['filename'];
        if (file_exists($path)) unlink($path);
        $pdo->prepare("DELETE FROM ticket_attachments WHERE id = ?")->execute([$attachId]);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'add_comment') {
    header('Content-Type: application/json');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }
    $comment = Sanitizer::remarks(trim($_POST['comment'] ?? ''));
    if (empty($comment) || strlen($comment) > 2000) {
        echo json_encode(['success' => false, 'message' => 'Comment required (max 2000 chars)']);
        exit;
    }
    $ticketId = intval($_POST['ticket_id'] ?? 0);
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("INSERT INTO ticket_comments (ticket_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$ticketId, $userId, $comment]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}
if ($action === 'get_comments') {
    header('Content-Type: application/json');
    $ticketId = intval($_GET['ticket_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT tc.id, tc.comment, tc.created_at, u.username, p.fullname
        FROM ticket_comments tc
        LEFT JOIN users u ON tc.user_id = u.id
        LEFT JOIN personnels p ON u.personnel_id = p.id
        WHERE tc.ticket_id = ?
        ORDER BY tc.created_at ASC
    ");
    $stmt->execute([$ticketId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT t.*, s.site_name, p.fullname AS created_by_name, ap.fullname AS assigned_to_name
                           FROM tickets t
                           LEFT JOIN sites s ON t.site_id = s.id
                           LEFT JOIN personnels p ON t.created_by = p.id
                           LEFT JOIN personnels ap ON t.assigned_to = ap.id
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

                    <!-- Comments Section -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold"><i class="bi bi-chat-dots"></i> Comments</h6>
                        <div id="commentsContainer" class="mb-3" style="max-height: 400px; overflow-y: auto;">
                            <div class="text-center text-muted py-3">Loading comments...</div>
                        </div>
                        <?php if ($ticket['status'] !== 'CLOSED'): ?>
                        <div class="input-group">
                            <input type="text" id="commentInput" class="form-control" placeholder="Add a comment..." maxlength="2000">
                            <button class="btn btn-primary" onclick="addComment()"><i class="bi bi-send"></i></button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <hr>

                    <!-- Attachments Section -->
                    <div class="mb-4">
                        <h6 class="text-muted fw-bold"><i class="bi bi-paperclip"></i> Attachments</h6>
                        <div id="attachmentsContainer" class="mb-3">
                            <div class="text-center text-muted py-2">Loading attachments...</div>
                        </div>
                        <?php if ($ticket['status'] !== 'CLOSED'): ?>
                        <div class="input-group">
                            <input type="file" id="fileInput" class="form-control" multiple>
                            <button class="btn btn-outline-primary" onclick="uploadFiles()"><i class="bi bi-upload"></i> Upload</button>
                        </div>
                        <small class="text-muted">Max 10MB per file. Allowed: images, PDF, DOC, XLS</small>
                        <?php endif; ?>
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

                    <!-- Priority -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Priority</h6>
                        <div>
                            <?php
                            $priorityClass = match($ticket['priority'] ?? 'medium') {
                                'critical' => 'bg-danger',
                                'high' => 'bg-warning text-dark',
                                'medium' => 'bg-info',
                                'low' => 'bg-secondary',
                                default => 'bg-secondary'
                            };
                            ?>
                            <span class="badge <?php echo $priorityClass; ?>"><?php echo escapeHtml(ucfirst($ticket['priority'] ?? 'medium')); ?></span>
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

                    <!-- Assigned To -->
                    <div class="mb-3">
                        <h6 class="text-muted small">Assigned To</h6>
                        <small><?php echo escapeHtml($ticket['assigned_to_name'] ?? 'Unassigned'); ?></small>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadComments();
    loadAttachments();
});

function loadComments() {
    fetch('detail_ticket.php?action=get_comments&ticket_id=<?php echo $id; ?>')
    .then(res => res.json())
    .then(comments => {
        const container = document.getElementById('commentsContainer');
        if (!comments.length) {
            container.innerHTML = '<div class="text-center text-muted py-3">No comments yet</div>';
            return;
        }
        container.innerHTML = comments.map(c => `
            <div class="d-flex mb-3">
                <div class="flex-shrink-0 me-2">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between">
                        <strong class="small">${escapeHtml(c.fullname || 'Unknown')}</strong>
                        <small class="text-muted">${new Date(c.created_at).toLocaleString()}</small>
                    </div>
                    <div class="mt-1" style="background:#f8f9fa;padding:8px;border-radius:4px;white-space:pre-wrap;word-wrap:break-word;">${escapeHtml(c.comment)}</div>
                </div>
            </div>
        `).join('');
        container.scrollTop = container.scrollHeight;
    });
}

function addComment() {
    const input = document.getElementById('commentInput');
    const comment = input.value.trim();
    if (!comment) return;

    const formData = new FormData();
    formData.append('action', 'add_comment');
    formData.append('ticket_id', '<?php echo $id; ?>');
    formData.append('comment', comment);
    formData.append('csrf_token', '<?php echo htmlspecialchars(generateCSRFToken()); ?>');

    fetch('detail_ticket.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            input.value = '';
            loadComments();
        } else {
            alert(data.message || 'Failed to add comment');
        }
    });
}

function escapeHtml(s) {
    if (!s) return '';
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function loadAttachments() {
    fetch('detail_ticket.php?action=get_attachments&ticket_id=<?php echo $id; ?>')
    .then(res => res.json())
    .then(files => {
        const container = document.getElementById('attachmentsContainer');
        if (!files.length) {
            container.innerHTML = '<div class="text-center text-muted py-2 small">No attachments</div>';
            return;
        }
        container.innerHTML = files.map(f => {
            const icon = f.mime_type.startsWith('image/') ? 'bi-image' : 'bi-file-earmark';
            const size = f.file_size > 1024*1024 ? (f.file_size/1024/1024).toFixed(1)+'MB' : (f.file_size/1024).toFixed(0)+'KB';
            return `<div class="d-flex align-items-center justify-content-between p-2 mb-1" style="background:#f8f9fa;border-radius:4px;">
                <div><i class="bi ${icon} me-2"></i><a href="../uploads/tickets/${escapeHtml(f.filename)}" target="_blank">${escapeHtml(f.original_name)}</a> <small class="text-muted">(${size})</small></div>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteAttachment(${f.id})"><i class="bi bi-trash"></i></button>
            </div>`;
        }).join('');
    });
}

function uploadFiles() {
    const input = document.getElementById('fileInput');
    if (!input.files.length) return;
    Array.from(input.files).forEach(file => {
        const formData = new FormData();
        formData.append('action', 'upload_attachment');
        formData.append('ticket_id', '<?php echo $id; ?>');
        formData.append('file', file);
        formData.append('csrf_token', '<?php echo htmlspecialchars(generateCSRFToken()); ?>');
        fetch('detail_ticket.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) loadAttachments();
            else alert(data.message || 'Upload failed');
        });
    });
    input.value = '';
}

function deleteAttachment(id) {
    if (!confirm('Delete this attachment?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_attachment');
    formData.append('attachment_id', id);
    fetch('detail_ticket.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => { if (data.success) loadAttachments(); });
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
