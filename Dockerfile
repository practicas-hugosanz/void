FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y \
    apache2 \
    php8.2 \
    libapache2-mod-php8.2 \
    php8.2-sqlite3 \
    php8.2-curl \
    php8.2-pdo \
    curl \
    && apt-get clean

RUN a2enmod php8.2 rewrite && \
    sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY . /var/www/html/
RUN mkdir -p /var/www/html/data && chmod 777 /var/www/html/data

EXPOSE 80

CMD ["apache2ctl", "-D", "FOREGROUND"]
