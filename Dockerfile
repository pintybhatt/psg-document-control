# syntax=docker/dockerfile:1
ARG PHP_IMAGE=php:8.5-apache-bookworm
ARG NODE_IMAGE=node:22-bookworm-slim
ARG COMPOSER_IMAGE=composer:2
FROM ${COMPOSER_IMAGE} AS composer
FROM ${NODE_IMAGE} AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM ${PHP_IMAGE} AS php-runtime
RUN apt-get update && apt-get install -y --no-install-recommends \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql zip \
    && apt-mark manual libfreetype6 libjpeg62-turbo libpng16-16 libzip4 \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /var/www/html

FROM php-runtime AS dependencies
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY routes ./routes
COPY database/migrations ./database/migrations
COPY database/seeders/xppl-master-data.json ./database/seeders/xppl-master-data.json
COPY artisan ./artisan
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && composer check-platform-reqs --no-dev

FROM php-runtime AS release
ARG SOURCE_COMMIT=unknown
LABEL org.opencontainers.image.revision=$SOURCE_COMMIT
ENV APACHE_RUN_DIR=/tmp/apache2 APACHE_LOCK_DIR=/tmp/apache2 \
    APACHE_PID_FILE=/tmp/apache2/apache2.pid \
    PHP_UPLOAD_MAX_FILESIZE=25M PHP_POST_MAX_SIZE=27M PHP_MEMORY_LIMIT=256M \
    HTTP_UPLOAD_LIMIT_BYTES=28311552 APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
COPY --from=dependencies /var/www/html /var/www/html
COPY resources/views ./resources/views
COPY public ./public
COPY --from=frontend /build/public/build ./public/build
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/dcms.ini
COPY docker/entrypoint.sh /usr/local/bin/dcms-entrypoint
RUN chmod 755 /usr/local/bin/dcms-entrypoint
RUN mkdir -p storage/app/document-vault storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chmod 0755 /var/www/html \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 0750 storage bootstrap/cache \
    && test ! -e .env && test ! -e public/storage && test -s public/build/manifest.json
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD curl --fail --silent --output /dev/null http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["dcms-entrypoint"]
CMD ["apache2-foreground"]
