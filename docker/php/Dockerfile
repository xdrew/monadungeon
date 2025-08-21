# fetch the RoadRunner image, image page: <https://hub.docker.com/r/spiralscout/roadrunner>
FROM spiralscout/roadrunner:2024.2.1 AS roadrunner

# install extensions, image page: <https://hub.docker.com/r/mlocati/php-extension-installer/tags>
FROM mlocati/php-extension-installer:2.7.5 AS ext_installer

# build application runtime, image page: <https://hub.docker.com/_/php>
FROM php:8.3-alpine3.17 AS app_base


COPY --from=ext_installer /usr/bin/install-php-extensions /usr/local/bin/

ENV COMPOSER_HOME="/tmp/composer"
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN set -x \
    && apk add supervisor patch \
    # install PHP extensions (CFLAGS usage reason - https://bit.ly/3ALS5NU)
    && install-php-extensions @composer opcache pdo_pgsql pgsql intl pcov pcntl sockets gmp \
    # make clean up
    && docker-php-source delete \
    # enable opcache for CLI, docs: <https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.jit>
    && echo -e "\nopcache.enable=1\nopcache.enable_cli=1\n" >> \
        ${PHP_INI_DIR}/conf.d/docker-php-ext-opcache.ini \
    # show installed PHP modules
    && php -m \
    # create directory for application sources and roadrunner unix socket
    && mkdir -p /sf/app /var/run/rr /root/history \
    && chmod -R 777 /var/run/rr \
    && echo 'alias sf="php bin/console"' >> ~/.bashrc
# install roadrunner
COPY --from=roadrunner /usr/bin/rr /usr/bin/rr

#
COPY ./docker/php/php.ini $PHP_INI_DIR/php.ini

# use directory with application sources by default
WORKDIR /sf/app/src

# copy composer (json|lock) files for dependencies layer caching
COPY ./src/composer.* /sf/app/src/
COPY ./src/config/preload.php /sf/app/src/config/preload.php
# copy patches directory for composer patches plugin
COPY ./src/patches /sf/app/src/patches

# install composer dependencies (autoloader MUST be generated later!)
RUN composer install -n --no-dev --no-cache --no-ansi --no-autoloader --no-scripts --prefer-dist

# unset default image entrypoint
ENTRYPOINT []


FROM app_base AS app_prod

ENV APP_ENV=prod

# copy application sources into image (completely)
COPY src /sf/app/src

RUN set -x \
    && echo -e "\nopcache.preload=/sf/app/src/config/preload.php\nopcache.preload_user=root\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\nopcache.validate_timestamps=0\n" >> \
        ${PHP_INI_DIR}/conf.d/docker-php-ext-opcache.ini \
    && echo -e "\nrealpath_cache_size=4096K\nrealpath_cache_ttl=600\n" >> \
        ${PHP_INI_DIR}/php.ini \
    # generate composer autoloader and trigger scripts
    && composer dump-autoload -n --optimize --apcu \
    && composer run-script --no-dev post-install-cmd \
    # "fix" composer issue "Cannot create cache directory /tmp/composer/cache/..." for docker-compose usage
    && chmod -R 777 ${COMPOSER_HOME}/cache


# Dev image
FROM app_base AS app_dev

RUN composer install -n --no-cache --no-scripts

# copy application sources into image (completely)
COPY src /sf/app/src

RUN set -x \
    && composer run-script post-install-cmd \
    && chmod -R 777 ${COMPOSER_HOME}/cache

WORKDIR /sf/app/src

# Dev image with xdebug
FROM app_dev AS app_dev_xdebug

ENV XDEBUG_MODE=off
RUN set -x && install-php-extensions xdebug
