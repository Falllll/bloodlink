<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Exceptions;

use App\Modules\Notification\Domain\NotificationChannel;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * Kode internal meminta kanal yang tidak diizinkan. Selalu bug kita, tidak pernah
 * kesalahan klien API -- maka 500. Pesannya menyebut ALASAN, supaya orang berikutnya
 * langsung paham ini keputusan (kartu 455), bukan kelalaian.
 */
final class ChannelNotPermitted extends DomainException
{
    public static function for(NotificationChannel $channel): self
    {
        return new self("Channel '{$channel->value}' is not enabled: only channels without a per-message cost are permitted, and paid per-message channels (SMS, WhatsApp) are deferred until there is budget.");
    }

    public static function unknownInConfig(string $value): self
    {
        return new self("Configured channel '{$value}' has no NotificationChannel case: a channel is added to the enum, together with its sender, on the day it is implemented -- a config line alone is not enough.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::NOTIFICATION_CHANNEL_NOT_PERMITTED;
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
