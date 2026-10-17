# Production Deployment Guide — Laravel Starter

This guide outlines the prerequisites, environment configurations, and deployment strategies required to deploy the Laravel Starter application safely and securely to a production environment.

---

## 1. Server Prerequisites

Before deploying the application, ensure the host server is configured with the following minimum software stack:

- **Operating System:** Ubuntu 22.04 LTS or 24.04 LTS (recommended)
- **Web Server:** Nginx (v1.20+)
- **PHP:** Version 8.3 (with `cli`, `fpm`, `pgsql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `intl`, `gd`, `opcache`)
- **Database:** PostgreSQL v15+ (with connection credentials and empty schema)
- **Cache/Queue Broker:** Redis v7+
- **Process Manager:** Supervisor (for maintaining active queue workers)

---

## 2. Environment Configurations (`.env`)

Ensure the following production settings are specified in your host `.env` file:

```ini
APP_NAME="Laravel Starter"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://anaklangit.manahpro.web.id

# PostgreSQL Connection
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=your_production_database
DB_USERNAME=your_production_user
DB_PASSWORD=your_secure_password

# Cache, Session, Queue
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Redis Config
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Mail Server Configuration
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@anaklangit.manahpro.web.id
MAIL_PASSWORD=your_smtp_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@anaklangit.manahpro.web.id"
MAIL_FROM_NAME="${APP_NAME}"

# Passport Keys
PASSPORT_PRIVATE_KEY="file://storage/oauth-private.key"
PASSPORT_PUBLIC_KEY="file://storage/oauth-public.key"
```

---

## 3. Production Nginx Host Configuration

Deploy the following Nginx virtual host block, ensuring that HTTP traffic is automatically forced to HTTPS:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name anaklangit.manahpro.web.id;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name anaklangit.manahpro.web.id;
    root /var/www/laravel-starter/public;

    ssl_certificate /etc/letsencrypt/live/anaklangit.manahpro.web.id/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/anaklangit.manahpro.web.id/privkey.pem;
    
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy "no-referrer-when-downgrade";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## 4. 10-Step Deployment Pipeline

Implement this standard zero-downtime or minimal-downtime deployment script in your CI/CD tool (e.g., GitHub Actions, Deployer, or custom shell scripts):

### Step 1: Put Application in Maintenance Mode
```bash
php artisan down --refresh=15 --retry=60
```

### Step 2: Fetch the Latest Production Codebase
```bash
git fetch origin
git checkout main
git pull origin main
```

### Step 3: Install Production Composer Dependencies
```bash
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
```

### Step 4: Run Migrations Safely
```bash
php artisan migrate --force
```

### Step 5: Generate OAuth Keys (First-time setup only)
```bash
php artisan passport:keys
```

### Step 6: Clear and Optimize Caching Systems
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### Step 7: Build Premium Front-end Assets (Vite)
```bash
npm install --production --ignore-scripts
npm run build
```

### Step 8: Restart Background Queue Workers
```bash
php artisan queue:restart
```

### Step 9: Re-enable Database / Config Cache
```bash
php artisan app:init-caching # if custom initialization exists
```

### Step 10: Bring Application Out of Maintenance Mode
```bash
php artisan up
```

---

## 5. Supervisor Queue Worker Configuration

To guarantee that background jobs (like push notifications, email verifications, and audit logging tasks) are executed reliably without blocking API processes, configure a Supervisor daemon on the server.

Create a configuration file `/etc/supervisor/conf.d/laravel-worker.conf`:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/laravel-starter/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/laravel-starter/storage/logs/worker.log
stopwaitsecs=3600
```

Apply the new supervisor settings:
```bash
sudo supervisorctl reread
sudo supervisorctl add laravel-worker
sudo supervisorctl start laravel-worker:*
```

---

## 6. Secure File System Permissions

Set proper ownership and write permissions so that Nginx/PHP-FPM can serve files while keeping configurations secure:

```bash
sudo chown -R www-data:www-data /var/www/laravel-starter
sudo find /var/www/laravel-starter -type f -exec chmod 644 {} \;
sudo find /var/www/laravel-starter -type d -exec chmod 755 {} \;
sudo chmod -R 775 /var/www/laravel-starter/storage
sudo chmod -R 775 /var/www/laravel-starter/bootstrap/cache
```
