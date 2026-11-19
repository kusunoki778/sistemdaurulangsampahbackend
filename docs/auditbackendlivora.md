# Audit Backend Livora

## 1. Kesimpulan Umum

Backend Livora sudah memiliki fondasi yang cukup baik untuk MVP:

- Laravel 13.
- Sanctum authentication.
- API terstruktur.
- Service layer untuk proses penting.
- Transaction database dan row locking.
- QR setor dan QR reward.
- Wallet koin dan saldo rupiah.
- Integrasi FCM, Google Login, Gemini, Cloudinary, dan Midtrans.
- Beberapa test feature untuk QR, reward, wallet, dan payout.

Namun backend belum sepenuhnya siap untuk production karena masih ada beberapa risiko penting pada:

- Konsistensi transaksi.
- Race condition.
- Status reward dan setoran.
- Mekanisme payout.
- Authorization admin/operator.
- Migration dan test yang belum konsisten.
- Integrasi Midtrans yang tidak cocok untuk payout.

Estimasi kondisi saat ini:

| Area | Nilai |
|---|---:|
| Struktur Laravel | 8/10 |
| Fungsionalitas | 7.5/10 |
| Konsistensi domain | 6.5/10 |
| Stabilitas transaksi | 6.5/10 |
| Security | 7/10 |
| Testing | 6/10 |
| Kesiapan production | 6.5/10 |

Kesimpulan: backend sudah cukup untuk pengembangan MVP, tetapi masih membutuhkan perbaikan P0 sebelum digunakan dengan transaksi nyata.

---

# 2. Arsitektur Backend Saat Ini

Struktur utama backend sudah cukup baik:

- Controller menangani HTTP request.
- Service menangani proses bisnis.
- Model menangani relasi database.
- Middleware menangani autentikasi dan admin.
- Migration mengatur struktur database.
- Feature test menguji beberapa alur utama.

Service yang sudah dipisahkan:

- RewardClaimService
- SetorSettlementService
- MidtransSnapService
- FcmService
- Service autentikasi dan integrasi lainnya.

Pemisahan ini sudah tepat karena proses wallet, reward, dan settlement tidak seharusnya seluruhnya ditulis langsung di controller.

---

# 3. Temuan P0, Wajib Diperbaiki

## 3.1 Race condition saat pembatalan setoran

File:

app/Http/Controllers/Api/AdminTransaksiSetorController.php

Masalah:

1. Admin mengecek setoran masih pending.
2. User dapat mengonfirmasi setoran di waktu yang sama.
3. Settlement menambahkan koin ke wallet.
4. Admin kemudian membatalkan setoran.
5. Status menjadi rejected, tetapi koin sudah masuk ke wallet.

Akibatnya:

- Status transaksi rejected.
- Koin user tetap bertambah.
- Saldo dan riwayat transaksi tidak konsisten.

Solusi:

- Proses pembatalan harus berada dalam DB::transaction().
- Gunakan lockForUpdate().
- Cek ulang status di dalam transaction.
- Pembatalan hanya boleh dilakukan jika status masih pending.
- Jika sudah approved atau completed, pembatalan ditolak.

Status transition harus seperti ini:

pending → approved
pending → rejected
approved → completed

Transaksi yang sudah completed tidak boleh kembali menjadi rejected.

---

## 3.2 Klaim harian dapat memberikan hadiah dua kali

File:

app/Http/Controllers/Api/DailyLoginController.php

Masalah:

Validasi klaim dilakukan sebelum transaction. Jika user mengirim dua request bersamaan, kedua request dapat melewati pengecekan dan sama-sama mendapatkan hadiah.

Solusi:

- Tambahkan unique constraint untuk kombinasi user dan tanggal.
- Gunakan transaction.
- Gunakan lockForUpdate().
- Tangani duplicate exception.
- Jadikan proses klaim idempotent.

Contoh aturan:

Satu user hanya boleh memiliki satu daily_login untuk satu tanggal.

---

