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
     * Kirim satu pesan. Melempar SendFailed bila gagal; kembalikan void bila berhasil.
     *
     * @param  string  $address  Alamat email, atau nilai token push.
     *
     * @throws SendFailed
     */
    public function send(string $address, RenderedNotification $message): void;
}
