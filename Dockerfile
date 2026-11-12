FROM composer:2 AS composer

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev libcurl4-openssl-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql mbstring curl \
    && a2enmod rewrite headers proxy proxy_http proxy_wstunnel \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app
COPY . /var/www/app
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative --no-progress
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN mkdir -p storage/modules app_store/uploads/screenshots \
    && chown -R www-data:www-data storage app_store/uploads \
    && chmod 750 storage storage/modules \
    && chmod -R 755 app_store/uploads \
    && chmod +x /var/www/app/docker/start.sh
RUN printf "expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nupload_max_filesize=5M\npost_max_size=8M\nmemory_limit=256M\n" > /usr/local/etc/php/conf.d/hendy.ini

EXPOSE 10000
CMD ["/var/www/app/docker/start.sh"]