## 3.3 Midtrans tidak tepat untuk payout saldo digital (SUDAH DIPERBAIKI)

Temuan ini sudah ditindaklanjuti. Midtrans dihapus total dari codebase, termasuk route-route berikut yang tidak lagi ada:

```text
/api/admin/permintaan-reward/{id}/bayar-sandbox
/api/admin/permintaan-reward/{id}/midtrans-token
/api/admin/permintaan-reward/{id}/midtrans-cek
/api/midtrans/webhook
```

Midtrans Snap cocok untuk menerima pembayaran dari user, bukan untuk mengirim uang ke DANA, GoPay, atau rekening user. Karena Livora membutuhkan arah sebaliknya, provider yang dipakai sekarang adalah **Xendit Payouts v3**.

Route pengganti:

```text
POST /api/permintaan-reward/{id}/xendit-payout
GET  /api/permintaan-reward/{id}/xendit-cek
POST /api/xendit/webhook
```

Mekanisme yang berjalan sekarang:

```text
Member mengajukan pencairan
→ koin di-hold, saldo tidak langsung dipotong
→ backend memvalidasi tujuan dan menghitung nominal dari koin
→ Xendit Payouts dipanggil
→ Xendit mengirim webhook
→ SUCCEEDED: koin dipotong permanen
→ FAILED/REJECTED/REVERSED/EXPIRED/CANCELLED: koin dikembalikan
```

Kebutuhan payout yang diminta audit dan statusnya:

| Kebutuhan | Status | Keterangan |
|---|---|---|
| Provider reference ID | Sudah | Disimpan dari respons Xendit |
| Idempotency key | Sudah | Mencegah payout ganda pada satu permintaan |
| Status pending, processing, success, failed | Sudah | Dipetakan dari status Xendit |
| Webhook signature verification | Sudah | Memakai `x-callback-token` |
| Retry yang aman | Belum | Masuk P2, retry payout otomatis |
| Refund otomatis jika payout gagal | Sudah | Koin dikembalikan ke saldo member |
| Nomor tujuan yang dimasking | Sudah | Hanya 4 digit awal dan 4 digit akhir yang tampil di CMS |

Dua keputusan yang harus dijaga:

1. **Nominal selalu dihitung backend.** Rumusnya `jumlah_koin x KURS_RUPIAH_PER_KOIN` dengan kurs 100. Frontend tidak pernah mengirim nominal rupiah, hanya jumlah koin. Ini menutup celah member mengubah nominal dari sisi klien.
2. **Pemotongan koin terjadi saat `SUCCEEDED`, bukan saat pengajuan.** Saat pengajuan koin hanya di-hold. Kalau payout gagal, koin dilepas kembali tanpa perlu mengurangi lalu menambah saldo, sehingga riwayat ledger tetap bersih. Pembatalan manual oleh admin memakai aksi `batalkan` untuk mengembalikan koin.

Untuk mode demo, saldo internal diberi label "Uji Coba" dan tidak menyebut proses demo sebagai pencairan uang sungguhan.

---

## 3.4 Status transaksi perlu diseragamkan

Ditemukan potensi ketidakkonsistenan antara status di kode dan migration.

Contoh status yang digunakan kode:

menunggu_konfirmasi
disetujui
selesai
dibatalkan
siap_diambil
kedaluwarsa
gagal

Namun beberapa migration lama menggunakan status berbeda seperti:

pending
approved
rejected
diproses
ditolak

Risiko:

- Query tidak menemukan data.
- Status tidak bisa disimpan ke database.
- Filter admin menghasilkan data yang salah.
- Transaksi gagal saat production.

Solusi:

Buat daftar status resmi untuk setiap domain.

### Setoran

pending
approved
rejected
completed
expired

### Klaim reward

pending
processing
ready_to_collect
completed
failed
cancelled
expired

Gunakan enum PHP atau constant terpusat agar status tidak ditulis sebagai string bebas di banyak file.

---

## 3.5 Test suite masih memiliki test Top Up yang tidak relevan

