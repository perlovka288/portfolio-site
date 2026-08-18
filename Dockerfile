FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql gd zip

RUN a2enmod rewrite headers

# До 40 файлов в заказе: post_max_size и max_file_uploads подняты под этот лимит
# (PHP по умолчанию режет max_file_uploads=20 — без этого файлы 21-40 молча терялись бы).
RUN echo "upload_max_filesize = 32M\n\
post_max_size = 500M\n\
memory_limit = 256M\n\
max_execution_time = 120\n\
max_input_time = 120\n\
max_file_uploads = 45" > /usr/local/etc/php/conf.d/uploads.ini

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/uploads/orders \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 777 /var/www/html/uploads

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Меняем порт Apache на $PORT от Render
CMD sed -i "s/80/${PORT:-80}/g" /etc/apache2/ports.conf /etc/apache2/sites-enabled/*.conf && apache2-foreground

EXPOSE 80