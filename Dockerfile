FROM php:8-alpine3.22

ARG build_version
ARG build_id
ARG build_date
ARG uid=1000
ARG gid=1000
ARG uploads_dir=/uploads

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
COPY . /srv

WORKDIR /srv

RUN cd /srv \
 && mkdir -p -m750 ${uploads_dir} \
 && chown ${uid}:${gid} ${uploads_dir} \
 && composer install --no-dev --optimize-autoloader

USER ${uid}:${gid}

ENV CLIUP_VERSION=${build_version}-${build_id} \
    CLIUP_BUILD_DATE=${build_date} \
    UPLOAD_DIR=${uploads_dir} \
    MEMORY_LIMIT=256M

CMD php -c /srv/php.ini -d memory_limit=${MEMORY_LIMIT} -S 0.0.0.0:8080 index.php
