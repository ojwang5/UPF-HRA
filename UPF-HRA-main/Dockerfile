FROM php:8.2-cli

# Required extensions: PDO SQLite (database) and mbstring (email headers).
# sqlite3/pdo_sqlite are bundled in the official image, so only install if missing.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && (php -m | grep -qi '^pdo_sqlite$' || docker-php-ext-install pdo_sqlite) \
    && (php -m | grep -qi '^mbstring$'    || docker-php-ext-install mbstring) \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . .

RUN mkdir -p data public/uploads/photos \
    && chown -R www-data:www-data data public/uploads

USER www-data

ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 5000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:5000/") === false ? 1 : 0);'

CMD ["php", "-S", "0.0.0.0:5000", "-t", "public", "public/router.php"]
