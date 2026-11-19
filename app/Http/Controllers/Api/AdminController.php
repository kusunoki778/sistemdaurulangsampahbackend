<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JenisSampah;
use App\Models\KategoriSampah;
use App\Models\Mission;
use App\Models\MissionProgress;
use App\Models\PermintaanReward;
use App\Models\PesanKontak;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Reward;
use App\Models\TransaksiSetor;
use App\Models\TransaksiSetorDetail;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CloudinaryService;
use App\Services\RewardClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct(
        private readonly RewardClaimService $rewardClaimService,
    ) {}

    /**
     * Ringkasan Statistik Dashboard Admin
     */
    public function stats()
    {
        $this->rewardClaimService->expireDue();

        $totalUsers = User::where('role', 'user')->count();
        $totalAdmins = User::where('role', 'admin')->count();

        $walletStats = Wallet::selectRaw('
            SUM(saldo_koin) as total_koin_beredar,
            SUM(total_koin_diperoleh) as total_koin_diperoleh,
            SUM(total_sampah_kg) as total_sampah_kg,
            SUM(total_sampah_liter) as total_sampah_liter
        ')->first();

        $pendingSetor = TransaksiSetor::whereIn('status', ['pending', 'menunggu_konfirmasi'])->count();
        $pendingReward = PermintaanReward::whereIn('status', ['pending', 'diproses', 'siap_diambil'])->count();
        $pesanBelumDibaca = PesanKontak::where('status', 'unread')->count();

        // Total minyak jelantah (liter) dari setor yang disetujui
        $totalMinyakLiter = (float) DB::table('transaksi_setor_detail')
            ->join('transaksi_setor', 'transaksi_setor_detail.transaksi_setor_id', '=', 'transaksi_setor.id')
            ->join('jenis_sampah', 'transaksi_setor_detail.jenis_sampah_id', '=', 'jenis_sampah.id')
            ->where('transaksi_setor.status', 'approved')
            ->where('jenis_sampah.satuan', 'liter')
            ->sum('transaksi_setor_detail.jumlah');

        // ---- Tren 14 hari + selisih (7 hari terakhir vs 7 hari sebelumnya) ----
        $batas14 = now()->copy()->subDays(13)->startOfDay();

        $harian = [];
        for ($i = 13; $i >= 0; $i--) {
            $tanggal = now()->copy()->subDays($i)->format('Y-m-d');

            // `tanggal` ikut dikirim di dalam item: array_values() di bawah
            // membuang key array, sedangkan grafik membaca field ini untuk
            // label sumbu dan tooltip.
            $harian[$tanggal] = [
                'tanggal' => $tanggal,
                'member' => 0,
                'sampah_kg' => 0.0,
                'minyak_liter' => 0.0,
                'koin' => 0.0,
            ];
        }

        // Member baru per hari (role user).
        $memberBaru = User::where('role', 'user')
            ->where('created_at', '>=', $batas14)
            ->selectRaw('DATE(created_at) as tanggal, COUNT(*) as jumlah')
            ->groupBy('tanggal')
            ->pluck('jumlah', 'tanggal');
        foreach ($memberBaru as $tanggal => $jumlah) {
            if (isset($harian[$tanggal])) {
                $harian[$tanggal]['member'] = (int) $jumlah;
            }
        }

        // Sampah (kg) & minyak (liter) per hari dari setor yang disetujui —
        // satu kueri, dua metrik via SUM bersyarat.
        $setorHarian = DB::table('transaksi_setor_detail')
            ->join('transaksi_setor', 'transaksi_setor_detail.transaksi_setor_id', '=', 'transaksi_setor.id')
            ->join('jenis_sampah', 'transaksi_setor_detail.jenis_sampah_id', '=', 'jenis_sampah.id')
            ->where('transaksi_setor.status', 'approved')
            ->where('transaksi_setor.created_at', '>=', $batas14)
            ->selectRaw(
                'DATE(transaksi_setor.created_at) as tanggal, '.
                "SUM(CASE WHEN jenis_sampah.satuan = 'kg' THEN transaksi_setor_detail.jumlah ELSE 0 END) as kg, ".
                "SUM(CASE WHEN jenis_sampah.satuan = 'liter' THEN transaksi_setor_detail.jumlah ELSE 0 END) as liter"
            )
            ->groupBy('tanggal')
            ->get();
        foreach ($setorHarian as $baris) {
            if (! isset($harian[$baris->tanggal])) {
                continue;
            }
            $harian[$baris->tanggal]['sampah_kg'] = (float) $baris->kg;
            $harian[$baris->tanggal]['minyak_liter'] = (float) $baris->liter;
        }

        // Koin yang masuk per hari.
        $koinHarian = DB::table('transaksi_koin')
            ->where('tipe', 'masuk')
            ->where('created_at', '>=', $batas14)
            ->selectRaw('DATE(created_at) as tanggal, SUM(jumlah_koin) as koin')
            ->groupBy('tanggal')
            ->pluck('koin', 'tanggal');
        foreach ($koinHarian as $tanggal => $jumlah) {
            if (isset($harian[$tanggal])) {
                $harian[$tanggal]['koin'] = (float) $jumlah;
            }
        }

        $semuaHarian = array_values($harian);

        // Persen selisih; null = periode sebelumnya kosong (UI tampil "Baru").
        $selisihKolom = static function (string $kolom) use ($semuaHarian) {
            $sekarang = array_sum(array_slice(array_column($semuaHarian, $kolom), 7, 7));
            $dulu = array_sum(array_slice(array_column($semuaHarian, $kolom), 0, 7));
            if ($dulu > 0) {
                return round((($sekarang - $dulu) / $dulu) * 100, 1);
            }
            if ($sekarang > 0) {
                return null;
            }

            return 0.0;
        };

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_users' => $totalUsers,
                'total_members' => $totalUsers,
                'total_admins' => $totalAdmins,
                'total_koin_beredar' => (float) ($walletStats->total_koin_beredar ?? 0),
                'total_koin_diperoleh' => (float) ($walletStats->total_koin_diperoleh ?? 0),
                'total_sampah_kg' => (float) ($walletStats->total_sampah_kg ?? 0),
                'total_minyak_liter' => $totalMinyakLiter,
                'pending_setor' => $pendingSetor,
                'pending_reward' => $pendingReward,
                'pesan_belum_dibaca' => $pesanBelumDibaca,
                // Pelengkap tren (backwards compatible - kolom lama tak berubah).
                'tren' => [
                    'harian' => $semuaHarian,
                    'naik_turun' => [
                        'member' => $selisihKolom('member'),
                        'sampah_kg' => $selisihKolom('sampah_kg'),
                        'minyak_liter' => $selisihKolom('minyak_liter'),
                        'koin' => $selisihKolom('koin'),
                    ],
                ],
            ],
        ]);
    }

    /**
     * Statistik publik untuk landing page (tanpa login).
     * Hanya agregat aman — tanpa angka antrean verifikasi maupun data admin.
     */
    public function publicStats()
    {
        $totalMembers = User::where('role', 'user')->count();

        $walletStats = Wallet::selectRaw('
            SUM(saldo_koin) as total_koin_beredar,
            SUM(total_sampah_kg) as total_sampah_kg,
            SUM(total_sampah_liter) as total_sampah_liter
        ')->first();

        $totalMinyakLiter = (float) DB::table('transaksi_setor_detail')
            ->join('transaksi_setor', 'transaksi_setor_detail.transaksi_setor_id', '=', 'transaksi_setor.id')
            ->join('jenis_sampah', 'transaksi_setor_detail.jenis_sampah_id', '=', 'jenis_sampah.id')
            ->where('transaksi_setor.status', 'approved')
            ->where('jenis_sampah.satuan', 'liter')
            ->sum('transaksi_setor_detail.jumlah');

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_members' => $totalMembers,
                'total_sampah_kg' => (float) ($walletStats->total_sampah_kg ?? 0),
                'total_sampah_liter' => (float) ($walletStats->total_sampah_liter ?? 0),
                'total_minyak_liter' => $totalMinyakLiter,
                'total_koin_beredar' => (float) ($walletStats->total_koin_beredar ?? 0),
            ],
        ]);
    }

    /**
     * Daftar Semua Pengguna
     */
    public function getUsers(Request $request)
    {
        $query = User::orderBy('id', 'desc');

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $data = $query->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Master Data: Kategori Sampah
     */
    public function kategoriIndex()
    {
        $kategori = KategoriSampah::withCount('jenisSampah')->orderBy('nama_kategori')->get();

        return response()->json(['status' => 'success', 'data' => $kategori]);
    }

    public function kategoriStore(Request $request)
    {
        $request->validate([
            'nama_kategori' => 'required|string|max:100|unique:kategori_sampah,nama_kategori',
            'deskripsi' => 'nullable|string',
            'icon' => 'nullable|string',
        ]);

        $kategori = KategoriSampah::create($request->only(['nama_kategori', 'deskripsi', 'icon']));

        return response()->json(['status' => 'success', 'message' => 'Kategori dibuat', 'data' => $kategori], 201);
    }

    public function kategoriUpdate(Request $request, $id)
    {
        $kategori = KategoriSampah::findOrFail($id);
        $request->validate([
            'nama_kategori' => 'sometimes|required|string|max:100|unique:kategori_sampah,nama_kategori,'.$id,
            'deskripsi' => 'nullable|string',
            'icon' => 'nullable|string',
        ]);
        $kategori->update($request->only(['nama_kategori', 'deskripsi', 'icon']));

        return response()->json(['status' => 'success', 'message' => 'Kategori diperbarui', 'data' => $kategori]);
    }

    public function kategoriDestroy($id)
    {
        $kategori = KategoriSampah::findOrFail($id);

        // Pengawal relasi: FK kategori -> jenis bersifat cascade, menghapus
        // kategori yang masih dipakai ikut menghapus jenisnya (dan berantai
        // sampai detail transaksi bersejarah). Tolak, jangan biarkan kaskade.
        if (JenisSampah::where('kategori_id', $kategori->id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kategori masih memiliki jenis sampah. Pindahkan atau hapus jenisnya terlebih dahulu.',
            ], 422);
        }

        $kategori->delete();

        return response()->json(['status' => 'success', 'message' => 'Kategori dihapus']);
    }

    /**
     * Master Data: Jenis Sampah
     */
    public function jenisSampahIndex(Request $request)
    {
        $query = JenisSampah::with('kategori');
        if ($request->filled('kategori_id')) {
            $query->where('kategori_id', $request->kategori_id);
        }
        $data = $query->orderBy('nama_jenis')->paginate(50);

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function jenisSampahStore(Request $request)
    {
        $request->validate([
            'kategori_id' => 'required|exists:kategori_sampah,id',
            'nama_jenis' => 'required|string|max:100',
            'satuan' => 'required|in:kg,liter,pcs',
            'poin_per_satuan' => 'required|numeric|min:0.01',
            'deskripsi' => 'nullable|string',
            'gambar' => 'nullable|string',
            'gambar_file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        $data = $request->only(['kategori_id', 'nama_jenis', 'satuan', 'poin_per_satuan', 'deskripsi']);
        $data['gambar'] = app(CloudinaryService::class)->resolveGambar($request, config('services.cloudinary.folder_sampah'));
        $jenis = JenisSampah::create($data);

        return response()->json(['status' => 'success', 'message' => 'Jenis sampah dibuat', 'data' => $jenis->load('kategori')], 201);
    }

    public function jenisSampahUpdate(Request $request, $id)
    {
        $jenis = JenisSampah::findOrFail($id);
        $request->validate([
            'kategori_id' => 'sometimes|required|exists:kategori_sampah,id',
            'nama_jenis' => 'sometimes|required|string|max:100',
            'satuan' => 'sometimes|required|in:kg,liter,pcs',
            'poin_per_satuan' => 'sometimes|required|numeric|min:0.01',
            'deskripsi' => 'nullable|string',
            'gambar' => 'nullable|string',
            'gambar_file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        $data = $request->only(['kategori_id', 'nama_jenis', 'satuan', 'poin_per_satuan', 'deskripsi']);
        $data['gambar'] = app(CloudinaryService::class)->resolveGambar($request, config('services.cloudinary.folder_sampah'));
        $jenis->update($data);

        return response()->json(['status' => 'success', 'message' => 'Jenis sampah diperbarui', 'data' => $jenis->load('kategori')]);
    }

    public function jenisSampahDestroy($id)
    {
        $jenis = JenisSampah::findOrFail($id);

        // Pengawal relasi: jenis yang pernah masuk transaksi setor menyimpan
        // riwayat berat + koin user. Menghapusnya mengkaskade detail transaksi
        // bersejarah — kuncilkan selama masih ada yang merujuk.
        if (TransaksiSetorDetail::where('jenis_sampah_id', $jenis->id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Jenis sampah ini sudah dipakai dalam transaksi setor sehingga tidak dapat dihapus — riwayat transaksi harus tetap utuh.',
            ], 422);
        }

        $jenis->delete();

        return response()->json(['status' => 'success', 'message' => 'Jenis sampah dihapus']);
    }

    /**
     * Master Data: Rewards
     */
    public function rewardIndex(Request $request)
    {
        $query = Reward::query();
        if ($request->filled('tipe')) {
            $query->where('tipe', $request->tipe);
        }
        $data = $query->orderBy('koin_dibutuhkan')->paginate(50);

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function rewardStore(Request $request)
    {
        $request->validate([
            'nama_reward' => 'required|string|max:150',
            'tipe' => 'required|in:barang,voucher,saldo_digital',
            'koin_dibutuhkan' => 'required|numeric|min:0.01',
            'nilai_rupiah' => 'nullable|numeric|min:0',
            'stok' => 'required|integer|min:0',
            'gambar' => 'nullable|string',
            'gambar_file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'deskripsi' => 'nullable|string',
            'status_aktif' => 'boolean',
        ]);
        $data = $request->only(['nama_reward', 'tipe', 'koin_dibutuhkan', 'nilai_rupiah', 'stok', 'deskripsi', 'status_aktif']);
        $data['gambar'] = app(CloudinaryService::class)->resolveGambar($request, config('services.cloudinary.folder_reward'));
        $reward = Reward::create($data);

        return response()->json(['status' => 'success', 'message' => 'Reward dibuat', 'data' => $reward], 201);
    }

    public function rewardUpdate(Request $request, $id)
    {
        $reward = Reward::findOrFail($id);
        $request->validate([
            'nama_reward' => 'sometimes|required|string|max:150',
            'tipe' => 'sometimes|required|in:barang,voucher,saldo_digital',
            'koin_dibutuhkan' => 'sometimes|required|numeric|min:0.01',
            'nilai_rupiah' => 'nullable|numeric|min:0',
            'stok' => 'sometimes|required|integer|min:0',
            'gambar' => 'nullable|string',
            'gambar_file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'deskripsi' => 'nullable|string',
            'status_aktif' => 'boolean',
        ]);
        $data = $request->only(['nama_reward', 'tipe', 'koin_dibutuhkan', 'nilai_rupiah', 'stok', 'deskripsi', 'status_aktif']);
        $data['gambar'] = app(CloudinaryService::class)->resolveGambar($request, config('services.cloudinary.folder_reward'));
        $reward->update($data);

        return response()->json(['status' => 'success', 'message' => 'Reward diperbarui', 'data' => $reward]);
    }

    public function rewardDestroy($id)
    {
        $reward = Reward::findOrFail($id);

        // Pengawal relasi: permintaan tukar reward dipakai oleh alur pencocok
        // kupon + refund. Reward dengan klaim aktif/riwayat tidak boleh
        // terhapus kaskade — matikan lewat status_aktif sebagai gantinya.
        if (PermintaanReward::where('reward_id', $reward->id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reward ini sudah ada yang menukar sehingga tidak dapat dihapus. Matikan lewat Status Aktif sebagai gantinya.',
            ], 422);
        }

        $reward->delete();

        return response()->json(['status' => 'success', 'message' => 'Reward dihapus']);
    }

    /**
     * Master Data: Quizzes
     */
    public function quizIndex(Request $request)
    {
        $query = Quiz::withCount('questions');
        if ($request->filled('status_aktif')) {
            $query->where('status_aktif', $request->boolean('status_aktif'));
        }
        $data = $query->orderBy('id', 'desc')->paginate(20);

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function quizStore(Request $request)
    {
        $request->validate([
            'judul' => 'required|string',
            'deskripsi' => 'nullable|string',
            'koin_reward' => 'required|numeric|min:0',
            'status_aktif' => 'boolean',
        ]);
        $quiz = Quiz::create($request->only(['judul', 'deskripsi', 'koin_reward', 'status_aktif']));

        return response()->json(['status' => 'success', 'message' => 'Quiz dibuat', 'data' => $quiz], 201);
    }

    public function quizUpdate(Request $request, $id)
    {
        $quiz = Quiz::findOrFail($id);
        $request->validate([
            'judul' => 'sometimes|required|string',
            'deskripsi' => 'nullable|string',
            'koin_reward' => 'sometimes|required|numeric|min:0',
            'status_aktif' => 'boolean',
        ]);
        $quiz->update($request->only(['judul', 'deskripsi', 'koin_reward', 'status_aktif']));

        return response()->json(['status' => 'success', 'message' => 'Quiz diperbarui', 'data' => $quiz]);
    }

    public function quizDestroy($id)
    {
        $quiz = Quiz::findOrFail($id);

        // Pengawal relasi: riwayat pengerjaan quiz (QuizAttempt) menahan koin
        // reward user — jangan biarkan ikut terkaskade.
        if (QuizAttempt::where('quiz_id', $quiz->id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Quiz ini sudah pernah dikerjakan sehingga tidak dapat dihapus — riwayat pengerjaan harus tetap utuh.',
            ], 422);
        }

        $quiz->delete();

        return response()->json(['status' => 'success', 'message' => 'Quiz dihapus']);
    }

    /**
     * Master Data: Soal Kuis
     *
     * Soal disimpan di tabel questions dan terhubung ke kuis lewat quiz_id.
     * Tanpa endpoint ini admin tidak bisa menambah soal sama sekali, sehingga
     * kuis baru selalu kosong dan member menerima kuis tanpa pertanyaan.
     */
    public function quizShow($id)
    {
        $quiz = Quiz::with('questions')->findOrFail($id);

        return response()->json(['status' => 'success', 'data' => $quiz]);
    }

    public function soalStore(Request $request, $quizId)
    {
        $quiz = Quiz::findOrFail($quizId);

        // Huruf jawaban dinormalisasi dulu supaya 'B' dari form tetap diterima.
        $request->merge([
            'jawaban_benar' => strtolower((string) $request->input('jawaban_benar')),
        ]);

        $data = $request->validate([
            'pertanyaan' => 'required|string|max:1000',
            'opsi_a' => 'required|string|max:255',
            'opsi_b' => 'required|string|max:255',
            'opsi_c' => 'required|string|max:255',
            'opsi_d' => 'required|string|max:255',
            'jawaban_benar' => 'required|in:a,b,c,d',
        ], [
            'jawaban_benar.in' => 'Jawaban benar harus salah satu dari A, B, C, atau D.',
        ]);

        $soal = $quiz->questions()->create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Soal ditambahkan',
            'data' => $soal,
        ], 201);
    }

    public function soalUpdate(Request $request, $quizId, $id)
    {
        Quiz::findOrFail($quizId);
        $soal = Question::where('quiz_id', $quizId)->findOrFail($id);

        if ($request->has('jawaban_benar')) {
            $request->merge([
                'jawaban_benar' => strtolower((string) $request->input('jawaban_benar')),
            ]);
        }

        $data = $request->validate([
            'pertanyaan' => 'sometimes|required|string|max:1000',
            'opsi_a' => 'sometimes|required|string|max:255',
            'opsi_b' => 'sometimes|required|string|max:255',
            'opsi_c' => 'sometimes|required|string|max:255',
            'opsi_d' => 'sometimes|required|string|max:255',
            'jawaban_benar' => 'sometimes|required|in:a,b,c,d',
        ], [
            'jawaban_benar.in' => 'Jawaban benar harus salah satu dari A, B, C, atau D.',
        ]);

        $soal->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Soal diperbarui',
            'data' => $soal->fresh(),
        ]);
    }

    public function soalDestroy($quizId, $id)
    {
        Quiz::findOrFail($quizId);
        $soal = Question::where('quiz_id', $quizId)->findOrFail($id);

        // Riwayat pengerjaan menyimpan jawaban member; soal yang sudah dipakai
        // tetap boleh dihapus, tetapi kuis yang sudah dikerjakan tidak dapat
        // dihapus (dijaga di quizDestroy).
        $soal->delete();

        return response()->json(['status' => 'success', 'message' => 'Soal dihapus']);
    }

    /**
     * Master Data: Missions
     */
    public function missionIndex(Request $request)
    {
        $query = Mission::query();
        if ($request->filled('tipe')) {
            $query->where('tipe', $request->tipe);
        }
        if ($request->filled('status_aktif')) {
            $query->where('status_aktif', $request->boolean('status_aktif'));
        }
        $data = $query->orderBy('tipe')->orderBy('id')->paginate(20);

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function missionStore(Request $request)
    {
        $request->validate([
            'judul' => 'required|string',
            'deskripsi' => 'nullable|string',
            'tipe' => 'required|in:daily,weekly',
            'target_jumlah' => 'required|integer|min:1',
            'koin_reward' => 'required|numeric|min:0',
            'status_aktif' => 'boolean',
        ]);
        $mission = Mission::create($request->only(['judul', 'deskripsi', 'tipe', 'target_jumlah', 'koin_reward', 'status_aktif']));

        return response()->json(['status' => 'success', 'message' => 'Misi dibuat', 'data' => $mission], 201);
    }

    public function missionUpdate(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        $request->validate([
            'judul' => 'sometimes|required|string',
            'deskripsi' => 'nullable|string',
            'tipe' => 'sometimes|required|in:daily,weekly',
            'target_jumlah' => 'sometimes|required|integer|min:1',
            'koin_reward' => 'sometimes|required|numeric|min:0',
            'status_aktif' => 'boolean',
        ]);
        $mission->update($request->only(['judul', 'deskripsi', 'tipe', 'target_jumlah', 'koin_reward', 'status_aktif']));

        return response()->json(['status' => 'success', 'message' => 'Misi diperbarui', 'data' => $mission]);
    }

    public function missionDestroy($id)
    {
        $mission = Mission::findOrFail($id);

        // Pengawal relasi: progres misi user (MissionProgress) adalah
        // riwayat capaian — kuncilkan selama masih ada yang merujuk.
        if (MissionProgress::where('mission_id', $mission->id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Misi ini sudah punya progres user sehingga tidak dapat dihapus — riwayat capaian harus tetap utuh.',
            ], 422);
        }

        $mission->delete();

        return response()->json(['status' => 'success', 'message' => 'Misi dihapus']);
    }
}
