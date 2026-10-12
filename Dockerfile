# syntax=docker/dockerfile:1
#
# UPF Human Resource Management System — production image
# Plain PHP 8.2 + Apache (mod_php) + SQLite. No Composer/dependencies.
#
FROM php:8.2-apache

LABEL org.opencontainers.image.title="UPF Human Resource Management System" \
      org.opencontainers.image.description="UPF HRM — PHP 8.2 + Apache + SQLite" \
      org.opencontainers.image.version="2.0"

# ── PHP extensions ───────────────────────────────────────────────────────────
#   pdo_sqlite : SQLite database used by the whole application
#   mbstring   : mb_encode_mimeheader() in the SMTP mailer
#   opcache    : opcode cache for production performance
#   (openssl — needed for SMTP TLS — ships enabled in the base image)
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libonig-dev; \
    if ! php -m | grep -qi '^pdo_sqlite$';      then docker-php-ext-install -j"$(nproc)" pdo_sqlite; fi; \
    if ! php -m | grep -qi '^mbstring$';        then docker-php-ext-install -j"$(nproc)" mbstring;   fi; \
    if ! php -m | grep -qi 'Zend OPcache';      then docker-php-ext-install -j"$(nproc)" opcache;    fi; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /usr/src/php

# ── Apache modules & hardening ───────────────────────────────────────────────
RUN a2enmod rewrite headers \
 && { sed -ri 's/^\s*ServerTokens .*/ServerTokens Prod/'     /etc/apache2/apache2.conf || true; } \
 && { sed -ri 's/^\s*ServerSignature .*/ServerSignature Off/' /etc/apache2/apache2.conf || true; }

# ── PHP runtime configuration ────────────────────────────────────────────────
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-upf.ini

# ── Virtual host: document root is public/ (app internals stay outside) ──────
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# ── Application code ─────────────────────────────────────────────────────────
WORKDIR /var/www/html
COPY includes/ ./includes/
COPY public/   ./public/

# ── Writable runtime dirs (data = SQLite, uploads = personnel photos) ─────────
RUN mkdir -p /var/www/html/data \
             /var/www/html/public/uploads/photos \
             /var/lib/php/sessions \
 && chown -R www-data:www-data /var/www/html/data \
                               /var/www/html/public/uploads \
                               /var/lib/php/sessions

# ── Startup: fix volume ownership, then boot Apache ─────────────────────────
COPY docker/entrypoint.sh /usr/local/bin/upf-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/upf-entrypoint \
 && chmod +x /usr/local/bin/upf-entrypoint

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/login.php") === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/upf-entrypoint"]
CMD ["apache2-foreground"]
