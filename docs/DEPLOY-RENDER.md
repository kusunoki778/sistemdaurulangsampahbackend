# Deploy Backend Livora ke Render

Repo: `sistemdaurulangsampahbackend`

Service: `livora-backend`

URL: `https://livora-backend-txpl.onrender.com`

## Perintah Render

Runtime: Docker

Dockerfile: `./Dockerfile`

Health check: `/api/health`

Image Docker menjalankan `composer install`, lalu start lewat `CMD` di `Dockerfile`. Tidak ada `buildCommand` atau `startCommand` terpisah di `render.yaml`.

Dockerfile memasang GD dengan freetype, jpeg, png, dan webp. Ini yang membuat captcha admin tampil sebagai gambar, bukan soal aritmetika. Build juga memeriksa `imagettftext` dan gagal kalau GD tidak aktif.

Jangan memakai `php artisan config:cache` karena `config/cors.php` memakai closure yang tidak bisa diserialisasi.

## Urutan setup

1. Buat Web Service dari repo backend dengan runtime Docker, atau hubungkan repo lewat Blueprint `render.yaml`. Jangan pilih runtime PHP native karena image tersebut tidak menyertakan GD.
2. Isi env inti dan database Aiven di dashboard Render.
3. Upload service account FCM sebagai Secret File.
4. Isi env fitur: Cloudinary, Gemini, Google, email, dan Xendit.
5. Deploy.
6. Buka `GET /api/health` sampai database berstatus `connected`.
7. Buka `GET /` dan pastikan membalas JSON API Livora.

Kalau memakai Blueprint, env yang bertanda `sync: false` harus diisi manual. Nilai lain sudah diisi otomatis oleh `render.yaml`.

### Service lama dengan runtime PHP native

Kalau service `livora-backend` sebelumnya dibuat dengan runtime PHP native, ubah runtime ke Docker lewat dashboard Render. Jika Render tidak mengizinkan perubahan runtime di tempat, buat service Docker baru dengan repo dan env yang sama, lalu hapus service lama. Sesuaikan `APP_URL` dan `CORS_ALLOWED_ORIGINS` bila URL berubah.

## Env inti

| Variabel | Nilai | Catatan |
| --- | --- | --- |
| `APP_NAME` | `Livora` | |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_KEY` | salin dari `backend/.env` lokal | Jangan diganti setelah database produksi berisi data |
| `APP_URL` | `https://livora-backend-txpl.onrender.com` | Sesuaikan dengan URL service Render |
| `APP_LOCALE` | `id` | |
| `APP_FALLBACK_LOCALE` | `en` | |
| `APP_FAKER_LOCALE` | `id_ID` | |
| `LOG_CHANNEL` | `stderr` | Supaya log tampil di dashboard Render |
| `LOG_LEVEL` | `error` | |
| `TRUSTED_PROXIES` | `*` | Agar IP klien asli terbaca |
| `SESSION_DRIVER` | `database` | |
| `SESSION_LIFETIME` | `120` | |
| `SESSION_SECURE_COOKIE` | `true` | Render memakai HTTPS |
| `CACHE_STORE` | `database` | |
| `QUEUE_CONNECTION` | `database` | Tidak ada job antrean di kode saat ini, jadi belum perlu worker |
| `FILESYSTEM_DISK` | `public` | Gambar utama tetap lewat Cloudinary |
| `FRONTEND_URL` | `https://sistemdaurulangsampahweb.vercel.app` | Cadangan bila CORS kosong |
| `CORS_ALLOWED_ORIGINS` | `https://sistemdaurulangsampahweb.vercel.app` | Boleh lebih dari satu, dipisah koma |
| `SANCTUM_EXPIRATION_MINUTES` | `10080` | Token login berlaku 7 hari |

`APP_KEY` harus sama dengan yang dipakai database produksi. Kalau database masih kosong dan baru dibuat, buat kunci baru dengan:

```bash
php artisan key:generate --show
```

Salin hasilnya ke Render, termasuk awalan `base64:`.

## Database Aiven

