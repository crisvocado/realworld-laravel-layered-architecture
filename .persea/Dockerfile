FROM php:8.2-cli

WORKDIR /workspace

RUN rm -rf /var/lib/apt/lists/* && \
    apt-get -o Acquire::Retries=3 -o Acquire::http::No-Cache=true update && \
    apt-get -o Acquire::Retries=3 install -y --no-install-recommends \
        build-essential \
        git \
        unzip \
        zip \
        libpq-dev \
        libzip-dev && \
    docker-php-ext-install bcmath pdo_pgsql pcntl zip && \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8.6 /usr/bin/composer /usr/bin/composer
