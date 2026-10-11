ARG BASE_IMAGE
FROM ${BASE_IMAGE}
WORKDIR /opt/guanlan
# The verified production base supplies unchanged Composer dependencies.
COPY apps/api/src/ ./src/
COPY apps/api/config/ ./config/
COPY apps/api/migrations/ ./migrations/
COPY apps/api/bin/import-practice-bank.php ./bin/import-practice-bank.php
COPY apps/api/resources/private/ ./resources/private/
COPY VERSION ./
CMD ["php", "bin/hyperf.php", "start"]
