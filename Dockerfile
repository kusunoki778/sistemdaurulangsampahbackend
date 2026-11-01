# Livora API Backend — Laravel 13 di PHP 8.3 (untuk Render Web Service, runtime Docker)
FROM php:8.3-cli-alpine

# Ekstensi PHP yang dibutuhkan Laravel + MySQL (SSL CA dibaca dari file repo)
RUN docker-php-ext-install pdo_mysql

# Composer dari image resmi
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependency dulu supaya bisa di-cache sebagai layer Docker
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

# Sisa kode aplikasi
COPY . .

# Render menyuntikkan PORT; server.php Laravel dipakai php artisan serve
CMD ["sh", "-c", "php artisan storage:link || true; php artisan migrate --force; exec php artisan serve --host 0.0.0.0 --port ${PORT:-10000}"]
