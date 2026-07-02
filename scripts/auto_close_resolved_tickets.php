<?php
/**
 * Auto-close resolved tickets script
 * Run via cron to close tickets resolved for 7+ days
 * Uses lib/AutoClose.php for consistent behavior with notifications
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/AutoClose.php';

$logger = new Logger($pdo);
$autoClosed = autoCloseResolvedTickets($pdo, $logger);

echo "$autoClosed tickets auto-closed\n";
