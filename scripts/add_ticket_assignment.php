<?php
/**
 * Migration: Add assigned_to column to tickets table
 * Run once: php scripts/add_ticket_assignment.php
 */

require_once __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM tickets LIKE 'assigned_to'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "Column 'assigned_to' already exists.\n";
        exit(0);
    }

    $pdo->exec("ALTER TABLE tickets ADD COLUMN assigned_to INT DEFAULT NULL AFTER created_by");
    echo "Added 'assigned_to' column to tickets table.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
