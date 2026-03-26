FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_sqlite && \
    a2enmod rewrite

COPY . /var/www/html/
RUN chmod 777 /var/www/html/data 2>/dev/null || mkdir -p /var/www/html/data && chmod 777 /var/www/html/data

EXPOSE 80
