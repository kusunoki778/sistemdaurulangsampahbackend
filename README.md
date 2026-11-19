# Livora Backend

API Livora berbasis Laravel 13 dan PHP 8.3. Folder ini adalah root repo backend dan tidak membutuhkan folder lain di luar repo.

## Kebutuhan

- PHP 8.3 dengan ekstensi `pdo_mysql`, `gd`, `mbstring`, `openssl`
- Composer
- MySQL 8

## Menjalankan

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Halaman akar `/` mengembalikan status API. Pemeriksaan kesehatan ada di `/api/health`.

## Pengujian

```bash
php artisan test
vendor/bin/pint --test
```

## Struktur penting

- `app/Http/Controllers/Api/`: endpoint aplikasi dan CMS
- `app/Services/`: pembayaran, Cloudinary, FCM, captcha, dan aturan koin
- `app/Console/Commands/CekStatusPayout.php`: pemeriksa status pencairan tiap menit
- `routes/api.php`: seluruh endpoint API
- `database/dumps/`: dump SQL untuk pemulihan, bukan untuk dijalankan otomatis
- `docs/`: audit dan rancangan backend

## Deploy

Konfigurasi Render ada di `render.yaml`. Variabel lingkungan wajib diisi di dashboard, termasuk koneksi MySQL, `APP_KEY`, Cloudinary, FCM, dan kunci pembayaran. Jalankan `php artisan migrate --force` saat deploy.

## Berkas rahasia

`.env`, kunci Firebase, dan kredensial pihak ketiga tidak boleh di-commit. `storage/app/firebase/` dan `vendor/` sudah diabaikan Git.
