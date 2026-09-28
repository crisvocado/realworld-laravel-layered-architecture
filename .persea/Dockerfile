FROM composer:2.8.5 AS composer

FROM php:8.2-cli

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN rm -rf /var/lib/apt/lists/* && \
    apt-get -o Acquire::Retries=3 -o Acquire::http::No-Cache=true update && \
    apt-get -o Acquire::Retries=3 install -y --no-install-recommends \
        build-essential \
        git \
        libpq-dev \
        libzip-dev \
        unzip \
        zip && \
    docker-php-ext-install \
        bcmath \
        pdo_pgsql \
        zip && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /workspace
