FROM hyperf/hyperf:8.3-alpine-v3.19-swoole
WORKDIR /opt/mayuan
# 无 composer.lock（install 等价 update）：用国内 Packagist 镜像，并校验关键依赖确实装全，避免网络抖动致 ClassLoader 缺失。
RUN composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && php -r "require 'vendor/autoload.php'; exit(class_exists('Hyperf\\Di\\ClassLoader') ? 0 : 1);" \
    && php -m | grep -q pdo_mysql
COPY . .
ENV MAYUAN_MODE=guanlan
EXPOSE 9501
CMD ["php", "bin/hyperf.php", "start"]
