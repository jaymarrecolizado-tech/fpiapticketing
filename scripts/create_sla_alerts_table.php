<?php
/**
 * SLA Alerts Migration
 * Creates the sla_alerts table for tracking SLA breach warnings.
 */
require_once __DIR__ . '/../config/db.php';

// Check if table already exists
$stmt = $pdo->query("SHOW TABLES LIKE 'sla_alerts'");
if ($stmt->rowCount() > 0) {
    echo "Table 'sla_alerts' already exists.\n";
    exit(0);
}

$pdo->exec("
CREATE TABLE `sla_alerts` (
    `id` int NOT NULL AUTO_INCREMENT,
    `ticket_id` int NOT NULL,
    `alert_type` varchar(50) NOT NULL COMMENT 'warning, critical, breached',
    `sla_hours` int NOT NULL COMMENT 'SLA threshold in hours for this priority',
    `priority` varchar(20) NOT NULL,
    `breached_at` datetime DEFAULT NULL COMMENT 'When the SLA was actually breached',
    `notified` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sla_ticket` (`ticket_id`),
    KEY `idx_sla_alert_type` (`alert_type`),
    KEY `idx_sla_notified` (`notified`),
    CONSTRAINT `fk_sla_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

echo "Created 'sla_alerts' table.\n";
