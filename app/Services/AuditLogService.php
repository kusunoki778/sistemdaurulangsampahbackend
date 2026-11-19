<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pencatat audit terpusat.
 *
 * Penulisan audit tidak boleh menggagalkan aksi utama. Semua error ditelan
 * dan dikirim ke log aplikasi supaya login atau perubahan saldo tetap jalan.
 */
class AuditLogService
{
    public function catat(string $event, array $data = []): ?AuditLog
    {
        try {
            $request = request();
            $actor = array_key_exists('actor', $data)
                ? $data['actor']
                : $request?->user();

            $metadata = $data['metadata'] ?? null;
            if (is_array($metadata)) {
                $metadata = $this->bersihkan($metadata);
            }

            $subject = $data['subject'] ?? null;

            return AuditLog::create([
                'request_id' => $this->requestId($request),
                'user_id' => $actor?->id,
                'actor_username' => $data['actor_username'] ?? $actor?->username,
                'event' => $event,
                'description' => isset($data['description'])
                    ? Str::limit((string) $data['description'], 1000)
                    : null,
                'subject_type' => $subject instanceof Model
                    ? $subject::class
                    : ($data['subject_type'] ?? null),
                'subject_id' => $subject instanceof Model
                    ? $subject->getKey()
                    : ($data['subject_id'] ?? null),
                'method' => $data['method'] ?? $request?->method(),
                'path' => $data['path'] ?? $request?->path(),
                'status_code' => $data['status_code'] ?? null,
                'ip_address' => $data['ip_address'] ?? $request?->ip(),
                'user_agent' => Str::limit((string) ($data['user_agent'] ?? $request?->userAgent()), 500, ''),
                'metadata' => $metadata,
            ]);
        } catch (Throwable $e) {
            Log::error('Gagal menulis audit log', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function requestId(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }

        if (! $request->attributes->has('audit_request_id')) {
            $request->attributes->set('audit_request_id', (string) Str::uuid());
        }

        return (string) $request->attributes->get('audit_request_id');
    }

    /**
     * Samarkan data sensitif secara rekursif sebelum masuk kolom JSON.
     */
    private function bersihkan(array $data, int $kedalaman = 0): array
    {
        if ($kedalaman >= 4) {
            return ['_diringkas' => 'data terlalu dalam'];
        }

        $hasil = [];

        foreach ($data as $kunci => $nilai) {
            $kunciKecil = strtolower((string) $kunci);

            if (Str::contains($kunciKecil, [
                'password', 'token', 'secret', 'authorization', 'api_key', 'apikey',
                'captcha', 'private_key',
            ])) {
                $hasil[$kunci] = '[disamarkan]';

                continue;
            }

            if (is_array($nilai)) {
                $hasil[$kunci] = $this->bersihkan($nilai, $kedalaman + 1);

                continue;
            }

            if ($nilai instanceof Model) {
                $hasil[$kunci] = [
                    'type' => $nilai::class,
                    'id' => $nilai->getKey(),
                ];

                continue;
            }

            if (is_string($nilai)) {
                if (Str::contains($kunciKecil, ['email'])) {
                    $hasil[$kunci] = $this->samarkanEmail($nilai);

                    continue;
                }

                if (Str::contains($kunciKecil, ['nomor', 'phone', 'no_hp', 'telepon'])) {
                    $hasil[$kunci] = $this->samarkanNomor($nilai);

                    continue;
                }

                $hasil[$kunci] = Str::limit($nilai, 1000, '');
            } elseif (is_object($nilai)) {
                $hasil[$kunci] = '[objek]';
            } else {
                $hasil[$kunci] = $nilai;
            }
        }

        return $hasil;
    }

    private function samarkanEmail(string $email): string
    {
        [$nama, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($domain === '') {
            return '[disamarkan]';
        }

        return substr($nama, 0, 1).str_repeat('*', max(2, strlen($nama) - 1)).'@'.$domain;
    }

    private function samarkanNomor(string $nomor): string
    {
        $digit = preg_replace('/\D/', '', $nomor) ?? '';

        if (strlen($digit) < 4) {
            return '[disamarkan]';
        }

        return str_repeat('*', strlen($digit) - 4).substr($digit, -4);
    }
}
