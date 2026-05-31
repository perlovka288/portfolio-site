FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_pgsql

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/uploads/orders \
    && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 80