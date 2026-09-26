FROM php:8.3-cli

# 境内加速：将 Debian 源切换到 Aliyun 镜像（trixie 用 deb822 格式，兼容旧 sources.list）
RUN if [ -f /etc/apt/sources.list.d/debian.sources ]; then \
      sed -i 's|deb.debian.org|mirrors.aliyun.com|g; s|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources; \
    fi; \
    if [ -f /etc/apt/sources.list ]; then \
      sed -i 's|deb.debian.org|mirrors.aliyun.com|g; s|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list; \
    fi

RUN apt-get update && apt-get install -y git unzip libzip-dev libssl-dev libbrotli-dev \
    && docker-php-ext-install zip pdo_mysql \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/guanlan

# 境内加速：使用 Aliyun packagist 镜像
RUN composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY . .
RUN mkdir -p runtime

EXPOSE 9501
CMD ["sh", "-c", "php bin/verify-dataset.php && until php bin/hyperf.php migrate --force; do echo 'waiting for mysql...'; sleep 3; done && php bin/hyperf.php db:seed --force && php bin/hyperf.php start"]
