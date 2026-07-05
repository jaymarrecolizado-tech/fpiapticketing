<?php
/**
 * Performance Optimization Migration
 * Adds database indexes for faster queries across tickets, sites, and related tables.
 */
require_once __DIR__ . '/../config/db.php';

$indexes = [
    // tickets table
    ['table' => 'tickets', 'name' => 'idx_status',           'sql' => 'ADD INDEX idx_status (status)'],
    ['table' => 'tickets', 'name' => 'idx_created_at',       'sql' => 'ADD INDEX idx_created_at (created_at)'],
    ['table' => 'tickets', 'name' => 'idx_site_id',          'sql' => 'ADD INDEX idx_site_id (site_id)'],
    ['table' => 'tickets', 'name' => 'idx_created_by',       'sql' => 'ADD INDEX idx_created_by (created_by)'],
    ['table' => 'tickets', 'name' => 'idx_assigned_to',      'sql' => 'ADD INDEX idx_assigned_to (assigned_to)'],
    ['table' => 'tickets', 'name' => 'idx_priority',         'sql' => 'ADD INDEX idx_priority (priority)'],
    ['table' => 'tickets', 'name' => 'idx_category',         'sql' => 'ADD INDEX idx_category (category)'],
    ['table' => 'tickets', 'name' => 'idx_status_created',   'sql' => 'ADD INDEX idx_status_created (status, created_at)'],

    // sites table
    ['table' => 'sites', 'name' => 'idx_sites_province',     'sql' => 'ADD INDEX idx_sites_province (province)'],
    ['table' => 'sites', 'name' => 'idx_sites_municipality', 'sql' => 'ADD INDEX idx_sites_municipality (municipality)'],
    ['table' => 'sites', 'name' => 'idx_sites_isp',          'sql' => 'ADD INDEX idx_sites_isp (isp)'],
    ['table' => 'sites', 'name' => 'idx_sites_project',      'sql' => 'ADD INDEX idx_sites_project (project_name)'],
    ['table' => 'sites', 'name' => 'idx_sites_created_by',   'sql' => 'ADD INDEX idx_sites_created_by (created_by)'],

    // ticket_comments
    ['table' => 'ticket_comments', 'name' => 'idx_tc_ticket_created', 'sql' => 'ADD INDEX idx_tc_ticket_created (ticket_id, created_at)'],

    // system_logs
    ['table' => 'system_logs', 'name' => 'idx_sl_action',    'sql' => 'ADD INDEX idx_sl_action (action)'],
    ['table' => 'system_logs', 'name' => 'idx_sl_created',   'sql' => 'ADD INDEX idx_sl_created (created_at)'],
    ['table' => 'system_logs', 'name' => 'idx_sl_severity',  'sql' => 'ADD INDEX idx_sl_severity (severity)'],

    // ticket_history
    ['table' => 'ticket_history', 'name' => 'idx_th_ticket_ts', 'sql' => 'ADD INDEX idx_th_ticket_ts (ticket_id, timestamp)'],

    // notifications
    ['table' => 'notifications', 'name' => 'idx_notif_user_read', 'sql' => 'ADD INDEX idx_notif_user_read (user_id, is_read)'],
];

$added = 0;
$skipped = 0;

foreach ($indexes as $idx) {
    // Check if index already exists
    $stmt = $pdo->prepare("SHOW INDEX FROM {$idx['table']} WHERE Key_name = ?");
    $stmt->execute([$idx['name']]);
    if ($stmt->rowCount() > 0) {
        echo "  [SKIP] {$idx['table']}.{$idx['name']} already exists\n";
        $skipped++;
        continue;
    }

    try {
        $pdo->exec("ALTER TABLE {$idx['table']} {$idx['sql']}");
        echo "  [ADD]  {$idx['table']}.{$idx['name']}\n";
        $added++;
    } catch (PDOException $e) {
        echo "  [ERR]  {$idx['table']}.{$idx['name']}: {$e->getMessage()}\n";
    }
}

echo "\nDone: {$added} added, {$skipped} skipped\n";