File:

tests/Feature/TopUpMidtransTest.php

Test tersebut masih merujuk ke fitur/model Top Up yang sudah dihapus atau tidak digunakan lagi.

Risiko:

- Test suite gagal meskipun fitur utama benar.
- Agent AI menganggap Top Up masih menjadi fitur aktif.
- Dokumentasi dan implementasi menjadi ambigu.

Solusi:

Pilih salah satu:

1. Hapus test Top Up jika fitur tersebut memang tidak digunakan.
2. Ubah test menjadi test payout/saldo digital.
3. Pisahkan test “saldo simulasi” dan “real payout”.

---

# 4. Temuan P1, Penting Diperbaiki

## 4.1 Lock order pada RewardClaimService belum konsisten

File:

app/Services/RewardClaimService.php

Beberapa proses mengunci resource dengan urutan berbeda:

Reward → Wallet

atau:

Wallet → Reward

Hal ini dapat menyebabkan deadlock ketika dua request berjalan bersamaan.

Solusi:

Tentukan satu urutan locking untuk seluruh proses reward.

Contoh:

PermintaanReward → Reward → Wallet

Gunakan urutan tersebut pada:

- Klaim reward.
- Penyelesaian reward.
- Pembatalan reward.
- Expired reward.
- Konversi saldo.

---

## 4.2 FCM dipanggil di dalam database transaction

Pada proses konversi saldo, notifikasi FCM dipanggil ketika transaction database masih berjalan.

Risiko:

- Transaction menjadi lebih lama.
- Database lock bertahan lebih lama.
- Jika FCM lambat, proses reward ikut lambat.
- Jika FCM gagal, proses bisnis dapat terganggu.

Solusi:

- Commit database terlebih dahulu.
- Kirim notifikasi setelah transaction selesai.
- Lebih baik gunakan queued job.

Contoh:

Commit transaksi
→ Dispatch SendRewardNotificationJob

Notifikasi tidak boleh menentukan berhasil atau gagalnya proses wallet.

---

## 4.3 Authorization admin/operator belum benar-benar diterapkan

Saat ini backend menggunakan middleware admin, tetapi belum terlihat implementasi permission operator yang terpisah.

Jika role operator digunakan, backend harus membatasi akses, bukan hanya menyembunyikan menu CMS.

### Admin

Boleh:

- Mengubah nilai tukar.
- Mengelola reward.
- Mengelola user.
- Mengubah konfigurasi.
- Melihat audit log.

### Operator

Boleh:

- Memproses setoran.
- Memproses reward.
- Menerbitkan QR.
- Membaca pesan kontak.

Operator tidak boleh:

- Mengubah nilai tukar.
- Mengubah role.
- Menghapus user.
- Mengubah konfigurasi payout.
- Menghapus data penting.

RBAC kompleks belum diperlukan. Middleware sederhana seperti berikut sudah cukup:

admin
operator

Tetapi pengecekan wajib dilakukan di backend.

---

## 4.4 Object-level authorization perlu diuji menyeluruh

Setiap endpoint yang menerima ID harus memastikan data tersebut milik user yang sedang login.

Area yang harus diuji:

- Detail transaksi setor.
- Struk transaksi.
- Detail reward claim.
- QR reward.
- Wallet.
- Riwayat koin.
- Data user.
- Klaim reward.

User A tidak boleh dapat mengakses data User B hanya dengan mengganti ID URL.

Gunakan:

- Policy.
- Query berdasarkan user_id.
- Authorization middleware.
- Test IDOR untuk seluruh endpoint.

Contoh aturan:

PermintaanReward::where('user_id', auth()->id())

Jangan hanya menggunakan:

PermintaanReward::findOrFail($id)

untuk endpoint user.

---

## 4.5 Reward direct ID perlu dibatasi

Pada endpoint detail reward, pastikan reward yang dikembalikan:

