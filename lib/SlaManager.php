<?php
/**
 * SLA Manager - Tracks and enforces Service Level Agreements for tickets.
 *
 * SLA thresholds by priority:
 *   critical: 24 hours
 *   high:     48 hours
 *   medium:   72 hours
 *   low:      120 hours (5 days)
 *
 * Alert stages:
 *   warning  = 75% of SLA time elapsed
 *   critical = 90% of SLA time elapsed
 *   breached = 100% of SLA time elapsed
 */

class SlaManager
{
    private $pdo;
    private $logger;

    /** SLA thresholds in hours by priority */
    private static $thresholds = [
        'critical' => 24,
        'high'     => 48,
        'medium'   => 72,
        'low'      => 120,
    ];

    /** Alert trigger percentages */
    private static $warningPct  = 0.75;
    private static $criticalPct = 0.90;

    public function __construct(PDO $pdo, Logger $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    /**
     * Get SLA threshold in hours for a given priority.
     */
    public static function getThreshold($priority)
    {
        return self::$thresholds[$priority] ?? 72;
    }

    /**
     * Get SLA threshold in minutes for a given priority.
     */
    public static function getThresholdMinutes($priority)
    {
        return self::getThreshold($priority) * 60;
    }

    /**
     * Calculate elapsed hours since ticket creation (for open/in-progress tickets).
     */
    public static function getElapsedHours($created_at)
    {
        $created = new DateTime($created_at, new DateTimeZone('+08:00'));
        $now = new DateTime('now', new DateTimeZone('+08:00'));
        $diff = $created->diff($now);
        return ($diff->days * 24) + $diff->h + ($diff->i / 60);
    }

    /**
     * Check a single ticket and create alerts as needed.
     * Returns the alert status: 'ok', 'warning', 'critical', 'breached'.
     */
    public function checkTicket($ticket)
    {
        $priority = $ticket['priority'] ?? 'medium';
        $status   = $ticket['status']   ?? 'OPEN';
        $id       = $ticket['id'];

        // Only check open/in-progress tickets
        if (!in_array($status, ['OPEN', 'IN_PROGRESS'])) {
            return 'ok';
        }

        $thresholdHours = self::getThreshold($priority);
        $elapsedHours   = self::getElapsedHours($ticket['created_at']);

        $alertType = 'ok';
        if ($elapsedHours >= $thresholdHours) {
            $alertType = 'breached';
        } elseif ($elapsedHours >= $thresholdHours * self::$criticalPct) {
            $alertType = 'critical';
        } elseif ($elapsedHours >= $thresholdHours * self::$warningPct) {
            $alertType = 'warning';
        }

        if ($alertType !== 'ok') {
            $this->upsertAlert($id, $alertType, $thresholdHours, $priority);
        }

        return $alertType;
    }

    /**
     * Scan all open/in-progress tickets and generate/update SLA alerts.
     * Returns summary: ['ok' => n, 'warning' => n, 'critical' => n, 'breached' => n]
     */
    public function scanAllTickets()
    {
        $stmt = $this->pdo->query("
            SELECT id, priority, status, created_at
            FROM tickets
            WHERE status IN ('OPEN', 'IN_PROGRESS')
        ");
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = ['ok' => 0, 'warning' => 0, 'critical' => 0, 'breached' => 0];

        foreach ($tickets as $ticket) {
            $result = $this->checkTicket($ticket);
            $summary[$result]++;
        }

        return $summary;
    }

    /**
     * Insert or update an alert for a ticket.
     */
    private function upsertAlert($ticketId, $alertType, $thresholdHours, $priority)
    {
        $breachedAt = ($alertType === 'breached') ? date('Y-m-d H:i:s') : null;

        // Check if alert already exists for this ticket and type
        $stmt = $this->pdo->prepare("
            SELECT id FROM sla_alerts
            WHERE ticket_id = ? AND alert_type = ?
        ");
        $stmt->execute([$ticketId, $alertType]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update breached_at if newly breached
            if ($alertType === 'breached' && $breachedAt) {
                $stmt = $this->pdo->prepare("
                    UPDATE sla_alerts SET breached_at = ? WHERE id = ?
                ");
                $stmt->execute([$breachedAt, $existing['id']]);
            }
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO sla_alerts (ticket_id, alert_type, sla_hours, priority, breached_at, notified)
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([$ticketId, $alertType, $thresholdHours, $priority, $breachedAt]);

            if ($this->logger) {
                $this->logger->logEntityAction(
                    'sla_' . $alertType,
                    'ticket',
                    $ticketId,
                    ['sla_hours' => $thresholdHours, 'priority' => $priority],
                    'medium'
                );
            }
        }
    }

    /**
     * Get SLA summary for dashboard display.
     * Returns: [total_active => n, warning => n, critical => n, breached => n]
     */
    public function getSlaSummary()
    {
        $stmt = $this->pdo->query("
            SELECT
                alert_type,
                COUNT(*) as cnt
            FROM sla_alerts sa
            INNER JOIN tickets t ON sa.ticket_id = t.id
            WHERE t.status IN ('OPEN', 'IN_PROGRESS')
            GROUP BY alert_type
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $totalActive = $this->pdo->query("
            SELECT COUNT(*) FROM tickets WHERE status IN ('OPEN', 'IN_PROGRESS')
        ")->fetchColumn();

        return [
            'total_active' => (int) $totalActive,
            'warning'      => (int) ($rows['warning'] ?? 0),
            'critical'     => (int) ($rows['critical'] ?? 0),
            'breached'     => (int) ($rows['breached'] ?? 0),
        ];
    }

    /**
     * Get tickets with SLA warnings/breaches for admin dashboard.
     */
    public function getSlAAlertTickets($limit = 10)
    {
        $stmt = $this->pdo->prepare("
            SELECT
                t.id, t.ticket_number, t.subject, t.priority, t.status, t.created_at,
                sa.alert_type, sa.sla_hours, sa.breached_at,
                p.fullname AS assigned_to_name
            FROM sla_alerts sa
            INNER JOIN tickets t ON sa.ticket_id = t.id
            LEFT JOIN personnels p ON t.assigned_to = p.id
            WHERE t.status IN ('OPEN', 'IN_PROGRESS')
            ORDER BY
                CASE sa.alert_type
                    WHEN 'breached' THEN 1
                    WHEN 'critical' THEN 2
                    WHEN 'warning' THEN 3
                END,
                t.created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
