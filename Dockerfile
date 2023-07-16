FROM php:8-alpine3.18

ARG build_version
ARG build_id
ARG build_date

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
COPY . /srv

WORKDIR /srv

RUN cd /srv \
 && mkdir -p uploads \
 && composer install --no-dev --optimize-autoloader

ENV CLIUP_VERSION=${build_version}-${build_id} \
    UPLOAD_DIR=/srv/uploads \
    MEMORY_LIMIT=256M

CMD php -c /srv/php.ini -d memory_limit=${MEMORY_LIMIT} -S 0.0.0.0:8080 index.php
