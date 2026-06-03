
Code that only runs on your laptop isn't done. Today you deploy your application to a production environment — nginx, PHP-FPM, MySQL, Redis, and your queue worker running as a systemd service. This is the same stack that runs most PHP applications in production.

## The mental model — environments

```
Development:  your laptop
              php -S localhost:8000
              display_errors = On
              debug toolbar visible
              .env with local credentials

Staging:      a server that mirrors production
              real nginx + PHP-FPM
              production config, test data
              used to verify before going live

Production:   the real server
              nginx + PHP-FPM + SSL
              display_errors = Off, log_errors = On
              real credentials in environment
              monitored, backed up
```

The principle: code moves from dev → staging → production. Config moves the other direction — production patterns inform how you configure dev.

## Part 1 — Server setup

```bash
# Assuming Ubuntu 24.04 LTS on a VPS (DigitalOcean, Hetzner, Linode etc.)

# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y \
    nginx \
    php8.3-fpm \
    php8.3-mysql \
    php8.3-redis \
    php8.3-curl \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-zip \
    php8.3-intl \
    mysql-server \
    redis-server \
    git \
    unzip \
    certbot \
    python3-certbot-nginx

# Install Composer globally
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Verify everything
php --version
nginx -v
mysql --version
redis-cli ping
```

## Part 2 — Application user and directory structure

```bash
# Create a dedicated user for the application
sudo useradd --system --no-create-home --shell /usr/sbin/nologin fleet-app

# Create directory structure
sudo mkdir -p /var/www/fleet-manager/{releases,shared,current}
sudo mkdir -p /var/www/fleet-manager/shared/{storage,logs}
sudo mkdir -p /var/www/fleet-manager/shared/storage/{uploads,reports,cache}

# Set ownership
sudo chown -R fleet-app:www-data /var/www/fleet-manager
sudo chmod -R 750 /var/www/fleet-manager
sudo chmod -R 770 /var/www/fleet-manager/shared/storage
sudo chmod -R 770 /var/www/fleet-manager/shared/logs
```

The release directory pattern:

```
/var/www/fleet-manager/
  releases/
    20260601120000/    ← old release
    20260602140000/    ← current release (symlinked)
  shared/
    storage/           ← uploads, reports — persists across releases
    logs/              ← app logs — persists across releases
    .env               ← credentials — never in git
  current -> releases/20260602140000/   ← symlink, updated on deploy
```

This is the capistrano-style deployment pattern. Each deploy creates a new release directory. The `current` symlink is updated atomically. Rollback is just pointing the symlink to the previous release.

## Part 3 — nginx configuration

```nginx
# /etc/nginx/sites-available/fleet-manager
server {
    listen 80;
    server_name fleet.example.com;

    # Redirect HTTP to HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name fleet.example.com;

    # SSL — certbot fills these in
    ssl_certificate     /etc/letsencrypt/live/fleet.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/fleet.example.com/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    # Web root — the public/ directory
    root  /var/www/fleet-manager/current/public;
    index index.php;

    # Logs
    access_log /var/www/fleet-manager/shared/logs/nginx-access.log;
    error_log  /var/www/fleet-manager/shared/logs/nginx-error.log;

    # Security headers
    add_header X-Frame-Options          "DENY"                always;
    add_header X-Content-Type-Options   "nosniff"             always;
    add_header Referrer-Policy          "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy       "geolocation=()"      always;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript;
    gzip_min_length 1000;

    # Static files — served directly by nginx, never hit PHP
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Storage directory — should never be web-accessible
    location /storage {
        deny all;
        return 404;
    }

    # PHP files — pass to PHP-FPM
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        # Security: only pass .php files that exist
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;

        fastcgi_pass   unix:/run/php/php8.3-fpm.sock;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include        fastcgi_params;

        # Timeouts
        fastcgi_read_timeout 60;
        fastcgi_connect_timeout 5;

        # Hide PHP version
        fastcgi_hide_header X-Powered-By;
    }

    # Block access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ ^/(composer\.json|composer\.lock|\.env|phpunit\.xml)$ {
        deny all;
    }

    client_max_body_size 20M;
}
```

```bash
# Enable the site
sudo ln -s /etc/nginx/sites-available/fleet-manager /etc/nginx/sites-enabled/
sudo nginx -t   # test config — must say "syntax is ok"
sudo systemctl reload nginx

# Get SSL certificate
sudo certbot --nginx -d fleet.example.com
```

## Part 4 — PHP-FPM configuration

```ini
; /etc/php/8.3/fpm/pool.d/fleet-manager.conf
[fleet-manager]
user  = fleet-app
group = www-data

listen = /run/php/php8.3-fpm-fleet.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

; Process management
pm                   = dynamic
pm.max_children      = 20
pm.start_servers     = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests      = 500   ; restart workers after 500 requests (prevents memory leaks)

; Environment variables — loaded from /etc/environment or set here
env[APP_ENV]  = production
env[APP_DEBUG]= false

; PHP settings for this pool
php_admin_value[error_log]      = /var/www/fleet-manager/shared/logs/php-error.log
php_admin_flag[log_errors]      = on
php_admin_flag[display_errors]  = off
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size]       = 25M
php_admin_value[memory_limit]        = 256M
php_admin_value[max_execution_time]  = 30
php_admin_value[session.save_path]   = /var/lib/php/sessions/fleet-manager
```

