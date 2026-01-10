ARG PHP_VERSION=8.4
ARG SHOPWARE_VERSION=6.7.5.1
FROM ghcr.io/shopware/docker-dev:php$PHP_VERSION-node24-caddy

ENV SHOPWARE_VERSION=${SHOPWARE_VERSION}

WORKDIR /var/www/html

RUN composer create-project shopware/production:$SHOPWARE_VERSION . --no-interaction --prefer-dist --no-progress > /tmp/install.log 2>&1

USER root

RUN install-php-extensions xdebug && \
    echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.start_with_request=trigger" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

USER www-data
