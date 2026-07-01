<?php
/**
 * Database Setup Script for Data Export Functionality
 * Run this script once to create the required database table
 */

require_once __DIR__ . '/../config/db.php';

try {
    // Create data_exports table
    $sql = "
        CREATE TABLE IF NOT EXISTS `data_exports` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `data_type` enum('tickets','sites','personnel') NOT NULL,
            `filename` varchar(255) NOT NULL,
            `filters` text,
            `total_records` int(11) DEFAULT 0,
            `file_size` bigint(20) DEFAULT 0,
            `status` enum('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
            `started_at` datetime NOT NULL,
            `completed_at` datetime DEFAULT NULL,
            `error_message` text,
            `created_by` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_data_type` (`data_type`),
            KEY `idx_status` (`status`),
            KEY `idx_created_by` (`created_by`),
            KEY `idx_started_at` (`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sql);
    echo "✓ data_exports table created successfully\n";

    // Create indexes for better performance
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_data_exports_data_type ON data_exports(data_type)",
        "CREATE INDEX IF NOT EXISTS idx_data_exports_status ON data_exports(status)",
        "CREATE INDEX IF NOT EXISTS idx_data_exports_created_by ON data_exports(created_by)",
        "CREATE INDEX IF NOT EXISTS idx_data_exports_started_at ON data_exports(started_at)"
    ];

    foreach ($indexes as $indexSql) {
        $pdo->exec($indexSql);
    }
    echo "✓ Database indexes created successfully\n";

    // Insert sample data for testing (optional)
    $sampleData = "
        INSERT IGNORE INTO data_exports (data_type, filename, total_records, file_size, status, started_at, completed_at, created_by)
        VALUES
        ('tickets', 'export_tickets_2024-01-01_12-00-00.csv', 150, 245760, 'completed', '2024-01-01 12:00:00', '2024-01-01 12:05:00', 1),
        ('sites', 'export_sites_2024-01-02_14-30-00.csv', 45, 51200, 'completed', '2024-01-02 14:30:00', '2024-01-02 14:32:00', 1),
        ('personnel', 'export_personnel_2024-01-03_09-15-00.csv', 12, 15360, 'completed', '2024-01-03 09:15:00', '2024-01-03 09:16:00', 1);
    ";

    $pdo->exec($sampleData);
    echo "✓ Sample data inserted successfully\n";

    echo "\n🎉 Database setup completed successfully!\n";
    echo "The data export functionality is now ready to use.\n";

} catch (PDOException $e) {
    echo "❌ Database setup failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>