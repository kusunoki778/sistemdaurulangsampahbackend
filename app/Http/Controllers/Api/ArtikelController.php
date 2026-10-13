<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use Illuminate\Http\Request;

class ArtikelController extends Controller
{
    /**
     * Daftar artikel publik (published only)
     * GET /api/artikels
     */
    public function index(Request $request)
    {
        $query = Artikel::where('status', 'published')
            ->with('admin:id,name')
            ->orderBy('created_at', 'desc');

        if ($request->filled('kategori')) {
            $query->where('kategori', $request->kategori);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('judul', 'like', "%{$search}%")
                    ->orWhere('kategori', 'like', "%{$search}%")
                    ->orWhere('isi', 'like', "%{$search}%");
            });
        }

        $perPage = $request->filled('per_page') ? min($request->per_page, 50) : 10;

        $artikels = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $artikels,
        ]);
    }

    /**
     * Detail artikel by slug
     * GET /api/artikels/{slug}
     */
    public function show($slug)
    {
        $artikel = Artikel::where('slug', $slug)
            ->where('status', 'published')
            ->with('admin:id,name')
            ->firstOrFail();

        // Increment views
        $artikel->increment('views');

        return response()->json([
            'status' => 'success',
            'data' => $artikel,
        ]);
    }

    /**
     * Admin: Create artikel
     * POST /api/admin/artikels
     */
    public function store(Request $request)
    {
        $request->validate([
            'judul' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:artikels,slug',
            'kategori' => 'required|string|max:100',
            'isi' => 'required|string',
            'gambar' => 'nullable|string',
            'status' => 'sometimes|in:draft,published',
        ]);

        $artikel = Artikel::create([
            'judul' => $request->judul,
            'slug' => $request->slug,
            'kategori' => $request->kategori,
            'isi' => $request->isi,
            'gambar' => $request->gambar,
            'admin_id' => $request->user()->id,
            'status' => $request->status ?? 'draft',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Artikel berhasil dibuat',
            'data' => $artikel,
        ], 201);
    }

    /**
     * Admin: Update artikel
     * PUT /api/admin/artikels/{id}
     */
    public function update(Request $request, $id)
    {
        $artikel = Artikel::findOrFail($id);

        $request->validate([
            'judul' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:artikels,slug,'.$id,
            'kategori' => 'sometimes|required|string|max:100',
            'isi' => 'sometimes|required|string',
            'gambar' => 'nullable|string',
            'status' => 'sometimes|in:draft,published',
        ]);

        $artikel->update($request->only(['judul', 'slug', 'kategori', 'isi', 'gambar', 'status']));

        return response()->json([
            'status' => 'success',
            'message' => 'Artikel berhasil diperbarui',
            'data' => $artikel,
        ]);
    }

    /**
     * Admin: Delete artikel
     * DELETE /api/admin/artikels/{id}
     */
    public function destroy($id)
    {
        $artikel = Artikel::findOrFail($id);
        $artikel->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Artikel berhasil dihapus',
        ]);
    }

    /**
     * Admin: List semua artikel (include draft)
     * GET /api/admin/artikels
     */
    public function adminIndex(Request $request)
    {
        $query = Artikel::with('admin:id,name')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('kategori')) {
            $query->where('kategori', $request->kategori);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('judul', 'like', "%{$search}%")
                    ->orWhere('kategori', 'like', "%{$search}%")
                    ->orWhere('isi', 'like', "%{$search}%");
            });
        }

        $data = $query->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }
}
