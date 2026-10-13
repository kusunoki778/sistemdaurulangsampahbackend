<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
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
        // Email lupa password (Bahasa Indonesia): tautan menuju SPA di
        // FRONTEND_URL. Catatan: versi framework ini mengirim TOKEN (bukan URL)
        // ke toMailUsing, jadi URL dibangun di sini.
        ResetPassword::toMailUsing(function ($notifiable, $token) {
            $frontend = rtrim(config('app.frontend_url'), '/');
            $resetLinkUrl = $frontend.'/atur-ulang-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());

            return (new MailMessage)
                ->subject('Atur Ulang Password Livora')
                ->greeting('Halo, '.$notifiable->name.'!')
                ->line('Kami menerima permintaan untuk mengatur ulang password akun Livora Anda.')
                ->line('Tautan berlaku selama 60 menit dan hanya bisa dipakai satu kali.')
                ->action('Atur Ulang Password', $resetLinkUrl)
                ->line('Jika Anda tidak meminta ini, abaikan email ini — password Anda tidak berubah.');
        });
    }
}
