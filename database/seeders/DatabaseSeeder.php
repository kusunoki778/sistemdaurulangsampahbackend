<?php

namespace Database\Seeders;

use App\Models\Artikel;
use App\Models\JenisSampah;
use App\Models\KategoriSampah;
use App\Models\Mission;
use App\Models\PesanKontak;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Reward;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Akun Admin
        $admin = User::create([
            'name' => 'Administrator Livora',
            'username' => 'admin',
            'email' => 'admin@livora.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'phone' => '081234567890',
            'status' => 'aktif',
        ]);
        Wallet::create(['user_id' => $admin->id]);

        // 2. Akun Member
        $membersData = [
            ['name' => 'Gonin Damai', 'username' => 'gonin', 'email' => 'gonindamaiselamanya09@gmail.com', 'saldo' => 29931, 'sampah' => 1112.00],
            ['name' => 'Raven Rafi', 'username' => 'raven', 'email' => 'fann.rafi.ananda@gmail.com', 'saldo' => 8000, 'sampah' => 23.00],
            ['name' => 'Lepi Bomatsu', 'username' => 'lepi', 'email' => 'bomatsubkptua@gmail.com', 'saldo' => 12215, 'sampah' => 107.00],
            ['name' => 'Hans Palsu', 'username' => 'hans', 'email' => 'palsu3467@gmail.com', 'saldo' => 1260, 'sampah' => 2.00],
            ['name' => 'Dinda DH', 'username' => 'dinda', 'email' => 'dindadh2@gmail.com', 'saldo' => 406, 'sampah' => 0.00],
            ['name' => 'Faiz Gibran', 'username' => 'iza', 'email' => 'faiz.gibran.rezi@gmail.com', 'saldo' => 160, 'sampah' => 0.00],
            ['name' => 'Jack Rafi', 'username' => 'jack', 'email' => 'irfanrafi0647@gmail.com', 'saldo' => 500, 'sampah' => 10.00],
        ];

        $userList = [];
        foreach ($membersData as $m) {
            $user = User::create([
                'name' => $m['name'],
                'username' => $m['username'],
                'email' => $m['email'],
                'password' => Hash::make('password123'),
                'role' => 'user',
                'phone' => '0812'.rand(10000000, 99999999),
                'status' => 'aktif',
            ]);
            Wallet::create([
                'user_id' => $user->id,
                'saldo_koin' => $m['saldo'],
                'total_koin_diperoleh' => $m['saldo'],
                'total_sampah_kg' => $m['sampah'],
            ]);
            $userList[$m['username']] = $user;
        }

        // 3. Kategori & Jenis Sampah (Nilai Tukar)
        $kategoriData = [
            'Minyak' => [
                ['nama_jenis' => 'Minyak Jelantah', 'satuan' => 'liter', 'poin_per_satuan' => 80, 'deskripsi' => 'Minyak goreng bekas yang telah disaring bersih dari sisa makanan.'],
            ],
            'Plastik' => [
                ['nama_jenis' => 'Botol Plastik PET', 'satuan' => 'kg', 'poin_per_satuan' => 25, 'deskripsi' => 'Botol plastik PET bening atau berwarna yang sudah dikeringkan.'],
            ],
            'Kertas' => [
                ['nama_jenis' => 'Kardus', 'satuan' => 'kg', 'poin_per_satuan' => 35, 'deskripsi' => 'Kardus cokelat kering dan bersih, diikat rapi per tumpukan.'],
                ['nama_jenis' => 'Kertas Bekas', 'satuan' => 'kg', 'poin_per_satuan' => 20, 'deskripsi' => 'Koran, majalah, kertas HVS tanpa plastik perekat.'],
            ],
            'Logam' => [
                ['nama_jenis' => 'Kaleng Aluminium', 'satuan' => 'kg', 'poin_per_satuan' => 55, 'deskripsi' => 'Kaleng minuman aluminium bebas kotoran dan tidak berkarat.'],
            ],
        ];

        foreach ($kategoriData as $namaKategori => $jenisList) {
            $kategori = KategoriSampah::create(['nama_kategori' => $namaKategori]);
            foreach ($jenisList as $jenis) {
                JenisSampah::create([...$jenis, 'kategori_id' => $kategori->id]);
            }
        }

        // 4. Katalog Reward
        $rewards = [
            ['nama_reward' => 'E-Wallet Rp 20.000', 'tipe' => 'saldo_digital', 'koin_dibutuhkan' => 200, 'stok' => 50, 'deskripsi' => 'Voucher saldo E-Wallet (GoPay/OVO/DANA) senilai Rp 20.000.'],
            ['nama_reward' => 'Tumbler Eco-Friendly Livora', 'tipe' => 'barang', 'koin_dibutuhkan' => 420, 'stok' => 100, 'deskripsi' => 'Tumbler stainless steel premium 500ml.'],
            ['nama_reward' => 'Totebag Kanvas Livora', 'tipe' => 'barang', 'koin_dibutuhkan' => 250, 'stok' => 49, 'deskripsi' => 'Tas belanja kain kanvas tebal ramah lingkungan.'],
            ['nama_reward' => 'Paket Bibit Tanaman & Pupuk Kompos', 'tipe' => 'barang', 'koin_dibutuhkan' => 120, 'stok' => 197, 'deskripsi' => 'Bibit tanaman hias dan pupuk kompos organik.'],
            ['nama_reward' => 'Voucher Diskon Toko Organik 20%', 'tipe' => 'voucher', 'koin_dibutuhkan' => 150, 'stok' => 80, 'deskripsi' => 'Kode voucher diskon 20% di toko organik mitra Livora.'],
        ];
        foreach ($rewards as $r) {
            Reward::create($r);
        }

        // 5. Artikel Edukasi
        $artikels = [
            ['judul' => 'Panduan Lengkap Memilah Sampah Organik dan Anorganik', 'slug' => 'panduan-lengkap-memilah-sampah-organik-dan-anorganik', 'kategori' => 'Pemilahan Sampah', 'isi' => 'Sampah yang tidak dipilah dengan benar dapat menyebabkan pencemaran lingkungan dan menghambat proses daur ulang. Sampah organik berasal dari sisa makhluk hidup dan dapat terurai alami, sedangkan sampah anorganik sulit terurai seperti plastik, logam, kaca, dan kertas yang justru dapat didaur ulang.', 'gambar' => 'artikel/artikel_organik_anorganik.jpg'],
            ['judul' => 'Manfaat Ekonomi dari Pemilahan Sampah yang Tepat', 'slug' => 'manfaat-ekonomi-dari-pemilahan-sampah-yang-tepat', 'kategori' => 'Ekonomi Hijau', 'isi' => 'Pemilahan sampah memberikan manfaat ekonomi besar: meningkatkan nilai jual sampah, mengurangi beban TPA, mendorong industri daur ulang, dan menciptakan produk bernilai tambah.', 'gambar' => 'artikel/artikel_ekonomi_hijau.jpg'],
            ['judul' => 'Bahaya Mikroplastik Bagi Ekosistem Laut', 'slug' => 'bahaya-mikroplastik-bagi-ekosistem-laut', 'kategori' => 'Isu Lingkungan', 'isi' => 'Mikroplastik adalah partikel plastik berukuran kurang dari 5 mm. Dampaknya meliputi penyumbatan pencernaan biota laut, akumulasi racun dalam rantai makanan, hingga risiko kesehatan bagi manusia.', 'gambar' => 'artikel/artikel_mikroplastik.jpg'],
            ['judul' => '10 Tips Mengurangi Sampah Rumah Tangga', 'slug' => '10-tips-mengurangi-sampah-rumah-tangga', 'kategori' => 'Gaya Hidup', 'isi' => 'Gunakan tas belanja reusable, bawa botol minum sendiri, pisahkan sampah organik dan anorganik, kurangi produk berkemasan, dan setorkan sampah daur ulang ke Livora.', 'gambar' => 'artikel/artikel_tips_sampah.jpg'],
            ['judul' => 'Cara Mengolah Minyak Jelantah Menjadi Sabun Ramah Lingkungan', 'slug' => 'cara-mengolah-minyak-jelantah-menjadi-sabun', 'kategori' => 'Daur Ulang', 'isi' => 'Minyak jelantah dapat diolah menjadi sabun melalui proses saponifikasi menggunakan NaOH, menghasilkan sabun pembersih serbaguna yang ramah lingkungan.', 'gambar' => 'artikel/artikel_sabun_jelantah.jpg'],
            ['judul' => 'Mengenal Jenis-jenis Plastik untuk Daur Ulang', 'slug' => 'mengenal-jenis-jenis-plastik-untuk-daur-ulang', 'kategori' => 'Pemilahan Sampah', 'isi' => 'Kenali 7 kode plastik: PETE, HDPE, PVC, LDPE, PP, PS, dan OTHER. Setiap jenis memiliki cara daur ulang yang berbeda.', 'gambar' => 'artikel/artikel_kode_plastik.jpg'],
        ];
        foreach ($artikels as $art) {
            Artikel::create([...$art, 'admin_id' => $admin->id, 'status' => 'published', 'views' => rand(20, 500)]);
        }

        // 6. Pesan Kontak
        PesanKontak::create([
            'nama' => 'Irfan Rafi',
            'email' => 'irfan@example.com',
            'subjek' => 'Tanya Jadwal Setor Sampah',
            'pesan' => 'Halo Livora, apakah drop point bisa dikunjungi di akhir pekan (Sabtu-Minggu)? Terima kasih.',
            'status' => 'read',
        ]);
        PesanKontak::create([
            'nama' => 'Lepi Bomatsu',
            'email' => 'lepi@gmail.com',
            'subjek' => 'Cara Menukar Poin dengan Voucher',
            'pesan' => 'Bagaimana alur klaim voucher E-Wallet setelah penukaran koin disetujui?',
            'status' => 'unread',
        ]);

        // 7. Kuis Harian
        $quiz = Quiz::create([
            'judul' => 'Kuis Harian: Daur Ulang & Pemilahan Sampah',
            'deskripsi' => 'Uji pengetahuanmu seputar pemilahan sampah dan daur ulang. Jawab benar minimal 70% untuk mendapat 25 koin!',
            'koin_reward' => 25,
            'status_aktif' => true,
        ]);
        $questions = [
            ['pertanyaan' => 'Sampah organik adalah sampah yang berasal dari...', 'opsi_a' => 'Sisa makhluk hidup', 'opsi_b' => 'Plastik', 'opsi_c' => 'Logam', 'opsi_d' => 'Kaca', 'jawaban_benar' => 'a'],
            ['pertanyaan' => 'Kode plastik PETE biasanya dipakai untuk...', 'opsi_a' => 'Pipa', 'opsi_b' => 'Botol air mineral', 'opsi_c' => 'Styrofoam', 'opsi_d' => 'Kantong kresek', 'jawaban_benar' => 'b'],
            ['pertanyaan' => 'Minyak jelantah sebaiknya diolah menjadi...', 'opsi_a' => 'Air minum', 'opsi_b' => 'Pupuk kimia', 'opsi_c' => 'Sabun', 'opsi_d' => 'Bensin', 'jawaban_benar' => 'c'],
        ];
        foreach ($questions as $q) {
            Question::create([...$q, 'quiz_id' => $quiz->id]);
        }

        // 8. Missions
        Mission::create(['judul' => 'Setor Sampah Minggu Ini', 'deskripsi' => 'Setorkan minimal 2 kg sampah ke drop point Livora minggu ini.', 'tipe' => 'weekly', 'target_jumlah' => 2, 'koin_reward' => 50, 'status_aktif' => true]);
        Mission::create(['judul' => 'Login Harian', 'deskripsi' => 'Klaim hadiah login harian hari ini.', 'tipe' => 'daily', 'target_jumlah' => 1, 'koin_reward' => 10, 'status_aktif' => true]);

        unset($userList);
    }
}
