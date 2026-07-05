# ROADMAP.md — FPIAP-SMARTs Feature Roadmap

## Overview

Phased implementation plan for post-launch improvements. Each phase is self-contained and deployable independently.

**Last Updated:** July 5, 2026  
**Current Status:** Phases 1, 4, 5, 6, 8, 9 complete; Phase 2 pending (deployment verification)

---

## Phase 1: Foundation & Deployment (Complete)

**Goal:** Stable production deployment with all core features working  
**Estimated Effort:** Already done

### Tasks
- [x] Fix case-sensitivity issues (Validator.php, Sanitizer.php, AutoClose.php, Duration.php)
- [x] Create missing migrations (ticket_counter, priority, category, due_date, assignment, comments, attachments)
- [x] Fix security gaps (requireAdmin on ticket_report, data_export)
- [x] Consistent auth checks across all pages
- [x] Auto-close script uses lib/AutoClose.php with notifications
- [x] Clean nav dropdown (no duplicate site_report links)
- [x] Create full DB dump for VPS import
- [x] Document deployment process (DEPLOYMENT.md)

### Deliverable
- All files committed to `house_refact` branch
- DB dump ready at `backups/cagayanregionsite_db_full.sql`

---

## Phase 2: Deployment Verification & Operations

**Goal:** Production environment fully operational with automated maintenance  
**Estimated Effort:** 1-2 hours

### Tasks
- [ ] Merge `house_refact` → `main`
- [ ] Import DB dump on VPS
- [ ] Verify admin login (`admin@dict.gov.ph` / `Fwticket@2026!`)
- [ ] Create user-role test account
- [ ] Test ticket creation flow (admin + user)
- [ ] Test report generation (site_report, ticket_report)
- [ ] Set up cron jobs:
  - `0 2 * * *` — Auto-close resolved tickets
  - `0 3 * * 0` — Weekly full backup
  - `0 3 * * 1-6` — Daily DB-only backup
- [ ] Test backup/restore flow
- [ ] Verify PHP error log is clean

### Deliverable
- VPS fully operational
- Cron jobs running
- Test user account created

---

## Phase 3: Email Notifications

**Goal:** Users receive email alerts for ticket events  
**Estimated Effort:** 4-6 hours  
**Dependencies:** Phase 2

### Features
1. **Ticket Created** — Notify admin when user creates ticket
2. **Ticket Assigned** — Notify assigned person
3. **Ticket Updated** — Notify ticket creator on status/priority change
4. **Ticket Resolved** — Notify ticket creator
5. **Ticket Auto-Closed** — Notify ticket creator (already in AutoClose.php, needs email)
6. **SLA Warning** — Notify when due date approaching (Phase 5)

### Technical Design
```
lib/EmailService.php          # PHPMailer wrapper (SMTP)
lib/EmailTemplates.php        # HTML email templates
config/email.php              # SMTP config (host, port, user, pass)
scripts/test_email.php        # Test SMTP connection
```

### SMTP Options
- **Option A:** Hostinger SMTP (included with hosting)
- **Option B:** Gmail SMTP (free, 500/day limit)
- **Option C:** SendGrid/Mailgun (free tier, 100/day)

### DB Changes
```sql
ALTER TABLE notifications ADD COLUMN email_sent TINYINT(1) DEFAULT 0;
ALTER TABLE notifications ADD COLUMN email_sent_at DATETIME DEFAULT NULL;
```

### Deliverable
- Email notifications for all ticket events
- Admin can enable/disable email notifications
- Email templates with FPIAP branding

---

## Phase 4: Dashboard Date Range Picker (Complete)

**Goal:** Analyze ticket data across custom time periods  
**Estimated Effort:** 3-4 hours  
**Dependencies:** None

### Features
1. **Date range selector** — Start/end date pickers on dashboard
2. **Preset ranges** — Today, This Week, This Month, This Quarter, This Year, Custom
3. **All charts update** — Status, aging, created_by, completion rate, avg resolution time
4. **URL persistence** — Date range saved in query params for sharing
5. **Export with date range** — CSV/PDF exports respect selected range

### Technical Design
```
Admin dashboard.php:
  - Add date range picker UI (Bootstrap Datepicker)
  - Pass date_from/date_to as AJAX params
  - All existing chart endpoints accept date filters
  - Default: "This Month" (current behavior)

User dashboard.php:
  - Same date range picker
  - Personal stats filtered by date range
```

