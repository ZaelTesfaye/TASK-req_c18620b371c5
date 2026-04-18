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

# Run cron in the background, then the API server in the foreground so
# "docker stop" still cleanly terminates the container.
CMD ["sh", "-c", "cron && php think run --host 0.0.0.0 --port 8000"]
