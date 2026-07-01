<?php
/**
 * Migration: Add category column and due_date to tickets
 * Run once: php scripts/add_categories_and_due_date.php
 */

require_once __DIR__ . '/../config/db.php';

try {
    // Add category column
    $stmt = $pdo->prepare("SHOW COLUMNS FROM tickets LIKE 'category'");
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN category VARCHAR(100) DEFAULT NULL AFTER priority");
        echo "Added 'category' column.\n";
    } else {
        echo "Column 'category' already exists.\n";
    }

    // Add due_date column
    $stmt = $pdo->prepare("SHOW COLUMNS FROM tickets LIKE 'due_date'");
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN due_date DATETIME DEFAULT NULL AFTER solved_date");
        echo "Added 'due_date' column.\n";
    } else {
        echo "Column 'due_date' already exists.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
