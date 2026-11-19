# Livora - System Workflows & Diagrams (`flow.md`)

Dokumen ini menjelaskan alur kerja (*workflows*) utama dalam sistem Livora secara mendetail untuk memandu AI CLI Agent dan Developer.

---

## 1. Alur Autentikasi & Pemisahan Akses (Auth & Roles)

### Visual Diagram:
```
[ User / Admin ]
       │
       ├─► (1) POST /api/register ──► Create User (role: 'user') ──► Generate Sanctum Token
       │
       └─► (2) POST /api/login ────► Check Credentials & Role
                                          │
                                          ├── Role = 'user'  ──► Akses Mobile App / User Portal
                                          └── Role = 'admin' ──► Akses Web CMS Admin Panel
```

### Aturan Teknis:
- Backend menggunakan **Laravel Sanctum**. Token dikirim melalui header `Authorization: Bearer <token>`.
- Middleware `role:admin` atau `can:admin` digunakan untuk membatasi endpoint khusus admin (seperti verifikasi transaksi, kelola reward, kelola kuis).

---

## 2. Alur Setor Sampah Offline (Core Waste Deposit Flow)

Livora **TIDAK MENGGUNAKAN KURIR/PICKUP**. Pengguna membawa sampah fisik ke bank sampah offline Livora.

### A. Opsi 1: Setor Manual MVP (Sprint 2 - 3)

```
[ User ]                           [ Admin ]                         [ Database & Wallet ]
   │                                   │                                      │
   │ (1) Bawa sampah ke lokasi física  │                                      │
   │ ─────────────────────────────────►│                                      │
   │                                   │                                      │
   │ (2) User buat pengajuan di App    │                                      │
   │     POST /api/transaksi-setor     │                                      │
   │ ──────────────────────────────────┼─────────────────────────────────────►│ Status: 'pending'
   │                                   │                                      │
   │                                   │ (3) Timbang sampah fisik             │
   │                                   │     & Cek pengajuan di Admin CMS     │
   │                                   │     GET /api/admin/transaksi-setor   │
   │                                   │                                      │
   │                                   │ (4) Admin konfirmasi berat & Approve │
   │                                   │     POST /api/admin/transaksi-setor/{id}/approve
   │                                   │ ────────────────────────────────────►│ Status: 'approved'
   │                                   │                                      │ Saldo Koin += Total Koin
   │                                   │                                      │ Total Sampah Kg += Berat
   │◄──────────────────────────────────┴──────────────────────────────────────┤
   │ (5) Koin bertambah & Notifikasi masuk                                    │
```

### B. Opsi 2: Setor Sampah QR Code (Sprint 4)

```
[ Admin (Web CMS) ]                    [ User (Mobile App) ]              [ Backend API ]
        │                                        │                              │
(1) Generate QR Code Sesi/Lokasi                 │                              │
    (berisi payload session_token)               │                              │
        │                                        │                              │
(2) Tampilkan QR di Layar/Meja                   │                              │
        │                                        │                              │
        │ ◄──────────────────────────────────────┤ (3) User Scan QR via Mobile  │
        │                                        │     POST /api/transaksi-setor/scan-qr
        │                                        │ ────────────────────────────►│ Create Draft Deposit
        │                                        │                              │ (Link User & Session)
        │                                        │                              │
(4) Admin Input Berat/Nilai di CMS ──────────────┼─────────────────────────────►│ Approve & Add Coins
        │                                        │                              │ Update Wallet User
```

---

## 3. Alur Penukaran Reward (Redemption Flow)

```
[ User ]                                 [ Database / Wallet ]                 [ Admin ]
   │                                               │                               │
   │ (1) Pilih Reward di Katalog App               │                               │
   │     POST /api/permintaan-reward               │                               │
   │ ─────────────────────────────────────────────►│ Cek Saldo Koin                │
   │                                               │ If Saldo >= Koin Dibutuhkan:  │
   │                                               │   - Potong Saldo Koin User    │
   │                                               │   - Subtrak Stok Reward (-1)  │
   │                                               │   - Status: 'pending'         │
   │                                               │                               │
   │                                               │ (2) Tampil di CMS Admin       │
   │                                               │ ◄─────────────────────────────┤
   │                                               │                               │
   │                                               │ (3) Admin Cek & Serahkan      │
   │                                               │     Barang / Kirim Saldo      │
   │                                               │     POST /api/admin/permintaan-reward/{id}/selesai
   │                                               │ ─────────────────────────────►│ Status: 'selesai'
   │                                               │                               │
   │                                               │ [Jika Ditolak Admin]:         │
   │                                               │ Status: 'ditolak'             │
   │                                               │ Refund Koin ke Wallet User    │
```

---

## 4. Alur Gamifikasi Harian & Edukasi

### A. Daily Login:
1. User membuka aplikasi setiap hari.
2. User menekan tombol "Klaim Harian" (`POST /api/daily-login`).
3. Sistem mengecek apakah hari ini sudah klaim (`daily_login` date == today).
4. Jika belum, tambahkan koin harian (misal: +10 koin) dan catat di `transaksi_koin`.

### B. Daily Quiz:
1. User mengambil kuis harian (`GET /api/quizzes/daily`).
2. User mengirimkan jawaban (`POST /api/quizzes/{id}/submit`).
3. Sistem menghitung jawaban benar. Jika memenuhi syarat lulus, berikan koin bonus (+25 koin).

### C. Daily & Weekly Missions:
1. User melihat daftar misi aktif (`GET /api/missions`).
2. Misi terupdate otomatis berdasarkan aksi user (misal: "Setor 2 kg Sampah" terhitung saat transaksi setor di-approve admin).
3. User mengeklik "Klaim Misi" saat progress mencapai 100% (`POST /api/missions/{id}/claim`).

---

## 5. Ringkasan Status Transaksi

| Modul | Field Status | Nilai Status | Keterangan |
|:---|:---|:---|:---|
| Transaksi Setor | `status` | `pending` | Pengajuan baru dibuat, menunggu verifikasi fisik Admin |
| Transaksi Setor | `status` | `approved` | Admin sudah menimbang fisik & menyetujui, koin berhasil cair |
| Transaksi Setor | `status` | `rejected` | Admin menolak pengajuan (misal: sampah tidak layak/salah jenis) |
| Permintaan Reward | `status` | `pending` | User sudah klaim reward, koin sudah dipotong sementara |
| Permintaan Reward | `status` | `diproses` | Admin sedang menyiapkan barang / memproses voucher |
| Permintaan Reward | `status` | `selesai` | Barang diserahkan / Saldo e-wallet dikirim, transaksi tuntas |
| Permintaan Reward | `status` | `ditolak` | Admin menolak klaim (misal: stok habis/salah no hp) → Koin di-refund |
