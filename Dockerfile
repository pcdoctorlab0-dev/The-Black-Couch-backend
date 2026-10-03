FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates libzip-dev unzip git \
    && update-ca-certificates \
    && docker-php-ext-install pdo_mysql mysqli fileinfo \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/backend

RUN sed -i 's#/var/www/html#/var/www/html/backend#g' \
    /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf

RUN { \
    echo '<Directory /var/www/html/backend/config>'; echo 'Require all denied'; echo '</Directory>'; \
    echo '<Directory /var/www/html/backend/includes>'; echo 'Require all denied'; echo '</Directory>'; \
    echo '<Directory /var/www/html/backend/storage>'; echo 'Require all denied'; echo '</Directory>'; \
    echo '<Directory /var/www/html/backend/migrations>'; echo 'Require all denied'; echo '</Directory>'; \
    } >> /etc/apache2/apache2.conf

# Render injects $PORT at runtime — rewrite Apache's listen port to match it
# on container start, rather than assuming 80.
RUN printf '#!/bin/sh\n\
set -eu\n\
PORT="${PORT:-80}"\n\
case "$PORT" in\n\
  ""|*[!0-9]*) echo "Invalid PORT: $PORT" >&2; exit 1 ;;\n\
esac\n\
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf\n\
sed -i "s#<VirtualHost \\*:80>#<VirtualHost *:${PORT}>#" /etc/apache2/sites-available/000-default.conf\n\
exec apache2-foreground\n' > /entrypoint.sh && chmod +x /entrypoint.sh

EXPOSE 80
CMD ["/entrypoint.sh"].env
.env.*
!.env.example
.git