FROM composer:2.8 AS composer

FROM php:8.2-cli-bookworm

RUN rm -rf /var/lib/apt/lists/* && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        build-essential \
        git \
        libpq-dev \
        libzip-dev \
        unzip \
        zip && \
    docker-php-ext-install -j$(nproc) \
        bcmath \
        pdo_pgsql \
        zip && \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /workspace
