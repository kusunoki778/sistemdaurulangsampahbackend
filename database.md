# Livora - Spesifikasi Database Resmi (`database.md`)

Dokumen ini berisi struktur tabel, relasi, tipe data, dan konvensi skema database MySQL untuk backend Laravel Livora.

---

## ⛔ CATATAN PEMBERSIHAN TABEL
Tabel-tabel berikut **DIHAPUS / TIDAK DIGUNAKAN** karena tidak sesuai dengan alur bisnis bank sampah offline:
- ❌ `jemput_sampah` (Dihapus — tidak ada fitur pickup)
- ❌ `locations` (Dihapus — tidak ada GPS maps mitra)
- ❌ `schedules` (Dihapus — tidak ada penjadwalan kurir)

---

## 🗄️ Daftar Tabel Resmi Livora

### 1. `users`
Tabel pengguna aplikasi (User Nasabah & Admin).

| Column | Type | Attributes | Description |
|:---|:---|:---|:---|
| `id` | `bigint` | `PRIMARY KEY, AUTO_INCREMENT` | ID Pengguna |
| `name` | `varchar(255)` | `NOT NULL` | Nama Lengkap |
| `email` | `varchar(255)` | `UNIQUE, NOT NULL` | Alamat Email |
| `phone` | `varchar(20)` | `NULLABLE` | Nomor Telepon / WA |
| `role` | `enum('user','admin')` | `DEFAULT 'user'` | Peran Pengguna |
| `avatar` | `varchar(255)` | `NULLABLE` | Path / URL foto profil |
| `status` | `enum('aktif','nonaktif')`| `DEFAULT 'aktif'` | Status Akun |
| `password` | `varchar(255)` | `NOT NULL` | Hash Password |
| `remember_token` | `varchar(100)` | `NULLABLE` | Token remember me |
| `created_at` | `timestamp` | `NULLABLE` | Waktu dibuat |
| `updated_at` | `timestamp` | `NULLABLE` | Waktu diperbarui |

---

### 2. `kategori_sampah`
Pengelompokan jenis sampah (misal: Plastik, Kertas, Logam, Minyak Jelantah).

| Column | Type | Attributes | Description |
|:---|:---|:---|:---|
| `id` | `bigint` | `PRIMARY KEY, AUTO_INCREMENT` | ID Kategori |
| `nama_kategori` | `varchar(100)` | `NOT NULL` | Nama Kategori |
| `deskripsi` | `text` | `NULLABLE` | Keterangan Kategori |
| `icon` | `varchar(255)` | `NULLABLE` | Icon SVG / Image path |
| `created_at`, `updated_at` | `timestamp` | `NULLABLE` | Timestamps |

---

### 3. `jenis_sampah` (Nilai Tukar Sampah)
Katalog spesifik jenis sampah beserta tarif koin per satuan.

| Column | Type | Attributes | Description |
|:---|:---|:---|:---|
| `id` | `bigint` | `PRIMARY KEY, AUTO_INCREMENT` | ID Jenis Sampah |
| `kategori_id` | `bigint` | `FOREIGN KEY (kategori_sampah)` | ID Kategori |
| `nama_jenis` | `varchar(100)` | `NOT NULL` | Nama Jenis (misal: Botol PET, Cardboard, Minyak Jelantah) |
| `satuan` | `enum('kg','liter','pcs')` | `DEFAULT 'kg'` | Satuan Takaran |
| `poin_per_satuan` | `integer` | `NOT NULL` | Jumlah Koin per Satuan |
| `deskripsi` | `text` | `NULLABLE` | Instruksi Pemilahan |
| `gambar` | `varchar(255)` | `NULLABLE` | Foto contoh jenis sampah |
| `created_at`, `updated_at` | `timestamp` | `NULLABLE` | Timestamps |

---

### 4. `wallets`
Dompet koin dan akumulasi data lingkungan tiap pengguna.

