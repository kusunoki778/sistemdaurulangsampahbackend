<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JenisSampah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Chatbot Livora: pertanyaan user dijawab lewat 3 lapis —
 * 1. FAQ berbasis kata kunci (tidak perlu key, selalu hidup).
 * 2. Gemini untuk pertanyaan yang belum dikenali, dengan konteks harga
 *    sampah, memori 6 giliran terakhir, dan cache untuk pertanyaan tunggal.
 * 3. Fallback generik.
 */
class ChatController extends Controller
{
    /**
     * FAQ berbasis kata kunci. Nilainya adalah closure yang boleh memakai
     * konteks DB (harga sampah hari ini) supaya jawaban tidak jadul.
     */
    private static function daftarTopik(): array
    {
        return [
            'livora' => 'Livora adalah bank sampah digital: kamu setor sampah di titik kumpul '
                .'Livora, beratnya diverifikasi admin, lalu kamu dapat koin yang bisa '
                .'ditukar reward (barang, pulsa, atau saldo e-wallet).',
            'setor' => 'Cara setor sampah: buka tab Setor di aplikasi, pindai QR di titik '
                .'kumpul Livora, pilih jenis sampah dan takarannya, lalu admin '
                .'memverifikasi. Setelah disetujui, koin otomatis masuk wallet '
                .'dan kamu dapat notifikasi.',
            'reward' => 'Buka tab Reward, pilih reward yang kamu suka, lalu tukar dengan koin. '
                .'Reward fisik mengeluarkan kupon yang tampilkan ke admin saat '
                .'pengambilan; reward digital (pulsa atau saldo e-wallet) diproses otomatis.',
            'kupon' => 'Kuponomu ada di tab Reward > Riwayat. Tampilannya seperti struk '
                .'berisi kode klaim. Tunjukkan kode klaim ke admin di titik kumpul, '
                .'admin memverifikasinya lewat CMS. Kupon berlaku sesuai tanggal '
                .'di struknya.',
            'koin' => 'Koin diperoleh dari setor sampah, kuis harian, misi, dan bonus '
                .'login harian. Cek koinmu di wallet. Koin juga bisa ditukar '
                .'menjadi saldo e-wallet.',
            'registrasi' => 'Daftar akun Livora lewat tombol Daftar di layar login: isi nama, '
                .'email/username, dan password. Bisa juga langsung masuk pakai '
                .'akun Google.',
            'login' => 'Masuk Livora dengan username/email dan passwordmu, atau lewat '
                .'tombol "Masuk dengan Google". Lupa sandi? Pakai "Lupa Password" '
                .'di layar login.',
            'sandi' => 'Lupa password? Buka layar login lalu pilih "Lupa Password", '
                .'ikuti langkah pengaturan ulang berikut.',
            'dana' => 'Saldo e-wallet (misal DANA) di Livora berasal dari koin yang kamu '
                .'tukar. Setelah pencairan diajukan, dana dikirim ke nomor e-wallet '
                .'tujuan dan statusnya diperbarui otomatis.',
            'saldo' => 'Saldo e-wallet di Livora berasal dari koin yang kamu tukar. '
                .'Pencairan dikirim ke nomor e-wallet tujuan dan statusnya '
                .'diperbarui otomatis.',
            'misi' => 'Misi memberi koin tambahan: misi login harian (klaim tiap hari), '
                .'misi setor mingguan, dan lainnya. Buka tab Misi lari dari '
                .'beranda, tekan Klaim Hadiah saat progressnya penuh.',
            'kuis' => 'Kuis harian: jawab pertanyaan seputar lingkungan dan sampah, '
                .'koin langsung masuk. Kuis bisa diikuti satu kali per hari, '
                .'cari di "Kuis Harian" dari beranda.',
            'notifikasi' => 'Notifikasi muncul di bel pojok kanan atas beranda. Notifikasi '
                .'sistem HP dikirim saat transaksi berhasil (setor disetujui, '
                .'reward diproses). Izinkan akses notifikasi saat ditanya.',
            'qr' => 'QR dipakai di dua tempat: scan QR titik kumpul saat setor sampah, '
                .'dan scan oleh admin saat pengambilan reward (kamu cukup '
                .'tunjukkan kode klaim kupon).',
            'lokasi' => 'Livora beroperasi di titik kumpul terpusat (satu titik yang '
                .'dikelola admin). Jam dan alamat titik kumpul diumumkan '
                .'oleh admin/livora di media sosial resmi.',
            'jam' => 'Jam operasional titik kumpul ditentukan admin. Untuk kepastian, '
                .'hubungi admin Livora atau cek pengumuman di media sosial '
                .'resmi Livora.',
            'harga' => null, // diisi dinamis dengan data jenis sampah.
            'sampah' => null, // diisi dinamis dengan data jenis sampah.
            'terima' => null, // "sampah apa yang diterima?"
            'tukar' => 'Koin bisa ditukar menjadi reward barang, saldo e-wallet, '
                .'atau item katalog reward. Perhatikan stok dan masa berlaku kupon.',
            'pencairan' => 'Cara mencairkan koin: buka Dompet, pilih Tukar Saldo, tentukan '
                .'jumlah, pilih e-wallet (DANA, OVO, ShopeePay, LinkAja, atau '
                .'AstraPay), lalu isi nama dan nomor tujuan. Minimal 100 koin atau '
                .'Rp 10.000. Status diperbarui otomatis, dan koin kembali ke dompet '
                .'kalau pengiriman gagal.',
            'tier' => 'Status member dihitung dari total sampah yang sudah kamu setor. '
                .'Bronze untuk di bawah 25 kg, Silver mulai 25 kg, dan Gold mulai '
                .'100 kg. Badge status tampil di profil dan beranda.',
            'rupiah' => 'Nilai 1 koin setara Rp 100. Jadi 100 koin sama dengan Rp 10.000, '
                .'dan itu juga minimal pencairan ke e-wallet.',
            'kendala' => 'Kalau koin belum masuk setelah setor, buka riwayat setoran dan '
                .'cek statusnya. Kalau setoran ditolak atau angkanya tidak sesuai, '
                .'hubungi admin lewat chat ini dengan menyertakan kode transaksi di '
                .'struk setoran.',
            'verifikasi' => 'Setelah kamu setor di titik kumpul, admin memverifikasi jenis dan '
                .'berat sampah. Koin baru masuk ke dompet setelah setoran disetujui, '
                .'dan kamu akan menerima notifikasi.',
            'kadaluarsa' => 'Kupon punya masa berlaku yang tertera di struk. Kalau tidak diambil '
                .'sampai masa berlaku habis, kupon otomatis kedaluwarsa dan koinnya '
                .'kembali ke dompetmu.',
            'stok' => 'Stok reward bisa habis karena jumlahnya terbatas. Kalau reward yang '
                .'kamu mau sedang habis, tunggu admin menambah stok atau pilih reward '
                .'lain di katalog.',
            'profil' => 'Ubah nama, foto, dan data diri lewat Pengaturan, lalu pilih Ubah Data '
                .'Diri. Foto bisa diambil dari galeri atau kamera.',
            'keamanan' => 'Jaga keamanan akun dengan kata sandi minimal 8 karakter dan jangan '
                .'bagikan sandi atau kode verifikasi ke siapa pun. Ganti sandi kapan '
                .'saja lewat Pengaturan, pilih Ubah Kata Sandi.',
            'privasi' => 'Penjelasan penggunaan data dan hak pengguna ada di Pengaturan, '
                .'pada menu Kebijakan Privasi.',
            'aplikasi' => 'Aplikasi Livora tersedia untuk Android 7.0 ke atas. Unduh APK dari '
                .'tautan resmi yang dibagikan admin, lalu izinkan pemasangan dari '
                .'sumber itu saat diminta.',
            'bantuan' => 'Butuh bantuan? Tanya langsung di chat ini, atau buka Pengaturan dan '
                .'pilih Hubungi Livora. Pengumuman resmi juga dibagikan lewat media '
                .'sosial Livora.',
            'riwayat' => 'Riwayat setoran dan transaksi ada di beranda dan menu dompet. '
                .'Riwayat kupon ada di Reward, lalu Riwayat. Riwayat kuis ada di layar '
                .'Kuis Harian.',
            'pengambilan' => 'Reward fisik diambil di titik kumpul Livora dengan menunjukkan '
                .'kode klaim ke admin. Reward tidak dikirim ke rumah, jadi pastikan kamu '
                .'bisa mengambilnya sebelum kupon kedaluwarsa.',
        ];
    }

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:500',
        ]);

        $userMessage = trim($validated['message']);

        if ($userMessage === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Pesan tidak boleh kosong.',
            ], 422);
        }

        // Konteks harga sampah dari DB — dipakai Gemini maupun fallback.
        $konteks = $this->konteksHargaSampah();

        // Riwayat ringan: maksimal 6 giliran terakhir (user/asisten) supaya
        // pertanyaan lanjutan ("tadi maksudnya apa?") punya konteks. Bila
        // tidak dikirim, dianggap pesan tunggal — kompatibel klien lama.
        $riwayat = [];
        foreach (array_slice($request->input('riwayat', []), -6) as $item) {
            $peran = $item['peran'] ?? '';
            $teks = trim((string) ($item['teks'] ?? ''));
            if (in_array($peran, ['user', 'asisten'], true)
                && $teks !== ''
                && mb_strlen($teks) <= 500
            ) {
                $riwayat[] = ['peran' => $peran, 'teks' => mb_substr($teks, 0, 500)];
            }
        }
        $riwayat = array_slice($riwayat, -6);

        // Lapis 1: FAQ kata kunci. Pertanyaan yang sudah dikenal dijawab
        // langsung supaya tidak menunggu layanan AI.
        $faqReply = $this->cariDiFaq($userMessage, $konteks);
        if ($faqReply !== null) {
            return response()->json([
                'status' => 'success',
                'data' => ['reply' => $faqReply, 'source' => 'faq'],
            ]);
        }

        // Lapis 2: Gemini hanya untuk pertanyaan yang belum dikenali FAQ.
        // Jawaban untuk pertanyaan tunggal disimpan sebentar supaya pertanyaan
        // yang berulang tidak perlu memanggil layanan AI lagi.
        $cacheKey = null;
        if ($riwayat === []) {
            $cacheKey = 'chat:gemini:'.sha1(mb_strtolower($userMessage).'|'.$konteks);
            $tersimpan = Cache::get($cacheKey);
            if (is_string($tersimpan) && $tersimpan !== '') {
                return response()->json([
                    'status' => 'success',
                    'data' => ['reply' => $tersimpan, 'source' => 'gemini'],
                ]);
            }
        }

        $geminiReply = $this->tanyaGemini($userMessage, $konteks, $riwayat);
        if ($geminiReply) {
            if ($cacheKey !== null) {
                Cache::put($cacheKey, $geminiReply, now()->addDays(7));
            }

            return response()->json([
                'status' => 'success',
                'data' => ['reply' => $geminiReply, 'source' => 'gemini'],
            ]);
        }

        // Lapis 3: fallback generik.
        return response()->json([
            'status' => 'success',
            'data' => [
                'reply' => 'Maaf, aku belum paham pertanyaan itu. Coba tanyakan: '
                    .'cara setor sampah, jenis sampah yang diterima, '
                    .'cara tukar reward, misi/kuis harian, atau saldo.',
                'source' => 'fallback',
            ],
        ]);
    }

    private function tanyaGemini(string $userMessage, string $konteksHarga, array $riwayat = []): ?string
    {
        $apiKey = (string) env('GEMINI_API_KEY');

        if ($apiKey === '') {
            return null;
        }

        $prompt = 'Kamu adalah asisten chat Livora (bank sampah digital Indonesia). '
            .'Jawab singkat, ramah, informatif, dan dalam Bahasa Indonesia. '
            .'Maksimal 3 kalimat. '
            .'Fokus pada Livora, sampah, koin, reward. Anggap ini percakapan '
            .'lanjutan: pakai riwayat di bawah bila relevan.'.$konteksHarga
            ."\n\nPertanyaan user: $userMessage";

        // Isi percakapan: riwayat (peran user <-> model) + pesan baru.
        $konten = [];
        foreach ($riwayat as $item) {
            $konten[] = [
                'role' => $item['peran'] === 'user' ? 'user' : 'model',
                'parts' => [['text' => $item['teks']]],
            ];
        }
        $konten[] = [
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ];

        try {
            $respons = Http::connectTimeout(3)->timeout(4)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent', [
                    'contents' => $konten,
                    'safetySettings' => [
                        [
                            'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                            'threshold' => 'BLOCK_NONE',
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 220,
                    ],
                ]);

            if ($respons->failed()) {
                return null;
            }

            $teks = $respons->json('candidates.0.content.parts.0.text');

            return is_string($teks) ? trim($teks) : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Cocokkan pesan user ke topik lewat kata kunci (skor paling banyak cocok
     * menang). Jawaban bisa memakai konteks harga sampah dari DB.
     */
    private function cariDiFaq(string $userMessage, string $konteksHarga): ?string
    {
        $pesan = strtolower($userMessage);

        $terbaik = null;
        $skorTerbaik = 0;

        foreach (self::daftarTopik() as $topik => $jawaban) {
            // Kata kunci topik + varian keyword umum.
            $kataKunci = $this->kataKunciTopik($topik);
            // Frasa panjang ("berapa koin") lebih spesifik daripada kata
            // tunggal ("koin") — bobot = jumlah kata frasa.
            $skor = 0;
            foreach ($kataKunci as $kata) {
                if (str_contains($pesan, $kata)) {
                    $skor += max(1, count(preg_split('/\s+/', trim($kata))));
                }
            }

            if ($skor > $skorTerbaik) {
                $skorTerbaik = $skor;
                $terbaik = $topik;
            }
        }

        if ($terbaik === null) {
            return null;
        }

        // Topik harga/sampah diterima: jawab dengan data DB terkini.
        return match ($terbaik) {
            'harga' => "Nilai tukar sampah saat ini:\n$konteksHarga\n"
                .'Satuan dalam koin per kg/liter. Admin memverifikasi berat saat setor.',
            'sampah', 'terima' => "Jenis sampah yang diterima Livora dan nilainya:\n$konteksHarga\n"
                .'Setor jenis berikut dengan menimbang di titik kumpul — koin '
                .'dihitung dari berat (atau liter untuk minyak jelantah).',
            default => self::daftarTopik()[$terbaik],
        };
    }

    /** Kata kunci pencocokan per topik (huruf kecil semua). */
    private function kataKunciTopik(string $topik): array
    {
        $umum = [
            'livora' => ['livora', 'bank sampah'],
            'setor' => ['setor', 'buang', 'tabung sampah'],
            'reward' => ['reward', 'hadiah', 'tukar koin', 'redeem'],
            'kupon' => ['kupon', 'kode klaim', 'kode kupon', 'kupon saya', 'ambil reward', 'pickup'],
            'koin' => ['koin', 'coin', 'poin'],
            'registrasi' => ['daftar', 'registrasi', 'buat akun'],
            'login' => ['login', 'masuk', 'google'],
            'sandi' => ['sandi', 'password', 'lupa'],
            'dana' => ['dana', 'e-wallet', 'ewallet', 'gopay', 'ovo', 'shopeepay'],
            'saldo' => ['saldo', 'rupiah', 'uang'],
            'misi' => ['misi', 'mission', 'tugas'],
            'kuis' => ['kuis', 'quiz'],
            'notifikasi' => ['notifikasi', 'bel', 'lonceng', 'pemberitahuan'],
            'qr' => ['qr', 'barcode', 'scan', 'pindai'],
            'lokasi' => ['lokasi', 'alamat', 'di mana', 'dimana', 'drop point', 'titik'],
            'jam' => ['jam buka', 'jam operasional', 'kapan buka'],
            'harga' => ['harga', 'nilai tukar', 'berapa koin', 'kurs'],
            'sampah' => ['sampah', 'jenis'],
            'terima' => ['terima', 'diterima', 'yang diterima', 'menerima'],
            'tukar' => ['tukar', 'konversi'],
            'pencairan' => ['pencairan', 'cairkan', 'tarik saldo', 'tarik dana', 'withdraw', 'tukar saldo'],
            'tier' => ['tier', 'member', 'membership', 'level member', 'status member', 'bronze', 'silver', 'gold'],
            'rupiah' => ['1 koin', 'satu koin', 'nilai koin', 'koin berapa rupiah', 'berapa rupiah', '100 koin'],
            'kendala' => ['belum masuk', 'koin belum', 'tidak masuk', 'gagal setor', 'setoran ditolak', 'salah timbang', 'komplain', 'kendala'],
            'verifikasi' => ['verifikasi', 'diverifikasi', 'validasi', 'disetujui', 'kapan koin masuk', 'berapa lama setor'],
            'kadaluarsa' => ['kadaluarsa', 'kedaluwarsa', 'hangus', 'masa berlaku', 'expired', 'kupon habis'],
            'stok' => ['stok', 'restock', 'reward habis', 'katalog habis'],
            'profil' => ['ubah nama', 'ganti nama', 'foto profil', 'ganti foto', 'ubah profil', 'data diri', 'username'],
            'keamanan' => ['keamanan', 'akun aman', 'akun dibajak', 'password kuat', 'sandi kuat', 'otp', 'kode verifikasi'],
            'privasi' => ['privasi', 'data pribadi', 'data saya', 'kebijakan privasi'],
            'aplikasi' => ['unduh', 'download', 'instal', 'install', 'versi aplikasi', 'android', 'aplikasi livora'],
            'bantuan' => ['bantuan', 'hubungi', 'kontak', 'customer service', 'cs livora'],
            'riwayat' => ['riwayat', 'histori', 'transaksi saya', 'riwayat setor'],
            'pengambilan' => ['pengambilan', 'ambil hadiah', 'diambil di', 'kirim ke rumah', 'ongkir', 'dikirim'],
        ];

        return $umum[$topik] ?? [$topik];
    }

    /** Ringkasan harga sampah dari DB, contoh: "Kardus: 35 koin/kg". */
    private function konteksHargaSampah(): string
    {
        try {
            $jenis = JenisSampah::orderBy('nama_jenis')->get();

            if ($jenis->isEmpty()) {
                return '';
            }

            $baris = $jenis->map(
                fn ($j) => "- {$j->nama_jenis}: ".((float) $j->poin_per_satuan).' koin per '.$j->satuan,
            )->implode("\n");

            return "\n\nData nilai tukar sampah hari ini:\n".$baris;
        } catch (\Exception) {
            return '';
        }
    }
}
