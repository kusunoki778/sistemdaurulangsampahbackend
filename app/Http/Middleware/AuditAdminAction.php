<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mencatat aksi tulis admin (POST/PUT/PATCH/DELETE) beserta hasilnya.
 *
 * Dipasang setelah middleware admin supaya identitas dan hak akses sudah
 * tervalidasi. Aksi baca tidak dicatat agar tabel tidak cepat membengkak.
 */
class AuditAdminAction
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        // File upload tidak ikut dicatat; objek file tidak berguna di JSON.
        $input = $request->except(array_keys($request->allFiles()));
        $route = $request->route();

        $this->auditLogService->catat('admin_action', [
            'description' => $request->method().' '.$request->path(),
            'status_code' => $response->getStatusCode(),
            'metadata' => [
                'route' => $route?->uri(),
                'input' => $input,
            ],
        ]);

        return $response;
    }
}