- Aktif.
- Masih tersedia.
- Boleh dilihat publik.
- Tidak termasuk reward sistem internal.
- Tidak termasuk reward saldo digital jika memang tidak ditampilkan di katalog.

Jangan hanya mengambil:

Reward::findOrFail($id)

Gunakan filter sesuai kebutuhan:

active = true
is_system = false

---

## 4.6 Controller terlalu besar

Beberapa controller menangani terlalu banyak hal:

- CRUD.
- Upload gambar.
- Validasi.
- Perhitungan.
- Status transition.
- Notifikasi.
- Audit log.

Untuk MVP masih dapat diterima, tetapi seiring bertambahnya fitur, controller akan sulit dipelihara.

Prioritas pemisahan:

- Form Request untuk validasi.
- Action/Service untuk proses bisnis.
- Policy untuk authorization.
- Resource untuk response API.
- Enum untuk status dan tipe transaksi.

Tidak perlu langsung melakukan refactor besar. Lakukan bertahap ketika menyentuh fitur terkait.

---

## 4.7 Health check perlu membedakan liveness dan readiness

Endpoint health sebaiknya membedakan:

### Liveness

Aplikasi masih hidup.

HTTP 200

### Readiness

Aplikasi siap menerima request dan database dapat digunakan.

Jika database gagal:

HTTP 503

Jika endpoint database gagal tetapi tetap mengembalikan HTTP 200, monitoring dapat menganggap backend sehat padahal transaksi tidak dapat diproses.

---

# 5. Domain Model yang Harus Diperjelas

## 5.1 Pisahkan jenis reward

Livora sebaiknya memiliki tiga kategori reward:

### Reward fisik

Contoh:

- Tumbler.
- Tas belanja.
- Bibit tanaman.

Alur:

Klaim → koin ditahan → QR pengambilan → user mengambil → selesai

### Reward voucher

Contoh:

- Voucher merchant.
- Voucher digital.

Alur:

Klaim → koin ditahan → voucher diterbitkan → selesai

### Reward saldo digital

Contoh:

- Saldo simulasi.
- Payout ke DANA.
- Payout ke GoPay.
- Payout ke rekening bank.

Untuk saldo simulasi:

Koin → saldo rupiah internal

Untuk payout nyata:

Koin → provider payout → webhook → status akhir

Jangan mencampurkan reward fisik dengan saldo digital dalam satu alur status yang sama tanpa aturan yang jelas.

---

## 5.2 Koin harus menggunakan ledger

Saldo wallet tidak cukup hanya berupa angka.

Setiap perubahan koin harus memiliki riwayat:

- User.
- Jumlah.
- Tipe transaksi.
- Sumber transaksi.
- Reference ID.
- Saldo sebelum.
- Saldo sesudah.
- Waktu.
- Keterangan.

Contoh sumber:

daily_login
setor_sampah
reward_claim
reward_refund
admin_adjustment
expired

Tidak boleh ada perubahan saldo manual tanpa ledger dan audit log.

---

## 5.3 Semua proses finansial harus idempotent

Proses berikut wajib aman jika request dikirim dua kali:

- Konfirmasi setoran.
- Klaim reward.
- Penyelesaian reward.
- Refund reward.
- Webhook payout.
- Daily login.
- Penambahan saldo.

Gunakan kombinasi:

- Unique reference.
- Idempotency key.
- Status check.
- Database lock.
- Unique constraint.
- Transaction.

---

# 6. Security Audit Berdasarkan OWASP

Acuan security dapat menggunakan OWASP Top 10 dan OWASP API Security Top 10.

## A01, Broken Access Control

Status: Perlu diperkuat.

Periksa:

- User hanya dapat melihat data sendiri.
- Operator tidak dapat mengakses konfigurasi.
- Admin route selalu memakai authorization backend.
- ID URL tidak dapat digunakan untuk mengakses data user lain.
- QR hanya dapat dipakai oleh pemilik yang sesuai.

## A02, Cryptographic Failures

Status: Cukup, tetap perlu audit.

Pastikan:

