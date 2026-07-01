<?php
/**
 * DataExport Class
 * Handles data export operations for the FPIAP-SMARTs Ticketing System
 *
 * Features:
 * - Export tickets, sites, and personnel data
 * - Advanced filtering capabilities
 * - Memory-efficient streaming for large datasets
 * - Progress tracking and audit logging
 * - Secure file handling with access controls
 * - UTF-8 BOM support for Excel compatibility
 * - CSV format with configurable delimiters
 */

class DataExport {
    private $pdo;
    private $logger;
    private $exportDir;
    private $maxMemoryLimit = '256M';
    private $chunkSize = 1000;

    /**
     * Constructor
     */
    public function __construct($pdo, $logger = null) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->exportDir = __DIR__ . '/../uploads/import_export/exports/';

        // Ensure export directory exists
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0755, true);
        }

        // Set memory limit for large exports
        ini_set('memory_limit', $this->maxMemoryLimit);
        ini_set('max_execution_time', 300); // 5 minutes
    }

    /**
     * Start export process
     */
    public function startExport($dataType, $filters = [], $options = []) {
        try {
            // Validate data type
            if (!in_array($dataType, ['tickets', 'sites', 'personnel'])) {
                throw new Exception('Invalid data type specified');
            }

            // Generate unique filename
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "export_{$dataType}_{$timestamp}.csv";
            $filepath = $this->exportDir . $filename;

            // Create export record in database
            $exportId = $this->createExportRecord($dataType, $filename, $filters);

            // Start export process
            $result = $this->exportData($dataType, $filters, $options, $filepath);

            // Update export record
            $this->updateExportRecord($exportId, 'completed', $result['record_count'], $result['file_size']);

            // Log successful export
            if ($this->logger) {
                $this->logger->log("Data export completed: {$dataType}, {$result['record_count']} records, {$result['file_size']} bytes", 'INFO');
            }

            return [
                'success' => true,
                'export_id' => $exportId,
                'filename' => $filename,
                'record_count' => $result['record_count'],
                'file_size' => $result['file_size']
            ];

        } catch (Exception $e) {
            // Update export record on failure
            if (isset($exportId)) {
                $this->updateExportRecord($exportId, 'failed', 0, 0, $e->getMessage());
            }

            // Log error
            if ($this->logger) {
                $this->logger->log("Data export failed: {$dataType} - " . $e->getMessage(), 'ERROR');
            }

            throw $e;
        }
    }

    /**
     * Export data to CSV file
     */
    private function exportData($dataType, $filters, $options, $filepath) {
        $recordCount = 0;
        $fileSize = 0;

        // Open file for writing
        $file = fopen($filepath, 'w');

        if (!$file) {
            throw new Exception('Unable to create export file');
        }

        try {
            // Write UTF-8 BOM if requested
            if (isset($options['utf8_bom']) && $options['utf8_bom']) {
                fwrite($file, "\xEF\xBB\xBF");
            }

            // Get data headers
            $headers = $this->getDataHeaders($dataType);
            if (isset($options['include_headers']) && $options['include_headers']) {
                fputcsv($file, $headers, $options['delimiter'] ?? ',', $options['enclosure'] ?? '"', $options['escape'] ?? '\\');
            }

            // Export data in chunks
            $offset = 0;
            while (true) {
                $data = $this->getDataChunk($dataType, $filters, $offset, $this->chunkSize);

                if (empty($data)) {
                    break;
                }

                foreach ($data as $row) {
                    fputcsv($file, $row, $options['delimiter'] ?? ',', $options['enclosure'] ?? '"', $options['escape'] ?? '\\');
                    $recordCount++;
                }

                $offset += $this->chunkSize;

                // Prevent memory exhaustion
                if ($recordCount % 10000 === 0) {
                    gc_collect_cycles();
                }
            }

            // Get final file size
            $fileSize = filesize($filepath);

        } finally {
            fclose($file);
        }

        return [
            'record_count' => $recordCount,
            'file_size' => $fileSize
        ];
    }

    /**
     * Get data headers for export
     */
    private function getDataHeaders($dataType) {
        switch ($dataType) {
            case 'tickets':
                return [
                    'Ticket ID',
                    'Ticket Number',
                    'Subject',
                    'Site Name',
                    'Province',
                    'Municipality',
                    'ISP',
                    'Project',
                    'Created By',
                    'Status',
                    'Notes',
                    'Created At',
                    'Updated At',
                    'Solved Date',
                    'Duration'
                ];
            case 'sites':
                return [
                    'Site ID',
                    'Project Name',
                    'Location Name',
                    'Site Name',
                    'AP Site Code',
                    'ISP',
                    'Province',
                    'Municipality',
                    'Status',
                    'Created At',
                    'Updated At',
                    'Created By'
                ];
            case 'personnel':
                return [
                    'Personnel ID',
                    'Full Name',
                    'Gmail',
                    'Status',
                    'Created At',
                    'Updated At'
                ];
            default:
                return [];
        }
    }

    /**
     * Get data chunk for export
     */
    private function getDataChunk($dataType, $filters, $offset, $limit) {
        $query = '';
        $params = [];

        switch ($dataType) {
            case 'tickets':
                list($query, $params) = $this->buildTicketsQuery($filters);
                break;
            case 'sites':
                list($query, $params) = $this->buildSitesQuery($filters);
                break;
            case 'personnel':
                list($query, $params) = $this->buildPersonnelQuery($filters);
                break;
        }

        if (!$query) {
            return [];
        }

        $query .= " LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format data for CSV export
        return $this->formatDataForExport($dataType, $results);
    }

    /**
     * Build tickets query with filters
     */
    private function buildTicketsQuery($filters) {
        $query = "
            SELECT
                t.id,
                t.ticket_number,
                t.subject,
                s.site_name,
                s.province,
                s.municipality,
                s.isp,
                s.project_name,
                COALESCE(p.fullname, 'Unknown') as created_by,
                t.status,
                t.notes,
                t.created_at,
                t.updated_at,
                t.solved_date,
                t.duration
            FROM tickets t
            LEFT JOIN sites s ON t.site_id = s.id
            LEFT JOIN personnels p ON t.created_by = p.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['date_from'])) {
            $query .= " AND DATE(t.created_at) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $query .= " AND DATE(t.created_at) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = str_repeat('?,', count($filters['status']) - 1) . '?';
                $query .= " AND t.status IN ($placeholders)";
                $params = array_merge($params, $filters['status']);
            } else {
                $query .= " AND t.status = ?";
                $params[] = $filters['status'];
            }
        }

        if (!empty($filters['site_id'])) {
            if (is_array($filters['site_id'])) {
                $placeholders = str_repeat('?,', count($filters['site_id']) - 1) . '?';
                $query .= " AND t.site_id IN ($placeholders)";
                $params = array_merge($params, array_map('intval', $filters['site_id']));
            } else {
                $query .= " AND t.site_id = ?";
                $params[] = intval($filters['site_id']);
            }
        }

        if (!empty($filters['created_by'])) {
            if (is_array($filters['created_by'])) {
                $placeholders = str_repeat('?,', count($filters['created_by']) - 1) . '?';
                $query .= " AND p.id IN ($placeholders)";
                $params = array_merge($params, array_map('intval', $filters['created_by']));
            } else {
                $query .= " AND p.id = ?";
                $params[] = intval($filters['created_by']);
            }
        }

        if (!empty($filters['isp'])) {
            if (is_array($filters['isp'])) {
                $placeholders = str_repeat('?,', count($filters['isp']) - 1) . '?';
                $query .= " AND s.isp IN ($placeholders)";
                $params = array_merge($params, $filters['isp']);
            } else {
                $query .= " AND s.isp = ?";
                $params[] = $filters['isp'];
            }
        }

        if (!empty($filters['province'])) {
            if (is_array($filters['province'])) {
                $placeholders = str_repeat('?,', count($filters['province']) - 1) . '?';
                $query .= " AND s.province IN ($placeholders)";
                $params = array_merge($params, $filters['province']);
            } else {
                $query .= " AND s.province = ?";
                $params[] = $filters['province'];
            }
        }

        if (!empty($filters['municipality'])) {
            if (is_array($filters['municipality'])) {
                $placeholders = str_repeat('?,', count($filters['municipality']) - 1) . '?';
                $query .= " AND s.municipality IN ($placeholders)";
                $params = array_merge($params, $filters['municipality']);
            } else {
                $query .= " AND s.municipality = ?";
                $params[] = $filters['municipality'];
            }
        }

        if (!empty($filters['project'])) {
            if (is_array($filters['project'])) {
                $placeholders = str_repeat('?,', count($filters['project']) - 1) . '?';
                $query .= " AND s.project_name IN ($placeholders)";
                $params = array_merge($params, $filters['project']);
            } else {
                $query .= " AND s.project_name = ?";
                $params[] = $filters['project'];
            }
        }

        $query .= " ORDER BY t.created_at DESC";

        return [$query, $params];
    }

    /**
     * Build sites query with filters
     */
    private function buildSitesQuery($filters) {
        $query = "
            SELECT
                id,
                project_name,
                location_name,
                site_name,
                ap_site_code,
                isp,
                province,
                municipality,
                status,
                created_at,
                updated_at,
                created_by
            FROM sites
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['province'])) {
            if (is_array($filters['province'])) {
                $placeholders = str_repeat('?,', count($filters['province']) - 1) . '?';
                $query .= " AND province IN ($placeholders)";
                $params = array_merge($params, $filters['province']);
            } else {
                $query .= " AND province = ?";
                $params[] = $filters['province'];
            }
        }

        if (!empty($filters['isp'])) {
            if (is_array($filters['isp'])) {
                $placeholders = str_repeat('?,', count($filters['isp']) - 1) . '?';
                $query .= " AND isp IN ($placeholders)";
                $params = array_merge($params, $filters['isp']);
            } else {
                $query .= " AND isp = ?";
                $params[] = $filters['isp'];
            }
        }

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = str_repeat('?,', count($filters['status']) - 1) . '?';
                $query .= " AND status IN ($placeholders)";
                $params = array_merge($params, $filters['status']);
            } else {
                $query .= " AND status = ?";
                $params[] = $filters['status'];
            }
        }

        if (!empty($filters['project'])) {
            if (is_array($filters['project'])) {
                $placeholders = str_repeat('?,', count($filters['project']) - 1) . '?';
                $query .= " AND project_name IN ($placeholders)";
                $params = array_merge($params, $filters['project']);
            } else {
                $query .= " AND project_name = ?";
                $params[] = $filters['project'];
            }
        }

        $query .= " ORDER BY site_name ASC";

        return [$query, $params];
    }

    /**
     * Build personnel query with filters
     */
    private function buildPersonnelQuery($filters) {
        $query = "
            SELECT
                id,
                fullname,
                gmail,
                status,
                created_at,
                updated_at
            FROM personnels
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query .= " AND (fullname LIKE ? OR gmail LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $query .= " ORDER BY fullname ASC";

        return [$query, $params];
    }

    /**
     * Format data for CSV export
     */
    private function formatDataForExport($dataType, $data) {
        $formatted = [];

        foreach ($data as $row) {
            switch ($dataType) {
                case 'tickets':
                    $formatted[] = [
                        $row['id'],
                        $row['ticket_number'],
                        $row['subject'],
                        $row['site_name'],
                        $row['province'],
                        $row['municipality'],
                        $row['isp'],
                        $row['project_name'],
                        $row['created_by'],
                        $row['status'],
                        $row['notes'],
                        date('Y-m-d H:i:s', strtotime($row['created_at'])),
                        $row['updated_at'] ? date('Y-m-d H:i:s', strtotime($row['updated_at'])) : '',
                        $row['solved_date'] ? date('Y-m-d H:i:s', strtotime($row['solved_date'])) : '',
                        $row['duration'] ?? ''
                    ];
                    break;

                case 'sites':
                    $formatted[] = [
                        $row['id'],
                        $row['project_name'],
                        $row['location_name'],
                        $row['site_name'],
                        $row['ap_site_code'],
                        $row['isp'],
                        $row['province'],
                        $row['municipality'],
                        $row['status'],
                        date('Y-m-d H:i:s', strtotime($row['created_at'])),
                        $row['updated_at'] ? date('Y-m-d H:i:s', strtotime($row['updated_at'])) : '',
                        $row['created_by'] ?? ''
                    ];
                    break;

                case 'personnel':
                    $formatted[] = [
                        $row['id'],
                        $row['fullname'],
                        $row['gmail'],
                        $row['status'],
                        date('Y-m-d H:i:s', strtotime($row['created_at'])),
                        $row['updated_at'] ? date('Y-m-d H:i:s', strtotime($row['updated_at'])) : ''
                    ];
                    break;
            }
        }

        return $formatted;
    }

    /**
     * Create export record in database
     */
    private function createExportRecord($dataType, $filename, $filters) {
        $stmt = $this->pdo->prepare("
            INSERT INTO data_exports (data_type, filename, filters, status, started_at, created_by)
            VALUES (?, ?, ?, 'processing', NOW(), ?)
        ");

        $userId = $_SESSION['personnel_id'] ?? $_SESSION['user_id'] ?? 1; // Use personnel_id if available, fallback to user_id or default to 1
        $stmt->execute([$dataType, $filename, json_encode($filters), $userId]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Update export record
     */
    private function updateExportRecord($exportId, $status, $recordCount, $fileSize, $errorMessage = null) {
        $stmt = $this->pdo->prepare("
            UPDATE data_exports
            SET status = ?, total_records = ?, file_size = ?, completed_at = NOW(), error_message = ?
            WHERE id = ?
        ");

        $stmt->execute([$status, $recordCount, $fileSize, $errorMessage, $exportId]);
    }

    /**
     * Get export history
     */
    public function getExportHistory($limit = 50) {
        $stmt = $this->pdo->prepare("
            SELECT
                id,
                data_type,
                filename,
                total_records,
                file_size,
                status,
                started_at,
                completed_at,
                error_message
            FROM data_exports
            ORDER BY started_at DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete export record and file
     */
    public function deleteExport($exportId) {
        // Get filename for the export record
        $stmt = $this->pdo->prepare("SELECT filename FROM data_exports WHERE id = ?");
        $stmt->execute([$exportId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return false;
        }

        // Delete file if exists
        $filepath = $this->exportDir . $record['filename'];
        if (file_exists($filepath)) {
            @unlink($filepath);
        }

        // Delete DB record
        $stmt = $this->pdo->prepare("DELETE FROM data_exports WHERE id = ?");
        return $stmt->execute([$exportId]);
    }

    /**
     * Get sites for filter dropdown
     */
    public function getSitesForFilter() {
        $stmt = $this->pdo->query("
            SELECT id, site_name, province
            FROM sites
            ORDER BY site_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get provinces for filter dropdown
     */
    public function getProvincesForFilter() {
        $stmt = $this->pdo->query("
            SELECT DISTINCT province
            FROM sites
            WHERE province IS NOT NULL AND province != ''
            ORDER BY province ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get ISPs for filter dropdown
     */
    public function getISPsForFilter() {
        $stmt = $this->pdo->query("
            SELECT DISTINCT isp
            FROM sites
            WHERE isp IS NOT NULL AND isp != ''
            ORDER BY isp ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Secure file download
     */
    public function downloadFile($filename) {
        $filepath = $this->exportDir . $filename;

        // Security checks
        if (!file_exists($filepath)) {
            throw new Exception('File not found');
        }

        // Check if file is in export directory
        $realPath = realpath($filepath);
        $realExportDir = realpath($this->exportDir);

        if (!$realPath || !str_starts_with($realPath, $realExportDir)) {
            throw new Exception('Invalid file path');
        }

        // Check if file is recent (within 24 hours)
        $fileAge = time() - filemtime($filepath);
        if ($fileAge > 86400) { // 24 hours
            throw new Exception('File download expired');
        }

        // Set headers for download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        // Output file
        readfile($filepath);
        exit;
    }

    /**
     * Clean up old export files
     */
    public function cleanupOldFiles($daysOld = 7) {
        $files = glob($this->exportDir . '*.csv');

        foreach ($files as $file) {
            $fileAge = time() - filemtime($file);
            if ($fileAge > ($daysOld * 86400)) {
                unlink($file);
            }
        }
    }
}
?>