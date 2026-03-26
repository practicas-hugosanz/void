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

COPY . /var/www/html/
RUN mkdir -p /var/www/html/data && chmod 777 /var/www/html/data && \
    echo '<?php echo "PHP OK"; ?>' > /var/www/html/test.php

RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf && \
    a2enmod php8.2 rewrite && \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf

EXPOSE 80

CMD bash -c "apache2ctl -D FOREGROUND 2>&1 | tee /proc/1/fd/1"
