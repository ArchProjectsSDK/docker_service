FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libzip-dev \
    unzip \
    wget \
    && docker-php-ext-install pdo pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

RUN wget https://phar.madelineproto.xyz/madeline.php

COPY . .

RUN mkdir -p downloads

CMD ["php", "bot.php"]