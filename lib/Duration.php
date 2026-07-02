<?php
/**
 * Duration helper functions for tickets.
 */

function calculateDuration($created_at, $solved_date, $status) {
    $start = new DateTime($created_at);

    if (($status === 'CLOSED' || $status === 'RESOLVED') && $solved_date) {
        $end = new DateTime($solved_date);
    } else {
        $end = new DateTime();
    }

    $interval = $start->diff($end);

    $days = $interval->days;
    $hours = $interval->h;
    $minutes = $interval->i;

    return sprintf("%02d %02d %02d", $days, $hours, $minutes);
}

function calculateDurationMinutes($created_at, $stored_duration, $status) {
    if ($status === 'CLOSED' || $status === 'RESOLVED') {
        return intval($stored_duration);
    }

    $created = new DateTime($created_at, new DateTimeZone('+08:00'));
    $now = new DateTime('now', new DateTimeZone('+08:00'));
    $interval = $created->diff($now);

    return ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
}

function formatDurationDisplay($minutes) {
    $minutes = max(0, intval($minutes));
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . ' Day' . ($days > 1 ? 's' : '');
    }
    if ($hours > 0) {
        $parts[] = $hours . ' Hr' . ($hours > 1 ? 's' : '');
    }
    if ($mins > 0 || empty($parts)) {
        $parts[] = $mins . ' Min' . ($mins > 1 ? 's' : '');
    }

    return implode(' ', $parts);
}
