# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# KayraPHP production image
#
# Two stages: dependencies are installed with the full toolchain, then only the
# result is copied into a slim runtime image.
# ---------------------------------------------------------------------------

FROM php:8.4-cli AS vendor

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install -j"$(nproc)" zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy manifests first so this layer is cached until dependencies change.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev


# ---------------------------------------------------------------------------

FROM php:8.4-fpm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install -j"$(nproc)" opcache zip \
    && rm -rf /var/lib/apt/lists/*

# OPcache settings for production. revalidate_freq=0 with validate_timestamps=0
# means compiled files are never re-checked: deploy by replacing the container.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.memory_consumption=256'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.save_comments=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

RUN { \
      echo 'expose_php=0'; \
      echo 'display_errors=0'; \
      echo 'log_errors=1'; \
      echo 'error_log=/dev/stderr'; \
      echo 'memory_limit=256M'; \
      echo 'upload_max_filesize=16M'; \
      echo 'post_max_size=16M'; \
    } > /usr/local/etc/php/conf.d/kayra.ini

WORKDIR /app

COPY --from=vendor /app /app

# Build the compiled artefacts into the image, so no request ever pays for them.
ENV APP_ENV=production
RUN php kayra optimize \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s \
    CMD php -r 'exit(0);'

CMD ["php-fpm"]
