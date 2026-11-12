FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev && docker-php-ext-install pdo_mysql mbstring && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*
RUN sed -ri 's!/var/www/html!/var/www/html/app_store!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
WORKDIR /var/www/html
COPY . /var/www/html
RUN mkdir -p app_store/uploads/screenshots && chown -R www-data:www-data app_store/uploads && chmod -R 0755 app_store/uploads
RUN printf "expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nupload_max_filesize=5M\npost_max_size=8M\nmemory_limit=256M\n" > /usr/local/etc/php/conf.d/hendy.ini
EXPOSE 80