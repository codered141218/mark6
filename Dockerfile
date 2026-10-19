# Mark6 Retail Suite — PHP 8.3 + Apache image for Railway (or any Docker host). Database: Supabase / PostgreSQL.
FROM php:8.3-apache

# PostgreSQL driver, zip (Excel .xlsx exports), opcache; Apache rewrite + headers
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip opcache \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/mark6.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/mark6-entrypoint
RUN chmod +x /usr/local/bin/mark6-entrypoint

WORKDIR /var/www/html
COPY . /var/www/html
RUN mkdir -p storage/backups storage/logs \
 && chown -R www-data:www-data storage \
 && rm -f config/config.php

ENV PORT=8080 APP_TRUST_PROXY=1
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD php -r 'exit(@file_get_contents("http://127.0.0.1:" . getenv("PORT") . "/healthz") === "ok" ? 0 : 1);'
ENTRYPOINT ["mark6-entrypoint"]
CMD ["apache2-foreground"]