- Password menggunakan hashing Laravel.
- Token tidak masuk log.
- Secret tidak disimpan di repository.
- Nomor rekening dan e-wallet dimasking.
- HTTPS digunakan di production.
- Data sensitif tidak dikirim ke frontend tanpa kebutuhan.

## A03, Injection

Status: Cukup baik, perlu konsistensi.

Pastikan:

- Validasi semua request.
- Jangan membuat query SQL dari input mentah.
- Sanitasi rich text artikel.
- Validasi file upload.
- Batasi prompt dan output chat AI.

## A04, Insecure Design

Status: Perlu diperbaiki.

Risiko utama:

- Payout memakai konsep Midtrans yang tidak sesuai.
- Status reward belum seragam.
- Race condition pada transaksi.
- Belum semua proses finansial idempotent.

## A05, Security Misconfiguration

Status: Perlu checklist production.

Pastikan:

- APP_DEBUG=false.
- CORS hanya mengizinkan domain resmi.
- Error detail tidak bocor.
- Admin route tidak terbuka tanpa Sanctum.
- Secret berada di environment variable.
- Security headers tersedia.

## A06, Vulnerable Components

Status: Belum final sebelum audit dependency.

Jalankan pada environment PHP yang sesuai:

composer audit
npm audit

Selain itu, lakukan secret scanning sebelum deployment.

## A07, Authentication Failures

Status: Cukup baik, perlu peningkatan.

Sudah ada:

- Sanctum.
- Rate limit login.
- Google authentication.
- Forgot password.

Tambahan yang disarankan:

- Revoke token saat password berubah.
- Logout mencabut token.
- Session/token expiry.
- MFA khusus admin ketika aplikasi mulai digunakan publik.

## A08, Software and Data Integrity Failures

Status: Perlu diperkuat.

Pastikan:

- Webhook memverifikasi signature.
- Webhook payout idempotent.
- File upload tidak dapat menjalankan script.
- Hanya format gambar yang diizinkan.
- Deployment menggunakan dependency lock file.

## A09 - Security Logging and Monitoring Failures

Status: **Sebagian besar selesai.** Infrastruktur audit log sudah ada dan halaman pembacanya sudah tersedia di CMS.

Yang sudah dikerjakan:

- `app/Services/AuditLogService.php` mencatat event ke tabel `audit_logs`.
- `app/Http/Middleware/AuditAdminAction.php` (alias `audit.admin`) mencatat otomatis setiap `POST`, `PUT`, `PATCH`, dan `DELETE` di grup route admin, beserta status code hasilnya.
- `AuthController` mencatat login berhasil, login gagal, login diblokir, captcha salah, login akun nonaktif, logout, perubahan kata sandi, dan reset kata sandi.
- `WalletObserver` mencatat setiap perubahan saldo (`wallet_change`).
- Endpoint baca log sengaja tidak disediakan. Data `audit_logs` dibaca langsung dari database oleh pengelola sistem, sehingga tidak ada jalur API baru yang bisa disalahgunakan untuk memanen data aktivitas admin.

Event yang diminta audit dan statusnya:

| Event yang diminta | Status |
|---|---|
| Login admin | Sudah dicatat |
| Perubahan nilai tukar | Sudah tercatat lewat `admin_action` |
| Perubahan stok reward | Sudah tercatat lewat `admin_action` |
| Persetujuan setoran | Sudah tercatat lewat `admin_action` |
| Pembatalan setoran | Sudah tercatat lewat `admin_action` |
| Penyelesaian reward | Sudah tercatat lewat `admin_action` |
| Refund koin | Sudah tercatat lewat `wallet_change` dan `admin_action` |
| Perubahan role | Sudah tercatat lewat `admin_action` |
| Percobaan payout gagal | Tercatat lewat `admin_action`, tetapi belum ada event khusus payout |

Dua alasan desain yang perlu dijaga saat mengubah kode:

