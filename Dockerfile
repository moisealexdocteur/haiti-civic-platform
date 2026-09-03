# syntax=docker/dockerfile:1

# ============================================================
# Base PHP
# ============================================================

FROM php:8.3-fpm-alpine AS php-base

USER root

RUN apk add --no-cache \
        curl \
        icu-libs \
        oniguruma \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        curl-dev \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
    && docker-php-ext-install -j1 \
        curl \
        intl \
        mbstring \
        mysqli \
        pdo_mysql \
        opcache \
        zip \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/uploads.ini \
    "$PHP_INI_DIR/conf.d/98-uploads.ini"

COPY docker/php/opcache.ini \
    "$PHP_INI_DIR/conf.d/99-opcache.ini"


# ============================================================
# Composer dependencies
# ============================================================

FROM php-base AS vendor

USER root

COPY --from=composer:2 \
    /usr/bin/composer \
    /usr/local/bin/composer

RUN apk add --no-cache git unzip

WORKDIR /build

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader


# ============================================================
# CodeIgniter runtime
# ============================================================

FROM php-base AS app

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html

# Test sources are required in the Docker build context for the
# dedicated test stage, but must not remain in the runtime image.
RUN rm -rf \
        /var/www/html/tests \
        /var/www/html/phpunit.dist.xml

COPY --from=vendor \
    --chown=www-data:www-data \
    /build/vendor \
    /var/www/html/vendor

RUN mkdir -p \
        writable/cache \
        writable/logs \
        writable/session \
        writable/uploads \
    && chown -R www-data:www-data writable

USER www-data

EXPOSE 9000

CMD ["php-fpm", "-F"]


# ============================================================
# Nginx
# ============================================================

FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf \
    /etc/nginx/conf.d/default.conf

COPY public /var/www/html/public

EXPOSE 80


# ============================================================
# Automated test runtime
# ============================================================

FROM php-base AS test

USER root

COPY --from=composer:2 \
    /usr/bin/composer \
    /usr/local/bin/composer

RUN apk add --no-cache git unzip

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
    --prefer-dist \
    --no-interaction \
    --no-progress

# Keep application/test sources owned by the same unprivileged user that
# executes Spark and PHPUnit. This prevents permission drift on the host
# from making Config/Routes.php or another source unreadable in the image.
COPY --chown=www-data:www-data . /var/www/html

RUN mkdir -p \
        writable/cache \
        writable/logs \
        writable/session \
        writable/uploads \
        writable/debugbar \
    && chown -R www-data:www-data writable

USER www-data

CMD ["php", "vendor/bin/phpunit"]