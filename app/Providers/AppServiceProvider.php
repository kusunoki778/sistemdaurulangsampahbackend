<?php

namespace App\Providers;

use App\Models\Wallet;
use App\Observers\WalletObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Wallet::observe(WalletObserver::class);

        // Kunci debug di produksi: stack trace tidak boleh pernah tampil ke
        // publik meskipun .env salah setel. Untuk investigasi, pakai log.
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }

        // Ambang batas global /api: 180 request per menit per IP. Endpoint
        // sensitif (login, chat, klaim) tetap punya batas lebih ketat.
        RateLimiter::for('api', function (Request $request) {
            if ($this->app->environment('testing')) {
                return Limit::none();
            }

            $kunci = $request->user()?->id
                ? 'user:'.$request->user()->id
                : 'ip:'.$request->ip();

            return Limit::perMinute(180)->by($kunci);
        });

        // Email lupa password (Bahasa Indonesia).
        // Versi framework ini mengirim TOKEN mentah ke toMailUsing.
        // Klien mobile meminta token untuk ditempel di layar Lupa Kata Sandi,
        // jadi token DITAMPILKAN sebagai teks yang bisa disalin — tanpa tautan
        // web (tidak ada halaman reset di web CMS; FRONTEND_URL lama sudah mati).
        ResetPassword::toMailUsing(function ($notifiable, $token) {
            return (new MailMessage)
                ->subject('Atur Ulang Password Livora')
                ->greeting('Halo, '.$notifiable->name.'!')
                ->line('Kami menerima permintaan untuk mengatur ulang password akun Livora Anda.')
                ->line('Salin token di bawah ini, lalu tempel di aplikasi Livora (layar Lupa Kata Sandi):')
                ->line($token)
                ->line('Token berlaku 60 menit dan hanya bisa dipakai satu kali.')
                ->line('Jika Anda tidak meminta ini, abaikan email ini — password Anda tidak berubah.');
        });
    }
}
