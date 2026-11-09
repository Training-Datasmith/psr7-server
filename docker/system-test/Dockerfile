FROM php:7.2.34-cli@sha256:5249d94b4b00db63a0ed53e6528114926110ab5dcb0d0f07cc30d1ea7dbe747e

RUN set -eux; \
    printf 'deb https://archive.debian.org/debian buster main\n' > /etc/apt/sources.list; \
    printf 'deb https://archive.debian.org/debian-security buster/updates main\n' >> /etc/apt/sources.list; \
    printf 'Acquire::Check-Valid-Until::archive.debian.org "false";\n' > /etc/apt/apt.conf.d/99no-check-valid-archive; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        libonig-dev; \
    docker-php-ext-install mbstring; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app
