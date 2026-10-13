# Livora - Revised Sprint Plan (`sprint.md`)

Dokumen ini memuat peta jalan (*roadmap*) pengembangan proyek Livora dari Sprint 1 hingga Sprint 5 yang telah disesuaikan dengan alur bisnis **Bank Sampah Offline (Direct Drop-Off)**.

---

## 🟢 SPRINT 1: Perencanaan & Perancangan Arsitektur (SELESAI ✅)
- [x] Penyusunan Dokumen Spesifikasi Kebutuhan & Proposal Proyek.
- [x] Perancangan Visual Identity & System Guide (`design.md`).
- [x] Perancangan Skema Relasi Database (`database.md`).
- [x] Penentuan Scope Utama: Bank Sampah Offline (Non-Pickup).

---

## 🟡 SPRINT 2: Cleaning & Sempurnakan Backend REST API (BERLANGSUNG ⏳)

### A. Cleaning & Refactoring Database/API
- [ ] Hapus tabel/migrasi `jemput_sampah`, `locations`, `schedules`.
- [ ] Hapus Controller & Endpoint `JemputSampahController.php` dan rute terkait.
- [ ] Buat/Perbarui migrasi tabel resmi: `users`, `kategori_sampah`, `jenis_sampah`, `wallets`, `transaksi_setor`, `transaksi_setor_detail`, `transaksi_koin`, `rewards`, `permintaan_reward`, `quizzes`, `questions`, `quiz_attempts`, `missions`, `mission_progress`, `artikels`, `pesan_kontak`.

### B. Implementasi Endpoint API RESTful
- [ ] **Autentikasi (Sanctum):** Register, Login (User & Admin), Logout, User Profile.
- [ ] **Katalog & Edukasi:**
  - `GET /api/kategori-sampah` & `GET /api/jenis-sampah`
  - `GET /api/artikels` & `GET /api/artikels/{slug}`
- [ ] **Setor Sampah (Direct Drop-Off):**
  - `POST /api/transaksi-setor` (User buat pengajuan setor manual)
  - `GET /api/transaksi-setor` (History transaksi user)
  - `GET /api/admin/transaksi-setor` (Daftar verifikasi admin)
  - `POST /api/admin/transaksi-setor/{id}/approve` (Admin menyetujui & melepaskan koin)
  - `POST /api/admin/transaksi-setor/{id}/reject` (Admin menolak pengajuan)
- [ ] **Wallet & Transaksi Koin:**
  - `GET /api/wallet` (Lihat saldo & total dampak lingkungan)
  - `GET /api/transaksi-koin` (Riwayat mutasi koin)
- [ ] **Katalog Reward & Klaim:**
  - `GET /api/rewards` (Daftar katalog reward)
  - `POST /api/permintaan-reward` (User tukar koin dengan reward)
  - `GET /api/admin/permintaan-reward` (Admin daftar pengajuan reward)
  - `POST /api/admin/permintaan-reward/{id}/selesai` (Admin konfirmasi penyerahan/payout)
  - `POST /api/admin/permintaan-reward/{id}/reject` (Admin tolak klaim & refund koin)
- [ ] **Gamifikasi (Daily Login, Quiz & Mission):**
  - `POST /api/daily-login` (Klaim koin harian)
  - `GET /api/quizzes/daily` & `POST /api/quizzes/{id}/submit`
  - `GET /api/missions` & `POST /api/missions/{id}/claim`
- [ ] **Pesan Kontak:** `POST /api/pesan-kontak` (User kirim pesan) & `GET /api/admin/pesan-kontak` (Admin baca pesan)

---

## 🔵 SPRINT 3: Web Frontend (React + Vite) — Landing Page & Admin CMS

### A. Landing Page Public (User Portal)
- [ ] Hero Section & CTA.
- [ ] Section "Mengapa Livora" & "Cara Kerja Setor Offline".
- [ ] Section Dampak Positif Lingkungan (Live statistics).
- [ ] Halaman Katalog Artikel & Detail Baca Artikel.
- [ ] Halaman Nilai Tukar Sampah & Katalog Reward.
- [ ] Halaman Form Hubungi Kami.

### B. Web Admin CMS (`/admin`)
- [ ] Halaman Login khusus Admin.
- [ ] Dashboard CMS (Statistik Total Sampah, User, Pending Verifikasi).
- [ ] Panel Verifikasi & Konfirmasi Setor Sampah Offline (Cek fisik, validasi & input berat berapa kg yang dijadikan koin → Approve / Reject).
- [ ] Panel Konfirmasi Permintaan Reward (Verifikasi klaim user → Konfirmasi penyerahan/payout → Selesai / Reject & Refund Koin).
- [ ] Panel Pesan Kontak (Admin membaca & mengelola pesan masuk dari form Kontak).
- [ ] Panel Kelola Master Data (Jenis Sampah, Nilai Tukar Koin, Reward & Stok).
- [ ] Panel CMS Artikel Edukasi & Kuis Harian.

---

## 🟣 SPRINT 4: Mobile Application (Flutter) & Fitur QR Scanner

### A. Fitur Mobile Utama
- [ ] Splash Screen & Onboarding.
- [ ] Auth (Login / Register / Profile Page).
- [ ] Home Dashboard User (Saldo Koin, Impact Stat, Quick Action Setor/Tukar).
- [ ] Fitur Setor Sampah Manual (Pilih Jenis Sampah → Estimasi Berat → Pengajuan).
- [ ] Fitur Gamifikasi: Daily Login Card, Daily Quiz Screen, Missions Tracker.
- [ ] Wallet & History Screen (Mutasi Koin Masuk/Keluar).
- [ ] Katalog Reward & Flow Redeem Reward.

### B. Fitur QR Barcode Claim (Sprint 4 Feature)
- [ ] Admin Web CMS: Generate Dynamic Session QR Code di lokasi bank sampah.
- [ ] Mobile App: Fitur Kamera QR Scanner untuk scan QR Code Admin.
- [ ] Integrasi Instant Verifikasi (User scan QR → Admin langsung terima notif & input berat → Koin cair real-time).

---

## 🔴 SPRINT 5: Testing, Midtrans Sandbox & Release

- [ ] End-to-End Testing (Alur User Setor → Verifikasi Admin → Saldo Koin → Claim Reward).
- [ ] Pengujian Midtrans Sandbox Payment Gateway (Simulasi).
- [ ] Bug Fixing UI/UX (Responsive Web & Smooth Mobile Layout).
- [ ] Dokumentasi API Postman Collection.
- [ ] Final Presentation Build & Demo Preparation.
