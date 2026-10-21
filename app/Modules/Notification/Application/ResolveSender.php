<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Domain\NotificationChannel;
use App\Modules\Notification\Domain\NotificationSender;
use LogicException;

/**
 * Satu-satunya jalan ke sebuah NotificationSender (kartu 460). Kebijakan kanal
 * diperiksa LEBIH DULU, supaya kanal terlarang ditolak dengan alasan yang benar
 * (ChannelNotPermitted) alih-alih "pengirim tidak ditemukan".
 */
final class ResolveSender
{
    /** @param  list<NotificationSender>  $senders */
    public function __construct(
        private readonly array $senders,
        private readonly ChannelPolicy $policy,
    ) {}

    public function for(NotificationChannel $channel): NotificationSender
    {
        $this->policy->assertDeliverable($channel);

        foreach ($this->senders as $sender) {
            if ($sender->channel() === $channel) {
                return $sender;
            }
        }

        throw new LogicException("Channel '{$channel->value}' is permitted but no sender is registered for it.");
    }
}
