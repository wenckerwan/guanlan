FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends tzdata curl \
    && rm -rf /var/lib/apt/lists/* \
    && ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY src/ ./src/
COPY bridge/ ./bridge/
COPY pipeline.py ./
RUN mkdir -p data/raw data/out logs

ENV PYTHONUNBUFFERED=1 TZ=Asia/Shanghai
CMD ["python", "pipeline.py"]
