FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_pgsql

# Включаем mod_rewrite и mod_headers
RUN a2enmod rewrite headers

# Увеличиваем лимиты PHP для загрузки файлов
RUN echo "upload_max_filesize = 32M\n\
post_max_size = 34M\n\
memory_limit = 128M\n\
max_execution_time = 60\n\
max_input_time = 60" > /usr/local/etc/php/conf.d/uploads.ini

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/uploads/orders \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 777 /var/www/html/uploads

# Разрешаем .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80