| Variabel | Nilai | Catatan |
| --- | --- | --- |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` | host Aiven | Ambil dari dashboard Aiven |
| `DB_PORT` | port Aiven | Biasanya `3306` atau port khusus Aiven |
| `DB_DATABASE` | nama database Aiven | Sering `defaultdb`, sesuaikan |
| `DB_USERNAME` | user Aiven | Sering `avnadmin`, sesuaikan |
| `DB_PASSWORD` | sandi Aiven | Rahasia |
| `MYSQL_ATTR_SSL_CA` | `/opt/render/project/src/database/certs/aiven-mysql-ca.pem` | Sertifikat sudah ada di repo |

Jangan memakai nilai lokal `127.0.0.1`, `root`, atau `livora_p3` di Render.

## Cloudinary

Dipakai untuk upload gambar admin dan avatar.

| Variabel | Nilai |
| --- | --- |
| `CLOUDINARY_CLOUD_NAME` | `drmfgh6zr` |
| `CLOUDINARY_API_KEY` | salin dari `backend/.env` |
| `CLOUDINARY_API_SECRET` | salin dari `backend/.env` |

Folder opsional, sudah punya nilai default:

| Variabel | Default |
| --- | --- |
| `CLOUDINARY_FOLDER_SAMPAH` | `livora/jenis-sampah` |
| `CLOUDINARY_FOLDER_REWARD` | `livora/rewards` |
| `CLOUDINARY_FOLDER_ARTIKEL` | `livora/artikels` |
| `CLOUDINARY_FOLDER_AVATAR` | `livora/avatar` |

## Gemini

| Variabel | Nilai | Catatan |
| --- | --- | --- |
| `GEMINI_API_KEY` | salin dari `backend/.env` | Chatbot memakai FAQ lebih dulu, Gemini untuk pertanyaan yang tidak dikenali |

Kalau kunci kosong atau gagal, chatbot tetap menjawab lewat FAQ dan balasan umum.

## Login Google

| Variabel | Nilai | Catatan |
| --- | --- | --- |
| `GOOGLE_CLIENT_ID` | `573438691498-4du7d2vk7503qc5k8r2ud698ed933s48.apps.googleusercontent.com` | Web Client ID Firebase, bukan rahasia |

Ambil dari Firebase Console > Authentication > Google > Web SDK configuration. Tanpa ini, login Google dari aplikasi mobile gagal diverifikasi.

## Notifikasi HP (FCM)

| Variabel | Nilai | Catatan |
| --- | --- | --- |
| `FCM_SERVICE_ACCOUNT_JSON` | `/etc/secrets/fcm-service-account.json` | Isi dengan path, bukan isi JSON |

Langkah memasang:

1. Firebase Console > Project settings > Service accounts > Generate new private key.
2. Rename berkas menjadi `fcm-service-account.json`.
3. Di Render, buka Environment > Secret Files > Add Secret File.
4. Nama berkas: `fcm-service-account.json`. Isi: seluruh isi berkas JSON.
5. Pastikan env `FCM_SERVICE_ACCOUNT_JSON` berisi `/etc/secrets/fcm-service-account.json`.
6. Deploy ulang, lalu uji dengan mengirim notifikasi ke akun yang sudah login di HP.

Jangan menempel isi JSON ke kolom env. Jangan commit berkas JSON ke repo. Kode menerima path relatif dari root backend dan path absolut seperti Secret File Render.

## Email

| Variabel | Nilai | Catatan |
| --- | --- | --- |
| `MAIL_MAILER` | `smtp` | |
| `MAIL_HOST` | `smtp.gmail.com` | |
| `MAIL_PORT` | `587` | STARTTLS |
| `MAIL_SCHEME` | `null` | Biarkan kosong atau `null` |
| `MAIL_USERNAME` | `livora.system@gmail.com` | |
| `MAIL_PASSWORD` | app password Gmail | Tanpa spasi |
| `MAIL_FROM_ADDRESS` | `livora.system@gmail.com` | |
| `MAIL_FROM_NAME` | `Livora` | |

Gmail memerlukan verifikasi 2 langkah dan app password. Kalau Render menolak koneksi SMTP, alternatifnya memakai Resend atau Postmark lewat API.

## Pencairan Xendit

| Variabel | Nilai | Catatan |
| --- | --- | --- |
| `XENDIT_SECRET_KEY` | salin dari `backend/.env` | Key dengan izin money-out |
| `XENDIT_CALLBACK_TOKEN` | salin dari `backend/.env` | Harus sama dengan token di dashboard Xendit |
| `XENDIT_API_VERSION` | `2025-09-01` | Default |
| `XENDIT_SOURCE_OF_FUND` | `BUSINESS_REVENUE` | Default |
| `XENDIT_PURPOSE_CODE` | `OTHER` | Default |
| `XENDIT_MINIMUM_RUPIAH` | `10000` | Default |
| `SANDBOX_PAYOUT` | `false` | Set `true` hanya untuk mode demo tanpa Xendit |

Callback URL di dashboard Xendit:

```text
https://livora-backend-txpl.onrender.com/api/xendit/webhook
```

Header `x-callback-token` dari Xendit harus cocok dengan `XENDIT_CALLBACK_TOKEN`. Kalau URL Render berbeda, sesuaikan callback URL.

## Captcha admin

Login admin memakai centang "Saya bukan robot" dan captcha gambar lima karakter. Endpoint `GET /api/auth/captcha` mengembalikan `token` dan `gambar` berupa data URI PNG.

Captcha gambar membutuhkan ekstensi GD dengan freetype. Dockerfile sudah memasangnya, dan build akan gagal kalau `imagettftext` tidak tersedia. Kalau GD tidak ada, service memakai soal aritmetika sebagai cadangan, tetapi itu bukan konfigurasi yang diharapkan di produksi.

## Turnstile opsional

Selama dua nilai ini kosong, login admin memakai captcha gambar lokal.

| Variabel | Nilai |
| --- | --- |
| `TURNSTILE_SITE_KEY` | kosong atau site key Cloudflare |
| `TURNSTILE_SECRET_KEY` | kosong atau secret key Cloudflare |

## Daftar env untuk disalin

Ganti semua `<...>` dengan nilai dari `backend/.env` lokal atau dashboard layanan.

```dotenv
APP_NAME=Livora
APP_ENV=production
APP_DEBUG=false
APP_KEY=<APP_KEY>
APP_URL=https://livora-backend-txpl.onrender.com
APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=id_ID
LOG_CHANNEL=stderr
LOG_LEVEL=error
TRUSTED_PROXIES=*
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=public
FRONTEND_URL=https://sistemdaurulangsampahweb.vercel.app
CORS_ALLOWED_ORIGINS=https://sistemdaurulangsampahweb.vercel.app
SANCTUM_EXPIRATION_MINUTES=10080

