# Development image used by compose.yaml. It runs PHP's built-in web server
# and is meant for trying out and contributing, not for production hosting.
FROM php:8.3-cli-alpine

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions gd exif intl zip opcache pdo_mysql pdo_pgsql \
    && apk add --no-cache git

# Allow image uploads up to the default APP_MAX_UPLOAD_MB.
RUN { \
        echo 'upload_max_filesize = 16M'; \
        echo 'post_max_size = 20M'; \
        echo 'memory_limit = 256M'; \
    } > /usr/local/etc/php/conf.d/symfocms.ini

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY docker/entrypoint.sh /usr/local/bin/symfocms-entrypoint
RUN chmod +x /usr/local/bin/symfocms-entrypoint

EXPOSE 8000

ENTRYPOINT ["symfocms-entrypoint"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "bin/dev-router.php"]
