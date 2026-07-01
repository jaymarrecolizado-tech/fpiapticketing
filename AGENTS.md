# AGENTS.md

## Project Overview

FPIAP-SMARTs (Free Public Internet Access Program - Service Management and Response Ticketing System) is a PHP/MySQL web application for DICT Region II (Philippines) to manage free public WiFi hotspot sites across the Cagayan region.

## Tech Stack

- **Language**: PHP 8.x (vanilla, no framework)
- **Database**: MySQL (cagayanregionsite_db) via PDO
- **Frontend**: Bootstrap 5.3.2, Bootstrap Icons, Chart.js, Leaflet/OpenStreetMap
- **Server**: WAMP64 (localhost, Apache)
- **Timezone**: Philippine Standard Time (UTC+8)

## Project Structure

```
cagayansite_tickets/
├── index.php                 # Login page (entry point)
├── logout.php                # Session destruction
├── config/
│   ├── db.php                # PDO connection (localhost, root, no pass)
│   ├── auth.php              # Session config, CSRF, role checks (requireAdmin, requireLogin)
│   └── security_headers.php  # CSP, HSTS, X-Frame-Options, etc.
├── admin/                    # Admin-only pages (requireAdmin())
│   ├── dashboard.php         # Main dashboard with charts and KPIs
│   ├── ticket.php            # Create/manage tickets (AJAX endpoint)
│   ├── viewtickets.php       # List all tickets
│   ├── detail_ticket.php     # Ticket detail view
│   ├── edit_ticket.php       # Edit ticket
│   ├── site.php              # Manage WiFi sites
│   ├── site_report.php       # Sites report
│   ├── ticket_report.php     # Ticket report
│   ├── personnel.php         # Personnel management
│   ├── user.php              # User account management
│   ├── notifications.php     # Admin notifications
│   ├── notification.php      # Notification helper
│   ├── history.php           # Activity history
│   ├── systemlog.php         # System audit log viewer
│   ├── backup.php            # Backup management UI
│   ├── data_export.php       # Data export
│   ├── describe_table.php    # DB table inspector
│   └── account.php           # Admin account settings
├── users/                    # Regular user pages (requireLogin())
│   ├── dashboard.php         # User dashboard (minimal)
│   ├── ticket.php            # Create ticket
│   ├── view_tickets.php      # View own tickets
│   ├── site.php              # View sites
│   ├── notifications.php     # User notifications
│   └── notification.php      # Notification helper
├── notif/
│   ├── notification.php      # NotificationManager class
│   └── api.php               # Notification API (get_unread, mark_read, get_count)
├── lib/
│   ├── Validator.php         # Input validation (email, name, subject, remarks, etc.)
│   ├── Sanitizer.php         # Input sanitization (normalize, remarks)
│   ├── Logger.php            # Audit logging to system_logs table
│   ├── TicketHistory.php     # Ticket history tracking
│   ├── DataExport.php        # Data export functionality
│   ├── HistoryExport.php     # History export
│   ├── BackupManager.php     # Backup creation and management
│   ├── duration.php          # Duration calculations
│   └── auto_close.php        # Auto-close resolved tickets (7 days)
├── scripts/                  # CLI/cron scripts
│   ├── automated_backup.php  # Cron backup (full/database/filesystem)
│   ├── auto_close_resolved_tickets.php
│   ├── cleanup_backups.php   # Remove old backups
│   ├── check_sites.php       # DB schema inspector
│   ├── create_backup_table.php
│   └── setup_data_export_db.php
├── assets/                   # Images (FPIAP-SMARTs.png, freewifilogo.png)
└── backups/                  # Generated backup files
```

## Database Schema (Key Tables)

- **users**: id, personnel_id (FK), password (bcrypt), role (admin/user), status (active/inactive)
- **personnels**: id, fullname, gmail (used as login username)
- **tickets**: id, ticket_number, subject, notes, status, site_id (FK), created_by (FK→personnels), duration (minutes), created_at, updated_at
- **sites**: id, site_name, isp, province, municipality, project_name, status
- **system_logs**: id, user_id, personnel_id, action, entity_type, entity_id, details (JSON), description, ip_address, user_agent, severity, status, session_id, created_at
- **notifications**: user_id, message, is_read, created_at

## Ticket Status Flow

```
OPEN → IN_PROGRESS → RESOLVED → CLOSED
```

