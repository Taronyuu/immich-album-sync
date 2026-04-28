FROM serversideup/php:8.4-fpm-nginx

ARG USER_ID=33
ARG GROUP_ID=33

USER root
RUN docker-php-serversideup-set-id www-data "${USER_ID}:${GROUP_ID}" \
 && docker-php-serversideup-set-file-permissions --owner "${USER_ID}:${GROUP_ID}" --service nginx \
 && install-php-extensions intl \
 && apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --chown=www-data:www-data . /var/www/html
WORKDIR /var/www/html

USER www-data
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

USER root
COPY docker/s6/scheduler /etc/s6-overlay/s6-rc.d/scheduler
COPY docker/s6/queue /etc/s6-overlay/s6-rc.d/queue
COPY docker/s6/user-contents.d/scheduler /etc/s6-overlay/s6-rc.d/user/contents.d/scheduler
COPY docker/s6/user-contents.d/queue /etc/s6-overlay/s6-rc.d/user/contents.d/queue
RUN chmod 755 \
    /etc/s6-overlay/s6-rc.d/scheduler/run \
    /etc/s6-overlay/s6-rc.d/queue/run

USER www-data
