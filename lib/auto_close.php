<?php
/**
 * Auto-close resolved tickets functionality
 * Contains the autoCloseResolvedTickets function for reuse across admin pages
 */

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

    // Send notifications for auto-closed tickets
    require_once '../notif/notification.php';
    $notifier = new NotificationManager($pdo);

    foreach ($ids as $id) {
        if ($logger) {
            $logger->logEntityAction('auto_close', 'ticket', $id, ['reason' => 'resolved 7 days', 'auto_close' => true], 'low');
        }

        // Send notification to ticket creator
        $notifier->notifyTicketAutoClosed($id);
    }

    return count($ids);
}
?>