- **Aging threshold**: Tickets OPEN or IN_PROGRESS for 3+ days (4320 minutes)
- **Auto-close**: RESOLVED tickets auto-close after 7 days via `auto_close.php`

## Security Patterns

### Authentication
- Email-based login (personnels.gmail → users table)
- `password_verify()` with bcrypt hashes
- Session regeneration on login (`session_regenerate_id(true)`)
- Rate limiting: 5 failed attempts → 15 min lockout
- CSRF tokens on all forms (`generateCSRFToken()` / `validateCSRFToken()`)

### Authorization
- `requireAdmin()` — redirects non-admins to users/dashboard
- `requireLogin()` — redirects unauthenticated users to index.php
- `hasRole($role)` — checks session role

### Input Handling
- **Always use** `Validator::email()`, `Validator::subject()`, `Validator::remarks()`, `Validator::positiveInteger()` before DB queries
- **Always use** `Sanitizer::normalize()` and `Sanitizer::remarks()` before storing
- **Never** trust `$_POST`/`$_GET` directly — validate and sanitize first
- Use prepared statements (PDO) for all queries — never string interpolation

### Security Headers (security_headers.php)
- Content-Security-Policy (CSP)
- X-Frame-Options: SAMEORIGIN
- X-Content-Type-Options: nosniff
- Referrer-Policy: strict-origin-when-cross-origin
- Permissions-Policy (disables camera, mic, geolocation, etc.)
- Strict-Transport-Security (HTTPS only, conditional on localhost)

## Code Conventions

### PHP
- No framework — vanilla PHP with PDO
- Files mix HTML and PHP (no templating engine)
- AJAX endpoints return JSON (`header('Content-Type: application/json')`)
- Use `ob_start()` / `ob_clean()` in AJAX handlers to prevent stray output
- Global `$pdo` is used in most files
- Session variables: `user_id`, `personnel_id`, `username`, `email`, `role`, `login_time`

### Frontend
- Bootstrap 5.3.2 via CDN (no npm/webpack)
- Bootstrap Icons via CDN
- Chart.js via CDN for dashboard charts
- Inline JavaScript in PHP files (no separate JS files)
- AJAX pattern: `fetch()` with `POST` + `action` parameter

### Naming
- Files: lowercase with underscores (`view_tickets.php`, `ticket_report.php`)
- Classes: PascalCase (`Logger`, `Validator`, `BackupManager`)
- Methods: camelCase (`requireAdmin()`, `logAuthEvent()`)
- DB columns: snake_case (`ticket_number`, `site_name`, `created_by`)
- Ticket statuses: UPPERCASE (`OPEN`, `IN_PROGRESS`, `RESOLVED`, `CLOSED`)

## Dashboard API Pattern

The admin dashboard (`admin/dashboard.php`) uses a single-entry AJAX pattern:

```php
// POST with action parameter
$action = $_POST['action'] ?? '';
switch ($action) {
    case 'get_stats':          // Total, open, in-progress, resolved, closed, aging counts
    case 'get_chart_status':   // Status distribution for pie chart
    case 'get_chart_aging':    // Aging tickets by creator
    case 'get_chart_created_by': // Tickets created per weekday per user
    case 'get_recent_tickets': // Latest tickets
    case 'get_aging_tickets':  // Aging tickets (paginated)
    case 'get_filter_options': // Dropdown options (sites, ISPs, provinces, etc.)
    case 'get_completion_rate': // Closed+Resolved / Total
    case 'get_avg_resolution_time': // Average ticket duration
    case 'get_today_intake':   // Today vs yesterday ticket count
    case 'get_weekly_trend':   // This week vs last week
    case 'get_problematic_site': // Site with most open tickets
    case 'get_problematic_isp':  // ISP with most open tickets
}
```

Filters are passed as POST data and built into SQL via `buildWhereClause($filters)`.

## Report Page API Pattern

`admin/site_report.php` and `admin/ticket_report.php` use a similar AJAX pattern:

```php
$action = $_POST['action'] ?? '';
switch ($action) {
    case 'get_filter_options': // Dropdown options for all filters
    case 'generate_report':    // Full dataset for selected report type
}
```

