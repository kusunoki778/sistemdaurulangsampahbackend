# Livora API Backend — Laravel 13 di PHP 8.3 (untuk Render Web Service, runtime Docker)
FROM php:8.3-cli-alpine

# Ekstensi PHP yang dibutuhkan Laravel + MySQL + unzip untuk composer
RUN docker-php-ext-install pdo_mysql && apk add --no-cache unzip

# Composer dari image resmi
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependency dulu supaya bisa di-cache sebagai layer Docker.
# --no-scripts: script post-install butuh file artisan yang belum tersedia di layer ini.
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

# Sisa kode aplikasi
COPY . .

# Render menyuntikkan PORT; server.php Laravel dipakai php artisan serve
CMD ["sh", "-c", "php artisan storage:link || true; php artisan migrate --force; exec php artisan serve --host 0.0.0.0 --port ${PORT:-10000}"]
