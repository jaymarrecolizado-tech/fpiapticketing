<?php
/**
 * Migration: Create ticket_counter table for ticket number generation
 * Run once: php scripts/create_ticket_counter_table.php
 */

require_once __DIR__ . '/../config/db.php';

try {
    // Check if table already exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'ticket_counter'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "Table 'ticket_counter' already exists.\n";
        exit(0);
    }

    // Create ticket_counter table
    $pdo->exec("CREATE TABLE ticket_counter (
        year INT PRIMARY KEY,
        counter INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "Created 'ticket_counter' table.\n";

    // Seed current year
    $currentYear = date('Y');
    $stmt = $pdo->prepare("INSERT IGNORE INTO ticket_counter (year, counter, updated_at) VALUES (?, 0, NOW())");
    $stmt->execute([$currentYear]);
    echo "Seeded row for year $currentYear.\n";

    // Seed previous years if tickets exist
    $stmt = $pdo->query("SELECT DISTINCT YEAR(created_at) AS y FROM tickets WHERE ticket_number IS NOT NULL ORDER BY y");
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($years as $yr) {
        $stmt2 = $pdo->prepare("INSERT IGNORE INTO ticket_counter (year, counter, updated_at) VALUES (?, 0, NOW())");
        $stmt2->execute([$yr]);
    }
    if (count($years) > 1) {
        echo "Seeded rows for " . implode(', ', $years) . ".\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
