FROM php:7

RUN apt update && apt install libgmp-dev -y && \
    docker-php-ext-install bcmath && docker-php-ext-install gmp && \
    mkdir /data

WORKDIR /data
