ARG BASE_IMAGE
FROM ${BASE_IMAGE}
WORKDIR /opt/guanlan
COPY apps/api/src/ ./src/
COPY apps/api/config/ ./config/
COPY apps/api/migrations/ ./migrations/
COPY apps/api/seeders/ ./seeders/
COPY apps/api/tests/ ./tests/
COPY VERSION ./
CMD ["sh", "-c", "php bin/verify-dataset.php && until php bin/hyperf.php migrate --force; do echo 'waiting for mysql...'; sleep 3; done && php bin/hyperf.php start"]
