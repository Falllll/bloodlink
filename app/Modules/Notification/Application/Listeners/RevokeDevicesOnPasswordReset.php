<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Models\DeviceToken;
use App\Modules\Identity\Domain\Events\PasswordWasReset;
use App\Modules\Notification\Application\RevokeDeviceToken;

/**
 * Password direset (kartu 485) = setiap perangkat akun ini berhenti menerima push.
 * Sinkron, di transaksi reset yang sama: reset yang gagal tidak mencabut apa pun,
 * reset yang berhasil tidak pernah meninggalkan token hidup.
 */
final class RevokeDevicesOnPasswordReset
{
    public function __construct(private readonly RevokeDeviceToken $revoke) {}

    public function handle(PasswordWasReset $event): void
    {
        DeviceToken::query()
            ->where('user_id', $event->userId)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (DeviceToken $device): DeviceToken => $this->revoke->handle($device));
    }
}
