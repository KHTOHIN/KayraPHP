FROM php:8.4-fpm

# Install extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql zip gd sockets \
    && pecl install swoole redis \
    && docker-php-ext-enable swoole redis

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Opcache for preload
RUN docker-php-ext-install opcache \
    && echo 'opcache.enable=1\nopcache.enable_cli=1\nopcache.memory_consumption=256\nopcache.interned_strings_buffer=16\nopcache.max_accelerated_files=10000\nopcache.revalidate_freq=0' > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /app
COPY . /app
RUN composer install --no-dev --optimize-autoloader

# Placeholder for preload
RUN echo '<?php // Preload compiled container\nif (file_exists("/app/storage/cache/container.php")) { require "/app/storage/cache/container.php"; }' > /app/opcache.preload.php