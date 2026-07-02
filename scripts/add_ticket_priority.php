<?php
/**
 * Migration: Add priority column to tickets table
 * Run once: php scripts/add_ticket_priority.php
 */

require_once __DIR__ . '/../config/db.php';

try {
    // Check if column already exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM tickets LIKE 'priority'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "Column 'priority' already exists in tickets table.\n";
        exit(0);
    }

    // Add priority column with default value
    $pdo->exec("ALTER TABLE tickets ADD COLUMN priority ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium' AFTER status");
    echo "Added 'priority' column to tickets table.\n";

    // Update existing tickets - set priority based on aging status
    $pdo->exec("UPDATE tickets SET priority = 'high' WHERE status IN ('OPEN', 'IN_PROGRESS') AND (duration >= 4320 OR (duration IS NULL AND DATEDIFF(NOW(), created_at) >= 3))");
    echo "Updated aging tickets to 'high' priority.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
