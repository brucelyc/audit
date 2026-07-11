FROM php:8.3-apache

RUN docker-php-ext-install mysqli \
    && a2enmod rewrite

COPY docker/php.ini /usr/local/etc/php/conf.d/audit-uploads.ini

WORKDIR /var/www/html

COPY *.php *.js *.css *.csv ./

RUN mkdir -p logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 640 config.php

EXPOSE 80
