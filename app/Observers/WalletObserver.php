<?php

namespace App\Observers;

use App\Models\Wallet;
use App\Services\AuditLogService;

class WalletObserver
{
    private const KOLOM_SALDO = ['saldo_koin', 'koin_ditahan', 'saldo_rupiah'];

    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function updated(Wallet $wallet): void
    {
        $perubahan = [];

        foreach (self::KOLOM_SALDO as $kolom) {
            if (! $wallet->wasChanged($kolom)) {
                continue;
            }

            $perubahan[$kolom] = [
                'lama' => (float) $wallet->getOriginal($kolom),
                'baru' => (float) $wallet->{$kolom},
            ];
        }

        if ($perubahan === []) {
            return;
        }

        $this->auditLogService->catat('wallet_change', [
            'description' => 'Perubahan saldo wallet.',
            'subject' => $wallet,
            'metadata' => [
                'user_id' => $wallet->user_id,
                'perubahan' => $perubahan,
            ],
        ]);
    }
}
