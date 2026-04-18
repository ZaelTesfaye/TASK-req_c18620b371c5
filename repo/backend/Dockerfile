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

RUN mkdir -p storage/keys storage/logs runtime \
    && chmod -R 777 storage runtime

# Install the scheduled-jobs crontab so AuditArchivalJob runs on cadence.
# Keep in sync with backend/config/schedule.php (single source of truth).
RUN install -m 0644 crontab /etc/cron.d/fieldops \
    && crontab /etc/cron.d/fieldops

EXPOSE 8000

# Start cron in the background for scheduled jobs, then `exec` into the API
# server so SIGTERM/SIGINT reach PHP directly and `docker stop` terminates
# cleanly. The startup banner makes it obvious in `docker compose up` logs
# that the container reached the serving stage — previously the container
# could sit silent during MySQL readiness waits with no indication that
# anything was running.
CMD ["sh", "-c", "cron || echo 'cron failed to start (non-fatal)'; echo '[backend] starting PHP server on 0.0.0.0:8000'; exec php think run --host 0.0.0.0 --port 8000"]
