<?php
// Database migration script to create backup_history table
require_once '../config/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS backup_history (
        id INT PRIMARY KEY AUTO_INCREMENT,
        backup_type ENUM('full', 'database', 'filesystem', 'incremental') NOT NULL,
        filename VARCHAR(255) NOT NULL,
        filepath VARCHAR(500) NOT NULL,
        file_size INT NOT NULL,
        status ENUM('success', 'failed') NOT NULL,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        FOREIGN KEY (created_by) REFERENCES personnels(id)
    )";

    $pdo->exec($sql);
    echo "backup_history table created successfully!\n";

} catch(PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
?>