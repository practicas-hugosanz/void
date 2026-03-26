FROM php:8.2-apache

RUN apt-get update && apt-get install -y libsqlite3-dev && \
    docker-php-ext-install pdo pdo_sqlite && \
    a2dismod mpm_event && \
    a2enmod mpm_prefork rewrite

COPY . /var/www/html/
RUN mkdir -p /var/www/html/data && chmod 777 /var/www/html/data

EXPOSE 80