| Column | Type | Attributes | Description |
|:---|:---|:---|:---|
| `id` | `bigint` | `PRIMARY KEY, AUTO_INCREMENT` | ID Wallet |
| `user_id` | `bigint` | `UNIQUE, FOREIGN KEY (users)` | Relasi ke Pengguna |
| `saldo_koin` | `integer` | `DEFAULT 0` | Saldo Koin Aktif |
| `total_koin_diperoleh` | `integer` | `DEFAULT 0` | Total Koin sepanjang waktu |
| `total_sampah_kg` | `decimal(10,2)` | `DEFAULT 0.00` | Total Berat Sampah Disetor (kg) |
| `created_at`, `updated_at` | `timestamp` | `NULLABLE` | Timestamps |

---

### 5. `transaksi_setor`
Header transaksi penyerahan/setor sampah offline oleh user.

| Column | Type | Attributes | Description |
|:---|:---|:---|:---|
| `id` | `bigint` | `PRIMARY KEY, AUTO_INCREMENT` | ID Transaksi Setor |
| `kode_transaksi` | `varchar(50)` | `UNIQUE, NOT NULL` | Contoh: `TRX-S-20261002-001` |
| `user_id` | `bigint` | `FOREIGN KEY (users)` | Nasabah yang setor |
| `admin_id` | `bigint` | `NULLABLE, FOREIGN KEY (users)` | Admin yang memverifikasi |
| `total_koin` | `integer` | `DEFAULT 0` | Total Koin Dihasilkan |
| `status` | `enum('pending','approved','rejected')` | `DEFAULT 'pending'` | Status Verifikasi |
| `catatan` | `text` | `NULLABLE` | Catatan Admin/User |
| `qr_code` | `varchar(255)` | `NULLABLE` | Token QR jika via scan |
| `created_at`, `updated_at` | `timestamp` | `NULLABLE` | Timestamps |

---

### 6. `transaksi_setor_detail`
Rincian item sampah dalam satu transaksi setor.

| Column | Type | Attributes | Description |
|:---|:---|:---|:---|
| `id` | `bigint` | `PRIMARY KEY, AUTO_INCREMENT` | ID Detail |
| `transaksi_setor_id` | `bigint` | `FOREIGN KEY (transaksi_setor)` | Relasi ke Transaksi Setor |
| `jenis_sampah_id` | `bigint` | `FOREIGN KEY (jenis_sampah)` | Relasi ke Jenis Sampah |
| `jumlah` | `decimal(10,2)` | `NOT NULL` | Quantity (kg / liter / pcs) |
| `koin_subtotal` | `integer` | `NOT NULL` | Subtotal Koin (`jumlah * poin_per_satuan`) |
| `created_at`, `updated_at` | `timestamp` | `NULLABLE` | Timestamps |

---

### 7. `transaksi_koin`
Ledger/Riwayat mutasi koin (Audit Log Koin Masuk & Keluar).

| Column | Type | Attributes | Description |
|:---|:---|:---|:---|
| `id` | `bigint` | `PRIMARY KEY, AUTO_INCREMENT` | ID Ledger |
| `user_id` | `bigint` | `FOREIGN KEY (users)` | Pemilik Koin |
| `tipe` | `enum('masuk','keluar')` | `NOT NULL` | Arah Transaksi |
| `jumlah_koin` | `integer` | `NOT NULL` | Nominal Koin |
| `sumber` | `enum('setor_sampah','daily_login','quiz','mission','reward_claim','refund')` | `NOT NULL` | Asal Sumber Koin |
| `referensi_id` | `bigint` | `NULLABLE` | ID Tabel Terkait (seperti `transaksi_setor_id` atau `permintaan_reward_id`) |
| `keterangan` | `varchar(255)` | `NOT NULL` | Deskripsi singkat mutasi |
| `created_at`, `updated_at` | `timestamp` | `NULLABLE` | Timestamps |

---

