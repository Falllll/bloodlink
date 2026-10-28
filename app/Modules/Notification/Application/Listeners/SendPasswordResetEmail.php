<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Models\User;
use App\Modules\Identity\Domain\Events\PasswordResetRequested;
use App\Modules\Notification\Application\DispatchNotification;
use App\Modules\Notification\Domain\NotificationChannel;

/**
 * Mengirim tautan reset password (kartu 485) lewat jalur kirim kartu 460 -- bukan
 * Mail::send langsung. EMAIL SAJA: push untuk reset password tampil di layar kunci
 * perangkat yang mungkin sudah di tangan orang lain.
 *
 * SINKRON, tidak pernah ShouldQueue: event-nya membawa tautan rahasia. Tautan itu
 * hanya disimpan terenkripsi di notification_deliveries.variables dan dihapus saat
 * terkirim; payload job pengirimnya hanya public_id.
 */
final class SendPasswordResetEmail
{
    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handle(PasswordResetRequested $event): void
    {
        $user = User::query()->findOrFail($event->userId);

        $this->dispatch->handle(
            $user,
            'auth.password_reset',
            ['reset_url' => $event->resetUrl, 'ttl_minutes' => $event->ttlMinutes],
            only: [NotificationChannel::EMAIL],
        );
    }
}
