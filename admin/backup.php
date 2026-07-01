<?php
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/security_headers.php';
require_once '../lib/BackupManager.php';
require_once '../lib/Logger.php';

requireAdmin();

$backupManager = new BackupManager($pdo, '../backups/');
$logger = new Logger($pdo);
$message = '';
$messageType = '';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'create_backup') {
    try {
        $backupType = $_POST['backup_type'] ?? 'full';

        if ($backupType === 'database') {
            $result = $backupManager->createDatabaseBackup();
        } elseif ($backupType === 'filesystem') {
            $result = $backupManager->createFilesystemBackup();
        } else {
            $result = $backupManager->createFullBackup();
        }

        if ($result['success']) {
            $message = "Backup created successfully: " . ($result['filename'] ?? $result['database']['filename'] . ', ' . $result['filesystem']['filename']);
            $messageType = 'success';

            // Log backup creation to system logs
            $logger->log('backup_created', [
                'backup_type' => $backupType,
                'filename' => $result['filename'] ?? $result['database']['filename'] . ', ' . $result['filesystem']['filename'],
                'filepath' => $result['filepath'] ?? $result['database']['filepath'] . ', ' . $result['filesystem']['filepath'],
                'size' => $result['size'] ?? $result['database']['size'] + $result['filesystem']['size'],
                'description' => "Backup created successfully: {$backupType} backup"
            ], 'medium', 'success');

            // Log backup creation
            logBackup($pdo, $backupType, $result, $_SESSION['user_id']);
        } else {
            $message = "Backup failed: " . ($result['error'] ?? 'Unknown error');
            $messageType = 'danger';

            // Log backup failure to system logs
            $logger->log('backup_failed', [
                'backup_type' => $backupType,
                'error' => $result['error'] ?? 'Unknown error',
                'description' => "Backup creation failed: {$backupType} backup"
            ], 'high', 'failure');
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'danger';
    }
}

if ($action === 'download') {
    $filename = $_GET['file'] ?? '';
    $filepath = '../backups/' . $filename;

    if (is_dir($filepath)) {
        if (!class_exists('ZipArchive')) {
            $message = 'PHP ZipArchive extension not installed. Directory backup cannot be downloaded as ZIP.';
            $messageType = 'danger';
            $logger->log('backup_download_failed', [
                'filename' => $filename,
                'filepath' => $filepath,
                'error' => 'ZipArchive not available',
                'description' => "Backup directory download failed: ZipArchive extension missing for {$filename}"
            ], 'high', 'failure');
        } else {
            // Create temporary ZIP from directory for download
            $tmpZip = '../backups/' . $filename . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($filepath, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    /** @var SplFileInfo $file */
                    $relativePath = substr($file->getPathname(), strlen(realpath($filepath)) + 1);
                    $zip->addFile($file->getPathname(), $relativePath);
                }
                $zip->close();

                // Log backup download to system logs
                $logger->log('backup_downloaded', [
                    'filename' => basename($tmpZip),
                    'filepath' => realpath($tmpZip),
                    'size' => filesize($tmpZip),
                    'description' => "Backup directory downloaded as ZIP: {$filename}"
                ], 'medium', 'success');

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($tmpZip) . '"');
                header('Content-Length: ' . filesize($tmpZip));
                readfile($tmpZip);
                unlink($tmpZip);
                exit;
            } else {
                $message = 'Unable to create ZIP for download';
                $messageType = 'danger';
                $logger->log('backup_download_failed', [
                    'filename' => $filename,
                    'filepath' => $filepath,
                    'error' => 'ZIP creation failed',
                    'description' => "Backup directory download failed (ZIP creation): {$filename}"
                ], 'medium', 'failure');
            }
        }
    } elseif (file_exists($filepath) && is_readable($filepath)) {
        // Log backup download to system logs
        $logger->log('backup_downloaded', [
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'description' => "Backup file downloaded: {$filename}"
        ], 'medium', 'success');

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        $message = "File not found or not readable";
        $messageType = 'danger';

        // Log download failure to system logs
        $logger->log('backup_download_failed', [
            'filename' => $filename,
            'filepath' => $filepath,
            'error' => 'File not found or not readable',
            'description' => "Backup download failed: {$filename}"
        ], 'medium', 'failure');
    }
}

