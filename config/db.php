<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'cagayanregionsite_db');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => true,
        PDO::MYSQL_ATTR_FOUND_ROWS   => true,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Set timezone to Philippine Standard Time (PST/PHT)
    $pdo->exec("SET time_zone = '+08:00'");
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Connection failed. Please check the configuration.");
}
?>
