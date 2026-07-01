<?php
require_once __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->query('DESCRIBE sites');
    echo "sites table columns:\n";
    while($row = $stmt->fetch()) {
        echo $row['Field'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>