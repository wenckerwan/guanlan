FROM php:8.3-cli

RUN apt-get update && apt-get install -y git unzip libzip-dev \
    && docker-php-ext-install zip pdo_mysql \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /opt/guanlan
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress
COPY . .
EXPOSE 9501
CMD ["php", "bin/hyperf.php", "start"]
