FROM composer:2.8.5 AS composer_bin

FROM php:8.2-cli

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

RUN rm -rf /var/lib/apt/lists/* && \
    apt-get -o Acquire::Retries=3 -o Acquire::http::No-Cache=true update && \
    apt-get -o Acquire::Retries=3 install -y --no-install-recommends \
        build-essential \
        git \
        unzip \
        zip \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-install bcmath pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /workspace