if ($action === 'delete') {
    $filename = $_POST['filename'] ?? '';
    $filepath = '../backups/' . $filename;

    if (file_exists($filepath)) {
        $fileSize = is_file($filepath) ? filesize($filepath) : 0;

        if (is_dir($filepath)) {
            // Remove directory recursively
            removeDirectory($filepath);
        } else {
            unlink($filepath);
        }

        // Log backup deletion to system logs
        $logger->log('backup_deleted', [
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => $fileSize,
            'description' => "Backup file deleted: {$filename}"
        ], 'high', 'success');

        $message = "Backup deleted successfully";
        $messageType = 'success';
    } else {
        $message = "File not found";
        $messageType = 'danger';

        // Log deletion failure to system logs
        $logger->log('backup_delete_failed', [
            'filename' => $filename,
            'filepath' => $filepath,
            'error' => 'File not found',
            'description' => "Backup deletion failed: {$filename}"
        ], 'medium', 'failure');
    }
}

$backups = $backupManager->listBackups();

function removeDirectory($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? removeDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

function logBackup($pdo, $type, $result, $userId) {
    if (is_array($result) && isset($result['database'])) {
        // Full backup
        $stmt = $pdo->prepare("INSERT INTO backup_history (backup_type, filename, filepath, file_size, status, created_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'full',
            $result['database']['filename'] . ', ' . $result['filesystem']['filename'],
            $result['database']['filepath'] . ', ' . $result['filesystem']['filepath'],
            $result['database']['size'] + $result['filesystem']['size'],
            'success',
            $userId,
            'Full backup created'
        ]);
    } elseif (isset($result['filename'])) {
        // Single backup
        $stmt = $pdo->prepare("INSERT INTO backup_history (backup_type, filename, filepath, file_size, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $type,
            $result['filename'],
            $result['filepath'],
            $result['size'],
            'success',
            $userId
        ]);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .dropdown-menu {
            z-index: 1050;
        }
        .navbar-toggler {
            border: none;
        }
    </style>
    <title>Backup Management - FPIAP-SMARTs</title>
</head>
<body class="d-flex flex-column min-vh-100">
    <!-- Navigation -->
    <nav class="navbar sticky-top navbar-expand-lg navbar-light shadow-sm" style="background-color: #0ef;">
        <div class="container-fluid">

            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img src="../assets/freewifilogo.png" alt="Logo" width="100" height="100" class="me-2">
                <img src="../assets/FPIAP-SMARTs.png" alt="Logo" width="100" height="100" class="me-2">
                <div class="d-flex flex-column ms-0">
                    <span class="fw-bold">FPIAP-SMARTs</span>
                    <span class="fw-bold small align-self-center">ADMIN PANEL</span>
                </div>
            </a>

            <hr class="mx-0 my-2 opacity-25">

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" id="navbarDropdownTicket" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Tickets
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="navbarDropdownTicket">
                            <li><a class="dropdown-item" href="viewtickets.php">View Tickets</a></li>
                            <li><a class="dropdown-item" href="ticket.php">Create Ticket</a></li>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" id="navbarDropdownSites" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Sites
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="navbarDropdownSites">
                            <li><a class="dropdown-item" href="site.php">Manage Sites</a></li>
                            <li><a class="dropdown-item" href="site_report.php">Sites Report</a></li>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" id="navbarDropdownReports" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Reports
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="navbarDropdownReports">
                            <li><a class="dropdown-item" href="ticket_report.php">Ticket Report</a></li>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" id="navbarDropdownSettings" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Setting
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="navbarDropdownSettings">
                            <li><a class="dropdown-item" href="personnel.php">Personnels</a></li>
                            <li><a class="dropdown-item" href="user.php">User's Management</a></li>
                            <li><a class="dropdown-item" href="systemlog.php">System Log</a></li>
                            <li><a class="dropdown-item" href="backup.php">Backup Management</a></li>
                            <li><a class="dropdown-item" href="data_export.php">Data Export</a></li>
                            <li><a class="dropdown-item" href="history.php">History</a></li>
                        </ul>
                    </li>
                </ul>

                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item dropdown me-3">
                        <a id="notificationBell" class="nav-link position-relative dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bell fs-5"></i>
                            <span id="notificationBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger visually-hidden">0</span>
                        </a>
                        <ul id="notificationDropdown" class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationBell">
                            <li class="dropdown-item text-center text-muted small">Loading...</li>
                        </ul>
                    </li>

                    <li class="nav-item d-flex align-items-center me-3">
                        <div class="d-flex flex-column text-end">
                            <span class="fw-semibold text-dark small"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown User'); ?></span>
                        </div>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle fs-4 me-1"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="account.php">My Account</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid my-4">
        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">
                    <i class="bi bi-shield-check text-primary me-2"></i>
                    Backup Management
                </h1>

                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Create Backup Section -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-plus-circle me-2"></i>
                            Create New Backup
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="backup.php">
                            <input type="hidden" name="action" value="create_backup">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Backup Type</label>
                                    <select name="backup_type" class="form-select" required>
                                        <option value="full">Full Backup (Database + Files)</option>
                                        <option value="database">Database Only</option>
                                        <option value="filesystem">Files Only</option>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-shield-check me-2"></i>
                                        Create Backup
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Backup History Section -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history me-2"></i>
                            Backup History
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Filename</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $backup): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $backup['type'] === 'database' ? 'primary' : 'success'; ?>">
                                                <?php echo ucfirst($backup['type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($backup['size'] / 1024 / 1024, 2); ?> MB</td>
                                        <td><?php echo $backup['created']; ?></td>
                                        <td>
                                            <?php if (is_file($backup['filepath']) || is_dir($backup['filepath'])): ?>
                                            <a href="backup.php?action=download&file=<?php echo urlencode($backup['filename']); ?>"
                                               class="btn btn-sm btn-outline-primary me-2">
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                            <?php else: ?>
                                            <span class="text-muted small">Not available for download</span>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="deleteBackup('<?php echo htmlspecialchars($backup['filename']); ?>')">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Backup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete this backup file? This action cannot be undone.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let deleteFilename = '';

        function deleteBackup(filename) {
            deleteFilename = filename;
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        function confirmDelete() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'backup.php';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete';
            form.appendChild(actionInput);

            const filenameInput = document.createElement('input');
            filenameInput.type = 'hidden';
            filenameInput.name = 'filename';
            filenameInput.value = deleteFilename;
            form.appendChild(filenameInput);

            document.body.appendChild(form);
            form.submit();
        }

        // Initialize dropdowns on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize all dropdown toggles
            const dropdownToggles = document.querySelectorAll('[data-bs-toggle="dropdown"]');
            dropdownToggles.forEach(toggle => {
                new bootstrap.Dropdown(toggle);
            });

            // Initialize notifications
            fetchNotifications();
            setInterval(fetchNotifications, 60000);

            const bellToggle = document.getElementById('notificationBell');
            if (bellToggle) {
                bellToggle.addEventListener('show.bs.dropdown', fetchNotifications);
            }
        });

        // Fetch notifications from notification.php
        async function fetchNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            const badge = document.getElementById('notificationBadge');
            if (!dropdown) return;
            try {
                const resp = await fetch('notification.php', { method: 'GET', cache: 'no-cache' });
                if (!resp.ok) throw new Error('Network response not ok');
                const html = await resp.text();

                if (html && html.trim().length > 0) {
                    dropdown.innerHTML = html;
                } else {
                    dropdown.innerHTML = '<li class="dropdown-item text-center text-muted small">No notifications</li>';
                }

                // Attach click handlers to notification items
                dropdown.querySelectorAll('.notification-item').forEach(item => {
                    item.addEventListener('click', function(e) {
                        const notificationId = this.getAttribute('data-notification-id');
                        if (notificationId) {
                            fetch('../notif/api.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=mark_read&notification_id=' + notificationId
                            }).catch(err => console.error('Failed to mark notification as read:', err));
                            this.classList.remove('unread');
                        }
                    });
                });

                const unread = dropdown.querySelectorAll('.notification-item.unread, li[data-unread="1"]').length;
                if (unread > 0) {
                    badge.textContent = String(unread);
                    badge.classList.remove('visually-hidden');
                } else {
                    badge.classList.add('visually-hidden');
                }
            } catch (err) {
                dropdown.innerHTML = '<li class="dropdown-item text-danger small">Error loading notifications</li>';
                if (badge) badge.classList.add('visually-hidden');
                console.error('Failed to load notifications:', err);
            }
        }
    </script>
</body>
</html>