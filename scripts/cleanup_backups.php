<?php
// Cleanup old backup files script
// Usage: php cleanup_backups.php

require_once '../config/db.php';
require_once '../lib/BackupManager.php';
require_once '../lib/Logger.php';

try {
    $backupManager = new BackupManager($pdo, '../backups/');
    $logger = new Logger($pdo);
    $deleted = $backupManager->cleanupOldBackups();

    echo "Cleanup completed. Deleted $deleted old backup files.\n";

    // Log cleanup operation to system logs
    $logger->log('backup_cleanup', [
        'files_deleted' => $deleted,
        'description' => "Manual backup cleanup deleted {$deleted} old backup files"
    ], 'low', 'success');

} catch (Exception $e) {
    echo "Error during cleanup: " . $e->getMessage() . "\n";

    // Log cleanup error to system logs
    $logger->log('backup_cleanup_error', [
        'error' => $e->getMessage(),
        'description' => "Backup cleanup script error: " . $e->getMessage()
    ], 'high', 'failure');

    exit(1);
}
?>