1. **Audit tidak boleh menggagalkan aksi utama.** `catat()` menelan seluruh `Throwable` dan hanya menulis ke log aplikasi. Login atau perubahan saldo harus tetap berhasil walaupun penulisan audit gagal. Jangan mengubahnya menjadi exception yang dilempar.
2. **Data sensitif tidak boleh tersimpan mentah.** `bersihkan()` mengganti nilai pada kunci yang mengandung `password`, `token`, `secret`, `authorization`, `api_key`, `apikey`, `captcha`, atau `private_key` menjadi `[disamarkan]`. Email disamarkan menjadi `a****@domain`. Nomor telepon disamarkan menjadi `********7890`. Kedalaman data dibatasi 4 level supaya payload besar tidak membengkak.

Sisa pekerjaan:

- Tambahkan event khusus untuk percobaan payout gagal supaya bisa difilter tanpa bergantung pada `description`.
- Tambahkan event khusus saat role diubah setelah pemisahan role admin dan operator dikerjakan.
- Pertimbangkan retensi atau arsip log karena tabel akan terus bertambah.

Bukti uji: `tests/Feature/AuditLogTest.php` dengan 7 test lulus, mencakup hak akses (admin boleh, member `403`, tamu `401`), filter event dan pencarian, ringkasan statistik, penyamaran data sensitif, dan pencatatan otomatis aksi admin.

Halaman CMS untuk log tidak dibuat. Keputusan ini diambil setelah evaluasi: setiap halaman verifikasi sudah menampilkan riwayat transaksinya sendiri, jadi tabel log penuh tidak menambah nilai bagi admin harian dan justru menambah permukaan data sensitif. `audit_logs` tetap terisi dan bisa ditelusuri lewat database.

## A10, Mishandling of Exceptional Conditions

Status: Perlu diperbaiki.

Pastikan:

- Error API memiliki format JSON konsisten.
- Error provider tidak langsung ditampilkan ke user.
- Transaction rollback ketika proses gagal.
- Tidak ada saldo yang berubah jika proses eksternal gagal.
- Health check mengembalikan status yang benar.

---

# 7. Struktur Alur Backend yang Disarankan

## Setor sampah

Admin membuat transaksi
→ Sistem menghitung koin di backend
→ QR diterbitkan
→ User scan QR
→ Backend validasi ownership dan expiry
→ User konfirmasi
→ Transaction lock
→ Wallet bertambah
→ Ledger dibuat
→ Status completed
→ Notifikasi dikirim

## Reward fisik

User memilih reward
→ Backend lock reward dan wallet
→ Validasi stok dan koin
→ Koin di-hold
→ Permintaan reward dibuat
→ Admin memproses
→ QR pengambilan diterbitkan
→ User scan QR
→ Reward selesai
→ Koin dipotong permanen

## Saldo simulasi

User memilih konversi
→ Wallet dikunci
→ Koin dikurangi
→ Saldo rupiah internal bertambah
→ Ledger dibuat
→ Riwayat reward dibuat
→ Notifikasi dikirim setelah commit

## Payout nyata

User memilih tujuan
→ Backend validasi tujuan
→ Koin di-hold
→ Payout dibuat dengan idempotency key
→ Provider memproses
→ Webhook diterima
→ Signature diverifikasi
→ Status diperbarui
→ Success: koin dipotong
→ Failed: koin dikembalikan

---

# 8. Testing yang Wajib Ditambahkan

## Transaction test

- Setoran tidak dapat dikonfirmasi dua kali.
- Setoran yang sudah selesai tidak dapat dibatalkan.
- Pembatalan bersamaan dengan konfirmasi tidak merusak saldo.
- Reward tidak dapat diklaim melebihi stok.
- Refund hanya terjadi satu kali.
- Daily login tidak dapat diklaim dua kali.

## Authorization test

- User tidak dapat melihat transaksi user lain.
- User tidak dapat mengakses reward claim user lain.
- Operator tidak dapat mengubah nilai tukar.
- Operator tidak dapat mengubah role.
- Guest tidak dapat mengakses endpoint private.
- Admin endpoint tidak dapat dipanggil tanpa Sanctum.

