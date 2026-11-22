# 只替换时政事件导入相关的 PHP 代码，复用现成 api 镜像的运行环境（不重装 composer 依赖）。
# 用于「内容 + 少量后端代码」发布：构建快、内存占用低，适合 4 GB 生产机。
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
WORKDIR /opt/guanlan
COPY apps/api/src/ ./src/
COPY apps/api/seeders/ ./seeders/
COPY apps/api/config/ ./config/
CMD ["sh", "-c", "php bin/verify-dataset.php && until php bin/hyperf.php migrate --force; do echo 'waiting for mysql...'; sleep 3; done && php bin/hyperf.php start"]
