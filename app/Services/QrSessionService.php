<?php

namespace App\Services;

use App\Models\PermintaanReward;
use App\Models\QrSession;
use App\Models\RewardQrSession;
use App\Models\TransaksiSetor;
use Illuminate\Support\Carbon;

class QrSessionService
{
    public const PREFIX_SETOR = 'LIVORA-SETOR:';

    public const PREFIX_REWARD = 'LIVORA-REWARD:';

    public const TIPE_SETOR = 'setor';

    public const TIPE_REWARD = 'reward';

    /**
     * Terbitkan token QR setor baru. Token asli hanya dikembalikan sekali;
     * database menyimpan hash-nya. Sesi lama yang belum terpakai dicabut.
     *
     * @return array{token: string, payload: string, expires_at: string, expires_in: int, session_id: int}
     */
    public function issue(TransaksiSetor $transaksi, string $tipe = self::TIPE_SETOR): array
    {
        $this->revokeFor($transaksi);

        $token = $this->buatToken();
        $expiresAt = $this->kedaluwarsaPada();

        $session = QrSession::create([
            'token_hash' => $this->hashToken($token),
            'transaksi_setor_id' => $transaksi->id,
            'user_id' => $transaksi->user_id,
            'tipe' => $tipe,
            'expires_at' => $expiresAt,
        ]);

        return $this->payloadQr($token, $expiresAt, self::PREFIX_SETOR, $session->id);
    }

    /**
     * Terbitkan token QR pickup reward baru.
     *
     * @return array{token: string, payload: string, expires_at: string, expires_in: int, session_id: int}
     */
    public function issueReward(PermintaanReward $permintaan): array
    {
        $this->revokeRewardFor($permintaan);

        $token = $this->buatToken();
        $expiresAt = $this->kedaluwarsaPada();

        $session = RewardQrSession::create([
            'token_hash' => $this->hashToken($token),
            'permintaan_reward_id' => $permintaan->id,
            'user_id' => $permintaan->user_id,
            'tipe' => self::TIPE_REWARD,
            'expires_at' => $expiresAt,
        ]);

        return $this->payloadQr($token, $expiresAt, self::PREFIX_REWARD, $session->id);
    }

    /**
     * Cari sesi QR setor yang masih berlaku (belum dipakai, belum dicabut,
     * belum kedaluwarsa).
     */
    public function findValid(string $input, string $tipe = self::TIPE_SETOR): ?QrSession
    {
        $token = $this->normalizeToken($input);

        if ($token === '') {
            return null;
        }

        return QrSession::with(['transaksiSetor.details.jenisSampah', 'transaksiSetor.admin'])
            ->where('token_hash', $this->hashToken($token))
            ->where('tipe', $tipe)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Cari sesi QR reward yang masih berlaku.
     */
    public function findValidReward(string $input): ?RewardQrSession
    {
        $token = $this->normalizeToken($input);

        if ($token === '') {
            return null;
        }

        return RewardQrSession::with(['permintaanReward.reward', 'permintaanReward.user'])
            ->where('token_hash', $this->hashToken($token))
            ->where('tipe', self::TIPE_REWARD)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Cari sesi setor apa pun dengan token ini (termasuk yang sudah dipakai,
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
            ->where('token_hash', $this->hashToken($token))
            ->where('tipe', $tipe)
            ->latest('id')
            ->first();
    }

    public function findLatestReward(string $input): ?RewardQrSession
    {
        $token = $this->normalizeToken($input);

        if ($token === '') {
            return null;
        }

        return RewardQrSession::with('permintaanReward.reward')
            ->where('token_hash', $this->hashToken($token))
            ->where('tipe', self::TIPE_REWARD)
            ->latest('id')
            ->first();
    }

    public function consume(QrSession $session): void
    {
        if ($session->used_at === null) {
            $session->forceFill(['used_at' => now()])->save();
        }
    }

    public function consumeReward(RewardQrSession $session): void
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

    public function revokeRewardFor(PermintaanReward $permintaan): void
    {
        RewardQrSession::where('permintaan_reward_id', $permintaan->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Terima token mentah maupun payload QR lengkap
     * ("LIVORA-SETOR:<token>" atau "LIVORA-REWARD:<token>").
     */
    public function normalizeToken(string $input): string
    {
        $input = trim($input);

        if (str_contains($input, ':')) {
            $input = substr($input, strrpos($input, ':') + 1);
        }

        return $input;
    }

    private function buatToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function kedaluwarsaPada(): Carbon
    {
        return now()->addMinutes((int) config('livora.qr_ttl_minutes', 3));
    }

    /**
     * @return array{token: string, payload: string, expires_at: string, expires_in: int, session_id: int}
     */
    private function payloadQr(string $token, Carbon $expiresAt, string $prefix, int $sessionId): array
    {
        return [
            'token' => $token,
            'payload' => $prefix.$token,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in' => max(0, (int) now()->diffInSeconds($expiresAt, false)),
            'session_id' => $sessionId,
        ];
    }
}
