# Livora - Dokumentasi Mekanisme Reward & Simulasi E-Wallet (`reward.md`)

Dokumen ini menjelaskan rancangan sistem **Reward**, jenis-jenis hadiah, mekanisme penukaran koin, dan integrasi payment/payout gateway (Midtrans Sandbox / Simulasi Admin).

---

## 1. Konsep Dasar Reward Livora
Dalam platform Livora, pengguna mengumpulkan **Koin** dari aktivitas ekologis:
- Setor sampah fisik di titik pengumpulan Livora.
- Daily Login & Daily Quiz (kuis lingkungan harian).
- Daily & Weekly Missions (misi harian/mingguan).

Koin yang terkumpul tersimpan di **Wallet Pengguna** dan dapat ditukarkan (*redeem*) dengan berbagai pilihan reward di Katalog Reward.

---

## 2. Kategori & Jenis Reward

### A. Barang Fisik (`barang`)
- **Contoh:** Tumbler Stainless Livora, Totebag Daur Ulang, Bibit Tanaman Organik, Kaos Ramah Lingkungan.
- **Mekanisme Penukaran:**
  1. User mengajukan klaim di aplikasi.
  2. Koin dipotong otomatis.
  3. Status pengajuan menjadi `pending`.
  4. User mengambil barang fisik langsung di titik pengumpulan Livora (atau Admin menyiapkan barang).
  5. Admin mengubah status menjadi `selesai` di Admin CMS.

### B. Voucher Digital (`voucher`)
- **Contoh:** Voucher Diskon 20% Toko Organik, Kode Promo Daur Ulang, Kode Voucher Google Play.
- **Mekanisme Penukaran:**
  1. User mengajukan klaim.
  2. Sistem / Admin menugaskan *unique voucher code* pada record permintaan reward.
  3. Status berubah menjadi `selesai`.
  4. User melihat kode voucher langsung di layar detail transaksi klaim.

### C. Saldo Digital / E-Wallet (`saldo_digital`)
- **Contoh:** Saldo GoPay Rp 10.000 (harga: 1.000 koin), Saldo DANA Rp 25.000 (harga: 2.500 koin), Saldo OVO Rp 50.000.
- **Mekanisme Penukaran & Simulasi:**
  1. User menginputkan **Nomor HP E-Wallet Tujuan** saat klaim.
  2. Koin dipotong otomatis dari Wallet User.
  3. Pengajuan masuk ke daftar tugas Admin CMS dengan status `pending`.
  4. **Untuk Keperluan Demo / Proyek Kuliah:**
     - Admin mengecek nomor HP dan nominal saldo yang diminta.
     - Admin menekan tombol **"Setujui & Tandai Dikirim"** (`POST /api/admin/permintaan-reward/{id}/selesai`).
     - **TIDAK PERLU TRANSFER UANG ASLI ATAU TOPUP MIDTRANS.** Sistem akan mensimulasikan status transaksi menjadi `selesai` secara sukses.
  5. **Untuk Keperluan Production (Live Business):**
     - Admin melakukan payout via Midtrans Iris / Manual Bank Transfer ke E-Wallet tujuan user.

---

## 3. Penanganan Pembatalan & Refund Koin (Rejection Flow)

Jika pengajuan klaim reward **ditolak** oleh Admin (misal: stok barang habis, nomor HP e-wallet tidak valid):
1. Admin menekan tombol **"Tolak Klaim"** (`POST /api/admin/permintaan-reward/{id}/reject`) dengan alasan penolakan.
2. Status pengajuan berubah menjadi `ditolak`.
3. **Refund Otomatis:** Sistem secara otomatis mengembalikan (*refund*) koin ke Wallet User.
4. Catatan mutasi di `transaksi_koin` dibuat dengan tipe `masuk` dan sumber `refund`.

---

## 4. Struktur Data Transaksi Klaim Reward

Setiap klaim reward mencatat data berikut di tabel `permintaan_reward`:
- `kode_klaim`: Unique string (misal: `CLM-20261002-0042`)
- `user_id`: ID Nasabah
- `reward_id`: ID Hadiah yang ditukar
- `koin_ditukar`: Nominal koin yang digunakan
- `status`: `pending` → `diproses` → `selesai` (atau `ditolak`)
- `nomor_tujuan_digital`: Nomor HP untuk e-wallet
- `catatan_admin`: Alasan penolakan / catatan penyerahan admin

---

## 5. Ringkasan Jawaban Integrasi Midtrans
- **Pertanyaan:** Apakah harus deposit uang nyata ke Midtrans untuk demo penukaran saldo GoPay/DANA?
- **Jawaban:** **TIDAK PERLU.** Untuk skenario demo/kuliah, alur klaim saldo disimulasikan melalui *Approval Workflow* Admin di Admin CMS. Midtrans Sandbox dapat dihubungkan sebagai opsi simulasi payment gateway tanpa mengeluarkan saldo riil sama sekali.
