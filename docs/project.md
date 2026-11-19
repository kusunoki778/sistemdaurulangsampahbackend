# Livora - Dokumentasi Utama Proyek (Bank Sampah Offline & Edukasi)

## 📌 Ringkasan Proyek
**Livora** adalah platform edukasi lingkungan dan pengelolaan bank sampah terpadu berbasis **Offline Drop-Off (Setor Sampah Langsung)** dengan sistem gamifikasi koin dan penukaran reward.

---

## ⛔ ATURAN EMAS AI CLI (MANDATORY AGENT RULES)
Setiap AI CLI Agent (`agy`, `codex`, `cursor`, `gemini`, `claude`) yang bekerja pada repository ini **WAJIB** mematuhi aturan berikut:

1. ❌ **TIDAK ADA FITUR JEMPUT SAMPAH (NO PICKUP SERVICE):**
   - Dilarang membuat API, controller, tabel, atau UI untuk penjemputan sampah ke rumah user.
   - Hapus/abaikan semua rujukan `jemput_sampah`.

2. ❌ **TIDAK ADA MAPS / GPS TRACKING / LOKASI MITRA:**
   - Dilarang membuat integrasi Google Maps, tracking driver, atau daftar lokasi mitra pihak ketiga.
   - Livora adalah bank sampah terpusat di lokasi pengumpulan resmi (offline).

3. ✅ **SETOR SAMPAH OFFLINE (DIRECT DROP-OFF):**
   - User membawa sampah fisik langsung ke titik pengumpulan Livora (Admin).
   - Admin memeriksa, menimbang, dan memvalidasi sampah secara fisik.
   - Koin otomatis ditambahkan ke Wallet User setelah verifikasi Admin disetujui (`approved`).

4. ✅ **PEMISAHAN PANEL USER DAN PANEL ADMIN:**
   - User biasa: Menggunakan Mobile App (Flutter) & Landing Page Web untuk edukasi/informasi.
   - Admin: Menggunakan Web CMS Admin khusus (akses via `/admin` atau portal login terpisah dengan guard/role admin).

5. ✅ **SIMULASI REWARD / DEMO MODE (PROYEK KULIAH):**
   - Reward berupa barang fisik, voucher digital, dan saldo e-wallet (GoPay/DANA).
   - Tidak memerlukan transfer uang nyata/deposit Midtrans asli untuk demo.
   - Admin memverifikasi klaim reward di CMS → Klik "Selesai" (saldo/voucher disimulasikan).

---

## 🛠️ Technology Stack

### 1. Backend API
- **Framework:** Laravel 13 (PHP 8.3)
- **Autentikasi:** Laravel Sanctum (Token-based Auth)
- **Database:** MySQL
- **Architecture:** RESTful API Architecture

### 2. Website (Landing Page & Admin CMS)
- **Framework:** React + Vite
- **Bahasa:** JavaScript (ES6+)
- **Styling:** Vanilla CSS (Modern Design System based on `design.md`)

### 3. Mobile Application
- **Framework:** Flutter
- **Bahasa:** Dart
- **Target Platform:** Android & iOS

---

## 👥 Hak Akses & Fitur Per Role

### A. Pengguna (User / Nasabah)
- **Autentikasi:** Register, Login, Logout, Update Profil.
- **Setor Sampah:**
  - Melihat katalog jenis sampah & nilai tukar koin per kg/liter.
  - Pengajuan setor sampah manual / Scan QR Code di titik pengumpulan.
  - Melihat status verifikasi & riwayat setor sampah.
- **Gamifikasi & Edukasi:**
  - Daily Login Reward (Klaim koin harian).
  - Daily Quiz (Kuis interaktif seputar daur ulang untuk koin bonus).
  - Daily & Weekly Missions (Misi harian/mingguan seperti target 2kg sampah).
  - Artikel Edukasi Lingkungan.
- **Wallet & Reward:**
  - Melihat saldo koin & riwayat mutasi koin (masuk/keluar).
  - Tukar koin dengan Reward (Barang Fisik, Voucher Digital, Saldo E-wallet).
  - Tracking status pengajuan klaim reward.

### B. Pengelola (Admin)
- **CMS Admin Panel:** Portal web khusus Admin (`/admin`).
- **Verifikasi & Konfirmasi Setor Sampah:** 
  - Meninjau pengajuan setor sampah dari user.
  - Memeriksa fisik sampah & menginput/mengonfirmasi **berat sebenarnya (berapa kg / liter / pcs)** yang valid untuk dikonversi menjadi koin.
  - Menyetujui (`approve`) untuk melepaskan koin otomatis ke wallet user atau menolak (`reject`).
- **Verifikasi & Konfirmasi Reward:**
  - Meninjau pengajuan klaim reward dari user (barang fisik, voucher, saldo e-wallet).
  - Konfirmasi pemrosesan/penyerahan reward → Ubah status menjadi `selesai`.
  - Atau menolak (`reject`) jika stok habis / data salah → Koin di-refund otomatis.
- **Manajemen Pesan Kontak:**
  - Membaca pesan masuk dari pengguna yang dikirim via form "Hubungi Kami" di website.
  - Menandai status pesan (`unread`, `read`, `replied`).
- **Kelola Katalog & Edukasi:**
  - Tambah/Edit/Hapus jenis sampah & tarif nilai tukar koin.
  - Tambah/Edit/Hapus katalog reward & stok.
  - Tambah/Edit/Hapus artikel edukasi & soal kuis harian.
- **Manajemen User:** Melihat daftar nasabah/user & riwayat transaksi.

---

## 📄 Referensi Dokumentasi Lainnya
- `flow.md` → Alur kerja detail sistem (User ↔ Admin ↔ Database).
- `database.md` → Skema database, nama tabel, & struktur relasi resmi.
- `reward.md` → Detail mekanisme reward & simulasi penukaran saldo.
- `sprint.md` → Rencana sprint kerja terbaru (Sprint 1 - 5).
- `design.md` → Panduan visual & UI design system.