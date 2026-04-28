FROM serversideup/php:8.4-fpm-nginx

USER root
RUN install-php-extensions intl

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