### Files to Modify
- `admin/dashboard.php` — Add date picker UI + JS
- `admin/dashboard.php` (AJAX handlers) — Accept date_from/date_to params
- `users/dashboard.php` — Add date picker UI + JS
- `assets/js/dashboard.js` — Extract chart JS (optional)

### Deliverable
- Dashboard with flexible date filtering
- All KPIs and charts respect date range

---

## Phase 5: PDF Export for Reports (Complete)

**Goal:** Generate professional PDF reports for stakeholders  
**Estimated Effort:** 4-6 hours  
**Dependencies:** Phase 4 (optional)

### Features
1. **Site Summary Report** — PDF with charts, tables, map
2. **Ticket Report** — PDF with status breakdown, aging analysis
3. **ISP Performance Report** — PDF with resolution metrics
4. **Monthly Activity Report** — PDF with trends
5. **Custom Date Range** — PDF exports respect selected period

### Technical Design
```
lib/PdfGenerator.php          # TCPDF/MPDF wrapper
lib/PdfTemplates.php          # Report-specific templates
assets/css/pdf.css            # Print-friendly styles
```

### Library Choice
- **TCPDF** — Mature, no dependencies, large output size
- **MPDF** — Better CSS support, modern output
- **DOMPDF** — HTML-to-PDF, good for simple layouts
- **Recommendation:** MPDF (best balance of features and output quality)

### Files to Modify
- `admin/site_report.php` — Add "Export PDF" button
- `admin/ticket_report.php` — Add "Export PDF" button
- New: `lib/PdfGenerator.php`
- New: `lib/PdfTemplates.php`
- New: `assets/css/pdf.css`

### Deliverable
- PDF export button on all report pages
- Professional branded PDF output
- Print-ready layout with headers/footers

---

## Phase 6: Ticket SLA Alerts (Complete)

**Goal:** Proactive notifications for approaching deadlines  
**Estimated Effort:** 3-4 hours  
**Dependencies:** Phase 3 (email notifications)

### Features
1. **SLA Definitions** — Admin configures warning thresholds per category
2. **Warning Alerts** — Notify when due date approaching (e.g., 24h, 1h before)
3. **Overdue Alerts** — Notify when due date passed
4. **Dashboard Indicators** — Color-coded SLA status on ticket list
5. **SLA Report** — Compliance metrics (on-time vs late resolution)

### Technical Design
```
Database:
  sla_definitions table:
    - id, category, warning_hours, critical_hours, created_at

  ticket_sla_events table:
    - id, ticket_id, event_type (warning/critical/overdue), sent_at

Config:
  sla.php — Default thresholds if no definition exists
```

### SLA Flow
```
Ticket Created (due_date set)
    ↓
Due - 24h → WARNING notification (in-app + email)
    ↓
Due - 1h → CRITICAL notification (in-app + email)
    ↓
Due passed → OVERDUE notification (in-app + email)
    ↓
Auto-close (if RESOLVED for 7 days)
```

### Files to Create/Modify
- New: `scripts/create_sla_tables.php` — Migration
- New: `lib/SlaManager.php` — SLA logic
- Modify: `lib/AutoClose.php` — Check SLA before closing
- Modify: `admin/dashboard.php` — Show SLA metrics
- Modify: `admin/edit_ticket.php` — SLA threshold config
- New: `scripts/check_sla_alerts.php` — Cron script

### Deliverable
- SLA tracking per ticket
- Automated alerts at warning/critical/overdue
- SLA compliance dashboard

---

## Phase 7: Mobile PWA

**Goal:** App-like experience on mobile devices  
**Estimated Effort:** 6-8 hours  
**Dependencies:** None (can run in parallel)

### Features
1. **Installable** — "Add to Home Screen" prompt
2. **Offline Support** — Cache key pages for offline viewing
3. **Push Notifications** — Browser push for ticket events
4. **Touch Optimized** — Larger buttons, swipe gestures
5. **App Icon** — Custom icons for iOS/Android

### Technical Design
```
manifest.json                 # PWA manifest
sw.js                         # Service worker
assets/images/icons/          # App icons (192x192, 512x512)
assets/js/push.js             # Push notification handler
```

### Service Worker Strategy
```
Cache-first for static assets (CSS, JS, images)
Network-first for dynamic pages (dashboard, tickets)
Stale-while-revalidate for API responses
```

### Files to Create
- `manifest.json` — PWA manifest
- `sw.js` — Service worker
- `assets/js/push.js` — Push notification registration
- `assets/images/icons/` — App icons
- `assets/css/mobile.css` — Mobile-specific styles

