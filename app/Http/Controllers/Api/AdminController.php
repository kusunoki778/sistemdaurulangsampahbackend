<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JenisSampah;
use App\Models\KategoriSampah;
use App\Models\Mission;
use App\Models\PermintaanReward;
use App\Models\PesanKontak;
use App\Models\Quiz;
use App\Models\Reward;
use App\Models\TransaksiSetor;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Ringkasan Statistik Dashboard Admin
     */
    public function stats()
    {
        $totalUsers = User::where('role', 'user')->count();
        $totalAdmins = User::where('role', 'admin')->count();

        $walletStats = Wallet::selectRaw('
            SUM(saldo_koin) as total_koin_beredar,
            SUM(total_koin_diperoleh) as total_koin_diperoleh,
            SUM(total_sampah_kg) as total_sampah_kg
        ')->first();

        $pendingSetor = TransaksiSetor::where('status', 'pending')->count();
        $pendingReward = PermintaanReward::where('status', 'pending')->count();
        $pesanBelumDibaca = PesanKontak::where('status', 'unread')->count();

        // Total minyak jelantah (liter) dari setor yang disetujui
        $totalMinyakLiter = (float) DB::table('transaksi_setor_detail')
            ->join('transaksi_setor', 'transaksi_setor_detail.transaksi_setor_id', '=', 'transaksi_setor.id')
            ->join('jenis_sampah', 'transaksi_setor_detail.jenis_sampah_id', '=', 'jenis_sampah.id')
            ->where('transaksi_setor.status', 'approved')
            ->where('jenis_sampah.satuan', 'liter')
            ->sum('transaksi_setor_detail.jumlah');

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_users' => $totalUsers,
                'total_members' => $totalUsers,
                'total_admins' => $totalAdmins,
                'total_koin_beredar' => (int) ($walletStats->total_koin_beredar ?? 0),
                'total_koin_diperoleh' => (int) ($walletStats->total_koin_diperoleh ?? 0),
                'total_sampah_kg' => (float) ($walletStats->total_sampah_kg ?? 0),
                'total_minyak_liter' => $totalMinyakLiter,
                'pending_setor' => $pendingSetor,
                'pending_reward' => $pendingReward,
                'pesan_belum_dibaca' => $pesanBelumDibaca,
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
            SUM(total_sampah_kg) as total_sampah_kg
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
                'total_minyak_liter' => $totalMinyakLiter,
                'total_koin_beredar' => (int) ($walletStats->total_koin_beredar ?? 0),
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
        KategoriSampah::findOrFail($id)->delete();

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
            'poin_per_satuan' => 'required|integer|min:1',
            'deskripsi' => 'nullable|string',
            'gambar' => 'nullable|string',
        ]);
        $jenis = JenisSampah::create($request->only(['kategori_id', 'nama_jenis', 'satuan', 'poin_per_satuan', 'deskripsi', 'gambar']));

        return response()->json(['status' => 'success', 'message' => 'Jenis sampah dibuat', 'data' => $jenis->load('kategori')], 201);
    }

    public function jenisSampahUpdate(Request $request, $id)
    {
        $jenis = JenisSampah::findOrFail($id);
        $request->validate([
            'kategori_id' => 'sometimes|required|exists:kategori_sampah,id',
            'nama_jenis' => 'sometimes|required|string|max:100',
            'satuan' => 'sometimes|required|in:kg,liter,pcs',
            'poin_per_satuan' => 'sometimes|required|integer|min:1',
            'deskripsi' => 'nullable|string',
            'gambar' => 'nullable|string',
        ]);
        $jenis->update($request->only(['kategori_id', 'nama_jenis', 'satuan', 'poin_per_satuan', 'deskripsi', 'gambar']));

        return response()->json(['status' => 'success', 'message' => 'Jenis sampah diperbarui', 'data' => $jenis->load('kategori')]);
    }

    public function jenisSampahDestroy($id)
    {
        JenisSampah::findOrFail($id)->delete();

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
            'koin_dibutuhkan' => 'required|integer|min:1',
            'stok' => 'required|integer|min:0',
            'gambar' => 'nullable|string',
            'deskripsi' => 'nullable|string',
            'status_aktif' => 'boolean',
        ]);
        $reward = Reward::create($request->only(['nama_reward', 'tipe', 'koin_dibutuhkan', 'stok', 'gambar', 'deskripsi', 'status_aktif']));

        return response()->json(['status' => 'success', 'message' => 'Reward dibuat', 'data' => $reward], 201);
    }

    public function rewardUpdate(Request $request, $id)
    {
        $reward = Reward::findOrFail($id);
        $request->validate([
            'nama_reward' => 'sometimes|required|string|max:150',
            'tipe' => 'sometimes|required|in:barang,voucher,saldo_digital',
            'koin_dibutuhkan' => 'sometimes|required|integer|min:1',
            'stok' => 'sometimes|required|integer|min:0',
            'gambar' => 'nullable|string',
            'deskripsi' => 'nullable|string',
            'status_aktif' => 'boolean',
        ]);
        $reward->update($request->only(['nama_reward', 'tipe', 'koin_dibutuhkan', 'stok', 'gambar', 'deskripsi', 'status_aktif']));

        return response()->json(['status' => 'success', 'message' => 'Reward diperbarui', 'data' => $reward]);
    }

    public function rewardDestroy($id)
    {
        Reward::findOrFail($id)->delete();

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
            'koin_reward' => 'required|integer|min:0',
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
            'koin_reward' => 'sometimes|required|integer|min:0',
            'status_aktif' => 'boolean',
        ]);
        $quiz->update($request->only(['judul', 'deskripsi', 'koin_reward', 'status_aktif']));

        return response()->json(['status' => 'success', 'message' => 'Quiz diperbarui', 'data' => $quiz]);
    }

    public function quizDestroy($id)
    {
        Quiz::findOrFail($id)->delete();

        return response()->json(['status' => 'success', 'message' => 'Quiz dihapus']);
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
            'koin_reward' => 'required|integer|min:0',
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
            'koin_reward' => 'sometimes|required|integer|min:0',
            'status_aktif' => 'boolean',
        ]);
        $mission->update($request->only(['judul', 'deskripsi', 'tipe', 'target_jumlah', 'koin_reward', 'status_aktif']));

        return response()->json(['status' => 'success', 'message' => 'Misi diperbarui', 'data' => $mission]);
    }

    public function missionDestroy($id)
    {
        Mission::findOrFail($id)->delete();

        return response()->json(['status' => 'success', 'message' => 'Misi dihapus']);
    }
}
