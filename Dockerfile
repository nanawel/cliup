FROM php:8-alpine3.18

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
COPY . /srv


WORKDIR /srv

RUN cd /srv \
 && mkdir -p uploads \
 && composer install --no-dev

ARG appVersion
ENV CLIUP_VERSION=${appVersion} \
    UPLOAD_DIR=/src/uploads

CMD php -S 0.0.0.0:8080 index.php
