<?php

namespace App\Services;

use App\Models\QrSession;
use App\Models\TransaksiSetor;

class QrSessionService
{
    public const PREFIX_SETOR = 'LIVORA-SETOR:';

    public const TIPE_SETOR = 'setor';

    /**
     * Terbitkan token QR baru. Token asli hanya dikembalikan sekali;
     * database menyimpan hash-nya. Sesi lama yang belum terpakai dicabut.
     *
     * @return array{token: string, payload: string, expires_at: string, expires_in: int, session_id: int}
     */
    public function issue(TransaksiSetor $transaksi, string $tipe = self::TIPE_SETOR): array
    {
        $this->revokeFor($transaksi);

        $token = bin2hex(random_bytes(16));
        $expiresAt = now()->addMinutes((int) config('livora.qr_ttl_minutes', 3));

        $session = QrSession::create([
            'token_hash' => hash('sha256', $token),
            'transaksi_setor_id' => $transaksi->id,
            'user_id' => $transaksi->user_id,
            'tipe' => $tipe,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'payload' => self::PREFIX_SETOR.$token,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in' => max(0, (int) now()->diffInSeconds($expiresAt, false)),
            'session_id' => $session->id,
        ];
    }

    /**
     * Cari sesi QR yang masih berlaku (belum dipakai, belum dicabut, belum kedaluwarsa).
     */
    public function findValid(string $input, string $tipe = self::TIPE_SETOR): ?QrSession
    {
        $token = $this->normalizeToken($input);

        if ($token === '') {
            return null;
        }

        return QrSession::with(['transaksiSetor.details.jenisSampah', 'transaksiSetor.admin'])
            ->where('token_hash', hash('sha256', $token))
            ->where('tipe', $tipe)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Cari sesi apa pun dengan token ini (termasuk yang sudah dipakai,
     * dicabut, atau kedaluwarsa). Dipakai untuk respons idempoten saat user
     * menekan konfirmasi setelah admin menyelesaikan transaksi.
     */
    public function findLatest(string $input, string $tipe = self::TIPE_SETOR): ?QrSession
    {
        $token = $this->normalizeToken($input);

        if ($token === '') {
            return null;
        }

        return QrSession::with('transaksiSetor')
            ->where('token_hash', hash('sha256', $token))
            ->where('tipe', $tipe)
            ->latest('id')
            ->first();
    }

    public function consume(QrSession $session): void
    {
        if ($session->used_at === null) {
            $session->forceFill(['used_at' => now()])->save();
        }
    }

    public function revokeFor(TransaksiSetor $transaksi): void
    {
        QrSession::where('transaksi_setor_id', $transaksi->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Terima token mentah maupun payload QR lengkap ("LIVORA-SETOR:<token>").
     */
    public function normalizeToken(string $input): string
    {
        $input = trim($input);

        if (str_contains($input, ':')) {
            $input = substr($input, strrpos($input, ':') + 1);
        }

        return $input;
    }
}