Report types (selected via report type dropdown):
- `1_site_summary` — Site summary by province/municipality
- `3_isp_performance` — ISP ticket resolution performance
- `5_project_report` — Project-wise ticket distribution
- `7_aging_tickets` — Aging open/in-progress tickets
- `8_monthly_activity` — Monthly ticket creation trends

Data is fetched in full, then filtered/sorted client-side via JavaScript.

## Common Tasks

### Adding a new ticket field
1. Add column to `tickets` table via SQL migration
2. Update `admin/ticket.php` create and edit handlers
3. Update `lib/TicketHistory.php` to track changes
4. Update dashboard queries in `admin/dashboard.php` if filtering/reporting needed

### Adding a new page
1. Create PHP file in `admin/` or `users/` directory
2. Require `config/db.php` and `config/auth.php` at top
3. Call `requireAdmin()` or `requireLogin()` as appropriate
4. Copy navbar from existing page
5. Add nav link in parent page's dropdown menu

### Database changes
- All changes via PDO prepared statements
- Migration scripts go in `scripts/` directory
- Use `DESCRIBE tablename` to inspect schema

## Table Loading Patterns

| Page | Method | Pagination | Notes |
|------|--------|-----------|-------|
| `admin/viewtickets.php` | AJAX POST | Server-side (LIMIT/OFFSET) | Default 25/page |
| `admin/site.php` | Server-side PHP | Server-side (LIMIT/OFFSET, page reload) | Default 10/page |
| `admin/systemlog.php` | AJAX POST | Server-side (LIMIT/OFFSET) | Default 25/page |
| `admin/personnel.php` | Server-side PHP | **None** (all rows) | Small table, acceptable |

## CSV Import (admin/site.php)

Two-phase import system:
1. **Preview**: Upload CSV → validate rows → show summary (valid/duplicates/errors) in side panel
2. **Confirm**: Click "Confirm Import" → insert/update DB from session-stored preview data
- Duplicate detection uses 6-field composite match (site_name, project_name, isp, province, municipality, barangay)
- Override performs full UPDATE on all 8 data fields
- Preview data stored in `$_SESSION['csv_preview']`

## Report Filters (site_report.php, ticket_report.php)

- Filter options fetched via AJAX `action=get_filter_options`
- Filters applied client-side via `buildWhereClause()` → `applyFilters()` → `generateReport()`
- `applyMultiSelect()` must call `applyFilters()` after updating selection
- `updateSelectionCount()` uses explicit `labelMap` object (not string replace) to match HTML IDs
- `buildWhereClause()` handles arrays for `province`/`municipality` with `IN (?)` placeholders

## Deployment

### Local (WAMP64)
- Apache on port 80, MySQL on port 3306
- MySQL binary: `C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe`
- Admin: `admin@dict.gov.ph` / `admin123`

### VPS (Hostinger KVM2)
- IP: `187.77.150.203`, Domain: `fwticket.dictr2.cloud`
- Site user: `dictr2-fwticket`, SSH: `root@dictr2`
- Nginx + PHP-FPM (PHP 8.4) on port 20006
- App root: `/home/fwtickets/htdocs/fwticket.dictr2.cloud/`
- Nginx config: `/etc/nginx/sites-enabled/custom-domain.conf`
- PHP error log: `/home/dictr2-fwticket/logs/php/error.log`
- Admin: `admin@dict.gov.ph` / `Fwticket@2026!`

### VPS Case Sensitivity
- `lib/Validator.php` and `lib/Sanitizer.php` filenames are case-sensitive on Linux
- Always use PascalCase: `Validator.php`, `Sanitizer.php`

## Known Gotchas

- `$_SESSION['personnel_id']` is the FK used for `tickets.created_by` — NOT `user_id`
- Ticket number generation happens server-side; don't let users set it
- Duration is stored in **minutes** (1440 = 1 day, 4320 = 3 days)
- The `backups/` directory contains generated backup files — do not commit
- CSP allows `'unsafe-inline'` for scripts and styles (required for inline JS in PHP files)
- Session cookie is named `JOBORDER_SESSID`
- Admin nav: Reports dropdown → "Ticket Report" and "Sites Report" (site_report.php)
- User pages nav: `view_tickets.php` (with underscore) — NOT `viewtickets.php`
- `config/auth.php` `cookie_secure` must be `1` for production HTTPS (currently `0` for localhost)
- `config/db.php` VPS credentials differ from local (see Deployment section)
