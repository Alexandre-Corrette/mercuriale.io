FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    libpq-dev \
    libmagickwand-dev \
    libheif-dev \
    imagemagick \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
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

RUN pecl install redis imagick \
    && docker-php-ext-enable redis imagick

# ImageMagick policy.xml peut bloquer HEIC/HEIF par defaut sur Debian.
# On autorise read/write pour ces formats afin de permettre la conversion HEIC -> JPEG.
RUN POLICY_FILE=$(find /etc/ImageMagick-* -name policy.xml 2>/dev/null | head -1) \
    && if [ -n "$POLICY_FILE" ]; then \
        sed -i 's|<policy domain="coder" rights="none" pattern="HEIC".*/>|<policy domain="coder" rights="read\|write" pattern="HEIC" />|g' "$POLICY_FILE" \
        && sed -i 's|<policy domain="coder" rights="none" pattern="HEIF".*/>|<policy domain="coder" rights="read\|write" pattern="HEIF" />|g' "$POLICY_FILE" ; \
    fi

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

ARG APP_ENV=prod
COPY docker/php/php-${APP_ENV}.ini $PHP_INI_DIR/conf.d/mercuriale.ini

WORKDIR /var/www/html

# Aligner l'utilisateur d'exécution (www-data) sur l'utilisateur hôte qui possède
# le repo cloné côté serveur (user "deploy" = 1001:1001).
# Sans cet alignement, php-fpm tourne en uid 1000 / gid 33 alors que public/ est
# owné 1001:1001 en mode 775 : www-data tombe dans "others" (r-x) et
# `asset-map:compile --env=prod` échoue sur `mkdir(public/assets): Permission denied`.
# En devenant owner de public/ (et var/), www-data écrit via les droits owner (rwx),
# et l'ownership reste cohérent avec les opérations git du déploiement (uid 1001).
# Surchargeable au build : --build-arg PUID=... PGID=... (cf. docker-compose.yml).
ARG PUID=1001
ARG PGID=1001
RUN groupmod -o -g "${PGID}" www-data \
    && usermod  -o -u "${PUID}" -g "${PGID}" www-data

USER www-data

CMD ["php-fpm"]
