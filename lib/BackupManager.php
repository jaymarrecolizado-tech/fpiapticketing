<?php
class BackupManager {
    private $pdo;
    private $backupPath;
    private $retentionDays;

    public function __construct($pdo, $backupPath = '../backups/', $retentionDays = 30) {
        $this->pdo = $pdo;
        $this->backupPath = $backupPath;
        $this->retentionDays = $retentionDays;

        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    public function createDatabaseBackup($type = 'full') {
        $filename = 'db_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $this->backupPath . $filename;

        // Get database credentials from config
        $dbConfig = require '../config/db.php';
        $host = $dbConfig['host'] ?? 'localhost';
        $user = $dbConfig['username'] ?? 'root';
        $pass = $dbConfig['password'] ?? '';
        $db = $dbConfig['database'] ?? 'cagayanregionsite_db';

        // Use full path to mysqldump for Windows
        $mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        $command = "\"$mysqldumpPath\" --host=$host --user=$user --password=\"$pass\" $db > \"$filepath\"";

        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            return [
                'success' => true,
                'filename' => $filename,
                'filepath' => $filepath,
                'size' => filesize($filepath),
                'type' => $type
            ];
        }

        return ['success' => false, 'error' => 'Backup failed', 'output' => $output];
    }

    // File system backup methods
    public function createFilesystemBackup($includePaths = []) {
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = $this->backupPath . 'fs_backup_' . $timestamp . '/';
        $filename = 'fs_backup_' . $timestamp . '.zip'; // Keep .zip extension for consistency
        $filepath = $this->backupPath . $filename;

        $defaultPaths = [
            '../config/',
            '../assets/',
            '../docs/',
            '../lib/',
            '../scripts/'
        ];

        $paths = array_merge($defaultPaths, $includePaths);

        // Create backup directory
        if (!mkdir($backupDir, 0755, true)) {
            return ['success' => false, 'error' => 'Could not create backup directory'];
        }

        // Copy files
        foreach ($paths as $path) {
            $sourcePath = realpath($path);
            if ($sourcePath && is_dir($sourcePath)) {
                $destPath = $backupDir . basename($path);
                $this->copyDirectory($sourcePath, $destPath);
            }
        }

        // Try to create ZIP if possible, otherwise just use the directory
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                $this->addFolderToZip($zip, $backupDir, 'filesystem_backup');
                $zip->close();
                // Remove the temporary directory
                $this->removeDirectory($backupDir);
                $size = filesize($filepath);
            } else {
                // If ZIP fails, keep the directory
                $filepath = $backupDir;
                $filename = basename($backupDir);
                $size = $this->getDirectorySize($backupDir);
            }
        } else {
            // No ZIP support, use directory
            $filepath = $backupDir;
            $filename = basename($backupDir);
            $size = $this->getDirectorySize($backupDir);
        }

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => $size,
            'type' => 'filesystem'
        ];
    }

    // Helper method to copy directory
    private function copyDirectory($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst);
        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $this->copyDirectory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    // Helper method to remove directory
    private function removeDirectory($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // Helper method to get directory size
    private function getDirectorySize($dir) {
        $size = 0;
        if (!is_dir($dir)) return $size;
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            $size += is_dir($path) ? $this->getDirectorySize($path) : filesize($path);
        }
        return $size;
    }

    // Helper method to add folder to ZIP
    private function addFolderToZip($zip, $folder, $zipFolder) {
        if (!is_dir($folder)) {
            return;
        }

        $folder = rtrim($folder, '/');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen(realpath($folder)) + 1);
                $zip->addFile($filePath, $zipFolder . '/' . $relativePath);
            }
        }
    }

    // Combined backup
    public function createFullBackup() {
        $dbResult = $this->createDatabaseBackup('full');
        $fsResult = $this->createFilesystemBackup();

        return [
            'database' => $dbResult,
            'filesystem' => $fsResult,
            'timestamp' => date('Y-m-d H:i:s'),
            'success' => $dbResult['success'] && $fsResult['success']
        ];
    }

    // List backups
    public function listBackups() {
        $backups = [];

        // Get files
        $files = glob($this->backupPath . '*.{sql,zip}', GLOB_BRACE);
        foreach ($files as $file) {
            $filename = basename($file);
            $backups[] = [
                'filename' => $filename,
                'filepath' => $file,
                'size' => filesize($file),
                'created' => date('Y-m-d H:i:s', filemtime($file)),
                'type' => strpos($filename, 'db_backup') === 0 ? 'database' : 'filesystem'
            ];
        }

        // Get directories (filesystem backups)
        $dirs = glob($this->backupPath . 'fs_backup_*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $dirname = basename($dir);
            $backups[] = [
                'filename' => $dirname,
                'filepath' => $dir,
                'size' => $this->getDirectorySize($dir),
                'created' => date('Y-m-d H:i:s', filemtime($dir)),
                'type' => 'filesystem'
            ];
        }

        usort($backups, function($a, $b) {
            return strtotime($b['created']) - strtotime($a['created']);
        });

        return $backups;
    }

    // Delete old backups
    public function cleanupOldBackups() {
        $deleted = 0;

        // Clean up files
        $files = glob($this->backupPath . '*.{sql,zip}', GLOB_BRACE);
        foreach ($files as $file) {
            $fileAge = time() - filemtime($file);
            if ($fileAge > ($this->retentionDays * 24 * 60 * 60)) {
                unlink($file);
                $deleted++;
            }
        }

        // Clean up directories
        $dirs = glob($this->backupPath . 'fs_backup_*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $fileAge = time() - filemtime($dir);
            if ($fileAge > ($this->retentionDays * 24 * 60 * 60)) {
                $this->removeDirectory($dir);
                $deleted++;
            }
        }

        return $deleted;
    }

    // Restore database
    public function restoreDatabase($backupFile) {
        if (!file_exists($backupFile)) {
            return ['success' => false, 'error' => 'Backup file not found'];
        }

        $dbConfig = require '../config/db.php';
        $host = $dbConfig['host'] ?? 'localhost';
        $user = $dbConfig['username'] ?? 'root';
        $pass = $dbConfig['password'] ?? '';
        $db = $dbConfig['database'] ?? 'cagayanregionsite_db';

        // Use full path to mysql for Windows
        $mysqlPath = 'C:\\xampp\\mysql\\bin\\mysql.exe';
        $command = "\"$mysqlPath\" --host=$host --user=$user --password=\"$pass\" $db < \"$backupFile\"";

        exec($command, $output, $returnCode);

        return [
            'success' => $returnCode === 0,
            'output' => $output,
            'return_code' => $returnCode
        ];
    }
}
?>