```bash
sudo mkdir -p /var/lib/php/sessions/fleet-manager
sudo chown fleet-app:www-data /var/lib/php/sessions/fleet-manager
sudo chmod 770 /var/lib/php/sessions/fleet-manager
sudo systemctl restart php8.3-fpm
```

## Part 5 — Environment configuration

```bash
# /var/www/fleet-manager/shared/.env
# This file NEVER goes in git — created manually on the server
sudo -u fleet-app nano /var/www/fleet-manager/shared/.env
```

```ini
APP_ENV=production
APP_DEBUG=false
APP_SECRET=your-random-64-char-secret-here

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=fleet_db
DB_USER=fleet_user
DB_PASS=strong-random-password-here

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

MQTT_BROKER=mqtt.factory.local
MQTT_PORT=1883

GITHUB_FIRMWARE_REPO=your-org/firmware-repo
```

```bash
# Secure the .env file
sudo chmod 640 /var/www/fleet-manager/shared/.env
sudo chown fleet-app:fleet-app /var/www/fleet-manager/shared/.env
```

## Part 6 — MySQL production setup

```bash
sudo mysql -u root -p
```

```sql
-- Create production database
CREATE DATABASE fleet_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user with minimal privileges
CREATE USER 'fleet_user'@'127.0.0.1' IDENTIFIED BY 'strong-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON fleet_db.* TO 'fleet_user'@'127.0.0.1';

-- No DROP, CREATE, ALTER in production — schema changes go through migrations
FLUSH PRIVILEGES;
EXIT;
```

The principle of least privilege: the application user has only the SQL commands it needs to run. It cannot drop tables, create new tables, or alter schema. Schema changes require a separate migration user with more privileges.

## Part 7 — The deploy script

```bash
#!/usr/bin/env bash
# deploy.sh — run from your local machine or CI/CD

set -euo pipefail   # exit on error, undefined var, pipe failure

REMOTE_USER="deploy"
REMOTE_HOST="fleet.example.com"
APP_DIR="/var/www/fleet-manager"
REPO_URL="git@github.com:your-org/fleet-manager.git"
BRANCH="${1:-main}"

TIMESTAMP=$(date +%Y%m%d%H%M%S)
RELEASE_DIR="$APP_DIR/releases/$TIMESTAMP"
SHARED_DIR="$APP_DIR/shared"

echo "==> Deploying branch '$BRANCH' to $REMOTE_HOST"

ssh "$REMOTE_USER@$REMOTE_HOST" bash << EOF
    set -euo pipefail

    echo "--> Creating release directory"
    mkdir -p "$RELEASE_DIR"

    echo "--> Cloning repository"
    git clone --depth=1 --branch="$BRANCH" "$REPO_URL" "$RELEASE_DIR"
    cd "$RELEASE_DIR"

    echo "--> Linking shared files"
    rm -rf storage
    ln -s "$SHARED_DIR/storage" storage
    ln -s "$SHARED_DIR/.env" .env

    echo "--> Installing Composer dependencies"
    composer install --no-dev --optimize-autoloader --no-interaction --quiet

    echo "--> Running database migrations"
    php artisan migrate --force 2>/dev/null || php bin/migrate.php

    echo "--> Optimising autoloader"
    composer dump-autoload --optimize --quiet

    echo "--> Switching to new release"
    ln -sfn "$RELEASE_DIR" "$APP_DIR/current"

    echo "--> Reloading PHP-FPM (zero downtime)"
    sudo systemctl reload php8.3-fpm

    echo "--> Restarting queue worker"
    sudo systemctl restart fleet-worker

    echo "--> Cleaning up old releases (keep last 5)"
    ls -dt "$APP_DIR/releases"/*/ | tail -n +6 | xargs rm -rf

    echo "==> Deploy complete: $TIMESTAMP"
EOF
```

`ln -sfn` is atomic on Linux — the symlink switch happens in a single kernel operation. There is no moment where `current` points to nothing. This is why the release directory pattern gives you true zero-downtime deploys.

## Part 8 — Systemd service for the queue worker

```ini
# /etc/systemd/system/fleet-worker.service
[Unit]
Description=Fleet Manager Queue Worker
After=network.target mysql.service redis.service
Requires=redis.service

[Service]
Type=simple
User=fleet-app
Group=fleet-app
WorkingDirectory=/var/www/fleet-manager/current

# Load environment from the shared .env file
EnvironmentFile=/var/www/fleet-manager/shared/.env

ExecStart=/usr/bin/php /var/www/fleet-manager/current/worker.php
ExecStop=/bin/kill -TERM $MAINPID

# Restart policy
Restart=always
RestartSec=5s

# Resource limits
LimitNOFILE=65536
MemoryMax=256M

# Logging
StandardOutput=append:/var/www/fleet-manager/shared/logs/worker.log
StandardError=append:/var/www/fleet-manager/shared/logs/worker-error.log

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=/var/www/fleet-manager/shared

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable fleet-worker
sudo systemctl start fleet-worker
sudo systemctl status fleet-worker

# Watch worker logs
sudo journalctl -u fleet-worker -f
# or
tail -f /var/www/fleet-manager/shared/logs/worker.log
```

