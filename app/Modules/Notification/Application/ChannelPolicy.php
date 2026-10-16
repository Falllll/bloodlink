<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Application\Exceptions\ChannelNotPermitted;
use App\Modules\Notification\Domain\NotificationChannel;

/**
 * Satu-satunya tempat yang memutuskan kanal boleh dipakai atau tidak (kartu 455).
 * Kelas lain TIDAK membaca config notifikasi langsung; AppServiceProvider
 * menyerahkan daftarnya ke konstruktor ini sebagai singleton.
 *
 * Di Application, bukan Domain: ia melempar exception Application, dan layer
 * Domain hanya boleh bergantung pada BloodKernel (deptrac).
 *
 * Menolak secara bawaan: kanal di luar daftar izin melempar, tidak dilewati diam-diam.
 */
final class ChannelPolicy
{
    /** @var list<NotificationChannel> */
    private readonly array $permitted;

    /** @param  list<string>  $permitted */
    public function __construct(array $permitted)
    {
        // Gagal saat dibangun, bukan saat kirim pertama: nilai config tanpa case enum
        // berarti seseorang menambah kanal di config tanpa implementasinya.
        $this->permitted = array_map(
            fn (string $value): NotificationChannel => NotificationChannel::tryFrom($value) ?? throw ChannelNotPermitted::unknownInConfig($value),
            $permitted,
        );
    }

    public function permits(NotificationChannel $channel): bool
    {
        return in_array($channel, $this->permitted, strict: true);
    }

    public function assertDeliverable(NotificationChannel $channel): void
    {
        if (! $this->permits($channel)) {
            throw ChannelNotPermitted::for($channel);
        }
    }

    /**
     * Kanal yang dicoba pengirim (kartu 460) untuk satu peristiwa, dalam urutan enum.
     *
     * @return list<NotificationChannel>
     */
    public function permittedChannels(): array
    {
        return array_values(array_filter(NotificationChannel::cases(), $this->permits(...)));
    }
}
