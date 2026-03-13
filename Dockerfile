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

RUN echo "opcache.enable=0" >> /usr/local/etc/php/conf.d/opcache.ini
RUN echo "display_errors=On" >> /usr/local/etc/php/conf.d/preprod.ini \
    && echo "error_reporting=E_ALL" >> /usr/local/etc/php/conf.d/preprod.ini \
    && echo "memory_limit=256M" >> /usr/local/etc/php/conf.d/preprod.ini \
    && echo "upload_max_filesize=20M" >> /usr/local/etc/php/conf.d/preprod.ini \
    && echo "post_max_size=20M" >> /usr/local/etc/php/conf.d/preprod.ini

WORKDIR /var/www/html

RUN usermod -u 1000 www-data

CMD ["php-fpm"]
