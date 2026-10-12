#!/bin/sh
set -e

# Directories the application writes to at runtime:
#   /var/www/html/data                  → SQLite database (+ WAL/SHM)
#   /var/www/html/public/uploads/photos → personnel photos
mkdir -p /var/www/html/data /var/www/html/public/uploads/photos

# A freshly attached Docker volume may be owned by root — make sure the Apache
# user (www-data) can read/write the database and uploads.
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data /var/www/html/data /var/www/html/public/uploads 2>/dev/null || true
fi

# Hand off to the base image entrypoint, preserving its behaviour.
exec docker-php-entrypoint "$@"
