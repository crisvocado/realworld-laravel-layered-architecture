FROM composer:2.7 AS composer

FROM php:8.2-cli

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN rm -rf /var/lib/apt/lists/* && \
    apt-get -o Acquire::Retries=3 -o Acquire::http::No-Cache=true update && \
    apt-get -o Acquire::Retries=3 install -y --no-install-recommends \
        build-essential \
        git \
        unzip \
        zip \
        libpq-dev \
        libzip-dev && \
    docker-php-ext-install -j$(nproc) bcmath pdo_pgsql zip && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /workspace

COPY composer.json composer.lock ./
