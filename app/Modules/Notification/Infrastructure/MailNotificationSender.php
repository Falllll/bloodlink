<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Modules\Notification\Domain\NotificationChannel;
use App\Modules\Notification\Domain\NotificationSender;
use App\Modules\Notification\Domain\RenderedNotification;
use App\Modules\Notification\Domain\SendFailed;
use App\Modules\Notification\Infrastructure\Mail\RenderedNotificationMail;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class MailNotificationSender implements NotificationSender
{
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
            // Pesan transport SMTP sering mengutip alamat penerima: tidak diteruskan.
            throw SendFailed::because('smtp_transport', 'SMTP transport rejected the message ('.$e::class.').');
        }
    }
}
