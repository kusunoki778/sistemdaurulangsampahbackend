# Deploy Backend Livora ke Render

Repo: `sistemdaurulangsampahbackend`

Service: `livora-backend`

URL: `https://livora-backend-txpl.onrender.com`

## Perintah Render

Build:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Start:

```bash
php artisan storage:link || true && php artisan migrate --force && php artisan serve --host 0.0.0.0 --port $PORT
```

Health check: `/api/health`

Konfigurasi ini juga ada di `render.yaml`. Jangan memakai `php artisan config:cache` karena `config/cors.php` memakai closure yang tidak bisa diserialisasi.

## Env wajib

| Variabel | Nilai | Catatan |
| --- | --- | --- |
| `APP_NAME` | `Livora` | |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_KEY` | rahasia | Pakai yang sudah ada, jangan diganti setelah ada data |
| `APP_URL` | `https://livora-backend-txpl.onrender.com` | |
| `LOG_CHANNEL` | `stderr` | Supaya log tampil di dashboard Render |
| `LOG_LEVEL` | `error` | |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` | host Aiven | Rahasia |
| `DB_PORT` | port Aiven | Rahasia |
| `DB_DATABASE` | `defaultdb` | Sesuaikan dengan Aiven |
| `DB_USERNAME` | `avnadmin` | |
| `DB_PASSWORD` | rahasia | |
| `MYSQL_ATTR_SSL_CA` | `/opt/render/project/src/database/certs/aiven-mysql-ca.pem` | Certificate harus ikut repo |
| `SESSION_DRIVER` | `database` | |
| `CACHE_STORE` | `database` | |
| `QUEUE_CONNECTION` | `database` | |
| `FILESYSTEM_DISK` | `public` | |
| `CORS_ALLOWED_ORIGINS` | `https://sistemdaurulangsampahweb.vercel.app` | Boleh lebih dari satu, dipisah koma |
| `CLOUDINARY_CLOUD_NAME` | rahasia | Upload gambar admin dan avatar |
| `CLOUDINARY_API_KEY` | rahasia | |
| `CLOUDINARY_API_SECRET` | rahasia | |
| `GEMINI_API_KEY` | rahasia | Chatbot |
| `MAIL_MAILER` | `smtp` | |
| `MAIL_HOST` | `smtp.gmail.com` | |
| `MAIL_PORT` | `587` | |
| `MAIL_USERNAME` | `livora.system@gmail.com` | |
| `MAIL_PASSWORD` | rahasia | App password Gmail |
| `MAIL_FROM_ADDRESS` | `livora.system@gmail.com` | |
| `MAIL_FROM_NAME` | `Livora` | |

Semua nilai rahasia diisi lewat dashboard Render, bukan di repo.

## Env opsional

| Variabel | Nilai | Catatan |
| --- | --- | --- |
| `APP_LOCALE` | `id` | |
| `APP_FALLBACK_LOCALE` | `en` | |
| `APP_FAKER_LOCALE` | `id_ID` | |
| `SESSION_LIFETIME` | `120` | |
| `SESSION_SECURE_COOKIE` | `true` | Karena Render memakai HTTPS |
| `TRUSTED_PROXIES` | `*` | Agar IP klien asli terbaca |
| `MAIL_SCHEME` | `null` | Port 587 memakai STARTTLS otomatis |
| `SANCTUM_EXPIRATION_MINUTES` | `10080` | Token login berlaku 7 hari |
| `FRONTEND_URL` | domain Vercel | Cadangan bila CORS_ALLOWED_ORIGINS kosong |

## Fitur yang belum lengkap tanpa env tambahan

| Variabel | Dampak bila kosong |
| --- | --- |
| `GOOGLE_CLIENT_ID` | Login Google dari aplikasi mobile gagal diverifikasi |
| `FCM_SERVICE_ACCOUNT_JSON` | Notifikasi push ke HP tidak terkirim |
| `XENDIT_SECRET_KEY` dan `XENDIT_CALLBACK_TOKEN` | Pencairan koin ke e-wallet tidak bisa diproses nyata |
| `SANDBOX_PAYOUT` | Bila `false` dan Xendit kosong, pencairan ditolak. Set `true` hanya untuk demo |

`MIDTRANS_CLIENT_KEY`, `MIDTRANS_SERVER_KEY`, dan `MIDTRANS_IS_SANDBOX` sudah tidak dipakai. Pencairan sekarang memakai Xendit.

Untuk FCM, isi `FCM_SERVICE_ACCOUNT_JSON` dengan path relatif dari root backend, misalnya `storage/app/firebase/fcm-service-account.json`. Berkas JSON itu tidak boleh masuk repo. Di Render, pasang sebagai secret file lalu sesuaikan path-nya.

## Urutan deploy

1. Pastikan `.env` tidak ikut ter-commit.
2. Isi seluruh env di dashboard Render.
3. Deploy.
4. Cek `GET /api/health` sampai `database: connected`.
5. Cek `GET /` mengembalikan JSON API.
6. Uji login admin, upload gambar, chatbot, dan pencairan.

## Catatan

- Render free akan tidur setelah tidak ada trafik. Request pertama bisa lambat.
- Disk Render bersifat sementara. Upload lokal bisa hilang saat redeploy. Cloudinary adalah jalur utama untuk gambar.
- `APP_KEY` dipakai untuk enkripsi sesi dan data terenkripsi. Menggantinya membuat sesi lama tidak valid.
- Jangan pernah commit `.env`, service account FCM, atau kunci pembayaran.
- Bila email Gmail gagal terkirim dari Render, cek pembatasan port SMTP. Alternatifnya memakai Resend atau Postmark lewat API.
