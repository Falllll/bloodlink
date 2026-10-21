<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * Satu pengirim per kanal (kartu 460). Pemanggil tidak pernah memegang nama kelas
 * implementasinya: ia diresolusi lewat ResolveSender berdasarkan NotificationChannel.
 */
interface NotificationSender
{
    public function channel(): NotificationChannel;

    /**
     * Kirim satu pesan; kembalikan void bila berhasil. Bila gagal, melempar
     * Application\Exceptions\DeliveryFailed yang jenisnya (TRANSIENT/PERMANENT)
     * diputuskan pengirim (kartu 470). Tidak ditulis sebagai @throws: layer Domain
     * hanya boleh bergantung pada BloodKernel.
     *
     * @param  string  $address  Alamat email, atau nilai token push.
     */
    public function send(string $address, RenderedNotification $message): void;
}
