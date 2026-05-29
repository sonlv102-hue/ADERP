# Stage 1: PHP dependencies (needed by frontend for Ziggy)
FROM composer:2.7 AS composer-deps

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# Stage 2: Build frontend assets
FROM node:20-alpine AS frontend-builder

WORKDIR /app

COPY package*.json ./
RUN npm ci

ARG CACHE_BUST=1
COPY . .
# Copy vendor so Vite can resolve vendor/tightenco/ziggy
COPY --from=composer-deps /app/vendor ./vendor
RUN npm run build

# Stage 3: PHP application
FROM php:8.2-fpm-alpine AS app

# Install system dependencies
RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    oniguruma-dev \
    icu-dev \
    postgresql-dev \
    supervisor

# Install PHP extensions
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy vendor from composer-deps stage
COPY --from=composer-deps /app/vendor ./vendor

ARG CACHE_BUST=1
# Copy application source
COPY . .

# Copy built frontend assets from frontend-builder stage
COPY --from=frontend-builder /app/public/build ./public/build

# Finish composer autoload
RUN composer dump-autoload --optimize --no-dev

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# PHP-FPM config
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

EXPOSE 9000

CMD ["php-fpm"]
