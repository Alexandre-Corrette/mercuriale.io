FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    libpq-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure intl \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
        opcache \
        sockets

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

RUN echo "memory_limit=256M" >> /usr/local/etc/php/conf.d/mercuriale.ini \
    && echo "upload_max_filesize=20M" >> /usr/local/etc/php/conf.d/mercuriale.ini \
    && echo "post_max_size=25M" >> /usr/local/etc/php/conf.d/mercuriale.ini \
    && echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/mercuriale.ini \
    && echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/mercuriale.ini \
    && echo "display_errors=Off" >> /usr/local/etc/php/conf.d/mercuriale.ini \
    && echo "expose_php=Off" >> /usr/local/etc/php/conf.d/mercuriale.ini

WORKDIR /var/www/html

RUN usermod -u 1000 www-data

USER www-data

CMD ["php-fpm"]
