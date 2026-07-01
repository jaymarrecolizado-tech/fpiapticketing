<?php
// Automated backup script for cron jobs
// Usage: php automated_backup.php [type]
// Types: full, database, filesystem

require_once '../config/db.php';
require_once '../lib/BackupManager.php';
require_once '../lib/Logger.php';

$backupType = $argv[1] ?? 'full';

try {
    $backupManager = new BackupManager($pdo, '../backups/');
    $logger = new Logger($pdo);

    switch ($backupType) {
        case 'full':
            $result = $backupManager->createFullBackup();
            break;
        case 'database':
            $result = $backupManager->createDatabaseBackup();
            break;
        case 'filesystem':
            $result = $backupManager->createFilesystemBackup();
            break;
        default:
            throw new Exception("Invalid backup type: $backupType");
    }

    if ($result['success']) {
        echo "Backup completed successfully\n";
        echo "Type: $backupType\n";

        if (isset($result['database'])) {
            echo "Database: " . $result['database']['filename'] . "\n";
            echo "Filesystem: " . $result['filesystem']['filename'] . "\n";
        } else {
            echo "File: " . $result['filename'] . "\n";
        }

        // Log successful automated backup to system logs
        $logger->log('automated_backup_created', [
            'backup_type' => $backupType,
            'filename' => isset($result['database']) ? $result['database']['filename'] . ', ' . $result['filesystem']['filename'] : $result['filename'],
            'filepath' => isset($result['database']) ? $result['database']['filepath'] . ', ' . $result['filesystem']['filepath'] : $result['filepath'],
            'size' => isset($result['database']) ? $result['database']['size'] + $result['filesystem']['size'] : $result['size'],
            'description' => "Automated backup completed successfully: {$backupType} backup"
        ], 'medium', 'success');

        // Cleanup old backups
        $deleted = $backupManager->cleanupOldBackups();
        if ($deleted > 0) {
            echo "Cleaned up $deleted old backup files\n";

            // Log cleanup to system logs
            $logger->log('backup_cleanup', [
                'files_deleted' => $deleted,
                'description' => "Automated cleanup deleted {$deleted} old backup files"
            ], 'low', 'success');
        }

    } else {
        echo "Backup failed: " . ($result['error'] ?? 'Unknown error') . "\n";

        // Log failed automated backup to system logs
        $logger->log('automated_backup_failed', [
            'backup_type' => $backupType,
            'error' => $result['error'] ?? 'Unknown error',
            'description' => "Automated backup failed: {$backupType} backup"
        ], 'high', 'failure');

        exit(1);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";

    // Log exception in automated backup to system logs
    $logger->log('automated_backup_error', [
        'backup_type' => $backupType,
        'error' => $e->getMessage(),
        'description' => "Automated backup script error: " . $e->getMessage()
    ], 'critical', 'failure');

    exit(1);
}
?>