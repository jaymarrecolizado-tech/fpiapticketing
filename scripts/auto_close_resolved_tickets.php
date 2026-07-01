<?php
/**
 * Auto-close resolved tickets script
 * Run this periodically (e.g., via cron) to close tickets that have been resolved for 7+ days
 */

// Load app bootstrap
require '../config/db.php';
require '../lib/Logger.php';

// Function to auto-close resolved tickets
function autoCloseResolvedTickets(PDO $pdo, Logger $logger = null) {
    $sql = "SELECT id FROM tickets WHERE status='RESOLVED' AND updated_at <= NOW() - INTERVAL 7 DAY";
    $stmt = $pdo->query($sql);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($ids)) {
        return 0;
    }

    $in = implode(',', array_map('intval', $ids));
    $updateSql = "UPDATE tickets
      SET status='CLOSED', updated_at=NOW(), solved_date = IFNULL(solved_date, NOW())
      WHERE id IN ($in)";
    $pdo->exec($updateSql);

    foreach ($ids as $id) {
        if ($logger) {
            $logger->logEntityAction('auto_close', 'ticket', $id, ['reason' => 'resolved 7 days', 'auto_close' => true], 'low');
        }
    }

    return count($ids);
}

// Execute the auto-close
$logger = new Logger($pdo);
$autoClosed = autoCloseResolvedTickets($pdo, $logger);

// Output summary
echo "$autoClosed tickets auto-closed\n";
?>