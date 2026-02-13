ARG PHP_VERSION=8.4
ARG SHOPWARE_VERSION=6.7.5.1
FROM ghcr.io/shopware/docker-dev:php$PHP_VERSION-node24-caddy

ENV SHOPWARE_VERSION=${SHOPWARE_VERSION}

WORKDIR /var/www/html

RUN composer create-project shopware/production:$SHOPWARE_VERSION . --no-interaction --prefer-dist --no-progress > /tmp/install.log 2>&1

USER www-data
