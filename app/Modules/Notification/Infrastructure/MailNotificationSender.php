<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Modules\Notification\Application\Exceptions\DeliveryFailed;
use App\Modules\Notification\Domain\NotificationChannel;
use App\Modules\Notification\Domain\NotificationSender;
use App\Modules\Notification\Domain\RenderedNotification;
use App\Modules\Notification\Infrastructure\Mail\RenderedNotificationMail;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class MailNotificationSender implements NotificationSender
{
    /**
     * Balasan SMTP 5xx yang menyangkut PENERIMA (RFC 5321 §4.2.2): mailbox tidak ada
     * (550), bukan pengguna lokal (551), nama mailbox tidak diizinkan (553). Kode 5xx
     * lain (mis. 535 autentikasi, 554 transaksi gagal) tetap TRANSIENT: penyebabnya
     * di sisi kita atau ambigu, dan untuk notifikasi darurat satu percobaan berlebih
     * lebih murah daripada menyerah terlalu cepat.
     */
    private const array PERMANENT_SMTP_CODES = [550, 551, 553];

    public function channel(): NotificationChannel
    {
        return NotificationChannel::EMAIL;
    }

    public function send(string $address, RenderedNotification $message): void
    {
        // CHECK di notification_templates menjamin (channel='email') = (subject IS NOT NULL):
        // null di sini adalah bug, bukan kasus yang ditangani.
        $subject = $message->subject ?? throw new LogicException('An email notification reached the mail sender without a subject.');

        try {
            Mail::to($address)->send(new RenderedNotificationMail($subject, $message->body));
        } catch (TransportExceptionInterface $e) {
            // Kode exception transport SMTP Symfony = kode balasan server; 0 bila koneksi
            // gagal (host mati, timeout, TLS) -- itu selalu TRANSIENT.
            $code = (int) $e->getCode();
            // Pesan transport sering mengutip alamat penerima: hanya kodenya yang diteruskan.
            $detail = "SMTP transport rejected the message (reply code {$code}).";

            throw in_array($code, self::PERMANENT_SMTP_CODES, true)
                ? DeliveryFailed::permanent("smtp_{$code}", $detail)
                : DeliveryFailed::transient($code === 0 ? 'smtp_unreachable' : "smtp_{$code}", $detail);
        }
    }
}