### 8. `rewards`
Katalog hadiah yang bisa ditukar dengan koin.

| Column | Type | Attributes | Description |
|:---|:---|:---|:---|
| `id` | `bigint` | `PRIMARY KEY, AUTO_INCREMENT` | ID Reward |
| `nama_reward` | `varchar(150)` | `NOT NULL` | Nama Hadiah (Tumbler, Saldo GoPay 20k) |
| `tipe` | `enum('barang','voucher','saldo_digital')` | `NOT NULL` | Kategori Hadiah |
| `koin_dibutuhkan` | `integer` | `NOT NULL` | Harga Koin |
| `stok` | `integer` | `DEFAULT 0` | Sisa Stok |
| `gambar` | `varchar(255)` | `NULLABLE` | URL / Path Gambar |
| `deskripsi` | `text` | `NULLABLE` | Deskripsi & Syarat Ketentuan |
| `status_aktif` | `boolean` | `DEFAULT true` | Status Aktif katalog |
| `created_at`, `updated_at` | `timestamp` | `NULLABLE` | Timestamps |

---

### 9. `permintaan_reward`
Daftar pengajuan klaim reward oleh user.

| Column | Type | Attributes | Description |
|:---|:---|:---|:---|
| `id` | `bigint` | `PRIMARY KEY, AUTO_INCREMENT` | ID Klaim |
| `kode_klaim` | `varchar(50)` | `UNIQUE, NOT NULL` | Contoh: `CLM-20261002-001` |
| `user_id` | `bigint` | `FOREIGN KEY (users)` | Pemohon Klaim |
| `reward_id` | `bigint` | `FOREIGN KEY (rewards)` | Hadiah yang Dipilih |
| `koin_ditukar` | `integer` | `NOT NULL` | Nominal koin yang dipotong |
| `status` | `enum('pending','diproses','selesai','ditolak')` | `DEFAULT 'pending'` | Status Pengajuan |
| `nomor_tujuan_digital` | `varchar(50)` | `NULLABLE` | Nomor HP e-wallet (jika tipe saldo_digital) |
| `catatan_admin` | `text` | `NULLABLE` | Catatan Verifikasi Admin |
| `created_at`, `updated_at` | `timestamp` | `NULLABLE` | Timestamps |

---

### 10. `quizzes`, `questions`, `quiz_attempts`
Tabel pendukung fitur Kuis Harian Lingkungan.

- **`quizzes`**: `id`, `judul`, `deskripsi`, `koin_reward`, `status_aktif`, `created_at`, `updated_at`
- **`questions`**: `id`, `quiz_id`, `pertanyaan`, `opsi_a`, `opsi_b`, `opsi_c`, `opsi_d`, `jawaban_benar` (`a/b/c/d`), `created_at`, `updated_at`
- **`quiz_attempts`**: `id`, `user_id`, `quiz_id`, `skor`, `lulus` (`boolean`), `koin_diperoleh`, `created_at`, `updated_at`

---

### 11. `missions`, `mission_progress`
Tabel pendukung fitur Misi Harian & Mingguan.

- **`missions`**: `id`, `judul`, `deskripsi`, `tipe` (`daily/weekly`), `target_jumlah` (`int`), `koin_reward` (`int`), `status_aktif`, `created_at`, `updated_at`
- **`mission_progress`**: `id`, `user_id`, `mission_id`, `progress` (`int`), `status` (`ongoing/completed/claimed`), `created_at`, `updated_at`

---

### 12. `artikels` & `pesan_kontak`
Content & Support Management.

- **`artikels`**: `id`, `judul`, `slug`, `kategori`, `isi` (`longtext`), `gambar`, `admin_id`, `status` (`draft/published`), `views`, `created_at`, `updated_at`
- **`pesan_kontak`**: `id`, `nama`, `email`, `subjek`, `pesan`, `status` (`unread/read/replied`), `created_at`, `updated_at`