# SakayTa — PHP/MariaDB Foundation (FINAL SRS)

Foundation-only additive layer toward the FINAL SRS target:
**XAMPP · Apache · PHP · MariaDB** (REST API, SOAP/WSDL verification, mapping,
HTTPS come in later stages). The existing Node.js/Express system is **fully
intact** — this directory adds PHP/MariaDB files without touching SQLite, the
Node backend, the frontend, or RabbitMQ.

```
php/
├── public/                 <- Apache DocumentRoot for SakayTa PHP
│   ├── index.php           <- minimal landing (JSON)
│   ├── health.php          <- Apache+PHP+MariaDB health check (JSON)
│   └── .htaccess           <- hardens the web root (no dir listing, deny .env)
├── config/
│   ├── Config.php          <- central config (env-driven)
│   ├── Env.php             <- private .env loader (no third-party dependency)
│   ├── .env.example        <- template (copy to `.env`, never commit `.env`)
│   └── .env                <- LOCAL credentials (git-ignored, DO NOT COMMIT)
├── db/
│   └── Database.php        <- PDO connection layer (strict, prepared-only)
├── sql/
│   └── schema.sql          <- idempotent MariaDB schema (all IF NOT EXISTS)
├── bin/
│   ├── 00_bootstrap_db.php <- create database + app user + grant (idempotent)
│   └── 01_apply_schema.php <- apply schema.sql (idempotent)
└── autoload.php            <- Sakayta\ PSR-ish autoloader
```

## Prerequisites (XAMPP)
- Apache 2.4 (XAMPP) — serves `php/public`
- MariaDB 10.4+ (XAMPP)
- PHP 8.2 (XAMPP)
- PHP extensions: `pdo_mysql`, `soap`, `openssl` (verify: `php -m`)

## Setup (one-time)

1. **Enable PHP extensions** in `C:\xampp\php\php.ini` if not already enabled:
   ```
   extension=soap        ; required by the SRS (license verification)
   extension=pdo_mysql   ; required (MariaDB)
   extension=openssl     ; required (SQL transport + future HTTPS)
   ```

2. **Create local config:**
   ```
   copy php\config\.env.example php\config\.env
   ```
   Edit `php\config\.env` with your MariaDB app-user password and, if your
   root user has a password, set `DB_ADMIN_PASSWORD`.

3. **Start MariaDB** (XAMPP Control Panel → MySQL, or `mysql_start.bat`).

4. **Bootstrap the database + user (idempotent):**
   ```
   "C:\xampp\php\php.exe" php/bin/00_bootstrap_db.php
   ```

5. **Apply the schema (idempotent):**
   ```
   "C:\xampp\php\php.exe" php/bin/01_apply_schema.php
   ```

6. **Start Apache** (XAMPP Control Panel → Apache). The SakayTa PHP vhost
   `http://localhost:8088` is served from `php/public` with port 80 (the
   existing Node/htdocs site) untouched.

7. **Check the health endpoint:**
   ```
   curl http://localhost:8088/health.php
   ```

## Apache vhost (additive, added to `C:\xampp\apache\conf\httpd.conf` +
`conf\extra\httpd-vhosts.conf`)

A `Listen 8088` was appended to httpd.conf and this vhost was added:

```apache
<VirtualHost *:8088>
  ServerName sakayta.local
  DocumentRoot "C:/Users/ASUS/Documents/sakayta-website/sakayta-website/php/public"
  <Directory "C:/Users/ASUS/Documents/sakayta-website/sakayta-website/php/public">
    Options -Indexes
    AllowOverride All
    Require all granted
  </Directory>
</VirtualHost>
```

Port `8088` keeps the stock XAMPP `localhost` (htdocs) site fully intact.

## Credentials policy
- No production secrets ever hardcoded.
- `php/config/.env` is git-ignored.
- Health endpoint returns **no** credentials/DSN: only PHP/extension status,
  MariaDB version, database name, and table list.

## What this foundation does NOT do (by design / next stages)
- No REST API / ride-dispatch endpoints yet.
- No auth migration.
- No data migration (SQLite unchanged; `data/sakayta.db` untouched).
- No RabbitMQ changes.
- No frontend changes.
- No HTTPS yet (the .htaccess carries the commented redirect ready for later).