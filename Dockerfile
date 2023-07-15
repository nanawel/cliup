FROM php:8-alpine3.18

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
COPY . /srv

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /srv

RUN cd /srv \
 && mkdir -p uploads \
 && composer install --no-dev --optimize-autoloader

ARG build_version
ARG build_id
ARG build_date
ENV CLIUP_VERSION=${build_version}-${build_id} \
    UPLOAD_DIR=/srv/uploads

CMD php -S 0.0.0.0:8080 index.php
