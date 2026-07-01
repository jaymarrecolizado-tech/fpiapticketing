# DEPLOYMENT.md

FPIAP-SMARTs — Production Deployment Guide

## Prerequisites

- Hostinger KVM2 VPS with SSH access
- Domain: `fwticket.dictr2.cloud` → IP `187.77.150.203`
- Nginx + PHP-FPM (PHP 8.4) installed
- MySQL 9.1+ installed
- `prod_folder/` ready (clean production files)

## VPS Access

```bash
ssh root@dictr2
# or
ssh root@187.77.150.203
```

## Step 1: Upload Files

From local machine, upload `prod_folder/` contents to VPS:

```bash
# Option A: SCP (recommended)
scp -r "prod_folder/*" root@187.77.150.203:/home/fwtickets/htdocs/fwticket.dictr2.cloud/

# Option B: SFTP via WinSCP/FileZilla
# Upload all files from prod_folder/ to /home/fwtickets/htdocs/fwticket.dictr2.cloud/
```

## Step 2: Set File Permissions

```bash
ssh root@187.77.150.203

# Set ownership to nginx user
chown -R dictr2-fwticket:dictr2-fwticket /home/fwtickets/htdocs/fwticket.dictr2.cloud/

# Set directory permissions
find /home/fwtickets/htdocs/fwticket.dictr2.cloud/ -type d -exec chmod 755 {} \;

# Set file permissions
find /home/fwtickets/htdocs/fwticket.dictr2.cloud/ -type f -exec chmod 644 {} \;

# Writable directories
chmod -R 775 /home/fwtickets/htdocs/fwticket.dictr2.cloud/backups/
chmod -R 775 /home/fwtickets/htdocs/fwticket.dictr2.cloud/uploads/
```

## Step 3: Create Database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE cagayanregionsite_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'fwticket_user'@'localhost' IDENTIFIED BY 'YOUR_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON cagayanregionsite_db.* TO 'fwticket_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## Step 4: Import Database Schema

```bash
mysql -u root -p cagayanregionsite_db < /home/fwtickets/htdocs/fwticket.dictr2.cloud/cagayanregionsite_db.sql
```

Or upload `cagayanregionsite_db.sql` to VPS first, then import.

## Step 5: Configure Database Connection

Edit `config/db.php` on VPS:

```php
<?php
$host = 'localhost';
$db   = 'cagayanregionsite_db';
$user = 'fwticket_user';        // Changed from 'root'
$pass = 'YOUR_STRONG_PASSWORD'; // Changed from ''
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}
```

## Step 6: Configure Session Security

Edit `config/auth.php` on VPS — set `cookie_secure` to `1` for HTTPS:

```php
// Change this line:
$cookie_secure = 0;  // localhost

// To this:
$cookie_secure = 1;  // production HTTPS
```

## Step 7: Create Admin User

```bash
php -r "echo password_hash('Fwticket@2026!', PASSWORD_BCRYPT);"
```

Copy the hash, then:

```bash
mysql -u root -p cagayanregionsite_db
```

```sql
-- Create personnel record
INSERT INTO personnels (fullname, gmail, status, created_at, updated_at)
VALUES ('System Admin', 'admin@dict.gov.ph', 'active', NOW(), NOW());

-- Create user account (use the hash from above)
INSERT INTO users (personnel_id, password, role, status, created_at, updated_at)
VALUES (LAST_INSERT_ID(), '$2y$10$YOUR_HASH_HERE', 'admin', 'active', NOW(), NOW());
```

## Step 8: Verify Nginx Config

Ensure `/etc/nginx/sites-enabled/custom-domain.conf` has:

```nginx
server {
    listen 80;
    server_name fwticket.dictr2.cloud;

    root /home/dictr2-fwticket/htdocs/fwticket.dictr2.cloud;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:20006;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~* \.(sql|log|bak)$ {
        deny all;
    }
}
```

```bash
nginx -t && systemctl reload nginx
```

## Step 9: Test the Site

1. Open `https://fwticket.dictr2.cloud` (or `http://` if SSL not yet configured)
2. Login with: `admin@dict.gov.ph` / `Fwticket@2026!`
3. Verify dashboard loads, charts render, filters work
4. Check PHP error log if issues:
   ```bash
   tail -f /home/dictr2-fwticket/logs/php/error.log
   ```

## Step 10: SSL Certificate (Optional but Recommended)

```bash
# Install certbot
apt install certbot python3-certbot-nginx

# Get certificate
certbot --nginx -d fwticket.dictr2.cloud

# Auto-renewal
systemctl enable certbot.timer
```

## Troubleshooting

### HTTP 500 Error
```bash
tail -50 /home/dictr2-fwticket/logs/php/error.log
```
Common cause: Case-sensitive filenames (`validator.php` vs `Validator.php`)

### Database Connection Failed
- Verify credentials in `config/db.php`
- Check MySQL is running: `systemctl status mysql`
- Test connection: `mysql -u fwticket_user -p cagayanregionsite_db`

### Permission Denied
```bash
chown -R dictr2-fwticket:dictr2-fwticket /home/fwtickets/htdocs/fwticket.dictr2.cloud/
chmod -R 775 /home/fwtickets/htdocs/fwticket.dictr2.cloud/backups/
chmod -R 775 /home/fwtickets/htdocs/fwticket.dictr2.cloud/uploads/
```

### Session Issues (Logged Out Immediately)
- Check `cookie_secure` in `config/auth.php` matches protocol (HTTPS=1, HTTP=0)
- Clear browser cookies for `fwticket.dictr2.cloud`

## Default Credentials

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@dict.gov.ph | Fwticket@2026! |

**Change these after first login!**
