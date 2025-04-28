# syntax=docker.io/docker/dockerfile:1
FROM serversideup/php:8.4-fpm-nginx-alpine

ENV PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

USER root
RUN apk add --no-cache coreutils grep sed curl git bash docker-cli nodejs-current npm

COPY --chmod=755 ./resources/docker/migrate.sh /etc/entrypoint.d/99-migrate.sh
COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader
RUN mkdir -p /var/www/html/storage/framework && cd /var/www/html/storage/framework && mkdir sessions views cache && chown -R www-data:www-data /var/www/html/storage
RUN mkdir -p /var/www/html/bootstrap/cache && chown -R www-data:www-data /var/www/html/bootstrap/cache
RUN npm install
RUN npm run build

USER www-data
EXPOSE 8080