## Part 9 — Database migrations

Every schema change needs a migration — a versioned, repeatable script:

```php
<?php
// bin/migrate.php
declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$pdo = new PDO(
    sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4",
        $_ENV["DB_HOST"], $_ENV["DB_NAME"]),
    $_ENV["DB_USER"],
    $_ENV["DB_PASS"],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Create migrations tracking table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        filename   VARCHAR(255) NOT NULL UNIQUE,
        ran_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

// Find migration files
$migrationsDir = __DIR__ . "/../database/migrations";
$files         = glob("$migrationsDir/*.sql");
sort($files);   // run in filename order

$ran = $pdo->query("SELECT filename FROM migrations")
           ->fetchAll(PDO::FETCH_COLUMN);

$pending = array_filter($files, fn($f) => !in_array(basename($f), $ran, true));

if (empty($pending)) {
    echo "Nothing to migrate.\n";
    exit(0);
}

foreach ($pending as $file) {
    $filename = basename($file);
    echo "Running: $filename\n";

    $sql = file_get_contents($file);

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)")
            ->execute([$filename]);
        $pdo->commit();
        echo "  ✓ Done\n";
    } catch (\PDOException $e) {
        $pdo->rollBack();
        echo "  ✗ Failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "All migrations complete.\n";
```

```sql
-- database/migrations/001_create_machines.sql
CREATE TABLE IF NOT EXISTS machines (
    id          VARCHAR(30)       NOT NULL PRIMARY KEY,
    location    VARCHAR(100)      NOT NULL,
    broker_ip   VARCHAR(45)       NOT NULL,
    port        SMALLINT UNSIGNED NOT NULL DEFAULT 1883,
    slots       TINYINT UNSIGNED  NOT NULL DEFAULT 20,
    status      ENUM('online','offline','fault') NOT NULL DEFAULT 'offline',
    created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- database/migrations/002_create_users.sql
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)   NOT NULL UNIQUE,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME      NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Filename convention: `NNN_description.sql` where NNN is a zero-padded number. Always runs in order. Never modifies an existing migration — add a new one.

## Part 10 — Monitoring and health checks

```php
<?php
// public/health.php — simple health check endpoint
// Called by load balancers and monitoring systems

declare(strict_types=1);

header("Content-Type: application/json");

$checks = [];
$status = "ok";

// Database
try {
    require_once __DIR__ . "/../vendor/autoload.php";
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();

    $pdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}",
        $_ENV["DB_USER"], $_ENV["DB_PASS"],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->query("SELECT 1");
    $checks["database"] = "ok";
} catch (\Throwable $e) {
    $checks["database"] = "error";
    $status = "degraded";
}

// Redis
try {
    $redis = new Redis();
    $redis->connect($_ENV["REDIS_HOST"] ?? "127.0.0.1");
    $redis->ping();
    $checks["redis"] = "ok";
} catch (\Throwable $e) {
    $checks["redis"] = "error";
    $status = "degraded";
}

// Storage writable
$storagePath = dirname(__DIR__) . "/storage";
$checks["storage"] = is_writable($storagePath) ? "ok" : "error";
if ($checks["storage"] === "error") $status = "degraded";

$httpStatus = $status === "ok" ? 200 : 503;
http_response_code($httpStatus);

echo json_encode([
    "status"    => $status,
    "checks"    => $checks,
    "timestamp" => date(\DateTimeInterface::ATOM),
    "version"   => trim(shell_exec("git rev-parse --short HEAD 2>/dev/null") ?? "unknown"),
]);
```

```bash
# Test the health endpoint
curl -s http://localhost/health | json_pp

# Add to crontab — alert if health check fails
# * * * * * curl -sf https://fleet.example.com/health || mail -s "Fleet down" you@example.com
```

## Part 11 — Log rotation

```ini
# /etc/logrotate.d/fleet-manager
/var/www/fleet-manager/shared/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    sharedscripts
    postrotate
        systemctl reload php8.3-fpm >/dev/null 2>&1 || true
        systemctl reload nginx >/dev/null 2>&1 || true
    endscript
}
```

---

## Today's exercise


![[Pasted image 20260603102245.png]]

The `kill -9` test in Part B is the most important verification of the day. Systemd's `Restart=always` is a promise — confirm it keeps that promise before you trust it in production. The worker handling OTA updates and email delivery must restart automatically after any crash, including OOM kills.

The migration rollback test in the stretch goal is equally important. A deploy script that can't handle a failed migration is dangerous — it might leave your database half-migrated, with some tables from the new schema and some from the old. The transaction wrapping every migration is what makes rollback reliable.

Two days left. Day 29 is Laravel — now that you've built everything by hand, the framework will feel like a collection of shortcuts rather than magic. Day 30 is the capstone. Paste your work when ready.