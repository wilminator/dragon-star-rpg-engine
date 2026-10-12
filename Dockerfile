FROM php:8.4-apache

# mysqli - the DB access layer (include/mysql.php) is built on it.
# pdo_mysql - the DB access layer (include/mysql.php) is built on it.
# gd     - images/fighters/cvdq3.php uses imagecreatefromgif().
RUN apt-get update \
    && apt-get install -y libzip-dev libpng-dev libsqlite3-dev libpq-dev \
    && docker-php-ext-install mysqli pdo_mysql pdo_sqlite pdo_pgsql gd

# Dev-mode PHP settings: surface every error/warning/deprecation
# instead of hiding them, since the whole point right now is finding
# what PHP 8.4 breaks. Tighten this once things stabilize.
COPY docker/php-dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

# Drop in a composer install script.
RUN <<EOR
cat > /usr/local/bin/composer-setup <<EOF
#!/bin/sh

EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]
then
    >&2 echo 'ERROR: Invalid installer checksum'
    rm composer-setup.php
    exit 1
fi

php composer-setup.php --quiet --install-dir=/usr/bin --filename=composer
RESULT=$?
rm composer-setup.php
exit $RESULT
EOF
chmod +x /usr/local/bin/composer-setup
EOR