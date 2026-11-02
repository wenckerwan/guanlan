# syntax=docker/dockerfile:1
# API 镜像：alpine 多阶段构建，编译工具链只留在构建阶段
#
# 优化前（单阶段 Debian 基础镜像）镜像体积 980MB，其中约 450MB 是 php:8.3-cli 基础镜像、
# 约 138MB 是 apt 安装的 git/unzip/*-dev 与残留编译工具链。
# 优化后约 270MB，运行时行为与原镜像一致（PHP 8.3 / swoole 6.2 / 相同的 CMD）。

# ========== 构建阶段：只在这里装编译器和 -dev 头文件 ==========
FROM php:8.3-cli-alpine AS builder

RUN apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS \
      brotli-dev \
      libzip-dev \
      libxml2-dev \
      oniguruma-dev \
      openssl-dev \
    && docker-php-ext-install -j"$(nproc)" zip pdo_mysql dom mbstring \
    && pecl install swoole \
    && docker-php-ext-enable swoole

# ========== 运行阶段：不带任何编译工具 ==========
FROM php:8.3-cli-alpine

# 只装运行时共享库（无 -dev 头文件）
# libstdc++ 必装：swoole 是 C++ 扩展，alpine 基础镜像默认不含该库；
# 缺失时 `php -m` 仍会列出 swoole，但启动报
# "Error loading shared library libstdc++.so.6"，属易漏项。
RUN apk add --no-cache brotli libzip libxml2 oniguruma libstdc++

# 复用构建阶段编译好的扩展与启用配置（含基础镜像自带的 opcache / sodium 等）
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/guanlan

# Composer 官方源包含当前稳定依赖；旧镜像可能缺少稳定版本。
RUN composer config -g repo.packagist composer https://repo.packagist.org

# 已编译 zip 扩展，composer 可直接解包，无需 unzip 二进制
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

COPY apps/api/. .
COPY storage/dataset ./storage/dataset
COPY storage/dataset-manifest.json ./storage/dataset-manifest.json
# 注：/opt/guanlan/storage 在生产被 compose 卷 ./storage 覆盖（:ro）。史纲数据集 history.v1.json 由部署方放入仓库根 storage/history/（随卷进入容器），故此处不 COPY。
COPY VERSION ./
RUN mkdir -p runtime

EXPOSE 9501
# Dataset imports are explicit maintenance operations, never repeated on restart.
CMD ["sh", "-c", "php bin/verify-dataset.php && until php bin/hyperf.php migrate --force; do echo 'waiting for mysql...'; sleep 3; done && php bin/hyperf.php start"]
