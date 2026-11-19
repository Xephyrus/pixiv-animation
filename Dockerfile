FROM php:8.4-cli

# 官方镜像已内置 pdo_sqlite / sqlite3 / curl / openssl，只需补 zip 扩展
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . .

RUN mkdir -p data downloads logs \
    && cp config.env.example config.env

EXPOSE 5400

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:5400/api/health') === false ? 1 : 0);"

CMD ["php", "-S", "0.0.0.0:5400", "router.php"]
