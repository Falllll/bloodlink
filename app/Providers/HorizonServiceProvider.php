<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Dashboard /horizon HANYA di APP_ENV=local (kartu 460).
 *
 * Backend ini headless: tidak ada login sesi, jadi gate berbasis user tidak punya
 * user untuk diperiksa. Paket Horizon selalu mendaftarkan route-nya (tidak ada
 * saklar untuk mematikannya tanpa ikut kehilangan perintah `horizon`), maka
 * penjaganya di sini: di luar lokal setiap request ke /horizon dijawab 403.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (): bool => app()->environment('local'));
    }

    /**
     * Tidak ada akun yang membuka dashboard di luar lokal -- termasuk admin.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (): bool => false);
    }
}
