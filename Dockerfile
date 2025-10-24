FROM php:8.2-apache

RUN a2enmod rewrite

# SQLite için derleme bağımlılıkları
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libsqlite3-dev pkg-config; \
    rm -rf /var/lib/apt/lists/*

# PHP eklentileri: PDO ve PDO_SQLITE
RUN docker-php-ext-configure pdo_sqlite; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache document root'u public/ yap
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html

# PHP bağımlılıkları (şimdilik boş ama autoload için)
RUN composer install --no-dev --no-interaction --prefer-dist || true

# SQLite dosya dizini
RUN mkdir -p /var/www/html/storage && chown -R www-data:www-data /var/www/html

