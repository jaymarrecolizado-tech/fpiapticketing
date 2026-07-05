<?php
/**
 * SLA Alert Checker - Run via cron every hour.
 *
 * Usage:
 *   php scripts/check_sla_alerts.php
 *
 * Recommended cron (runs every hour):
 *   0 * * * * php /path/to/scripts/check_sla_alerts.php
 *
 * What it does:
 *   1. Scans all OPEN/IN_PROGRESS tickets
 *   2. Compares elapsed time against priority-based SLA thresholds
 *   3. Creates warning/critical/breached alerts in sla_alerts table
 *   4. Logs all SLA events to system_logs
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/SlaManager.php';

$logger = new Logger($pdo);
$slaManager = new SlaManager($pdo, $logger);

echo "[" . date('Y-m-d H:i:s') . "] Starting SLA scan...\n";

$summary = $slaManager->scanAllTickets();

echo "[" . date('Y-m-d H:i:s') . "] SLA Scan Results:\n";
echo "  Active tickets:  " . $summary['total_active'] ?? 0 . "\n";
echo "  OK:              " . $summary['ok'] . "\n";
echo "  Warning (75%):   " . $summary['warning'] . "\n";
echo "  Critical (90%):  " . $summary['critical'] . "\n";
echo "  Breached:        " . $summary['breached'] . "\n";
echo "Done.\n";