DB_CONNECTION=mysql
DB_HOST=<host Aiven>
DB_PORT=<port Aiven>
DB_DATABASE=<nama database Aiven>
DB_USERNAME=<user Aiven>
DB_PASSWORD=<sandi Aiven>
MYSQL_ATTR_SSL_CA=/opt/render/project/src/database/certs/aiven-mysql-ca.pem

CLOUDINARY_CLOUD_NAME=drmfgh6zr
CLOUDINARY_API_KEY=<api key Cloudinary>
CLOUDINARY_API_SECRET=<api secret Cloudinary>

GEMINI_API_KEY=<api key Gemini>
GOOGLE_CLIENT_ID=573438691498-4du7d2vk7503qc5k8r2ud698ed933s48.apps.googleusercontent.com
FCM_SERVICE_ACCOUNT_JSON=/etc/secrets/fcm-service-account.json

MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=livora.system@gmail.com
MAIL_PASSWORD=<app password Gmail>
MAIL_FROM_ADDRESS=livora.system@gmail.com
MAIL_FROM_NAME=Livora

XENDIT_SECRET_KEY=<secret key Xendit>
XENDIT_CALLBACK_TOKEN=<callback token Xendit>
XENDIT_API_VERSION=2025-09-01
XENDIT_SOURCE_OF_FUND=BUSINESS_REVENUE
XENDIT_PURPOSE_CODE=OTHER
XENDIT_MINIMUM_RUPIAH=10000
SANDBOX_PAYOUT=false
```

## Catatan

- Render free akan tidur setelah tidak ada trafik. Request pertama bisa lambat.
- Disk Render bersifat sementara. Upload lokal bisa hilang saat redeploy, jadi Cloudinary adalah jalur utama untuk gambar.
- `APP_KEY` dipakai untuk enkripsi sesi dan data terenkripsi. Menggantinya membuat sesi lama tidak valid.
- Jangan pernah commit `.env`, service account FCM, atau kunci pembayaran.
- `MIDTRANS_CLIENT_KEY`, `MIDTRANS_SERVER_KEY`, dan `MIDTRANS_IS_SANDBOX` sudah tidak dipakai. Pencairan memakai Xendit.
