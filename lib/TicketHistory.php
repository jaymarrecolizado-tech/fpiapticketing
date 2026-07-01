<?php
class TicketHistory {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function logChange($ticketId, $userId, $action, $fieldName = null, $oldValue = null, $newValue = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ticket_history
                (ticket_id, user_id, action, field_name, old_value, new_value, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $stmt->execute([
                $ticketId, $userId, $action, $fieldName,
                $oldValue, $newValue, $ip, $userAgent
            ]);

            return true;
        } catch (PDOException $e) {
            error_log('TicketHistory logChange error: ' . $e->getMessage());
            return false;
        }
    }

    public function getHistory($ticketId, $limit = 50, $offset = 0) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT h.*, p.fullname
                FROM ticket_history h
                JOIN users u ON h.user_id = u.id
                JOIN personnels p ON u.personnel_id = p.id
                WHERE h.ticket_id = ?
                ORDER BY h.timestamp DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $ticketId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('TicketHistory getHistory error: ' . $e->getMessage());
            return [];
        }
    }

    public function getAllHistory($filters = [], $limit = 100, $offset = 0) {
        try {
            $where = [];
            $params = [];

            if (!empty($filters['ticket_number'])) {
                $where[] = "t.ticket_number LIKE ?";
                $params[] = '%' . $filters['ticket_number'] . '%';
            }

            if (!empty($filters['editor_name'])) {
                $where[] = "p.fullname LIKE ?";
                $params[] = '%' . $filters['editor_name'] . '%';
            }

            if (!empty($filters['action'])) {
                $where[] = "h.action = ?";
                $params[] = $filters['action'];
            }

            if (!empty($filters['date_from'])) {
                $where[] = "h.timestamp >= ?";
                $params[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $where[] = "h.timestamp <= ?";
                $params[] = $filters['date_to'];
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

            $stmt = $this->pdo->prepare("
                SELECT h.*, p.fullname, t.subject as ticket_title, t.ticket_number
                FROM ticket_history h
                JOIN users u ON h.user_id = u.id
                JOIN personnels p ON u.personnel_id = p.id
                JOIN tickets t ON h.ticket_id = t.id
                {$whereClause}
                ORDER BY h.timestamp DESC
                LIMIT ? OFFSET ?
            ");

            $paramIndex = 1;
            foreach ($params as $param) {
                $stmt->bindValue($paramIndex++, $param, PDO::PARAM_STR);
            }
            $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('TicketHistory getAllHistory error: ' . $e->getMessage());
            return [];
        }
    }

    public function getGroupedHistory($filters = [], $limit = 100, $offset = 0) {
        try {
            $where = [];
            $params = [];

            if (!empty($filters['ticket_number'])) {
                $where[] = 't.ticket_number LIKE ?';
                $params[] = '%' . $filters['ticket_number'] . '%';
            }
            if (!empty($filters['editor_name'])) {
                $where[] = 'p.fullname LIKE ?';
                $params[] = '%' . $filters['editor_name'] . '%';
            }
            if (!empty($filters['action'])) {
                $where[] = 'h.action = ?';
                $params[] = $filters['action'];
            }
            if (!empty($filters['date_from'])) {
                $where[] = 'h.timestamp >= ?';
                $params[] = $filters['date_from'];
            }
            if (!empty($filters['date_to'])) {
                $where[] = 'h.timestamp <= ?';
                $params[] = $filters['date_to'];
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $sql = "
                SELECT
                    h.ticket_id,
                    t.ticket_number,
                    t.subject AS ticket_title,
                    COUNT(h.id) AS changes_count,
                    MAX(h.timestamp) AS last_updated,
                    (
                        SELECT p2.fullname
                        FROM ticket_history h2
                        JOIN users u2 ON h2.user_id = u2.id
                        JOIN personnels p2 ON u2.personnel_id = p2.id
                        WHERE h2.ticket_id = h.ticket_id
                        ORDER BY h2.timestamp DESC
                        LIMIT 1
                    ) AS last_updated_by,
                    GROUP_CONCAT(
                        CONCAT(
                            h.action, ': ', COALESCE(h.field_name, 'N/A'),
                            ' (', COALESCE(h.old_value, '-'), ' → ', COALESCE(h.new_value, '-'), ')',
                            ' by ', COALESCE(p.fullname, 'Unknown'),
                            ' @ ', DATE_FORMAT(h.timestamp, '%Y-%m-%d %H:%i:%s')
                        )
                        ORDER BY h.timestamp DESC
                        SEPARATOR ' | '
                    ) AS history_summary
                FROM ticket_history h
                JOIN tickets t ON h.ticket_id = t.id
                JOIN users u ON h.user_id = u.id
                JOIN personnels p ON u.personnel_id = p.id
                $whereClause
                GROUP BY h.ticket_id, t.ticket_number, t.subject
                ORDER BY last_updated DESC
                LIMIT ? OFFSET ?
            ";

            $stmt = $this->pdo->prepare($sql);
            $paramIndex = 1;
            foreach ($params as $param) {
                $stmt->bindValue($paramIndex++, $param, PDO::PARAM_STR);
            }
            $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('TicketHistory getGroupedHistory error: ' . $e->getMessage());
            return [];
        }
    }

    public function getGroupedHistoryCount($filters = []) {
        try {
            $where = [];
            $params = [];

            if (!empty($filters['ticket_number'])) {
                $where[] = 't.ticket_number LIKE ?';
                $params[] = '%' . $filters['ticket_number'] . '%';
            }
            if (!empty($filters['editor_name'])) {
                $where[] = 'p.fullname LIKE ?';
                $params[] = '%' . $filters['editor_name'] . '%';
            }
            if (!empty($filters['action'])) {
                $where[] = 'h.action = ?';
                $params[] = $filters['action'];
            }
            if (!empty($filters['date_from'])) {
                $where[] = 'h.timestamp >= ?';
                $params[] = $filters['date_from'];
            }
            if (!empty($filters['date_to'])) {
                $where[] = 'h.timestamp <= ?';
                $params[] = $filters['date_to'];
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            $sql = "
                SELECT COUNT(DISTINCT h.ticket_id) AS total_tickets
                FROM ticket_history h
                JOIN tickets t ON h.ticket_id = t.id
                JOIN users u ON h.user_id = u.id
                JOIN personnels p ON u.personnel_id = p.id
                $whereClause
            ";

            $stmt = $this->pdo->prepare($sql);
            $paramIndex = 1;
            foreach ($params as $param) {
                $stmt->bindValue($paramIndex++, $param, PDO::PARAM_STR);
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return intval($result['total_tickets'] ?? 0);
        } catch (PDOException $e) {
            error_log('TicketHistory getGroupedHistoryCount error: ' . $e->getMessage());
            return 0;
        }
    }

    public function getHistoryStats() {
        try {
            $stmt = $this->pdo->query("
                SELECT
                    COUNT(*) as total_changes,
                    COUNT(DISTINCT ticket_id) as tickets_with_history,
                    MAX(timestamp) as latest_change
                FROM ticket_history
            ");
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('TicketHistory getHistoryStats error: ' . $e->getMessage());
            return ['total_changes' => 0, 'tickets_with_history' => 0, 'latest_change' => null];
        }
    }
}
?>