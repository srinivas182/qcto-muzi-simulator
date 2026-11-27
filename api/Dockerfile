# Development image for the API. Production uses Octane (FrankenPHP) — added in the deployment sprint.
FROM php:8.4-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libzip-dev \
 && docker-php-ext-install pdo_pgsql pgsql intl zip \
 && pecl install redis && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
