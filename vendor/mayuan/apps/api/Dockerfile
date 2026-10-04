FROM hyperf/hyperf:8.3-alpine-v3.19-swoole
WORKDIR /opt/mayuan
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction && php -m | grep -q pdo_mysql
ENV MAYUAN_MODE=guanlan
EXPOSE 9501
CMD ["php", "bin/hyperf.php", "start"]
