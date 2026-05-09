FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        bash \
        git \
        unzip \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        gmp-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        pdo_mysql \
        bcmath \
        gmp \
        intl \
        mbstring \
        opcache \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Install Composer 2.x
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Recommended OPcache settings for dev (timestamps validated each request)
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'memory_limit=512M'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app

EXPOSE 9000
CMD ["php-fpm", "-F"]
