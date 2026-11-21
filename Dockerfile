# Livora API Backend - Laravel 13 di PHP 8.3 (untuk Render Web Service, runtime Docker)
FROM php:8.3-cli-alpine

# Library runtime + ekstensi PHP yang dibutuhkan Laravel, MySQL, dan captcha.
# GD wajib punya freetype untuk imagettftext, plus jpeg/png/webp untuk gambar.
RUN apk add --no-cache freetype libjpeg-turbo libpng libwebp unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
    && apk del .build-deps

# Gagalkan build kalau GD atau freetype tidak ikut terpasang, supaya captcha
# tidak diam-diam jatuh ke soal aritmetika di produksi.
RUN php -r "if (!extension_loaded('gd') || !function_exists('imagettftext')) { fwrite(STDERR, 'GD atau freetype tidak tersedia'.PHP_EOL); exit(1); }"

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
