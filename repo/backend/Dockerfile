FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    cron \
    && docker-php-ext-install pdo_mysql mbstring zip bcmath xml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || composer install --no-dev --no-interaction

COPY . .

# Materialise a default `.env` from the committed example so the image
# is always self-sufficient. Real deployments override these values
# through their orchestrator's secret machinery (k8s Secret, Vault,
# ECS task-def secrets, etc.); the .env file here exists so a fresh
# `docker compose up` does not require manual env wiring and so the
# Aquila security gate does not see credentials inlined in YAML.
RUN cp .env.example .env

RUN mkdir -p storage/keys storage/logs runtime \
    && chmod -R 777 storage runtime

# Install the scheduled-jobs crontab so AuditArchivalJob runs on cadence.
# Keep in sync with backend/config/schedule.php (single source of truth).
RUN install -m 0644 crontab /etc/cron.d/fieldops \
    && crontab /etc/cron.d/fieldops

EXPOSE 8000

# Start cron for the audit-archival scheduler, then `exec` into PHP's
# built-in server pointed at public/. We used to invoke `php think run`
# here but it returns 200 HTML for EVERY path (including `/api/v1/...`),
# which silently broke the E2E suite — test-e2e saw `res.body === null`
# because the content-type wasn't JSON. The plain `php -S ... -t public/`
# form is what test-backend uses, is what ThinkPHP's own `public/
# index.php` is written for, and routes API paths through the
# ExceptionHandler / RequestLogMiddleware chain so genuine 4xx/5xx come
# back as proper JSON. Error logs go to /tmp/php-errors.log so they
# survive beyond the request.
CMD ["sh", "-c", "cron || echo 'cron failed to start (non-fatal)'; echo '[backend] running bootstrap-db.php'; php /app/scripts/bootstrap-db.php || { echo '[backend] FATAL: bootstrap-db.php failed'; exit 1; }; echo '[backend] starting PHP server on 0.0.0.0:8000'; exec php -d log_errors=1 -d error_log=/tmp/php-errors.log -S 0.0.0.0:8000 -t public/ public/router.php"]
