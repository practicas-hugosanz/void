FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y \
    apache2 \
    php8.2 \
    libapache2-mod-php8.2 \
    php8.2-pgsql \
    php8.2-curl \
    php8.2-pdo \
    php-pdo \
    curl \
    && apt-get clean \
    && phpenmod pdo_pgsql

COPY . /var/www/html/
RUN echo '<?php echo "PHP OK"; ?>' > /var/www/html/test.php

RUN echo '<VirtualHost *:8080>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf && \
    echo "Listen 8080" > /etc/apache2/ports.conf && \
    a2enmod php8.2 rewrite && \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf && \
    echo "ErrorLog /dev/stderr" >> /etc/apache2/apache2.conf && \
    echo "TransferLog /dev/stdout" >> /etc/apache2/apache2.conf

EXPOSE 8080

CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
