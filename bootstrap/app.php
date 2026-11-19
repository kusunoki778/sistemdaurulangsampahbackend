<?php

use App\Http\Middleware\AuditAdminAction;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Services\AuditLogService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'audit.admin' => AuditAdminAction::class,
        ]);

        // Backend ini murni API (tidak punya route web "login"),
        // jadi tamu yang belum login tidak boleh diarahkan ke route 'login'
        // yang tidak ada (akan melempar RouteNotFoundException / error 500).
        $middleware->redirectGuestsTo(fn () => '/');

        // Render/Vercel berada di belakang reverse proxy. Tanpa trusted proxy,
        // IP klien terbaca sebagai IP proxy sehingga rate limit per-IP bisa
        // memblokir semua pengguna sekaligus. Isi TRUSTED_PROXIES dengan IP
        // proxy yang spesifik bila backend diekspos langsung.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        // Batas global per IP untuk seluruh endpoint /api (mencegah scraping,
        // spam, dan banjir request). Endpoint sensitif punya throttle lebih ketat.
        $middleware->api(append: [
            'throttle:api',
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Throttle route berjalan sebelum controller, jadi 429 pada login tidak
        // pernah masuk ke AuditController. Catat di sini agar percobaan brute
        // force tetap terlihat di audit log.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/auth/login')) {
                app(AuditLogService::class)->catat('login_blocked', [
                    'actor' => null,
                    'actor_username' => (string) $request->input('login'),
                    'description' => 'Login diblokir throttle.',
                    'status_code' => 429,
                    'metadata' => [
                        'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
                    ],
                ]);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