### Deliverable
- Installable web app
- Offline viewing of cached pages
- Push notifications for ticket events

---

## Phase 8: Role-Based Permissions (Complete)

**Goal:** Granular access control beyond admin/user  
**Estimated Effort:** 6-8 hours  
**Dependencies:** None

### Features
1. **Roles** — admin, supervisor, agent, user
2. **Permissions** — Granular (create_ticket, edit_ticket, assign_ticket, view_reports, manage_users, etc.)
3. **Role Management UI** — Admin can create/edit roles
4. **Permission Checks** — On every page and AJAX endpoint
5. **Audit Trail** — Log permission changes

### Technical Design
```
Database:
  roles table:
    - id, name, description, created_at

  permissions table:
    - id, name, description, category

  role_permissions table:
    - role_id, permission_id

  ALTER TABLE users ADD COLUMN role_id INT REFERENCES roles(id);

Roles & Permissions:
  admin:      ALL permissions
  supervisor: create_ticket, edit_ticket, assign_ticket, view_reports, manage_tickets
  agent:      create_ticket, edit_ticket, view_assigned_tickets, add_comments
  user:       create_ticket, view_own_tickets, manage_own_sites
```

### Files to Create/Modify
- New: `scripts/create_rbac_tables.php` — Migration
- New: `lib/PermissionManager.php` — Permission logic
- New: `admin/roles.php` — Role management UI
- Modify: `config/auth.php` — Add `hasPermission($permission)` function
- Modify: All admin pages — Add permission checks
- Modify: All user pages — Add permission checks
- New: `admin/role_permissions.php` — Assign permissions to roles

### Deliverable
- 4 predefined roles (admin, supervisor, agent, user)
- Custom role creation
- Granular permission system
- Audit trail for permission changes

---

## Phase 9: Performance Optimization (Complete)

**Goal:** Fast load times and scalable architecture  
**Estimated Effort:** 4-6 hours  
**Dependencies:** None

### Features
1. **Query Optimization** — Add missing indexes, optimize slow queries
2. **Caching** — Cache dashboard stats, filter options
3. **Lazy Loading** — Load charts on demand
4. **CDN** — Serve static assets from CDN
5. **Compression** — Enable gzip/brotli on Nginx
6. **Image Optimization** — Resize avatars, compress uploads

### Technical Design
```
Caching Strategy:
  - Dashboard stats: 5-minute cache (Redis/file-based)
  - Filter options: Cache until data changes
  - Report data: Cache per date range

Index Additions:
  tickets: (status, created_at), (assigned_to, status), (due_date, status)
  ticket_comments: (ticket_id, created_at)
  ticket_attachments: (ticket_id)
  notifications: (user_id, is_read)

Nginx Config:
  gzip on;
  gzip_types text/plain text/css application/json application/javascript;
  
  # Static assets
  location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
      expires 30d;
      add_header Cache-Control "public, immutable";
  }
```

### Files to Create/Modify
- New: `lib/Cache.php` — Simple file-based cache
- Modify: `admin/dashboard.php` — Use cache for stats
- Modify: `config/db.php` — Add index creation SQL
- Nginx config update — Enable compression and caching

### Deliverable
- Sub-second dashboard load time
- Optimized database queries
- Browser caching for static assets

---

## Implementation Order

```
Phase 1: Foundation          ████████████████████ COMPLETE
Phase 2: Deployment          ░░░░░░░░░░░░░░░░░░░░ NEXT
Phase 3: Email Notifications ⏭  SKIPPED (per user request)
Phase 4: Date Range Picker   ████████████████████ COMPLETE
Phase 5: PDF Export          ████████████████████ COMPLETE
Phase 6: SLA Alerts          ████████████████████ COMPLETE
Phase 7: Mobile PWA          ⏭  SKIPPED (per user request)
Phase 8: Role Permissions    ████████████████████ COMPLETE
Phase 9: Performance         ████████████████████ COMPLETE
```

## Success Metrics

| Phase | Metric | Target |
|-------|--------|--------|
| 2 | VPS uptime | 99%+ |
| 2 | Cron job success rate | 100% |
| 3 | Email delivery rate | 95%+ |
| 3 | Notification open rate | 60%+ |
| 4 | Dashboard load time | < 2s |
| 5 | PDF generation time | < 5s |
| 6 | SLA compliance rate | 80%+ |
| 7 | PWA install rate | 20%+ of mobile users |
| 8 | Permission coverage | 100% of endpoints |
| 9 | Page load time | < 1s |
