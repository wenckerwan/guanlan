FROM hyperf/hyperf:8.3-alpine-v3.19-swoole
WORKDIR /opt/mayuan
# Install the locked runtime and verify both framework and file-session dependencies.
RUN composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && php -r "require 'vendor/autoload.php'; exit(class_exists('Hyperf\\Di\\ClassLoader') ? 0 : 1);" \
    && php -r "require 'vendor/autoload.php'; exit(class_exists('Carbon\\Carbon') ? 0 : 1);" \
    && php -m | grep -q pdo_mysql
COPY . .
ENV MAYUAN_MODE=guanlan
EXPOSE 9501
CMD ["php", "bin/hyperf.php", "start"]