## Webhook test

- Webhook duplikat tidak menggandakan saldo.
- Webhook invalid ditolak.
- Webhook dengan signature salah ditolak.
- Status payout tidak dapat mundur dari completed ke pending.

## Migration test

Jalankan di PHP 8.3:

php artisan migrate:fresh --seed
php artisan route:list
php artisan test

Pastikan database fresh dapat dibuat tanpa error dan semua enum/status cocok dengan kode.

---

# 9. Prioritas Pengerjaan

## P0, Sebelum production

- Perbaiki race condition pembatalan setoran.
- Perbaiki double claim daily login.
- Seragamkan status transaksi.
- Hapus atau ubah test Top Up yang sudah tidak relevan.
- Tentukan saldo simulasi atau payout nyata. **Selesai**: payout nyata memakai Xendit Payouts v3, koin di-hold lalu dipotong hanya saat `SUCCEEDED`.
- Jangan gunakan Midtrans Snap untuk payout. **Selesai**: Midtrans dihapus total dari codebase.
- Pastikan authorization object-level.
- Pastikan seluruh perubahan wallet memiliki ledger.
- Jalankan test dengan PHP 8.3.
- Verifikasi migration fresh install. **Selesai**: ditemukan dan diperbaiki bug kolom `token` pada `2026_11_09_000001_create_fcm_tokens_table.php` (dari `text` menjadi `string(255)`) karena MySQL menolak `BLOB/TEXT` sebagai kunci unik tanpa panjang. `migrate:fresh --seed` sekarang berhasil untuk seluruh 33 migration.

## P1 - Setelah P0

- Seragamkan urutan database locking.
- Pindahkan FCM ke queued job.
- Implementasikan role operator di backend.
- Tambahkan audit log lengkap. **Selesai sebagian besar**: service, middleware, observer, endpoint baca, dan halaman CMS sudah ada. Lihat bagian A09 untuk rincian event dan sisa pekerjaan.
- Tambahkan payout provider abstraction.
- Perbaiki health check.
- Tambahkan policy dan Form Request.
- Tambahkan test concurrency dan webhook.

## P2, Pengembangan lanjutan

- MFA admin.
- Dashboard monitoring transaksi.
- Retry payout otomatis.
- Export laporan.
- Queue monitoring.
- Cache katalog dan statistik.
- Observability menggunakan logging dan error tracking.

---

# 10. Acceptance Criteria Backend

Backend dianggap siap untuk MVP apabila:

- Semua endpoint private memakai Sanctum.
- User hanya dapat mengakses datanya sendiri.
- Admin dan operator memiliki permission berbeda.
- Setoran tidak dapat diproses dua kali.
- Reward tidak dapat diklaim melebihi stok.
- Daily login tidak dapat diklaim dua kali.
- Koin dihitung backend.
- Semua perubahan koin memiliki ledger.
- Semua proses penting memakai transaction.
- Status transaksi sudah seragam.
- QR memiliki expiry dan hanya dapat digunakan sekali.
- Webhook memiliki signature verification dan idempotency.
- Tidak ada payout nyata yang menggunakan Midtrans Snap.
- Error API konsisten.
- Secret tidak masuk repository.
- Migration fresh dapat dijalankan.
- Test suite berjalan di PHP 8.3.
- Audit log mencatat tindakan admin penting.

---

# Kesimpulan Akhir

Backend Livora sudah memiliki fondasi yang baik dan tidak perlu dibangun ulang.

Fokus utama perbaikannya adalah:

Konsistensi transaksi
→ Keamanan authorization
→ Idempotency
→ Status domain
→ Payout yang tepat
→ Testing race condition
→ Audit log

Untuk MVP internal, backend sudah cukup dekat untuk digunakan. Untuk production dengan uang sungguhan, perbaikan P0 wajib diselesaikan terlebih dahulu, terutama race condition, status transaksi, ledger koin, dan mekanisme payout.